<?php

use Starfiniti\Search\Domain\Search\RelevancePolicy;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalSearchProvider;

global $wpdb;
$configuration = new ConfigurationRepository($wpdb);
$original = $configuration->current();
if ($original === null) {
    throw new RuntimeException('Configuration unavailable for relevance-policy test.');
}
$locale = determine_locale();
$draft = $original->toArray();
$draft['ranking'] = RelevancePolicy::defaults();
$draft['ranking']['stop_words'] = [$locale => ['the']];
$draft['ranking']['synonyms'] = [
    ['id' => 'mountaineering-alpine', 'type' => 'directional', 'locale' => $locale, 'channel' => 'storefront', 'source' => 'mountaineering', 'targets' => ['alpine']],
    ['id' => 'restricted-wholesale', 'type' => 'directional', 'locale' => $locale, 'channel' => 'storefront', 'source' => 'wholesaleish', 'targets' => ['wholesale']],
];
$draft['ranking']['curations'] = [
    [
        'id' => 'shirt-merchandising', 'query' => 'shirt', 'locale' => $locale, 'channel' => 'storefront', 'priority' => 100,
        'actions' => ['pin' => [12], 'boost' => [11 => 500], 'bury' => [10 => 100], 'hide' => [14], 'redirect' => '/curated-shirts/'],
    ],
    [
        'id' => 'shirt-conflicting-lower-priority', 'query' => 'shirt', 'locale' => $locale, 'channel' => 'storefront', 'priority' => 10,
        'actions' => ['pin' => [14]],
    ],
    [
        'id' => 'hide-red-exact', 'query' => 'red trail shirt', 'locale' => $locale, 'channel' => 'storefront', 'priority' => 100,
        'actions' => ['hide' => [11]],
    ],
    [
        'id' => 'restricted-pin-denial', 'query' => 'belt picks', 'locale' => $locale, 'channel' => 'storefront', 'priority' => 100,
        'actions' => ['pin' => [14]],
    ],
    [
        'id' => 'sale-intent-rewrite', 'query' => 'sale intent', 'locale' => $locale, 'channel' => 'storefront', 'priority' => 100,
        'actions' => ['rewrite' => 'shirt', 'filter' => ['field' => 'inventory.stock_status', 'op' => 'eq', 'value' => 'instock']],
    ],
];

$provider = new LocalSearchProvider($wpdb, new Tokenizer(), $configuration);
$request = static fn (string $query, string $channel = 'storefront', bool $explain = false, int $page = 1, int $size = 10, mixed $filters = null, array $facets = []): array => [
    'query' => $query,
    'context' => ['locale' => determine_locale(), 'channel' => $channel, 'customer_scope' => ['public']],
    'page' => ['number' => $page, 'size' => $size],
    'filters' => $filters,
    'facets' => $facets,
    'sort' => [],
    'options' => ['suggestion_mode' => $explain ? 'admin_test' : 'full_results', 'include_explanation' => $explain],
];

