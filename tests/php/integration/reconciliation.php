<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxWorker;
use Starfiniti\Search\Infrastructure\WordPress\Reconciliation\CatalogReconciler;

global $wpdb;
$id = 10;
$original = (string) $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID=%d", $id));
$originalType = (string) $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $id));
$originalStatus = (string) $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d", $id));
$source = new WooProductSource(new ProductDocumentFactory());
$outbox = new OutboxRepository($wpdb);
$tokenizer = new Tokenizer();
$indexer = new LocalIndexer($wpdb, $tokenizer);
$worker = new OutboxWorker($outbox, $source, $indexer);
$reconciler = new CatalogReconciler($wpdb, $source, $outbox);
$provider = new LocalSearchProvider($wpdb, $tokenizer);
$request = static fn (string $query): array => [
    'query' => $query,
    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
    'page' => ['number' => 1, 'size' => 10],
    'filters' => null, 'facets' => [], 'sort' => [], 'options' => ['suggestion_mode' => 'full_results'],
];

try {
    $wpdb->update($wpdb->posts, ['post_title' => 'Direct Database Drift Sample'], ['ID' => $id], ['%s'], ['%d']);
    clean_post_cache($id);
    wc_delete_product_transients($id);
    $reconciler->run(0);
    $worker->run();
    $drifted = $provider->search($request('Direct Database Drift'));
    if (($drifted['hits'][0]['projection']['identity']['title'] ?? '') !== 'Direct Database Drift Sample') {
        throw new RuntimeException('Reconciliation did not repair a direct database write.');
    }

    $wpdb->update($wpdb->posts, ['post_status' => 'trash'], ['ID' => $id], ['%s'], ['%d']);
    clean_post_cache($id);
    wc_delete_product_transients($id);
    $outbox->enqueue('product', $id, 'upsert');
    $worker->run();
    if (($provider->search($request('EXACT-001'))['total'] ?? 0) !== 0) {
        throw new RuntimeException('An ordinary upsert event did not immediately remove an ineligible product.');
    }
    $wpdb->update($wpdb->posts, ['post_status' => $originalStatus], ['ID' => $id], ['%s'], ['%d']);
    clean_post_cache($id);
    wc_delete_product_transients($id);
    $outbox->enqueue('product', $id, 'upsert');
    $worker->run();

    $wpdb->update($wpdb->posts, ['post_type' => 'post'], ['ID' => $id], ['%s'], ['%d']);
    clean_post_cache($id);
    wc_delete_product_transients($id);
    if ($source->get($id) !== null) {
        throw new RuntimeException('Canonical product source accepted an ineligible post type.');
    }
    $reconciler->run(0);
    $worker->run();
    if (($provider->search($request('EXACT-001'))['total'] ?? 0) !== 0) {
        throw new RuntimeException('Reconciliation did not remove a document whose post type bypassed WooCommerce hooks.');
    }
} finally {
    $wpdb->update($wpdb->posts, ['post_title' => $original, 'post_type' => $originalType, 'post_status' => $originalStatus], ['ID' => $id], ['%s', '%s', '%s'], ['%d']);
    clean_post_cache($id);
    wc_delete_product_transients($id);
    $reconciler->run(0);
    $worker->run();
}

$restored = $provider->search($request('EXACT-001'));
if (($restored['hits'][0]['projection']['identity']['title'] ?? '') !== $original) {
    throw new RuntimeException('Reconciliation fixture restore failed.');
}
$status = get_option('starfiniti_search_reconciliation_status');
if (!is_array($status) || (int) ($status['drift_enqueued'] ?? 0) < 1) {
    throw new RuntimeException('Reconciliation status did not report detected drift.');
}
echo "Reconciliation passed: bypass content and post-type writes detected, stale records removed, status recorded, fixture restored.\n";
