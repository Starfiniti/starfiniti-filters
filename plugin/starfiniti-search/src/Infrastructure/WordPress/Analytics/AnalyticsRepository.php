<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Analytics;

use Starfiniti\Search\Domain\Analytics\AggregateReport;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use wpdb;

final class AnalyticsRepository
{
    public function __construct(
        private readonly wpdb $db,
        private readonly ConfigurationRepository $configuration
    ) {
    }

    /** @param array<string,mixed> $result */
    public function record(string $query, array $result, bool $error = false): void
    {
        $current = $this->configuration->current();
        $analytics = $current?->toArray()['analytics'] ?? ['enabled' => false];
        if (($analytics['enabled'] ?? false) !== true) {
            return;
        }
        $total = max(0, (int) ($result['total'] ?? 0));
        $rawLatency = $result['timing']['total_ms'] ?? null;
        $hasLatency = is_int($rawLatency) || is_float($rawLatency) || (is_string($rawLatency) && is_numeric($rawLatency));
        $latency = $hasLatency ? max(0.0, min(60000.0, (float) $rawLatency)) : 0.0;
        $values = [
            gmdate('Y-m-d'),
            substr(sanitize_key((string) ($result['provider'] ?? 'unknown')), 0, 32),
            $current->revision(),
            max(0, (int) get_option('starfiniti_search_active_generation')),
            $this->queryLengthBucket(mb_strlen($query)),
            $this->resultBucket($total),
            1,
            !$error && $total === 0 ? 1 : 0,
            $error ? 1 : 0,
            $latency,
            $latency,
            $hasLatency ? 1 : 0,
        ];
        $table = $this->db->prefix . 'sfs_analytics_daily';
        $sql = $this->db->prepare(
            "INSERT INTO {$table} (metric_date,provider,configuration_revision,index_generation,query_length_bucket,result_bucket,searches,no_result_searches,errors,latency_sum_ms,latency_max_ms,latency_observations)
             VALUES (%s,%s,%d,%d,%s,%s,%d,%d,%d,%f,%f,%d)
             ON DUPLICATE KEY UPDATE searches=searches+VALUES(searches),no_result_searches=no_result_searches+VALUES(no_result_searches),errors=errors+VALUES(errors),latency_sum_ms=latency_sum_ms+VALUES(latency_sum_ms),latency_max_ms=GREATEST(latency_max_ms,VALUES(latency_max_ms)),latency_observations=latency_observations+VALUES(latency_observations)",
            ...$values
        );
        $this->db->query($sql);
    }

    public function purgeExpired(): int
    {
        $current = $this->configuration->current();
        $days = max(0, min(365, (int) ($current?->toArray()['analytics']['retention_days'] ?? 0)));
        if ($days === 0) {
            return $this->purgeAll();
        }
        $cutoff = gmdate('Y-m-d', time() - (($days - 1) * DAY_IN_SECONDS));
        return (int) $this->db->query($this->db->prepare("DELETE FROM {$this->db->prefix}sfs_analytics_daily WHERE metric_date < %s", $cutoff));
    }

    public function purgeAll(): int
    {
        return (int) $this->db->query("DELETE FROM {$this->db->prefix}sfs_analytics_daily");
    }

    /** @return list<array<string,mixed>> */
    public function summary(int $days = 30): array
    {
        return $this->report($days)['series'];
    }

    /** @return array<string,mixed> */
    public function report(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $windowEnd = gmdate('Y-m-d');
        $windowStart = gmdate('Y-m-d', time() - (($days - 1) * DAY_IN_SECONDS));
        $table = $this->db->prefix . 'sfs_analytics_daily';
        $series = $this->db->get_results($this->db->prepare("SELECT metric_date,provider,configuration_revision,index_generation,SUM(searches) searches,SUM(no_result_searches) no_result_searches,SUM(errors) errors,SUM(latency_sum_ms) latency_sum_ms,MAX(latency_max_ms) maximum_latency_ms,SUM(latency_observations) latency_observations FROM {$table} WHERE metric_date >= %s GROUP BY metric_date,provider,configuration_revision,index_generation ORDER BY metric_date DESC,provider ASC,configuration_revision DESC,index_generation DESC", $windowStart), ARRAY_A);
        $breakdowns = $this->db->get_results($this->db->prepare("SELECT query_length_bucket,result_bucket,SUM(searches) searches FROM {$table} WHERE metric_date >= %s GROUP BY query_length_bucket,result_bucket", $windowStart), ARRAY_A);
        $policy = $this->configuration->current()?->toArray()['analytics'] ?? ['enabled' => false, 'retention_days' => 0];
        return AggregateReport::build(
            is_array($series) ? $series : [],
            is_array($breakdowns) ? $breakdowns : [],
            ['enabled' => ($policy['enabled'] ?? false) === true, 'retention_days' => (int) ($policy['retention_days'] ?? 0)],
            $days,
            $windowStart,
            $windowEnd
        );
    }

    private function queryLengthBucket(int $length): string
    {
        return match (true) { $length === 0 => '0', $length <= 3 => '1-3', $length <= 10 => '4-10', $length <= 32 => '11-32', $length <= 128 => '33-128', default => '129-512' };
    }

    private function resultBucket(int $total): string
    {
        return match (true) { $total === 0 => '0', $total === 1 => '1', $total <= 10 => '2-10', $total <= 100 => '11-100', default => '101+' };
    }
}
