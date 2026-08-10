<?php

use Starfiniti\Search\Infrastructure\Typesense\CredentialReference;
use Starfiniti\Search\Infrastructure\Typesense\ProviderUnavailable;
use Starfiniti\Search\Infrastructure\Typesense\TypesenseSearchProvider;
use Starfiniti\Search\Infrastructure\Typesense\TypesenseTransport;
use Starfiniti\Search\Infrastructure\Typesense\TypesenseIndexManager;
use Starfiniti\Search\Infrastructure\Typesense\TypesenseRelevanceReconciler;

final class FakeTypesenseTransport implements TypesenseTransport
{
    /** @var list<array{status:int,body:string}> */
    public array $responses = [];
    /** @var list<array<string,mixed>> */
    public array $requests = [];
    public bool $throw = false;

    public function request(string $method, string $url, array $headers, ?string $body, int $connectTimeoutMs, int $totalTimeoutMs): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'connectTimeoutMs', 'totalTimeoutMs');
        if ($this->throw) {
            throw new RuntimeException('transport detail containing internal state');
        }
        return array_shift($this->responses) ?? ['status' => 500, 'body' => '{}'];
    }
}

function typesenseFixtureRequest(): array
{
    return [
        'query' => 'Blue Alpine',
        'context' => ['locale' => 'en_US', 'channel' => 'storefront', 'customer_scope' => ['public']],
        'page' => ['number' => 1, 'size' => 8],
        'filters' => null,
        'facets' => [],
        'sort' => [],
        'options' => ['suggestion_mode' => 'autocomplete'],
    ];
}

