<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Reconciliation;

use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;
use Starfiniti\Search\Infrastructure\WordPress\Scheduling\ActionSchedulerQueue;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Operations\AdaptiveBatchSizer;
use wpdb;

final class CatalogReconciler
{
    public function __construct(
        private readonly wpdb $db,
        private readonly WooProductSource $source,
        private readonly OutboxRepository $outbox,
        private readonly ?ConfigurationRepository $configuration = null
    ) {
    }

    public function schedule(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (!as_has_scheduled_action('starfiniti_search_reconcile_catalog', [], 'starfiniti-search')) {
            as_schedule_recurring_action(time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'starfiniti_search_reconcile_catalog', [], 'starfiniti-search', true);
        }
    }

    public function run(int $cursor = 0): void
    {
        $generations = $this->writeGenerations();
        if ($generations === []) {
            return;
        }
        $configured = (int) ($this->configuration?->current()?->toArray()['operations']['batch_size'] ?? 100);
        $batchSize = AdaptiveBatchSizer::fromRuntime($configured);
        $ids = array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT ID FROM {$this->db->posts} WHERE ID > %d AND post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending') ORDER BY ID ASC LIMIT %d",
            max(0, $cursor),
            $batchSize
        )));
        $drift = 0;
        foreach ($ids as $id) {
            try {
                $document = $this->source->get($id);
            } catch (\Throwable) {
                $postType = get_post_type($id);
                foreach ($generations as $generation) {
                    $this->outbox->enqueue($postType === 'product_variation' ? 'variation' : 'product', $id, 'upsert', 50, '', $generation);
                    ++$drift;
                }
                continue;
            }
            if ($document === null) {
                $postType = get_post_type($id);
                foreach ($generations as $generation) {
                    $this->outbox->enqueue($postType === 'product_variation' ? 'variation' : 'product', $id, 'delete', 25, '', $generation);
                    ++$drift;
                }
                continue;
            }
            $data = $document->toArray();
            foreach ($generations as $generation) {
                $checksum = $this->db->get_var($this->db->prepare(
                    "SELECT checksum FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d AND entity_type=%s AND entity_id=%d AND locale=%s LIMIT 1",
                    $generation,
                    $data['entity_type'],
                    $id,
                    $data['locale']
                ));
                if (!is_string($checksum) || !hash_equals($data['checksum'], $checksum)) {
                    $this->outbox->enqueue((string) $data['entity_type'], $id, 'upsert', 50, (string) $data['locale'], $generation);
                    ++$drift;
                }
            }
        }

        $next = $ids === [] ? 0 : max($ids);
        if (count($ids) === $batchSize && function_exists('as_schedule_single_action')) {
            ActionSchedulerQueue::scheduleExactContinuation('starfiniti_search_reconcile_catalog', [$next]);
        } else {
            foreach ($generations as $generation) {
                $drift += $this->runStale(0, $generation);
            }
        }
        update_option('starfiniti_search_reconciliation_status', [
            'last_run_at' => gmdate(DATE_ATOM),
            'cursor' => $next,
            'drift_enqueued' => $drift,
            'generation' => (int) get_option('starfiniti_search_active_generation'),
            'write_generations' => $generations,
        ], false);
        if ($drift > 0 && function_exists('as_enqueue_async_action') && !as_has_scheduled_action('starfiniti_search_process_outbox', [], 'starfiniti-search')) {
            as_enqueue_async_action('starfiniti_search_process_outbox', [], 'starfiniti-search', true);
        }
    }

    public function runStale(int $cursor = 0, int $generation = 0): int
    {
        $generation = $generation > 0 ? $generation : (int) get_option('starfiniti_search_active_generation');
        if (!in_array($generation, $this->writeGenerations(), true)) {
            return 0;
        }
        $stale = $this->db->get_results($this->db->prepare(
            "SELECT d.entity_type,d.entity_id FROM {$this->db->prefix}sfs_documents d LEFT JOIN {$this->db->posts} p ON p.ID=d.entity_id WHERE d.generation_id=%d AND d.entity_id>%d AND (p.ID IS NULL OR p.post_type NOT IN ('product','product_variation') OR p.post_status NOT IN ('publish','private','draft','pending')) ORDER BY d.entity_id ASC LIMIT 100",
            $generation,
            max(0, $cursor)
        ), ARRAY_A);
        $rows = is_array($stale) ? $stale : [];
        foreach ($rows as $row) {
            $this->outbox->enqueue((string) $row['entity_type'], (int) $row['entity_id'], 'delete', 25, '', $generation);
        }

        $next = $rows === [] ? 0 : max(array_map(static fn (array $row): int => (int) $row['entity_id'], $rows));
        if (count($rows) === 100) {
            ActionSchedulerQueue::scheduleExactContinuation('starfiniti_search_reconcile_stale', [$next, $generation]);
        }
        update_option('starfiniti_search_stale_reconciliation_status', [
            'last_run_at' => gmdate(DATE_ATOM),
            'cursor' => count($rows) === 100 ? $next : 0,
            'drift_enqueued' => count($rows),
            'generation' => $generation,
            'complete' => count($rows) < 100,
        ], false);
        if ($rows !== [] && function_exists('as_enqueue_async_action') && !as_has_scheduled_action('starfiniti_search_process_outbox', [], 'starfiniti-search')) {
            as_enqueue_async_action('starfiniti_search_process_outbox', [], 'starfiniti-search', true);
        }
        return count($rows);
    }

    /** @return list<int> */
    private function writeGenerations(): array
    {
        $candidates = array_values(array_unique(array_filter([
            (int) get_option('starfiniti_search_active_generation'),
            (int) get_option('starfiniti_search_build_generation'),
            (int) get_option('starfiniti_search_previous_generation'),
        ], static fn (int $generation): bool => $generation > 0)));
        if ($candidates === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($candidates), '%d'));
        $rows = $this->db->get_col($this->db->prepare(
            "SELECT generation_id FROM {$this->db->prefix}sfs_index_generations WHERE provider='local' AND state IN ('active','building','ready','retired') AND generation_id IN ({$placeholders}) ORDER BY generation_id ASC",
            ...$candidates
        ));
        return array_values(array_map('intval', $rows));
    }
}
