<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\CatalogSeeder;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxWorker;
use Starfiniti\Search\Infrastructure\WordPress\Reconciliation\CatalogReconciler;

global $wpdb;

$group = 'starfiniti-search';
$hooks = [
    'starfiniti_search_seed_catalog',
    'starfiniti_search_reconcile_catalog',
    'starfiniti_search_reconcile_stale',
    'starfiniti_search_process_outbox',
];
$scheduledIds = static function (string $hook) use ($group): array {
    return array_map('intval', as_get_scheduled_actions([
        'hook' => $hook,
        'group' => $group,
        'per_page' => 1000,
        'orderby' => 'none',
    ], 'ids'));
};
$beforeActions = [];
foreach ($hooks as $hook) {
    $beforeActions[$hook] = $scheduledIds($hook);
}

$missing = new stdClass();
$seedOption = get_option('starfiniti_search_initial_seed_complete', $missing);
$reconciliationOption = get_option('starfiniti_search_reconciliation_status', $missing);
$staleReconciliationOption = get_option('starfiniti_search_stale_reconciliation_status', $missing);
$outboxTable = $wpdb->prefix . 'sfs_sync_outbox';
$outboxBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$outboxTable}");
if ($outboxBefore !== 0) {
    throw new RuntimeException('Continuation scheduling test requires an initially drained outbox.');
}

$createdIds = [];
$syntheticDocumentIds = [];
$activeGeneration = (int) get_option('starfiniti_search_active_generation');
$writeGenerations = array_values(array_unique(array_filter([
    $activeGeneration,
    (int) get_option('starfiniti_search_build_generation'),
    (int) get_option('starfiniti_search_previous_generation'),
], static fn (int $generation): bool => $generation > 0)));
$outbox = new OutboxRepository($wpdb);
$source = new WooProductSource(new ProductDocumentFactory());
$indexer = new LocalIndexer($wpdb, new Tokenizer());
$seeder = new CatalogSeeder($outbox);
$reconciler = new CatalogReconciler($wpdb, $source, $outbox);
$worker = new OutboxWorker($outbox, $source, $indexer);

