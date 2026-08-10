<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationBuilder;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;

$setupMarker = getenv('STARFINITI_BUILDER_KILL_SETUP');
$workerMarker = getenv('STARFINITI_BUILDER_KILL_WORKER');
if (!is_string($setupMarker) || !is_readable($setupMarker) || !is_string($workerMarker) || $workerMarker === '') {
    throw new RuntimeException('Builder-kill worker markers are unavailable.');
}
$setup = json_decode((string) file_get_contents($setupMarker), true, 8, JSON_THROW_ON_ERROR);
$generation = (int) ($setup['generation'] ?? 0);
global $wpdb;
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('starfiniti_search_build_generation', [$generation, 0], 'starfiniti-search');
}
add_action('starfiniti_search_generation_document_indexed', static function (int $observedGeneration, int $entityId) use ($generation, $workerMarker, $wpdb): void {
    static $published = false;
    if ($published || $observedGeneration !== $generation) {
        return;
    }
    $published = true;
    $row = $wpdb->get_row($wpdb->prepare("SELECT state,build_cursor,build_attempts,build_lease_token,build_leased_until FROM {$wpdb->prefix}sfs_index_generations WHERE generation_id=%d", $generation), ARRAY_A);
    $documents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $generation));
    file_put_contents($workerMarker, wp_json_encode(['generation' => $generation, 'entity_id' => $entityId, 'documents' => $documents, 'row' => $row]), LOCK_EX);
    sleep(120);
}, 10, 2);

$tokenizer = new Tokenizer();
$builder = new GenerationBuilder($wpdb, new WooProductSource(new ProductDocumentFactory()), new LocalIndexer($wpdb, $tokenizer), 2);
$builder->run($generation, 0);
throw new RuntimeException('Builder-kill worker was not terminated as required.');
