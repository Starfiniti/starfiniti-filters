<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Catalog;

use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;
use Starfiniti\Search\Infrastructure\WordPress\Scheduling\ActionSchedulerQueue;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Operations\AdaptiveBatchSizer;

final class CatalogSeeder
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly ?ConfigurationRepository $configuration = null
    )
    {
    }

    public function schedule(): void
    {
        if (get_option('starfiniti_search_initial_seed_complete') || !function_exists('as_has_scheduled_action')) {
            return;
        }
        if (!as_has_scheduled_action('starfiniti_search_seed_catalog', [], 'starfiniti-search')) {
            as_schedule_single_action(time() + 1, 'starfiniti_search_seed_catalog', [0], 'starfiniti-search', true);
        }
    }

    public function run(int $cursor = 0): void
    {
        global $wpdb;
        $configured = (int) ($this->configuration?->current()?->toArray()['operations']['batch_size'] ?? 100);
        $batchSize = AdaptiveBatchSizer::fromRuntime($configured);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor pagination needs an authoritative catalog snapshot; caching can omit newly eligible products.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE ID > %d
                   AND post_type IN ('product','product_variation')
                   AND post_status IN ('publish','private','draft','pending')
                 ORDER BY ID ASC LIMIT %d",
                max(0, $cursor),
                $batchSize
            )
        );
        $ids = array_values(array_map('intval', $ids));

        foreach ($ids as $id) {
            $postType = get_post_type($id);
            $this->outbox->enqueue($postType === 'product_variation' ? 'variation' : 'product', $id);
        }

        if ($ids !== [] && count($ids) === $batchSize) {
            ActionSchedulerQueue::scheduleExactContinuation('starfiniti_search_seed_catalog', [max($ids)]);
        } else {
            update_option('starfiniti_search_initial_seed_complete', gmdate(DATE_ATOM), false);
        }

        if (function_exists('as_enqueue_async_action') && !as_has_scheduled_action('starfiniti_search_process_outbox', [], 'starfiniti-search')) {
            as_enqueue_async_action('starfiniti_search_process_outbox', [], 'starfiniti-search', true);
        }
    }
}
