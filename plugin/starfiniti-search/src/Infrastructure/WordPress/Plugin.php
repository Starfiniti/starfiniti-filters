<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress;

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\CatalogSeeder;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\Admin\AdminPage;
use Starfiniti\Search\Infrastructure\WordPress\Cli\SearchCommand;
use Starfiniti\Search\Infrastructure\WordPress\Database\SchemaInstaller;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationBuilder;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\Operations\HealthService;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationExecutor;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Operations\StructuredLogger;
use Starfiniti\Search\Infrastructure\WordPress\Operations\SetupReadinessService;
use Starfiniti\Search\Infrastructure\WordPress\Migration\LegacyFiboFiltersReader;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\AnalyticsRepository;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\PrivacyHooks;
use Starfiniti\Search\Infrastructure\WordPress\Security\Capabilities;
use Starfiniti\Search\Infrastructure\WordPress\Reconciliation\CatalogReconciler;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxWorker;
use Starfiniti\Search\Infrastructure\WordPress\Rest\SearchController;
use Starfiniti\Search\Infrastructure\WordPress\Rest\ControlController;
use Starfiniti\Search\Infrastructure\WordPress\Storefront\SearchShortcode;
use Starfiniti\Search\Infrastructure\WordPress\Storefront\IntegrationRegistry;

final class Plugin
{
    public static function activate(): void
    {
        SchemaInstaller::activate();
        Capabilities::install();
    }

    public static function boot(): void
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [self::class, 'missingWooCommerceNotice']);
            return;
        }

        if ((int) get_option('starfiniti_search_schema_version') !== SchemaInstaller::SCHEMA_VERSION) {
            SchemaInstaller::activate();
        }
        if ((int) get_option('starfiniti_search_capability_version') !== Capabilities::VERSION) {
            Capabilities::install();
        }

        global $wpdb;
        $tokenizer = new Tokenizer();
        $outbox = new OutboxRepository($wpdb);
        $configuration = new ConfigurationRepository($wpdb);
        $configuration->initialize(determine_locale());
        $source = new WooProductSource(new ProductDocumentFactory(), $configuration);
        $indexer = new LocalIndexer($wpdb, $tokenizer);
        $logger = new StructuredLogger();
        $worker = new OutboxWorker($outbox, $source, $indexer, $logger, $configuration);
        $seeder = new CatalogSeeder($outbox, $configuration);
        $generations = new GenerationManager($wpdb);
        $builder = new GenerationBuilder($wpdb, $source, $indexer, 120, $configuration);
        $provider = new LocalSearchProvider($wpdb, $tokenizer, $configuration);
        $analytics = new AnalyticsRepository($wpdb, $configuration);
        $integrations = new IntegrationRegistry();
        $setup = new SetupReadinessService($wpdb, $provider, $configuration, $integrations);
        $health = new HealthService($wpdb, $provider, $generations, new LegacyFiboFiltersReader($wpdb), $configuration, $analytics, $integrations, $setup);
        $privacy = new PrivacyHooks($analytics);
        $reconciler = new CatalogReconciler($wpdb, $source, $outbox, $configuration);
        $operations = new OperationRepository($wpdb, $configuration);
        $operationExecutor = new OperationExecutor($operations, $generations, $analytics, $reconciler, $configuration);
        $admin = new AdminPage($health, $generations, $operations, $operationExecutor, $configuration, $provider);

        add_action('before_woocommerce_init', [self::class, 'declareWooCommerceCompatibility']);
        add_action('rest_api_init', [new SearchController($provider, $health, $analytics, $logger), 'register']);
        add_action('rest_api_init', [new ControlController($health, $configuration, $operations, $operationExecutor, $provider), 'register']);
        add_action('init', [new SearchShortcode(), 'register']);
        add_action('starfiniti_search_process_outbox', [$worker, 'run'], 10, 1);
        add_action('starfiniti_search_seed_catalog', [$seeder, 'run'], 10, 1);
        add_action('starfiniti_search_build_generation', [$builder, 'run'], 10, 2);
        add_action('starfiniti_search_reconcile_catalog', [$reconciler, 'run'], 10, 1);
        add_action('starfiniti_search_reconcile_stale', [$reconciler, 'runStale'], 10, 2);
        add_action('admin_menu', [$admin, 'register']);
        add_action('admin_post_starfiniti_search_operation', [$admin, 'handleAction']);
        add_action('init', [$privacy, 'register'], 20);

        foreach (['woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation'] as $hook) {
            add_action($hook, static function (int $id) use ($outbox): void {
                $type = get_post_type($id) === 'product_variation' ? 'variation' : 'product';
                self::enqueueForWriteTargets($outbox, $type, $id);
                self::scheduleWorker();
            }, 100, 1);
        }
        add_action('before_delete_post', static function (int $id, ?\WP_Post $post = null) use ($outbox): void {
            $type = $post?->post_type ?? get_post_type($id);
            if (!in_array($type, ['product', 'product_variation'], true)) {
                return;
            }
            self::enqueueForWriteTargets($outbox, $type === 'product_variation' ? 'variation' : 'product', $id, 'delete', 1);
            self::scheduleWorker();
        }, 10, 2);

        add_action('init', static function () use ($seeder, $outbox, $generations, $reconciler): void {
            $seeder->schedule();
            $reconciler->schedule();
            if ($generations->requiresBuild()) {
                $generations->startBuild();
            }
            $generations->ensureBuildScheduled();
            if ($outbox->hasReadyOrPending()) {
                self::scheduleWorker();
            }
        }, 30);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('starfiniti-search', new SearchCommand($health, $generations, $operations, $operationExecutor));
        }
    }

    public static function declareWooCommerceCompatibility(): void
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', STARFINITI_SEARCH_FILE, true);
        }
    }

    public static function missingWooCommerceNotice(): void
    {
        echo '<div class="notice notice-error"><p>' . esc_html__('Starfiniti Search requires WooCommerce.', 'starfiniti-search') . '</p></div>';
    }

    private static function scheduleWorker(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_enqueue_async_action')) {
            return;
        }
        if (!as_has_scheduled_action('starfiniti_search_process_outbox', [], 'starfiniti-search')) {
            as_enqueue_async_action('starfiniti_search_process_outbox', [], 'starfiniti-search', true);
        }
    }

    private static function enqueueForWriteTargets(OutboxRepository $outbox, string $type, int $id, string $operation = 'upsert', int $priority = 100): void
    {
        $active = (int) get_option('starfiniti_search_active_generation');
        $building = (int) get_option('starfiniti_search_build_generation');
        $previous = (int) get_option('starfiniti_search_previous_generation');
        foreach (array_values(array_unique(array_filter([$active, $building, $previous], static fn (int $generation): bool => $generation > 0))) as $generation) {
            $outbox->enqueue($type, $id, $operation, $priority, '', $generation);
        }
    }
}
