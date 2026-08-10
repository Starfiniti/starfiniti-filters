<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Domain\Search\PositionCodec;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationBuilder;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;

global $wpdb;
$original = (int) get_option('starfiniti_search_active_generation');
$tokenizer = new Tokenizer();
$manager = new GenerationManager($wpdb);
$indexer = new LocalIndexer($wpdb, $tokenizer);
$builder = new GenerationBuilder($wpdb, new WooProductSource(new ProductDocumentFactory()), $indexer);
$generation = $manager->startBuild();
$builder->run($generation, 0);

$rows = $manager->list();
$row = array_values(array_filter($rows, static fn (array $item): bool => (int) $item['generation_id'] === $generation))[0] ?? null;
if (!is_array($row) || $row['state'] !== 'ready' || (int) $row['document_count'] !== 7) {
    throw new RuntimeException('Shadow generation did not reach the verified ready state.');
}
if ((int) get_option('starfiniti_search_build_generation') !== $generation || $manager->ensureBuildScheduled() !== $generation) {
    throw new RuntimeException('Ready generation was not retained as a live dual-write target before cutover.');
}

$originalTitle = (string) $wpdb->get_var("SELECT post_title FROM {$wpdb->posts} WHERE ID=10");
try {
    $wpdb->update($wpdb->posts, ['post_title' => 'Cutover Window Pending Sample'], ['ID' => 10], ['%s'], ['%d']);
    clean_post_cache(10);
    wc_delete_product_transients(10);
    $cutoverProduct = wc_get_product(10);
    if (!$cutoverProduct instanceof WC_Product) {
        throw new RuntimeException('Cutover-window product fixture is unavailable.');
    }
    do_action('woocommerce_update_product', 10, $cutoverProduct);
    try {
        $manager->activate($generation);
        throw new RuntimeException('Cutover accepted a target with pending synchronization work.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'Cutover accepted a target with pending synchronization work.') {
            throw $exception;
        }
    }
    if ((int) get_option('starfiniti_search_active_generation') !== $original) {
        throw new RuntimeException('Rejected cutover changed the active generation.');
    }
    do_action('starfiniti_search_process_outbox', 'integration-cutover-window');
    $readyTitle = (string) $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d AND entity_id=10 LIMIT 1", $generation));
    if ($readyTitle !== 'Cutover Window Pending Sample') {
        throw new RuntimeException('Ready generation did not receive the cutover-window dual write.');
    }
} finally {
    $wpdb->update($wpdb->posts, ['post_title' => $originalTitle], ['ID' => 10], ['%s'], ['%d']);
    clean_post_cache(10);
    wc_delete_product_transients(10);
    $restoreProduct = wc_get_product(10);
    if ($restoreProduct instanceof WC_Product) {
        do_action('woocommerce_update_product', 10, $restoreProduct);
        do_action('starfiniti_search_process_outbox', 'integration-cutover-restore');
    }
}