test('Typesense provider compiles bounded safe search and maps canonical hits', static function (): void {
    putenv('STARFINITI_TEST_SEARCH_CREDENTIAL=search-only-value');
    $transport = new FakeTypesenseTransport();
    $transport->responses = [
        ['status' => 503, 'body' => '{}'],
        ['status' => 200, 'body' => json_encode([
            'found' => 1,
            'hits' => [[
                'text_match' => 900000000,
                'document' => ['canonical' => [
                    'document_id' => 'product:1:10:en_US', 'entity_type' => 'product', 'entity_id' => 10, 'parent_id' => null,
                    'identity' => ['title' => 'Blue Alpine Shirt', 'sku' => 'EXACT-001', 'url' => '/product/blue/'],
                    'content' => ['excerpt' => 'Typed safe details'], 'classification' => [], 'attributes' => [], 'pricing' => [], 'inventory' => [], 'media' => [], 'quality' => [],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR)],
    ];
    $provider = new TypesenseSearchProvider(
        $transport,
        'https://search.example.com',
        new CredentialReference('env:STARFINITI_TEST_SEARCH_CREDENTIAL'),
        'sfs_1234567890abcdef_production_products_en_us',
        1500,
        ['en_US|storefront' => ['synonym_set' => 'sfsr_scope_r9_syn', 'curation_set' => 'sfsr_scope_r9_cur']]
    );
    $capabilities = $provider->capabilities();
    assertSameValue('typesense', $capabilities['provider_id'], 'Structured capability provider ID mismatch.');
    assertSameValue('unsupported', $capabilities['capabilities']['service_certification']['state'], 'Uncertified service capability was overstated.');
    $response = $provider->search(typesenseFixtureRequest());
    assertSameValue(2, count($transport->requests), 'Transient unavailable response was not retried once.');
    assertTrueValue(str_contains($transport->requests[0]['url'], 'filter_by=searchable%3A%3Dtrue'), 'Mandatory visibility filter was not compiled.');
    assertTrueValue(str_contains($transport->requests[0]['url'], 'synonym_sets=sfsr_scope_r9_syn'), 'Scoped synonym set was not selected at query time.');
    assertTrueValue(str_contains($transport->requests[0]['url'], 'curation_sets=sfsr_scope_r9_cur'), 'Scoped curation set was not selected at query time.');
    assertSameValue('search-only-value', $transport->requests[0]['headers']['X-TYPESENSE-API-KEY'], 'Search credential was not used by the transport.');
    assertSameValue('typesense', $response['provider'], 'Provider response ID mismatch.');
    assertSameValue('Blue Alpine Shirt', $response['hits'][0]['projection']['identity']['title'], 'Canonical hit mapping failed.');
    assertSameValue('Typed safe details', $response['hits'][0]['projection']['content']['excerpt'], 'Typed details projection was not mapped.');
    assertTrueValue(!str_contains(json_encode($response, JSON_THROW_ON_ERROR), 'search-only-value'), 'Credential leaked into provider response.');
    putenv('STARFINITI_TEST_SEARCH_CREDENTIAL');
});

test('Typesense circuit opens after repeated transport failures with redacted errors', static function (): void {
    putenv('STARFINITI_TEST_SEARCH_CREDENTIAL=search-only-value');
    $transport = new FakeTypesenseTransport();
    $transport->throw = true;
    $provider = new TypesenseSearchProvider($transport, 'https://search.example.com', new CredentialReference('env:STARFINITI_TEST_SEARCH_CREDENTIAL'), 'sfs_1234567890abcdef_production_products_en_us');
    for ($attempt = 0; $attempt < 3; ++$attempt) {
        try {
            $provider->search(typesenseFixtureRequest());
        } catch (ProviderUnavailable $exception) {
            assertTrueValue(!str_contains($exception->getMessage(), 'internal state'), 'Transport detail leaked through typed error.');
        }
    }
    $requestsBefore = count($transport->requests);
    try {
        $provider->search(typesenseFixtureRequest());
        throw new TestFailure('Open circuit accepted another request.');
    } catch (ProviderUnavailable $exception) {
        assertSameValue('Typesense circuit is open.', $exception->getMessage(), 'Open-circuit error mismatch.');
    }
    assertSameValue($requestsBefore, count($transport->requests), 'Open circuit called the transport.');
    putenv('STARFINITI_TEST_SEARCH_CREDENTIAL');
});

test('Typesense control plane creates versioned collections and records every import result', static function (): void {
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL=admin-only-value');
    $transport = new FakeTypesenseTransport();
    $expectedCollection = 'sfs_' . substr(hash('sha256', '12345678-1234-1234-1234-123456789012'), 0, 16) . '_production_products_en_us_v3_7';
    $transport->responses = [
        ['status' => 404, 'body' => '{}'],
        ['status' => 201, 'body' => '{}'],
        ['status' => 200, 'body' => json_encode(['name' => $expectedCollection], JSON_THROW_ON_ERROR)],
        ['status' => 200, 'body' => "{\"success\":true}\n{\"success\":false,\"code\":400}\n"],
    ];
    $manager = new TypesenseIndexManager($transport, 'https://search.example.com', new CredentialReference('env:STARFINITI_TEST_ADMIN_CREDENTIAL'), '12345678-1234-1234-1234-123456789012', 'production', 'en_US');
    $collection = $manager->ensureVersionedCollection(3, 7, ['fields' => [['name' => 'document_id', 'type' => 'string']]]);
    assertSameValue($expectedCollection, $collection, 'Versioned Typesense collection name mismatch.');
    $results = $manager->import($collection, [
        ['document_id' => 'product:1:10:en_US', 'title' => 'Blue'],
        ['document_id' => 'product:1:11:en_US', 'title' => 'Red'],
    ]);
    assertSameValue(true, $results[0]['success'], 'Successful import result was not recorded.');
    assertSameValue(false, $results[1]['success'], 'Failed import result was not recorded.');
    assertTrueValue((bool) preg_match('/^typesense_import_[a-f0-9]{12}$/', (string) $results[1]['error_code']), 'Import failure code was not bounded and redacted.');
    assertSameValue('admin-only-value', $transport->requests[0]['headers']['X-TYPESENSE-API-KEY'], 'Admin credential was not used by the control plane.');
    assertSameValue('text/plain', $transport->requests[3]['headers']['Content-Type'], 'NDJSON import content type mismatch.');
    assertTrueValue(str_contains((string) $transport->requests[3]['body'], '"id":"product:1:10:en_US"'), 'Canonical document identity was not bound to the Typesense document ID.');
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL');
});

test('Typesense alias activation and rollback are verified and idempotent', static function (): void {
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL=admin-only-value');
    $transport = new FakeTypesenseTransport();
    $first = 'sfs_1234567890abcdef_production_products_en_us_v3_7';
    $second = 'sfs_1234567890abcdef_production_products_en_us_v3_8';
    $aliasBody = static fn (string $collection): string => json_encode(['collection_name' => $collection], JSON_THROW_ON_ERROR);
    $transport->responses = [
        ['status' => 404, 'body' => '{}'], ['status' => 200, 'body' => '{}'], ['status' => 200, 'body' => $aliasBody($second)],
        ['status' => 200, 'body' => $aliasBody($second)], ['status' => 200, 'body' => $aliasBody($second)],
        ['status' => 200, 'body' => $aliasBody($second)], ['status' => 200, 'body' => '{}'], ['status' => 200, 'body' => $aliasBody($first)],
    ];
    $manager = new TypesenseIndexManager($transport, 'https://search.example.com', new CredentialReference('env:STARFINITI_TEST_ADMIN_CREDENTIAL'), '12345678-1234-1234-1234-123456789012', 'production', 'en_US');
    $activated = $manager->activate($second);
    assertSameValue(true, $activated['changed'], 'First alias activation was not recorded as a change.');
    $replayed = $manager->activate($second);
    assertSameValue(false, $replayed['changed'], 'Idempotent alias replay performed a change.');
    $rolledBack = $manager->rollback($first);
    assertSameValue($second, $rolledBack['previous'], 'Rollback did not record the replaced collection.');
    assertSameValue($first, $rolledBack['active'], 'Rollback did not verify the retained collection.');
    $puts = array_values(array_filter($transport->requests, static fn (array $request): bool => $request['method'] === 'PUT'));
    assertSameValue(2, count($puts), 'Idempotent activation issued an unnecessary alias mutation.');
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL');
});

test('Typesense v30 relevance reconciliation is scoped verified idempotent and drift removing', static function (): void {
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL=admin-only-value');
    $transport = new FakeTypesenseTransport();
    $collection = 'sfs_1234567890abcdef_production_products_en_us_v3_7';
    $scope = 'sfsr_' . substr(hash('sha256', $collection . '|en_US|storefront'), 0, 16) . '_';
    $synonymSet = $scope . 'syn';
    $curationSet = $scope . 'cur';
    $synonymItems = [[
        'id' => 'sfs_outerwear', 'locale' => 'en_US', 'symbols_to_index' => [], 'synonyms' => ['coat', 'jacket'],
    ]];
    $curationItems = [[
        'id' => 'sfs_p0950_winter',
        'rule' => ['query' => 'winter coat', 'match' => 'exact'],
        'includes' => [['id' => 'product:1:10:en_US', 'position' => 1]],
        'excludes' => [['id' => 'product:1:11:en_US']],
        'filter_curated_hits' => true,
        'stop_processing' => false,
        'metadata' => ['starfiniti_rule_id' => 'winter'],
        'replace_query' => 'alpine jacket',
        'effective_from_ts' => (new DateTimeImmutable('2026-08-08T00:00:00Z'))->getTimestamp(),
        'effective_to_ts' => (new DateTimeImmutable('2026-08-10T00:00:00Z'))->getTimestamp(),
    ]];
    $set = static fn (array $items): string => json_encode(['items' => $items], JSON_THROW_ON_ERROR);
    $before = json_encode([
        'name' => $collection,
        'synonym_sets' => ['external_synonyms', $scope . 'r8_syn'],
        'curation_sets' => ['external_curations', $scope . 'r8_cur'],
    ], JSON_THROW_ON_ERROR);
    $after = json_encode([
        'name' => $collection,
        'synonym_sets' => ['external_synonyms'],
        'curation_sets' => ['external_curations'],
    ], JSON_THROW_ON_ERROR);
    $transport->responses = [
        ['status' => 404, 'body' => '{}'], ['status' => 201, 'body' => '{}'], ['status' => 200, 'body' => $set($synonymItems)],
        ['status' => 404, 'body' => '{}'], ['status' => 201, 'body' => '{}'], ['status' => 200, 'body' => $set($curationItems)],
        ['status' => 200, 'body' => $before], ['status' => 200, 'body' => '{}'], ['status' => 200, 'body' => $after],
        ['status' => 200, 'body' => '{}'], ['status' => 404, 'body' => '{}'],
        ['status' => 200, 'body' => $set($synonymItems)], ['status' => 200, 'body' => $set($synonymItems)],
        ['status' => 200, 'body' => $set($curationItems)], ['status' => 200, 'body' => $set($curationItems)],
        ['status' => 200, 'body' => $after], ['status' => 200, 'body' => $after],
    ];
    $ranking = [
        'profile' => 'local-default-v1',
        'synonyms' => [[
            'id' => 'outerwear', 'type' => 'equivalent', 'locale' => 'en_US', 'channel' => 'storefront', 'terms' => ['coat', 'jacket'],
        ]],
        'stop_words' => [],
        'curations' => [[
            'id' => 'winter', 'query' => 'winter coat', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 50,
            'starts_at' => '2026-08-08T00:00:00Z', 'ends_at' => '2026-08-10T00:00:00Z',
            'actions' => [
                'pin' => [10, 999], 'hide' => [11], 'boost' => ['12' => 5], 'rewrite' => 'alpine jacket', 'redirect' => '/winter/',
            ],
        ]],
    ];
    $reconciler = new TypesenseRelevanceReconciler($transport, 'https://search.example.com', new CredentialReference('env:STARFINITI_TEST_ADMIN_CREDENTIAL'));
    $result = $reconciler->reconcile($collection, 30, 9, $ranking, 'en_US', 'storefront', [
        10 => 'product:1:10:en_US', 11 => 'product:1:11:en_US', 12 => 'product:1:12:en_US',
    ], new DateTimeImmutable('2026-08-09T00:00:00Z'));
    assertSameValue(true, $result['changed'], 'Initial relevance projection did not report a change.');
    assertSameValue($synonymSet, $result['synonym_set'], 'Synonym set identity mismatch.');
    assertSameValue($curationSet, $result['curation_set'], 'Curation set identity mismatch.');
    assertSameValue('2026-08-10T00:00:00+00:00', $result['next_reconcile_at'], 'Next effective-date reconciliation boundary mismatch.');
    assertSameValue(['curation:winter:boost', 'curation:winter:missing_document:999', 'curation:winter:redirect'], $result['application_only'], 'Application-only curation actions were not explicit.');
    assertTrueValue((bool) preg_match('/^[a-f0-9]{64}$/', $result['projection_hash']), 'Relevance projection hash was invalid.');
    $legacyCalls = array_filter($transport->requests, static fn (array $request): bool => str_contains($request['url'], '/overrides') || str_contains($request['url'], '/collections/' . $collection . '/synonyms'));
    assertSameValue(0, count($legacyCalls), 'Reconciler used an incompatible legacy Typesense endpoint.');
    $patches = array_values(array_filter($transport->requests, static fn (array $request): bool => $request['method'] === 'PATCH'));
    assertSameValue(1, count($patches), 'Initial relevance links were not patched exactly once.');
    assertSameValue(['synonym_sets' => ['external_synonyms'], 'curation_sets' => ['external_curations']], json_decode((string) $patches[0]['body'], true, 16, JSON_THROW_ON_ERROR), 'Scoped Starfiniti sets were not unlinked while external relevance sets were preserved.');

    $beforeReplay = count($transport->requests);
    $replay = $reconciler->reconcile($collection, 30, 9, $ranking, 'en_US', 'storefront', [
        10 => 'product:1:10:en_US', 11 => 'product:1:11:en_US', 12 => 'product:1:12:en_US',
    ], new DateTimeImmutable('2026-08-09T00:00:00Z'));
    assertSameValue(false, $replay['changed'], 'Identical relevance reconciliation was not idempotent.');
    $replayMutations = array_filter(array_slice($transport->requests, $beforeReplay), static fn (array $request): bool => in_array($request['method'], ['PUT', 'PATCH', 'DELETE'], true));
    assertSameValue(0, count($replayMutations), 'Idempotent relevance replay performed a mutation.');
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL');
});

test('Typesense relevance reconciliation rejects an unmapped server major before network access', static function (): void {
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL=admin-only-value');
    $transport = new FakeTypesenseTransport();
    $reconciler = new TypesenseRelevanceReconciler($transport, 'https://search.example.com', new CredentialReference('env:STARFINITI_TEST_ADMIN_CREDENTIAL'));
    try {
        $reconciler->reconcile('sfs_1234567890abcdef_production_products_en_us_v3_7', 29, 1, [
            'profile' => 'local-default-v1', 'synonyms' => [], 'stop_words' => [], 'curations' => [],
        ], 'en_US', 'storefront', []);
        throw new TestFailure('Unmapped Typesense major was accepted.');
    } catch (InvalidArgumentException) {
    }
    assertSameValue(0, count($transport->requests), 'Unmapped Typesense major reached the network.');
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL');
});

test('Typesense relevance reconciliation redacts transport failures', static function (): void {
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL=admin-only-value');
    $transport = new FakeTypesenseTransport();
    $transport->throw = true;
    $reconciler = new TypesenseRelevanceReconciler($transport, 'https://search.example.com', new CredentialReference('env:STARFINITI_TEST_ADMIN_CREDENTIAL'));
    try {
        $reconciler->reconcile('sfs_1234567890abcdef_production_products_en_us_v3_7', 30, 1, [
            'profile' => 'local-default-v1',
            'synonyms' => [['id' => 'safe', 'type' => 'equivalent', 'locale' => 'en_US', 'channel' => 'storefront', 'terms' => ['coat', 'jacket']]],
            'stop_words' => [],
            'curations' => [],
        ], 'en_US', 'storefront', []);
        throw new TestFailure('Typesense transport failure was accepted.');
    } catch (ProviderUnavailable $exception) {
        assertSameValue('Typesense relevance request failed.', $exception->getMessage(), 'Typesense relevance transport detail was not redacted.');
        assertSameValue(null, $exception->getPrevious(), 'Typesense relevance transport exception chain was exposed.');
    }
    putenv('STARFINITI_TEST_ADMIN_CREDENTIAL');
});
