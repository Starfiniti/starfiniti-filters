<?php

use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\Operations\SetupReadinessService;
use Starfiniti\Search\Infrastructure\WordPress\Storefront\IntegrationRegistry;

global $wpdb;
$configuration = new ConfigurationRepository($wpdb);
$provider = new LocalSearchProvider($wpdb, new Tokenizer(), $configuration);
$readiness = new SetupReadinessService($wpdb, $provider, $configuration, new IntegrationRegistry());
$report = $readiness->report();
$expected = ['environment', 'catalog_analysis', 'catalog_policy', 'provider', 'storage', 'language_variations', 'fields_identifiers', 'price_stock_visibility', 'index_plan', 'build_verification', 'storefront_placement', 'smoke_activation'];
$steps = is_array($report['steps'] ?? null) ? $report['steps'] : [];
$ids = array_column($steps, 'id');
if ($ids !== $expected || count($steps) !== 12) {
    throw new RuntimeException('Setup readiness did not return the complete ordered 12-step contract.');
}
if (($report['status'] ?? '') !== 'ready' || ($report['release_certified'] ?? true) !== false) {
    throw new RuntimeException('Qualified fixture setup readiness is incorrect or overstated release certification.');
}
$byId = array_column($steps, null, 'id');
$catalog = $byId['catalog_analysis']['evidence'] ?? [];
if (($catalog['eligible_entities'] ?? null) !== 7 || ($catalog['public_documents'] ?? null) !== 4 || ($catalog['restricted_documents'] ?? null) !== 3) {
    throw new RuntimeException('Setup catalog analysis did not use canonical fixture evidence.');
}
$build = $byId['build_verification']['evidence'] ?? [];
if (($byId['build_verification']['status'] ?? '') !== 'pass' || ($build['expected_documents'] ?? null) !== 7 || ($build['indexed_documents'] ?? null) !== 7) {
    throw new RuntimeException('Setup build verification did not prove exact source/index counts.');
}
$placement = $byId['storefront_placement']['evidence'] ?? [];
if (($byId['storefront_placement']['status'] ?? '') !== 'pass' || ($placement['published_placements'] ?? 0) < 1 || empty($placement['qualified_integration'])) {
    throw new RuntimeException('Setup storefront placement was not proven from published content and the integration registry.');
}
$smoke = $byId['smoke_activation']['evidence'] ?? [];
if (($byId['smoke_activation']['status'] ?? '') !== 'pass' || empty($smoke['passed']) || ($smoke['provider'] ?? '') !== 'local' || ($smoke['total'] ?? 0) < 1) {
    throw new RuntimeException('Setup exact identifier/title smoke did not pass.');
}
$encoded = wp_json_encode($report);
foreach (['password', 'api_key', 'authorization', 'document_json'] as $forbidden) {
    if (stripos((string) $encoded, $forbidden) !== false) {
        throw new RuntimeException('Setup readiness leaked prohibited diagnostic material.');
    }
}

$active = (int) get_option('starfiniti_search_active_generation');
$originalDocumentCount = (int) $wpdb->get_var($wpdb->prepare("SELECT document_count FROM {$wpdb->prefix}sfs_index_generations WHERE generation_id=%d", $active));
try {
    $wpdb->update($wpdb->prefix . 'sfs_index_generations', ['document_count' => max(0, $originalDocumentCount - 1)], ['generation_id' => $active], ['%d'], ['%d']);
    $failedClosed = $readiness->report();
    $failedSteps = array_column($failedClosed['steps'] ?? [], null, 'id');
    if (($failedClosed['status'] ?? '') !== 'blocked' || ($failedSteps['build_verification']['status'] ?? '') !== 'blocked') {
        throw new RuntimeException('Setup readiness did not fail closed on a source/index count mismatch.');
    }
} finally {
    $wpdb->update($wpdb->prefix . 'sfs_index_generations', ['document_count' => $originalDocumentCount], ['generation_id' => $active], ['%d'], ['%d']);
}

echo "Setup readiness passed: 12 evidence-backed stages, exact counts, qualified placement, smoke query, fail-closed mismatch, and no release overclaim.\n";