$manager->activate($generation);
if (get_option('starfiniti_search_build_generation', false) !== false) {
    throw new RuntimeException('Successful cutover did not clear the ready dual-write target.');
}
$provider = new LocalSearchProvider($wpdb, $tokenizer);
$result = $provider->search([
    'query' => 'EXACT-001',
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
]);
if (($result['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt') {
    throw new RuntimeException('Activated shadow generation did not serve the expected exact-SKU result.');
}

try {
    $wpdb->update($wpdb->posts, ['post_title' => 'Rollback Window Pending Sample'], ['ID' => 10], ['%s'], ['%d']);
    clean_post_cache(10);
    wc_delete_product_transients(10);
    $rollbackProduct = wc_get_product(10);
    if (!$rollbackProduct instanceof WC_Product) {
        throw new RuntimeException('Rollback-window product fixture is unavailable.');
    }
    do_action('woocommerce_update_product', 10, $rollbackProduct);
    do_action('starfiniti_search_process_outbox', 'integration-rollback-window');
    $rollbackTitles = $wpdb->get_col($wpdb->prepare(
        "SELECT title FROM {$wpdb->prefix}sfs_documents WHERE entity_id=10 AND generation_id IN (%d,%d) ORDER BY generation_id ASC",
        $original,
        $generation
    ));
    if (count($rollbackTitles) !== 2 || array_unique(array_map('strval', $rollbackTitles)) !== ['Rollback Window Pending Sample']) {
        throw new RuntimeException('Active and retained rollback generations did not receive the same catalog write.');
    }
} finally {
    $wpdb->update($wpdb->posts, ['post_title' => $originalTitle], ['ID' => 10], ['%s'], ['%d']);
    clean_post_cache(10);
    wc_delete_product_transients(10);
    $rollbackRestoreProduct = wc_get_product(10);
    if ($rollbackRestoreProduct instanceof WC_Product) {
        do_action('woocommerce_update_product', 10, $rollbackRestoreProduct);
        do_action('starfiniti_search_process_outbox', 'integration-rollback-restore');
    }
}

$restored = $manager->rollback();
if ($restored !== $original || (int) get_option('starfiniti_search_active_generation') !== $original) {
    throw new RuntimeException('Rollback did not restore the original generation.');
}

$manager->activate($generation);
update_option('starfiniti_search_active_generation', $original, false);
try {
    $manager->rollback();
    throw new RuntimeException('Rollback accepted inconsistent active option/database state.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Rollback accepted inconsistent active option/database state.') {
        throw $exception;
    }
} finally {
    update_option('starfiniti_search_active_generation', $generation, false);
}
$activeRows = array_map('intval', $wpdb->get_col("SELECT generation_id FROM {$wpdb->prefix}sfs_index_generations WHERE provider='local' AND state='active'"));
if ($activeRows !== [$generation]) {
    throw new RuntimeException('Failed rollback did not preserve the single-active-generation invariant.');
}
$faceted = $provider->search([
    'query' => '',
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'filters' => ['field' => 'inventory.stock_status', 'op' => 'eq', 'value' => 'instock'],
    'facets' => ['inventory.stock_status', 'classification.category_paths'],
    'sort' => [['field' => 'pricing.active_min_minor', 'direction' => 'asc']],
    'page' => ['number' => 1, 'size' => 10],
]);
$stockFacet = $faceted['facets']->{'inventory.stock_status'} ?? [];
if (($faceted['total'] ?? 0) !== 3 || ($stockFacet[0]['count'] ?? 0) !== 3) {
    throw new RuntimeException('Filtered discovery or facet counts are incorrect.');
}
if ((int) get_option('starfiniti_search_active_index_schema') !== 3) {
    throw new RuntimeException('Index schema capability did not follow final activation.');
}
if ((int) get_option('starfiniti_search_active_analyzer_revision') !== 1 || (string) get_option('starfiniti_search_active_ranking_profile') !== 'local-default-v1') {
    throw new RuntimeException('Analyzer or ranking identity did not follow final activation.');
}

$phrase = $provider->search([
    'query' => '"Blue Alpine"',
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
    'options' => ['highlight' => true, 'suggestion_mode' => 'full_results'],
]);
if (($phrase['total'] ?? 0) !== 1 || ($phrase['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt') {
    throw new RuntimeException('Quoted phrase matching failed.');
}
if (($phrase['hits'][0]['highlights']['identity.title'][0]['text'] ?? '') === '' || !in_array('identity.title', $phrase['hits'][0]['matched_fields'] ?? [], true)) {
    throw new RuntimeException('Safe highlights or matched-field evidence is missing.');
}

$typo = $provider->search([
    'query' => 'Alpnie',
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
    'options' => ['highlight' => false, 'suggestion_mode' => 'full_results'],
]);
if (($typo['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt' || !in_array('fuzzy_expansions_applied:1', $typo['warnings'] ?? [], true)) {
    throw new RuntimeException('Bounded transposition typo recovery failed.');
}
$explained = $provider->search([
    'query' => 'Alpnie',
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
    'options' => ['highlight' => false, 'include_explanation' => true, 'suggestion_mode' => 'admin_test'],
]);
$explanation = $explained['hits'][0]['explanation'] ?? null;
if (!is_array($explanation) || ($explanation['fuzzy_expansions']['alpine'] ?? null) !== 1 || ($explanation['ranking_profile']['version'] ?? '') !== 'local-default-v1') {
    throw new RuntimeException('Administrator explanation omitted the bounded expansion or ranking profile.');
}

$numericTypo = $provider->search([
    'query' => 'EXACT-002',
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
]);
foreach ($numericTypo['warnings'] ?? [] as $warning) {
    if (str_starts_with((string) $warning, 'fuzzy_expansions_applied:')) {
        throw new RuntimeException('Numeric identifier incorrectly enabled general fuzzy expansion.');
    }
}

$positionBlob = $wpdb->get_var($wpdb->prepare(
    "SELECT p.positions_blob FROM {$wpdb->prefix}sfs_postings p INNER JOIN {$wpdb->prefix}sfs_terms t ON t.generation_id=p.generation_id AND t.term_id=p.term_id INNER JOIN {$wpdb->prefix}sfs_documents d ON d.generation_id=p.generation_id AND d.document_id=p.document_id WHERE p.generation_id=%d AND d.entity_id=10 AND p.field_code=1 AND t.term='alpine' LIMIT 1",
    $generation
));
if (!is_string($positionBlob) || PositionCodec::decode($positionBlob) !== [1]) {
    throw new RuntimeException('Title position encoding did not persist correctly.');
}
$ngramCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_term_ngrams WHERE generation_id=%d", $generation));
if ($ngramCount < 10) {
    throw new RuntimeException('Fuzzy vocabulary n-gram index was not built.');
}

$hiddenTypo = $provider->search([
    'query' => 'Wholeslae',
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
]);
if (($hiddenTypo['total'] ?? 0) !== 0) {
    throw new RuntimeException('Fuzzy recovery leaked a restricted product or facet existence signal.');
}

echo "Generation lifecycle passed: ready/previous dual writes, synchronized atomic cutover/rollback, single-active invariant, schema v3 search, facets, phrase, highlights, and bounded typo recovery.\n";
