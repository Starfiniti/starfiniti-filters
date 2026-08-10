<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\LocalIndex;

use RuntimeException;
use Starfiniti\Search\Domain\Search\RankingProfile;
use wpdb;

final class GenerationManager
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function startBuild(): int
    {
        $table = $this->db->prefix . 'sfs_index_generations';
        $existing = (int) $this->db->get_var($this->db->prepare("SELECT generation_id FROM {$table} WHERE provider='local' AND schema_version=3 AND analyzer_revision=%d AND ranking_profile=%s AND state IN ('building','ready') ORDER BY generation_id DESC LIMIT 1", RankingProfile::ANALYZER_REVISION, RankingProfile::VERSION));
        if ($existing > 0) {
            update_option('starfiniti_search_build_generation', $existing, false);
            return $existing;
        }

        $this->db->insert(
            $table,
            [
                'provider' => 'local',
                'state' => 'building',
                'schema_version' => 3,
                'schema_hash' => hash('sha256', 'starfiniti-local-schema-v3'),
                'analyzer_revision' => RankingProfile::ANALYZER_REVISION,
                'ranking_profile' => RankingProfile::VERSION,
                'document_count' => 0,
                'build_cursor' => 0,
                'build_attempts' => 0,
                'build_updated_at' => gmdate('Y-m-d H:i:s.u'),
                'created_at' => gmdate('Y-m-d H:i:s.u'),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s']
        );
        $generation = (int) $this->db->insert_id;
        if ($generation < 1) {
            throw new RuntimeException('Could not create a shadow index generation.');
        }
        update_option('starfiniti_search_build_generation', $generation, false);
        $this->scheduleBuild($generation, 0);
        return $generation;
    }

    public function activate(int $generation): void
    {
        $table = $this->db->prefix . 'sfs_index_generations';
        $this->db->query('START TRANSACTION');
        try {
            $activeRows = array_map('intval', $this->db->get_col("SELECT generation_id FROM {$table} WHERE provider='local' AND state='active' FOR UPDATE"));
            $current = (int) get_option('starfiniti_search_active_generation');
            if (count($activeRows) !== 1 || $activeRows[0] !== $current) {
                throw new RuntimeException('Active generation database and option state are inconsistent.');
            }
            $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$table} WHERE generation_id=%d AND provider='local' FOR UPDATE", $generation), ARRAY_A);
            $actual = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d", $generation));
            $expected = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending')");
            $pending = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_sync_outbox WHERE target_generation=%d AND status IN ('pending','processing')", $generation));
            if (!is_array($row) || !in_array($row['state'], ['ready', 'retired'], true) || (int) $row['document_count'] !== $actual || $actual !== $expected || $pending !== 0) {
                throw new RuntimeException('Only a verified, synchronized ready or retired generation can be activated.');
            }

            $retired = $this->db->query($this->db->prepare("UPDATE {$table} SET state='retired' WHERE generation_id=%d AND provider='local' AND state='active'", $current));
            if ($retired !== 1) {
                throw new RuntimeException('Active generation changed before activation.');
            }
            $this->db->query($this->db->prepare("UPDATE {$table} SET state='active',activated_at=%s WHERE generation_id=%d AND state IN ('ready','retired')", gmdate('Y-m-d H:i:s.u'), $generation));
            if ($this->db->rows_affected !== 1) {
                throw new RuntimeException('Generation state changed before activation.');
            }
            update_option('starfiniti_search_active_generation', $generation, false);
            update_option('starfiniti_search_active_index_schema', (int) $row['schema_version'], false);
            update_option('starfiniti_search_active_analyzer_revision', (int) $row['analyzer_revision'], false);
            update_option('starfiniti_search_active_ranking_profile', (string) $row['ranking_profile'], false);
            update_option('starfiniti_search_previous_generation', $current, false);
            delete_option('starfiniti_search_build_generation');
            $this->db->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }
    }

    public function rollback(): int
    {
        $table = $this->db->prefix . 'sfs_index_generations';
        $this->db->query('START TRANSACTION');
        try {
            $activeRows = array_map('intval', $this->db->get_col("SELECT generation_id FROM {$table} WHERE provider='local' AND state='active' FOR UPDATE"));
            $current = (int) get_option('starfiniti_search_active_generation');
            if (count($activeRows) !== 1 || $activeRows[0] !== $current) {
                throw new RuntimeException('Active generation database and option state are inconsistent.');
            }
            $target = (int) get_option('starfiniti_search_previous_generation');
            $targetRow = $target > 0 ? $this->db->get_row($this->db->prepare("SELECT state,schema_version,analyzer_revision,ranking_profile,document_count FROM {$table} WHERE generation_id=%d AND provider='local' FOR UPDATE", $target), ARRAY_A) : null;
            if (!is_array($targetRow) || $targetRow['state'] !== 'retired') {
                throw new RuntimeException('No verified previous generation is available for rollback.');
            }
            $actual = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d", $target));
            $expected = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending')");
            $pending = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_sync_outbox WHERE target_generation=%d AND status IN ('pending','processing')", $target));
            if ((int) $targetRow['document_count'] !== $actual || $actual !== $expected || $pending !== 0) {
                throw new RuntimeException('Previous generation is not fully synchronized for rollback.');
            }

            $retired = $this->db->query($this->db->prepare("UPDATE {$table} SET state='retired' WHERE generation_id=%d AND provider='local' AND state='active'", $current));
            if ($retired !== 1) {
                throw new RuntimeException('Active generation changed before rollback.');
            }
            $activated = $this->db->query($this->db->prepare("UPDATE {$table} SET state='active' WHERE generation_id=%d AND provider='local' AND state='retired'", $target));
            if ($activated !== 1) {
                throw new RuntimeException('Rollback target changed before activation.');
            }
            update_option('starfiniti_search_active_generation', $target, false);
            update_option('starfiniti_search_active_index_schema', (int) $targetRow['schema_version'], false);
            update_option('starfiniti_search_active_analyzer_revision', (int) $targetRow['analyzer_revision'], false);
            update_option('starfiniti_search_active_ranking_profile', (string) $targetRow['ranking_profile'], false);
            update_option('starfiniti_search_previous_generation', $current, false);
            $this->db->query('COMMIT');
            return $target;
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $generations = $this->db->prefix . 'sfs_index_generations';
        $documents = $this->db->prefix . 'sfs_documents';
        $rows = $this->db->get_results("SELECT g.generation_id,g.provider,g.state,g.schema_version,g.schema_hash,g.analyzer_revision,g.ranking_profile,(SELECT COUNT(*) FROM {$documents} d WHERE d.generation_id=g.generation_id) AS document_count,g.build_cursor,g.build_attempts,g.build_leased_until,g.build_updated_at,g.created_at,g.activated_at FROM {$generations} g ORDER BY g.generation_id DESC LIMIT 20", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function ensureBuildScheduled(): ?int
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_single_action')) {
            return null;
        }
        $table = $this->db->prefix . 'sfs_index_generations';
        $generation = max(0, (int) get_option('starfiniti_search_build_generation'));
        $row = $generation > 0 ? $this->db->get_row($this->db->prepare("SELECT generation_id,state,build_cursor,build_leased_until FROM {$table} WHERE generation_id=%d AND provider='local'", $generation), ARRAY_A) : null;
        if (is_array($row) && $row['state'] === 'ready') {
            return (int) $row['generation_id'];
        }
        if (!is_array($row) || $row['state'] !== 'building') {
            $row = $this->db->get_row("SELECT generation_id,state,build_cursor,build_leased_until FROM {$table} WHERE provider='local' AND state='building' ORDER BY generation_id DESC LIMIT 1", ARRAY_A);
            if (!is_array($row)) {
                if ($generation > 0) {
                    delete_option('starfiniti_search_build_generation');
                }
                return null;
            }
            $generation = (int) $row['generation_id'];
            update_option('starfiniti_search_build_generation', $generation, false);
        }
        $leasedUntil = is_string($row['build_leased_until']) ? strtotime($row['build_leased_until'] . ' UTC') : false;
        if ($leasedUntil !== false && $leasedUntil > time()) {
            return $generation;
        }
        $args = [$generation, max(0, (int) $row['build_cursor'])];
        if (!as_has_scheduled_action('starfiniti_search_build_generation', $args, 'starfiniti-search')) {
            // Action Scheduler's DB uniqueness check is hook/group-wide and can be blocked by
            // an unrelated stale generation action. The generation lease owns concurrency.
            as_schedule_single_action(time() + 1, 'starfiniti_search_build_generation', $args, 'starfiniti-search', false);
        }
        return $generation;
    }

    public function requiresBuild(): bool
    {
        $active = (int) get_option('starfiniti_search_active_generation');
        $row = $active > 0 ? $this->db->get_row($this->db->prepare("SELECT schema_version,analyzer_revision,ranking_profile FROM {$this->db->prefix}sfs_index_generations WHERE generation_id=%d", $active), ARRAY_A) : null;
        return !is_array($row) || (int) $row['schema_version'] < 3 || (int) $row['analyzer_revision'] !== RankingProfile::ANALYZER_REVISION || (string) $row['ranking_profile'] !== RankingProfile::VERSION;
    }

    private function scheduleBuild(int $generation, int $cursor): void
    {
        if (!function_exists('as_schedule_single_action')) {
            throw new RuntimeException('Action Scheduler is required for shadow builds.');
        }
        $args = [$generation, $cursor];
        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('starfiniti_search_build_generation', $args, 'starfiniti-search')) {
            as_schedule_single_action(time() + 1, 'starfiniti_search_build_generation', $args, 'starfiniti-search', false);
        }
    }
}