try {
    for ($number = 1; $number <= 101; ++$number) {
        $id = wp_insert_post([
            'post_type' => 'product',
            'post_status' => 'draft',
            'post_title' => 'Continuation Fixture',
            'post_content' => '',
            'post_excerpt' => '',
        ], true);
        if (is_wp_error($id) || (int) $id < 1) {
            throw new RuntimeException('Could not create the continuation scheduling fixture catalog.');
        }
        $createdIds[] = (int) $id;
    }

    $firstBatch = array_map('intval', $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending') ORDER BY ID ASC LIMIT 100"
    ));
    if (count($firstBatch) !== 100) {
        throw new RuntimeException('The continuation scheduling fixture did not produce a full catalog batch.');
    }
    $expectedCursor = max($firstBatch);

    as_schedule_single_action(time() + HOUR_IN_SECONDS, 'starfiniti_search_seed_catalog', [PHP_INT_MAX], $group, false);
    as_schedule_single_action(time() + HOUR_IN_SECONDS, 'starfiniti_search_reconcile_catalog', [PHP_INT_MAX], $group, false);
    as_schedule_single_action(time() + HOUR_IN_SECONDS, 'starfiniti_search_reconcile_stale', [PHP_INT_MAX], $group, false);

    delete_option('starfiniti_search_initial_seed_complete');
    $seeder->run(0);
    if (!as_has_scheduled_action('starfiniti_search_seed_catalog', [$expectedCursor], $group)) {
        throw new RuntimeException('Catalog seeding did not schedule its exact next cursor beside an unrelated action.');
    }

    $reconciler->run(0);
    if (!as_has_scheduled_action('starfiniti_search_reconcile_catalog', [$expectedCursor], $group)) {
        throw new RuntimeException('Catalog reconciliation did not schedule its exact next cursor beside an unrelated action.');
    }

    for ($number = 1; $number <= 101; ++$number) {
        $entityId = 800000000 + $number;
        $documentId = 'continuation-orphan-' . $number;
        $inserted = $wpdb->insert($wpdb->prefix . 'sfs_documents', [
            'generation_id' => $activeGeneration,
            'document_id' => $documentId,
            'entity_type' => 'product',
            'entity_id' => $entityId,
            'locale' => determine_locale(),
            'channel' => 'storefront',
            'searchable' => 0,
            'catalog_visible' => 0,
            'password_protected' => 0,
            'scope_hash' => hash('sha256', 'restricted'),
            'title' => 'Continuation Orphan',
            'title_normalized' => 'continuation orphan',
            'search_text' => 'continuation orphan',
            'checksum' => hash('sha256', $documentId),
            'document_json' => '{}',
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
        if ($inserted !== 1) {
            throw new RuntimeException('Could not create the stale reconciliation continuation fixture.');
        }
        $syntheticDocumentIds[] = $documentId;
    }
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}sfs_index_generations SET document_count=document_count+%d WHERE generation_id=%d",
        count($syntheticDocumentIds),
        $activeGeneration
    ));
    if ($reconciler->runStale(0, $activeGeneration) !== 100 || !as_has_scheduled_action('starfiniti_search_reconcile_stale', [800000100, $activeGeneration], $group)) {
        throw new RuntimeException('Stale reconciliation did not schedule its exact next cursor after 100 records.');
    }
    if ($reconciler->runStale(800000100, $activeGeneration) !== 1) {
        throw new RuntimeException('Stale reconciliation did not enqueue the final continuation batch.');
    }
    $staleStatus = get_option('starfiniti_search_stale_reconciliation_status');
    if (!is_array($staleStatus) || ($staleStatus['complete'] ?? false) !== true || (int) ($staleStatus['cursor'] ?? -1) !== 0) {
        throw new RuntimeException('Stale reconciliation did not record completed pagination status.');
    }

    $pendingBeforeWorker = count($scheduledIds('starfiniti_search_process_outbox'));
    $worker->run();
    $pendingAfterWorker = count($scheduledIds('starfiniti_search_process_outbox'));
    if ($pendingAfterWorker !== $pendingBeforeWorker + 1) {
        throw new RuntimeException('Outbox worker did not schedule exactly one tokenized successor while work remained.');
    }
    if (!$outbox->hasReadyOrPending()) {
        throw new RuntimeException('Outbox continuation fixture was too small to exercise self-draining behavior.');
    }
} finally {
    foreach ($createdIds as $id) {
        foreach ($writeGenerations as $writeGeneration) {
            $indexer->deleteEntity('product', $id, $writeGeneration);
        }
    }
    if ($syntheticDocumentIds !== []) {
        $quotedDocumentIds = implode(',', array_fill(0, count($syntheticDocumentIds), '%s'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d AND document_id IN ({$quotedDocumentIds})",
            $activeGeneration,
            ...$syntheticDocumentIds
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}sfs_index_generations SET document_count=(SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d) WHERE generation_id=%d",
            $activeGeneration,
            $activeGeneration
        ));
    }
    $wpdb->query("DELETE FROM {$outboxTable}");
    foreach ($hooks as $hook) {
        foreach (array_diff($scheduledIds($hook), $beforeActions[$hook]) as $actionId) {
            ActionScheduler::store()->cancel_action((int) $actionId);
        }
    }
    if ($createdIds !== []) {
        $idList = implode(',', array_map('intval', $createdIds));
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$idList})");
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$idList})");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$idList})");
        foreach ($createdIds as $id) {
            clean_post_cache($id);
        }
    }
    if ($seedOption === $missing) {
        delete_option('starfiniti_search_initial_seed_complete');
    } else {
        update_option('starfiniti_search_initial_seed_complete', $seedOption, false);
    }
    if ($reconciliationOption === $missing) {
        delete_option('starfiniti_search_reconciliation_status');
    } else {
        update_option('starfiniti_search_reconciliation_status', $reconciliationOption, false);
    }
    if ($staleReconciliationOption === $missing) {
        delete_option('starfiniti_search_stale_reconciliation_status');
    } else {
        update_option('starfiniti_search_stale_reconciliation_status', $staleReconciliationOption, false);
    }
}

if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$outboxTable}") !== 0) {
    throw new RuntimeException('Continuation scheduling test did not restore the drained outbox invariant.');
}
$actualDocuments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $activeGeneration));
$recordedDocuments = (int) $wpdb->get_var($wpdb->prepare("SELECT document_count FROM {$wpdb->prefix}sfs_index_generations WHERE generation_id=%d", $activeGeneration));
if ($actualDocuments !== $recordedDocuments) {
    throw new RuntimeException('Continuation scheduling test did not restore generation document-count metadata.');
}

echo "Continuation scheduling passed: seed and reconciliation cursors coexist with unrelated actions, and the outbox self-drains.\n";
