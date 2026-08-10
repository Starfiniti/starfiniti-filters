<?php

use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;

$marker = getenv('STARFINITI_BUILDER_KILL_SETUP');
if (!is_string($marker) || $marker === '') {
    throw new RuntimeException('Builder-kill setup marker path is unavailable.');
}
global $wpdb;
$active = (int) get_option('starfiniti_search_active_generation');
$existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_index_generations WHERE provider='local' AND state IN ('building','ready')");
if ($existing !== 0) {
    throw new RuntimeException('Builder-kill test requires no pre-existing building or ready generation.');
}
if (function_exists('as_schedule_single_action')) {
    as_schedule_single_action(time() + HOUR_IN_SECONDS, 'starfiniti_search_build_generation', [$active, 424242], 'starfiniti-search', false);
}
$manager = new GenerationManager($wpdb);
$generation = $manager->startBuild();
if ($generation < 1 || $generation === $active) {
    throw new RuntimeException('Builder-kill setup did not create an isolated shadow generation.');
}
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('starfiniti_search_build_generation', [$generation, 0], 'starfiniti-search');
}
if (!as_has_scheduled_action('starfiniti_search_build_generation', [$active, 424242], 'starfiniti-search')) {
    throw new RuntimeException('Builder-kill test could not establish the unrelated stale-action regression fixture.');
}
file_put_contents($marker, wp_json_encode(['generation' => $generation, 'active_generation' => $active]), LOCK_EX);
echo "Builder-kill setup created isolated generation {$generation}.\n";
