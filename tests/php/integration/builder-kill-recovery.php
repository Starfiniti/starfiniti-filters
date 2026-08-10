<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationBuilder;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;

$setupMarker = getenv('STARFINITI_BUILDER_KILL_SETUP');
$workerMarker = getenv('STARFINITI_BUILDER_KILL_WORKER');
if (!is_string($setupMarker) || !is_readable($setupMarker) || !is_string($workerMarker) || !is_readable($workerMarker)) {
    throw new RuntimeException('Builder-kill recovery markers are unavailable.');
}
$setup = json_decode((string) file_get_contents($setupMarker), true, 8, JSON_THROW_ON_ERROR);
$killed = json_decode((string) file_get_contents($workerMarker), true, 16, JSON_THROW_ON_ERROR);
$generation = (int) ($setup['generation'] ?? 0);
$active = (int) ($setup['active_generation'] ?? 0);
global $wpdb;
$table = $wpdb->prefix . 'sfs_index_generations';
$row = $wpdb->get_row($wpdb->prepare("SELECT state,build_cursor,build_attempts,build_lease_token,build_leased_until FROM {$table} WHERE generation_id=%d", $generation), ARRAY_A);
$documents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $generation));
if (!is_array($row)
    || $row['state'] !== 'building'
    || (int) $row['build_cursor'] !== 0
    || (int) $row['build_attempts'] !== 1
    || $documents !== 1
    || !hash_equals((string) ($killed['row']['build_lease_token'] ?? ''), (string) $row['build_lease_token'])
    || strtotime((string) $row['build_leased_until'] . ' UTC') > time()
    || (int) get_option('starfiniti_search_active_generation') !== $active) {
    throw new RuntimeException('Killed shadow-builder evidence or active-generation isolation is incorrect.');
}

$manager = new GenerationManager($wpdb);
if ($manager->ensureBuildScheduled() !== $generation
    || !as_has_scheduled_action('starfiniti_search_build_generation', [$generation, 0], 'starfiniti-search')) {
    throw new RuntimeException('Expired shadow build was not automatically rescheduled from its stored cursor.');
}
as_unschedule_all_actions('starfiniti_search_build_generation', [$generation, 0], 'starfiniti-search');
$tokenizer = new Tokenizer();
$builder = new GenerationBuilder($wpdb, new WooProductSource(new ProductDocumentFactory()), new LocalIndexer($wpdb, $tokenizer));
$builder->run($generation, 999999);

$recovered = $wpdb->get_row($wpdb->prepare("SELECT state,build_cursor,build_attempts,build_lease_token,build_leased_until,document_count FROM {$table} WHERE generation_id=%d", $generation), ARRAY_A);
$documents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $generation));
$duplicateEntities = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT entity_type,entity_id,locale,COUNT(*) copies FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d GROUP BY entity_type,entity_id,locale HAVING copies<>1) duplicates", $generation));
$exactSku = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d AND entity_id=10 AND sku_normalized='exact001'", $generation));
if (!is_array($recovered)
    || $recovered['state'] !== 'ready'
    || (int) $recovered['build_attempts'] !== 2
    || (int) $recovered['build_cursor'] !== 16
    || (int) $recovered['document_count'] !== 7
    || $recovered['build_lease_token'] !== null
    || $recovered['build_leased_until'] !== null
    || $documents !== 7
    || $duplicateEntities !== 0
    || $exactSku !== 1
    || (int) get_option('starfiniti_search_active_generation') !== $active) {
    throw new RuntimeException('Shadow-builder recovery was not exact, resumable, or isolated from the active generation.');
}

echo "Builder-kill recovery passed: expired lease rescheduled, stale cursor ignored, attempt two completed exactly, and active generation stayed unchanged.\n";
