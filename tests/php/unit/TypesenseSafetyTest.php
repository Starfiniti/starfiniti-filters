<?php

use Starfiniti\Search\Infrastructure\Typesense\CollectionNames;
use Starfiniti\Search\Infrastructure\Typesense\CredentialReference;
use Starfiniti\Search\Infrastructure\Typesense\EndpointPolicy;

test('Typesense endpoint policy normalizes HTTPS and rejects SSRF targets', static function (): void {
    assertSameValue('https://search.example.com:8108', EndpointPolicy::validate('https://SEARCH.example.com:8108/'), 'Endpoint normalization failed.');
    foreach (['http://search.example.com', 'https://user:pass@example.com', 'https://169.254.169.254', 'https://localhost:8108', 'https://example.com/?redirect=x'] as $unsafe) {
        try {
            EndpointPolicy::validate($unsafe);
            throw new TestFailure('Unsafe endpoint was accepted: ' . $unsafe);
        } catch (InvalidArgumentException) {
        }
    }
    EndpointPolicy::assertResolvedAddresses(['10.0.0.12'], true);
    try {
        EndpointPolicy::assertResolvedAddresses(['10.0.0.12']);
        throw new TestFailure('Private address was accepted without private-network mode.');
    } catch (InvalidArgumentException) {
    }
});

test('Typesense names isolate installation environment locale schema and build', static function (): void {
    $alias = CollectionNames::alias('90a67c4d-3bc0-4afb-a47c-1cf655456acd', 'Production', 'sl_SI');
    assertTrueValue(str_starts_with($alias, 'sfs_'), 'Collection prefix missing.');
    assertTrueValue(str_ends_with($alias, '_production_products_sl_si'), 'Collection scope segments missing.');
    assertSameValue($alias . '_v2_17', CollectionNames::versioned('90a67c4d-3bc0-4afb-a47c-1cf655456acd', 'Production', 'sl_SI', 2, 17), 'Versioned collection mismatch.');
});

test('Typesense credentials resolve only approved external references', static function (): void {
    putenv('STARFINITI_TEST_CREDENTIAL=abcdefgh12345678');
    $reference = new CredentialReference('env:STARFINITI_TEST_CREDENTIAL');
    assertSameValue('env:STARFINITI_TEST_CREDENTIAL', $reference->id(), 'Credential reference ID mismatch.');
    assertSameValue('abcdefgh12345678', $reference->resolve(), 'External credential did not resolve.');
    putenv('STARFINITI_TEST_CREDENTIAL');
    try {
        new CredentialReference('option:typesense_key');
        throw new TestFailure('Unapproved credential storage was accepted.');
    } catch (InvalidArgumentException) {
    }
});
