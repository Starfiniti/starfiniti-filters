<?php

use Starfiniti\Search\Infrastructure\WordPress\Storefront\IntegrationRegistry;
use Starfiniti\Search\Infrastructure\WordPress\Storefront\SearchWidget;

$search = do_shortcode('[starfiniti_search]');
foreach (['<form', 'role="search"', 'name="s"', 'name="post_type" value="product"', 'role="combobox"', 'role="listbox"', 'aria-live="polite"', 'data-sfs-close', 'Close search'] as $required) {
    if (!str_contains($search, $required)) {
        throw new RuntimeException('Search fallback/accessibility markup is missing: ' . $required);
    }
}

$first = do_shortcode('[starfiniti_discovery]');
$second = do_shortcode('[starfiniti_discovery]');
foreach (['data-starfiniti-discovery', 'name="sfs_stock"', 'data-sfs-category-options', 'name="sfs_sort"', 'data-sfs-results', 'data-sfs-previous', 'data-sfs-next', '<noscript>'] as $required) {
    if (!str_contains($first, $required)) {
        throw new RuntimeException('Discovery contract markup is missing: ' . $required);
    }
}
preg_match('/id="(starfiniti-discovery-[^"]+)-title"/', $first, $firstId);
preg_match('/id="(starfiniti-discovery-[^"]+)-title"/', $second, $secondId);
if (empty($firstId[1]) || empty($secondId[1]) || $firstId[1] === $secondId[1]) {
    throw new RuntimeException('Multiple discovery instances do not have unique IDs.');
}
if (!WP_Block_Type_Registry::get_instance()->is_registered('starfiniti/search') || !WP_Block_Type_Registry::get_instance()->is_registered('starfiniti/discovery') || !WP_Block_Type_Registry::get_instance()->is_registered('starfiniti/navigation-search')) {
    throw new RuntimeException('Search, discovery, or navigation dynamic block was not registered.');
}
$navigationBlock = WP_Block_Type_Registry::get_instance()->get_registered('starfiniti/navigation-search');
if ($navigationBlock === null || $navigationBlock->parent !== ['core/navigation']) {
    throw new RuntimeException('Navigation search block is not constrained to the WordPress Navigation parent.');
}

foreach (['starfiniti_search_render', 'starfiniti_search', 'starfiniti_discovery_render', 'starfiniti_discovery'] as $function) {
    if (!function_exists($function)) {
        throw new RuntimeException('Public storefront PHP API is missing: ' . $function);
    }
}
$apiSearch = starfiniti_search_render(['label' => 'Catalog search', 'placeholder' => '<script>alert(1)</script> Products']);
if (!str_contains($apiSearch, 'Catalog search') || str_contains($apiSearch, '<script>') || !str_contains($apiSearch, 'data-starfiniti-search')) {
    throw new RuntimeException('Public renderer did not sanitize attributes or share the search component.');
}
ob_start();
starfiniti_discovery(['title' => 'Template discovery']);
$echoedDiscovery = (string) ob_get_clean();
if (!str_contains($echoedDiscovery, 'Template discovery') || !str_contains($echoedDiscovery, 'data-starfiniti-discovery')) {
    throw new RuntimeException('Template echo API did not use the shared discovery component.');
}
if (!shortcode_exists('starfiniti_product_search') || !str_contains(do_shortcode('[starfiniti_product_search]'), 'data-starfiniti-search')) {
    throw new RuntimeException('Deprecated compatibility shortcode does not share the renderer.');
}
global $wp_widget_factory;
if (!isset($wp_widget_factory->widgets[SearchWidget::class])) {
    throw new RuntimeException('Classic Starfiniti search widget was not registered.');
}
$registry = new IntegrationRegistry();
$entries = $registry->entries();
if (count($entries) < 4 || $registry->detected() === []) {
    throw new RuntimeException('Versioned storefront integration registry is incomplete or did not detect the active theme.');
}
$inlineConfiguration = wp_scripts()->get_data('starfiniti-search', 'before');
$configurationLines = is_array($inlineConfiguration) ? array_values(array_filter($inlineConfiguration, static fn (mixed $line): bool => is_string($line) && str_contains($line, 'StarfinitiSearchConfig'))) : [];
if (count($configurationLines) !== 1 || substr_count($configurationLines[0], 'StarfinitiSearchConfig') !== 1) {
    throw new RuntimeException('Multiple render surfaces duplicated the shared runtime configuration.');
}

echo "Storefront contract passed: shared shortcode/block/widget/PHP/headless renderer, no-JS fallback, ARIA, unique instances, and integration registry.\n";
