<?php

use Starfiniti\Search\Domain\Search\RelevancePolicy;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;

global $wpdb;
$repository = new ConfigurationRepository($wpdb);
$provider = new LocalSearchProvider($wpdb, new Tokenizer(), $repository);
$original = $repository->current();
$request = [
    'query' => '', 'context' => ['locale' => determine_locale(), 'channel' => 'storefront'],
    'filters' => ['field' => 'classification.category_paths', 'op' => 'in', 'value' => ['Apparel']],
    'facets' => ['classification.category_paths'], 'options' => ['facet_mode' => 'disjunctive'],
    'page' => ['number' => 1, 'size' => 12],
];
$response = $provider->search($request);
$facets = (array) $response['facets'];
if ($response['total'] !== 2 || !in_array('Accessories', array_column($facets['classification.category_paths'], 'value'), true)) {
    throw new RuntimeException('Category alternatives disappeared after selecting Apparel.');
}
$conjunctive = $request;
$conjunctive['options']['facet_mode'] = 'conjunctive';
if (array_column(((array) $provider->search($conjunctive)['facets'])['classification.category_paths'], 'value') !== ['Apparel']) {
    throw new RuntimeException('Legacy conjunctive facet semantics changed.');
}
$draft = $original->toArray();
$draft['ranking'] = RelevancePolicy::defaults();
$coffee = wc_get_product_id_by_sku('KAVA-Č-500');
$hidden = wc_get_product_id_by_sku('HIDDEN-404');
if (!$coffee || !$hidden) {
    throw new RuntimeException('Golden facet fixtures are missing.');
}
$draft['ranking']['curations'] = [[
    'id' => 'facet-preserve-curation', 'query' => 'shirt', 'locale' => determine_locale(), 'channel' => 'storefront', 'priority' => 100,
    'actions' => [
        'pin' => [$coffee, $hidden],
        'filter' => ['field' => 'inventory.stock_status', 'op' => 'eq', 'value' => 'instock'],
    ],
]];
try {
    $repository->createRevision($draft, 'local disjunctive facet restriction test', 0);
    $request['query'] = 'shirt';
    $request['facets'] = ['inventory.stock_status'];
    $request['filters'] = ['field' => 'inventory.stock_status', 'op' => 'in', 'value' => ['outofstock']];
    $response = $provider->search($request);
    $values = ((array) $response['facets'])['inventory.stock_status'];
    if ($response['total'] !== 0 || $values !== [['value' => 'instock', 'count' => 2]]) {
        throw new RuntimeException('Alternative counts removed a curation restriction, missed a visible pin or counted a restricted product.');
    }
} finally {
    $repository->createRevision($original->toArray(), 'restore after local disjunctive facet restriction test', 0);
}
echo "Disjunctive facets passed: alternatives, legacy semantics, curation restriction, visible pins and hidden-product exclusion.\n";
