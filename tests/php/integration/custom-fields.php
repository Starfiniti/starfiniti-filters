<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;

global $wpdb;
$productId = 10;
$repository = new ConfigurationRepository($wpdb);
$original = $repository->initialize(determine_locale());
$keys = ['_sfs_test_string', '_sfs_test_integer', '_sfs_test_number', '_sfs_test_boolean', '_sfs_test_invalid'];
$previous = [];
foreach ($keys as $key) {
    $previous[$key] = [
        'exists' => metadata_exists('post', $productId, $key),
        'value' => get_post_meta($productId, $key, true),
    ];
}

try {
    update_post_meta($productId, '_sfs_test_string', 'Supplier <b>A</b>');
    update_post_meta($productId, '_sfs_test_integer', '7');
    update_post_meta($productId, '_sfs_test_number', '1.25');
    update_post_meta($productId, '_sfs_test_boolean', 'yes');
    update_post_meta($productId, '_sfs_test_invalid', '7x');

    $draft = $original->toArray();
    $draft['catalog']['custom_fields'] = [
        ['field' => 'supplier_name', 'meta_key' => '_sfs_test_string', 'type' => 'string'],
        ['field' => 'lead_days', 'meta_key' => '_sfs_test_integer', 'type' => 'integer'],
        ['field' => 'weight_score', 'meta_key' => '_sfs_test_number', 'type' => 'number'],
        ['field' => 'hazardous', 'meta_key' => '_sfs_test_boolean', 'type' => 'boolean'],
        ['field' => 'invalid_integer', 'meta_key' => '_sfs_test_invalid', 'type' => 'integer'],
    ];
    $repository->createRevision($draft, 'integration typed custom field projection', 0);
    $document = (new WooProductSource(new ProductDocumentFactory(), $repository))->get($productId)?->toArray();
    if (!is_array($document)) {
        throw new RuntimeException('Custom-field fixture product was not projected.');
    }
    $custom = $document['custom'] ?? null;
    if (!is_array($custom)
        || ($custom['supplier_name'] ?? null) !== 'Supplier A'
        || ($custom['lead_days'] ?? null) !== 7
        || ($custom['weight_score'] ?? null) !== 1.25
        || ($custom['hazardous'] ?? null) !== true
        || array_key_exists('invalid_integer', $custom)) {
        throw new RuntimeException('Custom-field allow-list projection did not preserve declared scalar types or omit invalid input: ' . wp_json_encode($custom));
    }
} finally {
    if (!hash_equals($repository->current()?->semanticChecksum() ?? '', $original->semanticChecksum())) {
        $repository->createRevision($original->toArray(), 'integration typed custom field restore', 0);
    }
    foreach ($previous as $key => $state) {
        if ($state['exists']) {
            update_post_meta($productId, $key, $state['value']);
        } else {
            delete_post_meta($productId, $key);
        }
    }
}

echo "Typed custom fields passed: allow-listed scalar coercion, HTML removal, invalid-value omission, and exact restoration.\n";
