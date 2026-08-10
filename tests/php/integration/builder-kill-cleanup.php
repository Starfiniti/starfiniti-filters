<?php

$setupMarker = getenv('STARFINITI_BUILDER_KILL_SETUP');
if (!is_string($setupMarker) || !is_readable($setupMarker)) {
    return;
}
$setup = json_decode((string) file_get_contents($setupMarker), true, 8, JSON_THROW_ON_ERROR);
$generation = (int) ($setup['generation'] ?? 0);
$active = (int) ($setup['active_generation'] ?? 0);
global $wpdb;
if ($generation < 1 || $generation === $active || (int) get_option('starfiniti_search_active_generation') !== $active) {
    throw new RuntimeException('Refusing unsafe builder-kill cleanup target.');
}
if (function_exists('as_unschedule_all_actions')) {
    foreach ([0, 16] as $cursor) {
        as_unschedule_all_actions('starfiniti_search_build_generation', [$generation, $cursor], 'starfiniti-search');
    }
    as_unschedule_all_actions('starfiniti_search_build_generation', [$active, 424242], 'starfiniti-search');
}
foreach (['sfs_postings', 'sfs_term_ngrams', 'sfs_document_terms', 'sfs_facet_values', 'sfs_documents', 'sfs_terms', 'sfs_sync_outbox'] as $suffix) {
    $wpdb->delete($wpdb->prefix . $suffix, $suffix === 'sfs_sync_outbox' ? ['target_generation' => $generation] : ['generation_id' => $generation], ['%d']);
}
$wpdb->delete($wpdb->prefix . 'sfs_index_generations', ['generation_id' => $generation], ['%d']);
if ((int) get_option('starfiniti_search_build_generation') === $generation) {
    delete_option('starfiniti_search_build_generation');
}
