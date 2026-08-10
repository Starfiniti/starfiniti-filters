<?php

use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Database\SchemaInstaller;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;

global $wpdb;
$table = $wpdb->prefix . 'sfs_index_generations';
$active = (int) get_option('starfiniti_search_active_generation');
$before = $wpdb->get_row($wpdb->prepare("SELECT state,schema_version,analyzer_revision,ranking_profile,document_count FROM {$table} WHERE generation_id=%d", $active), ARRAY_A);
$documentCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $active));
$configuration = new ConfigurationRepository($wpdb);
$checksum = (string) $configuration->current()?->checksum();
if (!is_array($before) || $before['state'] !== 'active' || $documentCount !== 7 || strlen($checksum) !== 64) {
    throw new RuntimeException('Schema-upgrade preconditions are unavailable.');
}

$columns = ['build_attempts', 'build_lease_token', 'build_leased_until', 'build_updated_at'];
foreach ($columns as $column) {
    if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s", $table, $column)) !== 1) {
        throw new RuntimeException('Schema-upgrade source does not begin at schema 10.');
    }
}

try {
    update_option('starfiniti_search_schema_version', 9, false);
    $dropped = $wpdb->query("ALTER TABLE {$table} DROP COLUMN build_attempts, DROP COLUMN build_lease_token, DROP COLUMN build_leased_until, DROP COLUMN build_updated_at, ALGORITHM=COPY");
    if ($dropped === false) {
        throw new RuntimeException('Schema-upgrade rehearsal could not create the physical schema-9 table copy.');
    }
    SchemaInstaller::activate();
} finally {
    SchemaInstaller::activate();
}

foreach ($columns as $column) {
    if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s", $table, $column)) !== 1) {
        throw new RuntimeException('Schema 9 to 10 upgrade did not restore generation recovery column: ' . $column);
    }
}
$after = $wpdb->get_row($wpdb->prepare("SELECT state,schema_version,analyzer_revision,ranking_profile,document_count,build_attempts,build_lease_token,build_leased_until FROM {$table} WHERE generation_id=%d", $active), ARRAY_A);
if (!is_array($after) || $after['state'] !== $before['state'] || $after['schema_version'] !== $before['schema_version']
    || $after['analyzer_revision'] !== $before['analyzer_revision'] || $after['ranking_profile'] !== $before['ranking_profile']
    || $after['document_count'] !== $before['document_count'] || (int) $after['build_attempts'] !== 0
    || $after['build_lease_token'] !== null || $after['build_leased_until'] !== null
    || (int) get_option('starfiniti_search_active_generation') !== $active
    || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $active)) !== $documentCount
    || !hash_equals($checksum, (string) $configuration->current()?->checksum())) {
    throw new RuntimeException('Schema 9 to 10 upgrade changed active index or immutable configuration state.');
}

$provider = new LocalSearchProvider($wpdb, new Tokenizer(), $configuration);
$request = ['context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']], 'page' => ['number' => 1, 'size' => 10]];
$exact = $provider->search(['query' => 'EXACT-001'] + $request);
$restricted = $provider->search(['query' => 'Hidden Wholesale Belt'] + $request);
if (($exact['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt' || (int) ($restricted['total'] ?? -1) !== 0) {
    throw new RuntimeException('Search or visibility changed after schema 9 to 10 upgrade.');
}

echo "Schema upgrade passed: v9 to v10 added generation recovery metadata without changing active index, configuration, exact SKU, or visibility.\n";
