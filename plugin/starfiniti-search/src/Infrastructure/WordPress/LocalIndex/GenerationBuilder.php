<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\LocalIndex;

use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Operations\AdaptiveBatchSizer;
use wpdb;

final class GenerationBuilder
{
    public function __construct(
        private readonly wpdb $db,
        private readonly WooProductSource $source,
        private readonly LocalIndexer $indexer,
        private readonly int $leaseSeconds = 120,
        private readonly ?ConfigurationRepository $configuration = null
    ) {
    }

    public function run(int $generation, int $cursor = 0): void
    {
        $table = $this->db->prefix . 'sfs_index_generations';
        $token = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');
        $leasedUntil = gmdate('Y-m-d H:i:s.u', time() + max(2, min(900, $this->leaseSeconds)));
        $claimed = $this->db->query($this->db->prepare(
            "UPDATE {$table} SET build_lease_token=%s,build_leased_until=%s,build_attempts=build_attempts+1,build_updated_at=%s WHERE generation_id=%d AND provider='local' AND state='building' AND (build_lease_token IS NULL OR build_leased_until IS NULL OR build_leased_until<=%s)",
            $token,
            $leasedUntil,
            $now,
            $generation,
            $now
        ));
        if ($claimed !== 1) {
            return;
        }
        $storedCursor = (int) $this->db->get_var($this->db->prepare("SELECT build_cursor FROM {$table} WHERE generation_id=%d AND build_lease_token=%s", $generation, $token));
        $cursor = max(0, $storedCursor);

        $configured = (int) ($this->configuration?->current()?->toArray()['operations']['batch_size'] ?? 50);
        $batchSize = AdaptiveBatchSizer::fromRuntime($configured);
        $ids = array_map('intval', $this->db->get_col(
            $this->db->prepare(
                "SELECT ID FROM {$this->db->posts} WHERE ID > %d AND post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending') ORDER BY ID ASC LIMIT %d",
                max(0, $cursor),
                $batchSize
            )
        ));

        foreach ($ids as $id) {
            $this->heartbeat($table, $generation, $token);
            $document = $this->source->get($id);
            if ($document !== null) {
                $this->indexer->upsert($document, $generation);
                $this->heartbeat($table, $generation, $token);
                do_action('starfiniti_search_generation_document_indexed', $generation, $id, $cursor);
            } else {
                $this->heartbeat($table, $generation, $token);
            }
        }

        $next = $ids === [] ? $cursor : max($ids);
        $count = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d", $generation));

        if (count($ids) === $batchSize) {
            $this->release($table, $generation, $token, $next, $count);
            $this->schedule($generation, $next, 1);
            return;
        }

        $pending = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_sync_outbox WHERE target_generation=%d AND status IN ('pending','processing')", $generation));
        if ($pending > 0) {
            if (function_exists('as_enqueue_async_action') && !as_has_scheduled_action('starfiniti_search_process_outbox', [], 'starfiniti-search')) {
                as_enqueue_async_action('starfiniti_search_process_outbox', [], 'starfiniti-search', true);
            }
            $this->release($table, $generation, $token, $next, $count);
            $this->schedule($generation, $next, 5);
            return;
        }

        $expected = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending')");
        $finalState = $count === $expected ? 'ready' : 'failed';
        $finished = $this->db->query($this->db->prepare(
            "UPDATE {$table} SET state=%s,document_count=%d,build_cursor=%d,build_lease_token=NULL,build_leased_until=NULL,build_updated_at=%s WHERE generation_id=%d AND state='building' AND build_lease_token=%s",
            $finalState,
            $count,
            $next,
            gmdate('Y-m-d H:i:s.u'),
            $generation,
            $token
        ));
        if ($finished !== 1) {
            throw new \RuntimeException('Shadow generation lease ownership was lost before verification.');
        }
        if ($finalState === 'ready') {
            update_option('starfiniti_search_build_generation', $generation, false);
        } else {
            delete_option('starfiniti_search_build_generation');
        }
    }

    private function heartbeat(string $table, int $generation, string $token): void
    {
        $this->db->query($this->db->prepare(
            "UPDATE {$table} SET build_leased_until=%s,build_updated_at=%s WHERE generation_id=%d AND state='building' AND build_lease_token=%s",
            gmdate('Y-m-d H:i:s.u', time() + max(2, min(900, $this->leaseSeconds))),
            gmdate('Y-m-d H:i:s.u'),
            $generation,
            $token
        ));
        $owner = (string) $this->db->get_var($this->db->prepare("SELECT build_lease_token FROM {$table} WHERE generation_id=%d AND state='building'", $generation));
        if (!hash_equals($token, $owner)) {
            throw new \RuntimeException('Shadow generation lease ownership was lost during indexing.');
        }
    }

    private function release(string $table, int $generation, string $token, int $cursor, int $count): void
    {
        $released = $this->db->query($this->db->prepare(
            "UPDATE {$table} SET build_cursor=%d,document_count=%d,build_lease_token=NULL,build_leased_until=NULL,build_updated_at=%s WHERE generation_id=%d AND state='building' AND build_lease_token=%s",
            $cursor,
            $count,
            gmdate('Y-m-d H:i:s.u'),
            $generation,
            $token
        ));
        if ($released !== 1) {
            throw new \RuntimeException('Shadow generation lease ownership was lost before progress persistence.');
        }
    }

    private function schedule(int $generation, int $cursor, int $delaySeconds): void
    {
        $args = [$generation, $cursor];
        if (!as_has_scheduled_action('starfiniti_search_build_generation', $args, 'starfiniti-search')) {
            // Action Scheduler uniqueness is hook/group-wide in its DB store, not argument-scoped.
            // The generation lease makes harmless duplicate races preferable to cross-generation starvation.
            as_schedule_single_action(time() + max(1, $delaySeconds), 'starfiniti_search_build_generation', $args, 'starfiniti-search', false);
        }
    }
}
