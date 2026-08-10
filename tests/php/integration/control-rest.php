<?php

use Starfiniti\Search\Domain\Search\RelevancePolicy;

global $wpdb;

do_action('rest_api_init');
wp_set_current_user(0);
$anonymous = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/status'));
if ($anonymous->get_status() < 400) {
    throw new RuntimeException('Anonymous control status access was allowed.');
}
$anonymousAnalytics = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/analytics'));
if ($anonymousAnalytics->get_status() < 400) {
    throw new RuntimeException('Anonymous analytics report access was allowed.');
}
$anonymousPreview = new WP_REST_Request('POST', '/starfiniti-search/v1/control/relevance/preview');
$anonymousPreview->set_header('Content-Type', 'application/json');
$anonymousPreview->set_body((string) wp_json_encode(['query' => 'mountaineering', 'ranking' => RelevancePolicy::defaults()]));
if (rest_do_request($anonymousPreview)->get_status() < 400) {
    throw new RuntimeException('Anonymous relevance preview access was allowed.');
}

wp_set_current_user(1);
$status = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/status'));
if ($status->get_status() !== 200 || ($status->get_data()['contract_version'] ?? '') !== '1.0') {
    throw new RuntimeException('Authorized versioned control status failed.');
}
if (array_key_exists('analytics', $status->get_data())) {
    throw new RuntimeException('General health response leaked separately authorized analytics.');
}
$analyticsResponse = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/analytics'));
$analyticsData = $analyticsResponse->get_data();
if ($analyticsResponse->get_status() !== 200
    || ($analyticsData['contract_version'] ?? '') !== '1.0'
    || ($analyticsData['privacy']['contains_raw_queries'] ?? true) !== false
    || !isset($analyticsData['definitions']['error_rate'])) {
    throw new RuntimeException('Protected analytics report contract failed.');
}
$denyAnalytics = static function (array $allCaps): array {
    $allCaps[\Starfiniti\Search\Infrastructure\WordPress\Security\Capabilities::VIEW_ANALYTICS] = false;
    return $allCaps;
};
add_filter('user_has_cap', $denyAnalytics, PHP_INT_MAX, 1);
$healthOnlyStatus = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/status'));
$healthOnlyAnalytics = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/analytics'));
remove_filter('user_has_cap', $denyAnalytics, PHP_INT_MAX);
if ($healthOnlyStatus->get_status() !== 200 || $healthOnlyAnalytics->get_status() < 400) {
    throw new RuntimeException('Health-read authority implied analytics-read authority.');
}
$capabilities = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/capabilities'));
$capabilityData = $capabilities->get_data();
if ($capabilities->get_status() !== 200 || ($capabilityData['raw_sql'] ?? true) !== false || ($capabilityData['shell'] ?? true) !== false) {
    throw new RuntimeException('Control API exposed a prohibited generic capability.');
}