try {
    $configuration->createRevision($draft, 'integration relevance policy activation', 0);
    $synonym = $provider->search($request('mountaineering', 'storefront', true));
    if (($synonym['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt'
        || !in_array('synonym_expansions_applied:1', $synonym['warnings'], true)
        || (($synonym['hits'][0]['explanation']['synonym_expansions']['alpine']['rule_id'] ?? '') !== 'mountaineering-alpine')) {
        throw new RuntimeException('Directional synonym did not produce an explained bounded result.');
    }

    $stopWord = $provider->search($request('the alpine'));
    if (($stopWord['hits'][0]['projection']['identity']['title'] ?? '') !== 'Blue Alpine Shirt'
        || !in_array('stop_words_removed:1', $stopWord['warnings'], true)) {
        throw new RuntimeException('Stop-word removal changed the expected relevant result.');
    }
    $allStop = $provider->search($request('the the'));
    if ($allStop['total'] !== 0 || $allStop['hits'] !== [] || !in_array('all_stop_words_safe_no_result', $allStop['warnings'], true)) {
        throw new RuntimeException('All-stop-word input did not fail closed to a safe empty result.');
    }
    $punctuation = $provider->search($request('!!!'));
    if ($punctuation['total'] !== 0 || !in_array('unsearchable_query_safe_no_result', $punctuation['warnings'], true)) {
        throw new RuntimeException('Untokenizable input became an unbounded match-all request.');
    }
    $wrongChannel = $provider->search($request('mountaineering', 'headless'));
    if ($wrongChannel['total'] !== 0 || in_array('synonym_expansions_applied:1', $wrongChannel['warnings'], true)) {
        throw new RuntimeException('Channel-scoped synonym leaked into another channel.');
    }
    $restricted = $provider->search($request('wholesaleish'));
    if ($restricted['total'] !== 0 || $restricted['hits'] !== []) {
        throw new RuntimeException('Synonym expansion bypassed server-side visibility.');
    }

    $curated = $provider->search($request('shirt', 'storefront', true, 1, 2, null, ['inventory.stock_status', 'classification.category_paths']));
    if ($curated['total'] !== 3 || ($curated['page']['has_more'] ?? false) !== true
        || array_column($curated['hits'], 'entity_id') !== [12, 11]
        || ($curated['redirect']['url'] ?? '') !== '/curated-shirts/'
        || !in_array('curations_applied:2', $curated['warnings'], true)
        || !in_array('pinned_results_applied:1', $curated['warnings'], true)
        || (($curated['hits'][0]['explanation']['curation_effect']['type'] ?? '') !== 'pin')
        || (($curated['hits'][1]['explanation']['curation_effect']['type'] ?? '') !== 'boost')) {
        throw new RuntimeException('Candidate-stage pin/boost/redirect curation was not deterministic.');
    }
    $curatedFacets = (array) $curated['facets'];
    $stockCounts = array_column($curatedFacets['inventory.stock_status'] ?? [], 'count', 'value');
    if (($stockCounts['instock'] ?? 0) !== 2 || ($stockCounts['outofstock'] ?? 0) !== 1) {
        throw new RuntimeException('Pinned result was not included exactly once in curated facet counts.');
    }
    $curatedPageTwo = $provider->search($request('shirt', 'storefront', false, 2, 2));
    if ($curatedPageTwo['total'] !== 3 || array_column($curatedPageTwo['hits'], 'entity_id') !== [10] || ($curatedPageTwo['page']['has_more'] ?? true) !== false) {
        throw new RuntimeException('Curated pagination duplicated, omitted, or miscounted results.');
    }
    $apparelFilter = ['field' => 'classification.category_paths', 'op' => 'eq', 'value' => 'Apparel'];
    $filteredCuration = $provider->search($request('shirt', 'storefront', false, 1, 10, $apparelFilter));
    if ($filteredCuration['total'] !== 2 || in_array(12, array_column($filteredCuration['hits'], 'entity_id'), true)) {
        throw new RuntimeException('Curation pin bypassed an active storefront filter.');
    }
    $hiddenByRule = $provider->search($request('"Red Trail Shirt"'));
    if ($hiddenByRule['total'] !== 0 || $hiddenByRule['hits'] !== []) {
        throw new RuntimeException('Curation hide did not remove the exact-query result.');
    }
    $restrictedPin = $provider->search($request('belt picks'));
    if ($restrictedPin['total'] !== 0 || $restrictedPin['hits'] !== []) {
        throw new RuntimeException('Curation pin bypassed canonical product visibility.');
    }
    $rewritten = $provider->search($request('sale intent', 'storefront', true));
    if ($rewritten['total'] !== 1 || ($rewritten['hits'][0]['entity_id'] ?? 0) !== 10
        || !in_array('query_rewritten:sale-intent-rewrite', $rewritten['warnings'], true)
        || !in_array('curation_filter_applied:sale-intent-rewrite', $rewritten['warnings'], true)
        || (($rewritten['hits'][0]['explanation']['normalized_query'] ?? '') !== 'shirt')
        || (($rewritten['hits'][0]['explanation']['curation_filter']['applied'] ?? false) !== true)) {
        throw new RuntimeException('Curation rewrite/filter did not apply a bounded explained plan.');
    }
} finally {
    $configuration->createRevision($original->toArray(), 'integration relevance policy restore', 0);
}

if (!hash_equals($configuration->current()?->semanticChecksum() ?? '', $original->semanticChecksum())) {
    throw new RuntimeException('Relevance-policy test did not restore the original immutable configuration.');
}
echo "Relevance policy passed: scoped synonyms/stop words, candidate-stage curations, facets, pagination, safe redirects, and visibility isolation.\n";
