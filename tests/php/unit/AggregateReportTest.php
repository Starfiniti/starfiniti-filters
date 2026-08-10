<?php

use Starfiniti\Search\Domain\Analytics\AggregateReport;

test('aggregate analytics report uses weighted formulas and explicit context', static function (): void {
    $report = AggregateReport::build([
        [
            'metric_date' => '2026-08-09',
            'provider' => 'local',
            'configuration_revision' => 4,
            'index_generation' => 8,
            'searches' => 10,
            'no_result_searches' => 2,
            'errors' => 1,
            'latency_sum_ms' => 90,
            'maximum_latency_ms' => 20,
            'latency_observations' => 9,
        ],
        [
            'metric_date' => '2026-08-09',
            'provider' => 'typesense',
            'configuration_revision' => 5,
            'index_generation' => 9,
            'searches' => 5,
            'no_result_searches' => 3,
            'errors' => 5,
            'latency_sum_ms' => 500,
            'maximum_latency_ms' => 100,
            'latency_observations' => 0,
        ],
    ], [
        ['query_length_bucket' => '1-3', 'result_bucket' => '0', 'searches' => 2],
        ['query_length_bucket' => '4-10', 'result_bucket' => '2-10', 'searches' => 13],
    ], ['enabled' => true, 'retention_days' => 30], 30, '2026-07-10', '2026-08-09');

    assertSameValue('local', $report['series'][0]['provider'], 'Provider context was lost.');
    assertSameValue(4, $report['series'][0]['configuration_revision'], 'Configuration revision context was lost.');
    assertSameValue(8, $report['series'][0]['index_generation'], 'Index generation context was lost.');
    assertSameValue(15, $report['totals']['searches'], 'Search totals are incorrect.');
    assertSameValue(9, $report['totals']['successful_searches'], 'Successful-search denominator is incorrect.');
    assertSameValue(2, $report['totals']['no_result_searches'], 'No-result count must exclude corrupt/error rows.');
    assertSameValue(0.222222, $report['totals']['no_result_rate'], 'No-result rate must use successful searches.');
    assertSameValue(0.4, $report['totals']['error_rate'], 'Error rate must use all searches.');
    assertSameValue(10.0, $report['totals']['average_latency_ms'], 'Latency mean must be weighted by observations.');
    assertSameValue(20.0, $report['totals']['maximum_latency_ms'], 'Latency maximum is incorrect.');
    assertSameValue(0.6, $report['totals']['latency_coverage_rate'], 'Latency coverage is incorrect.');
    assertSameValue(null, $report['series'][1]['no_result_rate'], 'A zero successful-search denominator must return null.');
    assertSameValue(null, $report['series'][1]['average_latency_ms'], 'A zero observation denominator must return null.');
    assertSameValue('unsampled_aggregate_within_scope', $report['sampling']['mode'], 'Sampling policy is not explicit.');
    assertSameValue(false, $report['privacy']['contains_raw_queries'], 'Privacy contract regressed.');
    assertSameValue(2, $report['breakdowns']['query_length'][0]['searches'], 'Query-length breakdown is incorrect.');
});

test('empty aggregate analytics report has null rates and no fabricated latency', static function (): void {
    $report = AggregateReport::build([], [], ['enabled' => false, 'retention_days' => 0], 999, '2025-08-09', '2026-08-09');
    assertSameValue(365, $report['window']['requested_days'], 'Report window was not bounded.');
    assertSameValue(0, $report['totals']['searches'], 'Empty report fabricated searches.');
    assertSameValue(null, $report['totals']['no_result_rate'], 'Empty no-result rate must be null.');
    assertSameValue(null, $report['totals']['error_rate'], 'Empty error rate must be null.');
    assertSameValue(null, $report['totals']['average_latency_ms'], 'Empty average latency must be null.');
    assertSameValue(null, $report['totals']['maximum_latency_ms'], 'Empty maximum latency must be null.');
    assertSameValue([], $report['series'], 'Empty report fabricated a series.');
});
