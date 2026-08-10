<?php

use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;

$database = getenv('STARFINITI_DR_DATABASE');
$user = getenv('STARFINITI_DR_USER');
$password = getenv('STARFINITI_DR_PASSWORD');
$host = getenv('STARFINITI_DR_HOST');
if (!is_string($database) || preg_match('/^starfiniti_search_dr_[0-9]+$/', $database) !== 1
    || !is_string($user) || $user === '' || !is_string($password) || !is_string($host) || $host === '') {
    throw new RuntimeException('Disaster-recovery connection contract is invalid.');
}

$sourceDatabase = $GLOBALS['wpdb'];
$restored = new wpdb($user, $password, $database, $host);
if ($restored->last_error !== '') {
    throw new RuntimeException('Could not connect to the restored database.');
}
$restored->set_prefix('wp_');
foreach (get_object_vars($sourceDatabase) as $property => $value) {
    if (str_starts_with((string) $property, 'actionscheduler_') && is_string($value)) {
        $restored->{$property} = $value;
    }
}
$GLOBALS['wpdb'] = $restored;
wp_cache_flush();

$schema = (int) get_option('starfiniti_search_schema_version');
$active = (int) get_option('starfiniti_search_active_generation');
$generation = $restored->get_row($restored->prepare("SELECT state,schema_version,analyzer_revision,ranking_profile,document_count FROM {$restored->prefix}sfs_index_generations WHERE generation_id=%d", $active), ARRAY_A);
$documents = (int) $restored->get_var($restored->prepare("SELECT COUNT(*) FROM {$restored->prefix}sfs_documents WHERE generation_id=%d", $active));
$configuration = new ConfigurationRepository($restored);
$current = $configuration->current();
if ($schema !== 10 || $active < 1 || !is_array($generation) || $generation['state'] !== 'active'
    || (int) $generation['schema_version'] !== 3 || (int) $generation['analyzer_revision'] !== 1
    || (string) $generation['ranking_profile'] !== 'local-default-v1'
    || (int) $generation['document_count'] !== $documents || $documents !== 7 || $current === null) {
    throw new RuntimeException('Restored search metadata or immutable configuration is inconsistent.');
}

$provider = new LocalSearchProvider($restored, new Tokenizer(), $configuration);
$request = [
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
];
$exact = $provider->search(['query' => 'EXACT-001'] + $request);
$restricted = $provider->search(['query' => 'Hidden Wholesale Belt'] + $request);
if (($exact['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt'
    || ($exact['provider'] ?? '') !== 'local' || (int) ($restricted['total'] ?? -1) !== 0) {
    throw new RuntimeException('Restored database failed exact-SKU or restricted-product search verification.');
}

$GLOBALS['wpdb'] = $sourceDatabase;
wp_cache_flush();
echo "Disaster-recovery application verification passed: schema 10, immutable configuration, seven active documents, exact SKU, and visibility isolation.\n";
