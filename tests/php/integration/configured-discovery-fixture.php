<?php

// Called only by the disposable localhost browser test, never by plugin runtime.
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;

if (!defined('WP_CLI') || !WP_CLI || home_url('/') !== 'http://127.0.0.1:8088/') {
    throw new RuntimeException('Configured discovery fixture is restricted to the localhost lab.');
}
global $wpdb;
$repository = new ConfigurationRepository($wpdb);
$snapshotPath = dirname(__DIR__, 3) . '/.cache/configured-discovery-fixture.json';
$mode = $args[0] ?? '';
if ($mode === 'restore') {
    if (!is_file($snapshotPath)) {
        echo "No configured discovery fixture to restore.\n";
        return;
    }
    $saved = json_decode(file_get_contents($snapshotPath), true, 64, JSON_THROW_ON_ERROR);
    foreach ($saved['products'] as $id) {
        $product = wc_get_product($id);
        if ($product && $product->get_meta('_sfs_discovery_test') !== '20260907') {
            throw new RuntimeException('Refusing to remove a product not owned by this test.');
        }
        if ($product) {
            $product->delete(true);
        }
    }
    $current = $repository->current();
    $original = \Starfiniti\Search\Domain\Configuration\DesiredConfiguration::fromArray($saved['configuration']);
    if ($current->semanticChecksum() !== $original->semanticChecksum()) {
        $repository->createRevision($saved['configuration'], 'restore after configured discovery browser test', 0);
    }
    do_action('starfiniti_search_process_outbox');
    unlink($snapshotPath);
    echo "Configured discovery fixture removed and original configuration restored.\n";
    return;
}
if ($mode !== 'setup' || is_file($snapshotPath)) {
    throw new RuntimeException('Run restore before starting a new configured discovery fixture.');
}
$saved = ['configuration' => $repository->current()->toArray(), 'products' => []];
file_put_contents($snapshotPath, wp_json_encode($saved));
$draft = $saved['configuration'];
$draft['discovery'] = ['filters' => [
    ['id' => 'color', 'field' => 'attributes.color', 'label' => 'Colour', 'type' => 'checkboxes', 'parameter' => 'color'],
    ['id' => 'price', 'field' => 'pricing.active_min_minor', 'label' => 'Price', 'type' => 'range', 'parameter' => 'price'],
    ['id' => 'stock', 'field' => 'inventory.stock_status', 'label' => 'Stock choice', 'type' => 'radio', 'parameter' => 'stock-status'],
    ['id' => 'featured', 'field' => 'quality.featured', 'label' => 'Featured', 'type' => 'select', 'parameter' => 'featured'],
]];
$repository->createRevision($draft, 'local configured discovery browser test', 0);
foreach ([['Red', '10.01', 'visible'], ['Blue', '20.02', 'visible'], ['Secret', '10.01', 'hidden']] as [$color, $price, $visibility]) {
    $product = new WC_Product_Simple();
    $product->set_name('SFS Browser Fixture ' . $color);
    $product->set_status('publish');
    $product->set_catalog_visibility($visibility);
    $product->set_regular_price($price);
    $product->update_meta_data('_sfs_discovery_test', '20260907');
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Color');
    $attribute->set_options([$color]);
    $attribute->set_visible(true);
    $product->set_attributes([$attribute]);
    $saved['products'][] = $product->save();
    file_put_contents($snapshotPath, wp_json_encode($saved));
}
do_action('starfiniti_search_process_outbox');
echo "Configured discovery fixture ready.\n";
