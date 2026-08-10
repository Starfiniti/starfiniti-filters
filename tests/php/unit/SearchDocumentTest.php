<?php

declare(strict_types=1);

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Catalog\ProductSnapshot;
use Starfiniti\Search\Domain\Catalog\SearchDocument;

/** @param array<string, string|int|float|bool> $custom */
function snapshotForTest(string $locale = 'sl_SI', array $custom = []): ProductSnapshot
{
    return new ProductSnapshot(
        entityId: 42,
        parentId: null,
        siteId: '12345678-1234-1234-1234-123456789012',
        blogId: 1,
        locale: $locale,
        channel: 'storefront',
        status: 'publish',
        catalogVisible: true,
        searchVisible: true,
        passwordProtected: false,
        scopeTokens: ['public'],
        title: 'Črna Kava 500 g',
        slug: 'crna-kava-500g',
        url: 'https://example.test/product/crna-kava-500g/',
        sku: 'KAVA-Č-500',
        gtin: null,
        shortDescription: 'Kava.',
        description: 'Opis.',
        excerpt: 'Kava.',
        searchKeywords: [],
        categoryIds: [9],
        categoryPaths: ['Čaj & Kava'],
        tagIds: [],
        attributes: [],
        currency: 'EUR',
        regularPriceMinor: 1230,
        salePriceMinor: null,
        activePriceMinor: 1230,
        taxDisplayMode: 'inclusive',
        priceScope: 'public:EUR',
        stockStatus: 'instock',
        quantity: 25.0,
        backorders: 'no',
        purchasable: true,
        inventorySearchable: true,
        imageId: null,
        imageUrl: null,
        thumbnailUrl: null,
        imageAlt: '',
        averageRatingScaled: 0,
        ratingCount: 0,
        salesCount: 0,
        menuOrder: 0,
        featured: false,
        createdAt: '2026-08-09T00:00:00+00:00',
        modifiedAt: '2026-08-09T00:00:00+00:00',
        observedAt: '2026-08-09T00:00:00+00:00',
        custom: $custom
    );
}

test('product document has stable identity, integer money, and checksum', static function (): void {
    $document = (new ProductDocumentFactory())->create(snapshotForTest())->toArray();
    assertSameValue('product:1:42:sl_SI', $document['document_id'], 'Document ID mismatch.');
    assertSameValue(1230, $document['pricing']['active_min_minor'], 'Price must remain integer minor units.');
    assertTrueValue((bool) preg_match('/^[a-f0-9]{64}$/', $document['checksum']), 'Checksum must be lowercase SHA-256.');
});

test('language documents have distinct stable identities', static function (): void {
    $factory = new ProductDocumentFactory();
    $slovenian = $factory->create(snapshotForTest('sl_SI'))->toArray();
    $english = $factory->create(snapshotForTest('en_US'))->toArray();
    assertSameValue('product:1:42:sl_SI', $slovenian['document_id'], 'Slovenian document identity mismatch.');
    assertSameValue('product:1:42:en_US', $english['document_id'], 'English document identity mismatch.');
    assertTrueValue($slovenian['document_id'] !== $english['document_id'], 'Language documents collided.');
});

test('typed allow-listed custom values enter the canonical document', static function (): void {
    $custom = ['supplier_code' => 'A-42', 'lead_days' => 3, 'hazardous' => false, 'weight_score' => 1.25];
    $document = (new ProductDocumentFactory())->create(snapshotForTest(custom: $custom))->toArray();
    assertSameValue($custom, $document['custom'], 'Typed custom projection changed values or types.');
});

test('product document checksum is deterministic', static function (): void {
    $factory = new ProductDocumentFactory();
    assertSameValue($factory->create(snapshotForTest())->checksum(), $factory->create(snapshotForTest())->checksum(), 'Checksum changed for identical source data.');
});

test('product checksum ignores volatile source observation time', static function (): void {
    $first = (new ProductDocumentFactory())->create(snapshotForTest())->toArray();
    $second = $first;
    $second['timestamps']['source_observed_at'] = '2030-01-01T00:00:00+00:00';
    assertSameValue(
        (new SearchDocument($first))->checksum(),
        (new SearchDocument($second))->checksum(),
        'Observation time created false catalog drift.'
    );
});
