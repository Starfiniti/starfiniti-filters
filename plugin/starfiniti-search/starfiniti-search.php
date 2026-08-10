<?php
/**
 * Plugin Name: Starfiniti Search for WooCommerce
 * Plugin URI: https://github.com/Starfiniti/starfiniti-filters
 * Description: Enterprise search and catalog discovery for WooCommerce.
 * Version: 0.3.0-alpha.1
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 10.7
 * Author: Starfiniti
 * License: GPL-3.0-only
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: starfiniti-search
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('STARFINITI_SEARCH_VERSION', '0.3.0-alpha.1');
define('STARFINITI_SEARCH_FILE', __FILE__);
define('STARFINITI_SEARCH_DIR', __DIR__);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Starfiniti\\Search\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = STARFINITI_SEARCH_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
);

if (!function_exists('starfiniti_search_render')) {
    /** @param array<string,mixed> $attributes */
    function starfiniti_search_render(array $attributes = []): string
    {
        return (new Starfiniti\Search\Infrastructure\WordPress\Storefront\SearchShortcode())->render($attributes);
    }
}

if (!function_exists('starfiniti_search')) {
    /** @param array<string,mixed> $attributes */
    function starfiniti_search(array $attributes = []): void
    {
        echo starfiniti_search_render($attributes); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes values.
    }
}

if (!function_exists('starfiniti_discovery_render')) {
    /** @param array<string,mixed> $attributes */
    function starfiniti_discovery_render(array $attributes = []): string
    {
        return (new Starfiniti\Search\Infrastructure\WordPress\Storefront\SearchShortcode())->renderDiscovery($attributes);
    }
}

if (!function_exists('starfiniti_discovery')) {
    /** @param array<string,mixed> $attributes */
    function starfiniti_discovery(array $attributes = []): void
    {
        echo starfiniti_discovery_render($attributes); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes values.
    }
}

register_activation_hook(__FILE__, [Starfiniti\Search\Infrastructure\WordPress\Plugin::class, 'activate']);

add_action(
    'plugins_loaded',
    static function (): void {
        Starfiniti\Search\Infrastructure\WordPress\Plugin::boot();
    },
    20
);