$configurationBefore = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/configuration'))->get_data();
$ranking = RelevancePolicy::defaults();
$ranking['synonyms'] = [[
    'id' => 'preview-mountaineering',
    'type' => 'directional',
    'locale' => determine_locale(),
    'channel' => 'storefront',
    'source' => 'mountaineering',
    'targets' => ['alpine'],
]];
$ranking['curations'] = [[
    'id' => 'preview-pin-coffee',
    'query' => 'mountaineering',
    'locale' => determine_locale(),
    'channel' => 'storefront',
    'priority' => 100,
    'actions' => ['pin' => [12], 'redirect' => '/preview-curation/'],
]];
$previewRequest = new WP_REST_Request('POST', '/starfiniti-search/v1/control/relevance/preview');
$previewRequest->set_header('Content-Type', 'application/json');
$previewRequest->set_body((string) wp_json_encode(['query' => 'mountaineering', 'locale' => determine_locale(), 'channel' => 'storefront', 'ranking' => $ranking]));
$previewResponse = rest_do_request($previewRequest);
$previewData = $previewResponse->get_data();
if ($previewResponse->get_status() !== 200
    || ($previewData['mutation_performed'] ?? true) !== false
    || strlen((string) ($previewData['preview_checksum'] ?? '')) !== 64
    || (($previewData['result']['hits'][0]['projection']['identity']['title'] ?? '') !== 'Črna Kava 500 g')
    || (($previewData['result']['hits'][0]['explanation']['curation_effect']['type'] ?? '') !== 'pin')
    || (($previewData['result']['hits'][1]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt')
    || (($previewData['result']['hits'][1]['explanation']['synonym_expansions']['alpine']['rule_id'] ?? '') !== 'preview-mountaineering')
    || (($previewData['result']['redirect']['url'] ?? '') !== '/preview-curation/')) {
    throw new RuntimeException('Draft relevance preview did not return an explained non-mutating result.');
}
$configurationAfter = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/configuration'))->get_data();
if (!hash_equals((string) ($configurationBefore['checksum'] ?? ''), (string) ($configurationAfter['checksum'] ?? ''))) {
    throw new RuntimeException('Draft relevance preview mutated active configuration.');
}

$cyclic = RelevancePolicy::defaults();
$cyclic['synonyms'] = [
    ['id' => 'preview-a-b', 'type' => 'directional', 'locale' => determine_locale(), 'channel' => 'storefront', 'source' => 'alpha', 'targets' => ['beta']],
    ['id' => 'preview-b-a', 'type' => 'directional', 'locale' => determine_locale(), 'channel' => 'storefront', 'source' => 'beta', 'targets' => ['alpha']],
];
$invalidPreview = new WP_REST_Request('POST', '/starfiniti-search/v1/control/relevance/preview');
$invalidPreview->set_header('Content-Type', 'application/json');
$invalidPreview->set_body((string) wp_json_encode(['query' => 'alpha', 'ranking' => $cyclic]));
if (rest_do_request($invalidPreview)->get_status() !== 400) {
    throw new RuntimeException('Draft relevance preview accepted a synonym cycle.');
}

$request = new WP_REST_Request('POST', '/starfiniti-search/v1/control/operations/plan');
$request->set_header('Content-Type', 'application/json');
$request->set_body((string) wp_json_encode([
    'type' => 'reconciliation.start',
    'idempotency_key' => 'rest-reconciliation-0001',
    'reason' => 'REST integration reconciliation',
    'desired_state' => [],
]));
$planned = rest_do_request($request);
$operation = $planned->get_data();
if ($planned->get_status() !== 201 || ($operation['contract_version'] ?? '') !== '1.0' || !in_array(($operation['status'] ?? ''), ['planned', 'succeeded'], true)) {
    throw new RuntimeException('Control API planning failed: ' . wp_json_encode($operation));
}

$execute = new WP_REST_Request('POST', '/starfiniti-search/v1/control/operations/' . $operation['operation_id'] . '/execute');
$execute->set_header('Content-Type', 'application/json');
$execute->set_body((string) wp_json_encode(['plan_hash' => $operation['plan_hash']]));
$executed = rest_do_request($execute);
if ($executed->get_status() !== 200 || ($executed->get_data()['contract_version'] ?? '') !== '1.0' || ($executed->get_data()['status'] ?? '') !== 'succeeded') {
    throw new RuntimeException('Control API typed execution failed: ' . wp_json_encode($executed->get_data()));
}

$replay = rest_do_request($execute);
if ($replay->get_status() !== 200 || ($replay->get_data()['operation_id'] ?? '') !== $operation['operation_id']) {
    throw new RuntimeException('Control API execution replay was not idempotent.');
}
$audit = rest_do_request(new WP_REST_Request('GET', '/starfiniti-search/v1/control/audit'));
if ($audit->get_status() !== 200 || count($audit->get_data()['records'] ?? []) < 1) {
    throw new RuntimeException('Protected control audit read failed.');
}

echo "Control REST passed: authorization, versioned contracts, non-mutating relevance preview, prohibited primitives, planning, execution, replay, and audit.\n";
