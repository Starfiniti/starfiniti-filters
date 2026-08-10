<?php

use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;

global $wpdb;
$configuration = new ConfigurationRepository($wpdb);
$original = $configuration->current();
if ($original === null) {
    throw new RuntimeException('Configuration is unavailable for analytics isolation test.');
}
$enabled = $original->toArray();
$enabled['analytics'] = ['enabled' => true, 'retention_days' => 7];
$configuration->createRevision($enabled, 'integration analytics failure isolation', 0);

$table = $wpdb->prefix . 'sfs_analytics_daily';
$temporary = $table . '_isolation_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
$renamed = false;
$previousSuppress = $wpdb->suppress_errors(true);
try {
    if ($wpdb->query("RENAME TABLE {$table} TO {$temporary}") === false) {
        throw new RuntimeException('Could not isolate the aggregate table.');
    }
    $renamed = true;
    do_action('rest_api_init');
    wp_set_current_user(0);
    $request = new WP_REST_Request('GET', '/starfiniti-search/v1/search');
    $request->set_param('q', 'EXACT-001');
    $request->set_param('size', 5);
    $response = rest_do_request($request);
    $data = $response->get_data();
    if ($response->get_status() !== 200 || ($data['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt') {
        throw new RuntimeException('Aggregate database failure interrupted public search.');
    }
} finally {
    if ($renamed) {
        $wpdb->query("RENAME TABLE {$temporary} TO {$table}");
    }
    $wpdb->suppress_errors($previousSuppress);
    $configuration->createRevision($original->toArray(), 'integration analytics failure isolation restore', 0);
}

if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table
    || !hash_equals($original->semanticChecksum(), (string) $configuration->current()?->semanticChecksum())) {
    throw new RuntimeException('Analytics isolation test did not restore database and configuration state.');
}

echo "Analytics failure isolation passed: aggregate storage failure did not interrupt search and state was restored.\n";
