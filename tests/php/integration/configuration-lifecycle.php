<?php

use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\AnalyticsRepository;
use Starfiniti\Search\Infrastructure\WordPress\Security\Capabilities;

global $wpdb;
$administrator = get_role('administrator');
if ($administrator === null) {
    throw new RuntimeException('Administrator role is unavailable.');
}
foreach (Capabilities::all() as $capability) {
    if (!$administrator->has_cap($capability)) {
        throw new RuntimeException('Administrator is missing dedicated capability: ' . $capability);
    }
}
$shopManager = get_role('shop_manager');
if ($shopManager !== null) {
    foreach (Capabilities::all() as $capability) {
        if ($shopManager->has_cap($capability)) {
            throw new RuntimeException('Dedicated capability was granted broadly to shop_manager: ' . $capability);
        }
    }
}
$repository = new ConfigurationRepository($wpdb);
$original = $repository->initialize(determine_locale());
$draft = $original->toArray();
$draft['operations']['batch_size'] = ($draft['operations']['batch_size'] ?? 50) === 50 ? 49 : 50;
$changed = $repository->createRevision($draft, 'integration configuration change', 0);
if ($changed->revision() !== $original->revision() + 1 || hash_equals($changed->semanticChecksum(), $original->semanticChecksum())) {
    throw new RuntimeException('Configuration revision was not immutable or monotonic.');
}
$restored = $repository->createRevision($original->toArray(), 'integration configuration restore', 0);
if (!hash_equals($restored->semanticChecksum(), $original->semanticChecksum())) {
    throw new RuntimeException('Configuration restore did not preserve semantic content.');
}

$unsafe = $restored->toArray();
$unsafe['active_read_provider'] = 'typesense';
$unsafe['write_targets'] = ['local', 'typesense'];
try {
    $repository->createRevision($unsafe, 'must remain uncertified', 0);
    throw new RuntimeException('Uncertified external provider activation was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Uncertified external provider activation was accepted.') {
        throw $exception;
    }
}

$analyticsDraft = $restored->toArray();
$analyticsDraft['analytics'] = ['enabled' => true, 'retention_days' => 7];
$analyticsConfiguration = $repository->createRevision($analyticsDraft, 'integration analytics opt in', 0);
$analytics = new AnalyticsRepository($wpdb, $repository);
$analytics->record('potentially-sensitive@example.invalid', [
    'provider' => 'local', 'total' => 0, 'timing' => ['total_ms' => 12.5],
]);
$analytics->record('another-sensitive-value', [
    'provider' => 'local', 'total' => 8, 'timing' => ['total_ms' => 7.5],
]);
$analytics->record('failed-sensitive-value', [
    'provider' => 'local', 'total' => 0, 'timing' => ['total_ms' => 30.0],
], true);
$summary = $analytics->summary(30);
if (count($summary) !== 1 || (int) $summary[0]['searches'] !== 3 || (int) $summary[0]['no_result_searches'] !== 1 || (int) $summary[0]['errors'] !== 1) {
    throw new RuntimeException('Aggregate analytics did not record the opt-in metric.');
}
$analyticsReport = $analytics->report(30);
if (($analyticsReport['totals']['no_result_rate'] ?? null) !== 0.5
    || ($analyticsReport['totals']['error_rate'] ?? null) !== 0.333333
    || ($analyticsReport['totals']['average_latency_ms'] ?? null) !== 16.667
    || ($analyticsReport['totals']['latency_coverage_rate'] ?? null) !== 1.0
    || ($analyticsReport['series'][0]['provider'] ?? '') !== 'local'
    || (int) ($analyticsReport['series'][0]['configuration_revision'] ?? 0) !== $analyticsConfiguration->revision()) {
    throw new RuntimeException('Aggregate analytics report formulas or provider/version context are incorrect: ' . wp_json_encode($analyticsReport['totals'] ?? []));
}
$encodedReport = (string) wp_json_encode($analyticsReport);
foreach (['potentially-sensitive@example.invalid', 'another-sensitive-value', 'failed-sensitive-value'] as $sensitiveValue) {
    if (str_contains($encodedReport, $sensitiveValue)) {
        throw new RuntimeException('Aggregate analytics report leaked query text.');
    }
}
$columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}sfs_analytics_daily", 0);
foreach ($columns as $column) {
    if (preg_match('/query|email|user|ip|identity/i', (string) $column) === 1 && $column !== 'query_length_bucket') {
        throw new RuntimeException('Analytics schema contains a user-linked or raw-query column.');
    }
}
$analyticsTable = $wpdb->prefix . 'sfs_analytics_daily';
foreach ([7 => 'expired', 6 => 'retained'] as $ageDays => $provider) {
    $wpdb->insert($analyticsTable, [
        'metric_date' => gmdate('Y-m-d', time() - ($ageDays * DAY_IN_SECONDS)),
        'provider' => $provider,
        'configuration_revision' => $analyticsConfiguration->revision(),
        'index_generation' => 0,
        'query_length_bucket' => '1-3',
        'result_bucket' => '1',
        'searches' => 1,
        'no_result_searches' => 0,
        'errors' => 0,
        'latency_sum_ms' => 1,
        'latency_max_ms' => 1,
        'latency_observations' => 1,
    ]);
}
$analytics->purgeExpired();
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$analyticsTable} WHERE provider='expired'") !== 0
    || (int) $wpdb->get_var("SELECT COUNT(*) FROM {$analyticsTable} WHERE provider='retained'") !== 1) {
    throw new RuntimeException('Analytics retention did not preserve exactly seven inclusive UTC date buckets.');
}
$analytics->purgeAll();
$final = $repository->createRevision($restored->toArray(), 'integration analytics restore', 0);
if (!hash_equals($final->semanticChecksum(), $original->semanticChecksum()) || $analytics->summary(30) !== []) {
    throw new RuntimeException('Analytics purge or configuration restore failed.');
}

$active = array_values(array_filter($repository->history(), static fn (array $row): bool => $row['state'] === 'active'));
if (count($active) !== 1) {
    throw new RuntimeException('Configuration history does not have exactly one active revision.');
}
echo "Configuration lifecycle passed: immutable change, audit history, external-provider guard, defined aggregate-only analytics, purge, restore.\n";
