<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Analytics;

final class AggregateReport
{
    /**
     * @param list<array<string,mixed>> $seriesRows
     * @param list<array<string,mixed>> $breakdownRows
     * @param array{enabled:bool,retention_days:int} $policy
     * @return array<string,mixed>
     */
    public static function build(array $seriesRows, array $breakdownRows, array $policy, int $days, string $windowStart, string $windowEnd): array
    {
        $series = [];
        $totals = self::emptyMetric();
        foreach ($seriesRows as $row) {
            $metric = self::metric($row);
            $series[] = [
                'metric_date' => (string) ($row['metric_date'] ?? ''),
                'provider' => (string) ($row['provider'] ?? 'unknown'),
                'configuration_revision' => max(0, (int) ($row['configuration_revision'] ?? 0)),
                'index_generation' => max(0, (int) ($row['index_generation'] ?? 0)),
            ] + self::present($metric);
            $totals = self::add($totals, $metric);
        }

        $queryLengths = [];
        $resultCounts = [];
        foreach ($breakdownRows as $row) {
            $searches = max(0, (int) ($row['searches'] ?? 0));
            $queryBucket = (string) ($row['query_length_bucket'] ?? 'unknown');
            $resultBucket = (string) ($row['result_bucket'] ?? 'unknown');
            $queryLengths[$queryBucket] = ($queryLengths[$queryBucket] ?? 0) + $searches;
            $resultCounts[$resultBucket] = ($resultCounts[$resultBucket] ?? 0) + $searches;
        }

        return [
            'contract_version' => '1.0',
            'policy' => [
                'enabled' => $policy['enabled'],
                'retention_days' => max(0, min(365, $policy['retention_days'])),
            ],
            'window' => [
                'requested_days' => max(1, min(365, $days)),
                'start_utc' => $windowStart,
                'end_utc' => $windowEnd,
                'bucket_timezone' => 'UTC',
            ],
            'sampling' => [
                'mode' => 'unsampled_aggregate_within_scope',
                'scope' => 'Public search endpoint requests that reached provider execution while analytics was enabled.',
                'excluded' => ['rate_limited_requests', 'request_validation_rejections', 'administrator_relevance_previews'],
                'known_loss' => 'Aggregate writes are non-critical; database write failures are intentionally not retried and can undercount requests.',
            ],
            'privacy' => [
                'contains_raw_queries' => false,
                'contains_user_identifiers' => false,
                'contains_ip_addresses' => false,
                'contains_product_or_order_identifiers' => false,
                'dimensions' => ['date', 'provider', 'configuration_revision', 'index_generation', 'query_length_bucket', 'result_count_bucket'],
            ],
            'definitions' => self::definitions(),
            'totals' => self::present($totals),
            'series' => $series,
            'breakdowns' => [
                'query_length' => self::orderedBuckets($queryLengths, ['0', '1-3', '4-10', '11-32', '33-128', '129-512']),
                'result_count' => self::orderedBuckets($resultCounts, ['0', '1', '2-10', '11-100', '101+']),
            ],
            'unavailable_metrics' => [
                ['metric' => 'latency_percentiles', 'reason' => 'Daily aggregates retain sums, counts, and maxima but no histogram or request samples.'],
                ['metric' => 'query_level_reports', 'reason' => 'Raw or normalized query text is deliberately not retained.'],
                ['metric' => 'click_cart_conversion_attribution', 'reason' => 'User, session, product, click, cart, and order identifiers are deliberately not retained.'],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function definitions(): array
    {
        return [
            'searches' => ['unit' => 'requests', 'definition' => 'Durably represented provider-executed public search attempts.'],
            'successful_searches' => ['unit' => 'requests', 'definition' => 'Searches minus provider errors.'],
            'no_result_searches' => ['unit' => 'requests', 'definition' => 'Successful searches whose provider returned zero results.'],
            'no_result_rate' => ['unit' => 'ratio', 'definition' => 'No-result searches divided by successful searches.', 'zero_denominator' => null],
            'errors' => ['unit' => 'requests', 'definition' => 'Provider executions that returned the generic search-unavailable response.'],
            'error_rate' => ['unit' => 'ratio', 'definition' => 'Errors divided by searches.', 'zero_denominator' => null],
            'average_latency_ms' => ['unit' => 'milliseconds', 'definition' => 'Arithmetic mean of measured provider execution latency observations.', 'zero_denominator' => null],
            'maximum_latency_ms' => ['unit' => 'milliseconds', 'definition' => 'Largest measured provider execution latency observation.', 'zero_denominator' => null],
            'latency_observations' => ['unit' => 'requests', 'definition' => 'Searches with a finite measured provider execution latency.'],
            'latency_coverage_rate' => ['unit' => 'ratio', 'definition' => 'Latency observations divided by searches.', 'zero_denominator' => null],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,int|float> */
    private static function metric(array $row): array
    {
        $searches = max(0, (int) ($row['searches'] ?? 0));
        $errors = min($searches, max(0, (int) ($row['errors'] ?? 0)));
        $successful = $searches - $errors;
        $noResults = min($successful, max(0, (int) ($row['no_result_searches'] ?? 0)));
        $observations = min($searches, max(0, (int) ($row['latency_observations'] ?? 0)));
        $sum = max(0.0, (float) ($row['latency_sum_ms'] ?? 0.0));
        $maximum = max(0.0, (float) ($row['maximum_latency_ms'] ?? $row['latency_max_ms'] ?? 0.0));
        if ($observations === 0) {
            $sum = 0.0;
            $maximum = 0.0;
        }
        return [
            'searches' => $searches,
            'successful_searches' => $successful,
            'no_result_searches' => $noResults,
            'errors' => $errors,
            'latency_observations' => $observations,
            'latency_sum_ms' => $sum,
            'maximum_latency_ms' => $maximum,
        ];
    }

    /** @param array<string,int|float> $metric @return array<string,int|float|null> */
    private static function present(array $metric): array
    {
        $searches = (int) $metric['searches'];
        $successful = (int) $metric['successful_searches'];
        $observations = (int) $metric['latency_observations'];
        return [
            'searches' => $searches,
            'successful_searches' => $successful,
            'no_result_searches' => (int) $metric['no_result_searches'],
            'no_result_rate' => $successful > 0 ? round(((int) $metric['no_result_searches']) / $successful, 6) : null,
            'errors' => (int) $metric['errors'],
            'error_rate' => $searches > 0 ? round(((int) $metric['errors']) / $searches, 6) : null,
            'average_latency_ms' => $observations > 0 ? round(((float) $metric['latency_sum_ms']) / $observations, 3) : null,
            'maximum_latency_ms' => $observations > 0 ? round((float) $metric['maximum_latency_ms'], 3) : null,
            'latency_observations' => $observations,
            'latency_coverage_rate' => $searches > 0 ? round($observations / $searches, 6) : null,
        ];
    }

    /** @return array<string,int|float> */
    private static function emptyMetric(): array
    {
        return ['searches' => 0, 'successful_searches' => 0, 'no_result_searches' => 0, 'errors' => 0, 'latency_observations' => 0, 'latency_sum_ms' => 0.0, 'maximum_latency_ms' => 0.0];
    }

    /** @param array<string,int|float> $left @param array<string,int|float> $right @return array<string,int|float> */
    private static function add(array $left, array $right): array
    {
        foreach (['searches', 'successful_searches', 'no_result_searches', 'errors', 'latency_observations'] as $key) {
            $left[$key] = (int) $left[$key] + (int) $right[$key];
        }
        $left['latency_sum_ms'] = (float) $left['latency_sum_ms'] + (float) $right['latency_sum_ms'];
        $left['maximum_latency_ms'] = max((float) $left['maximum_latency_ms'], (float) $right['maximum_latency_ms']);
        return $left;
    }

    /** @param array<string,int> $counts @param list<string> $order @return list<array{bucket:string,searches:int}> */
    private static function orderedBuckets(array $counts, array $order): array
    {
        $rows = [];
        foreach ($order as $bucket) {
            if (isset($counts[$bucket])) {
                $rows[] = ['bucket' => $bucket, 'searches' => $counts[$bucket]];
                unset($counts[$bucket]);
            }
        }
        ksort($counts);
        foreach ($counts as $bucket => $searches) {
            $rows[] = ['bucket' => $bucket, 'searches' => $searches];
        }
        return $rows;
    }
}
