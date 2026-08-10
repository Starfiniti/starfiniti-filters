<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Admin\AdminPage;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\AnalyticsRepository;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\Migration\LegacyFiboFiltersReader;
use Starfiniti\Search\Infrastructure\WordPress\Operations\HealthService;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationExecutor;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Operations\SetupReadinessService;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;
use Starfiniti\Search\Infrastructure\WordPress\Reconciliation\CatalogReconciler;
use Starfiniti\Search\Infrastructure\WordPress\Storefront\IntegrationRegistry;

global $wpdb;
$configuration = new ConfigurationRepository($wpdb);
$provider = new LocalSearchProvider($wpdb, new Tokenizer(), $configuration);
$generations = new GenerationManager($wpdb);
$analytics = new AnalyticsRepository($wpdb, $configuration);
$integrations = new IntegrationRegistry();
$setup = new SetupReadinessService($wpdb, $provider, $configuration, $integrations);
$health = new HealthService($wpdb, $provider, $generations, new LegacyFiboFiltersReader($wpdb), $configuration, $analytics, $integrations, $setup);
$operations = new OperationRepository($wpdb, $configuration);
$reconciler = new CatalogReconciler($wpdb, new WooProductSource(new ProductDocumentFactory()), new OutboxRepository($wpdb));
$executor = new OperationExecutor($operations, $generations, $analytics, $reconciler, $configuration);
$admin = new AdminPage($health, $generations, $operations, $executor, $configuration, $provider);

$previousUser = get_current_user_id();
$previousGet = $_GET;
try {
    wp_set_current_user(1);
    $_GET['sfs_preview_query'] = 'EXACT-001';
    ob_start();
    $admin->render();
    $html = (string) ob_get_clean();
} finally {
    $_GET = $previousGet;
    wp_set_current_user($previousUser);
}

foreach (['Setup readiness assessment', 'Smoke test and activation', 'never represents release certification', 'Attempts / lease expiry', 'Last build update', 'sfs_synonyms_json', 'sfs_stop_words_json', 'sfs_curations_json', 'sfs_change_reason', 'Relevance laboratory', 'Blue Alpine Shirt', 'recognized_identifier', 'local-default-v1'] as $required) {
    if (!str_contains($html, $required)) {
        throw new RuntimeException('Administrator relevance surface is missing: ' . $required);
    }
}
foreach (['<script', 'Authorization:', 'api_key', 'password'] as $forbidden) {
    if (stripos($html, $forbidden) !== false) {
        throw new RuntimeException('Administrator relevance surface exposed a prohibited value or primitive: ' . $forbidden);
    }
}
echo "Administrator relevance surface passed: immutable JSON controls, required reason, active-policy preview, safe explanation, and escaped output.\n";
