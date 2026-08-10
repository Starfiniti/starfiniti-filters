<?php

declare(strict_types=1);

use Starfiniti\Search\Domain\Search\EditDistance;
use Starfiniti\Search\Domain\Search\PositionCodec;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Domain\Search\RankingProfile;
use Starfiniti\Search\Domain\Search\RelevancePolicy;

test('position codec round trips bounded increasing positions', static function (): void {
    foreach ([[], [0], [0, 1, 2, 127, 128, 4096], [4, 400, 40000]] as $positions) {
        assertSameValue($positions, PositionCodec::decode(PositionCodec::encode($positions)), 'Position codec round trip failed.');
    }
    assertTrueValue(strlen(PositionCodec::encode(range(0, 1023))) <= 2048, 'Position codec exceeded the database bound.');
});

test('position codec fails closed on corruption and invalid order', static function (): void {
    foreach (["", "\x02", "\x01\x80", "\x01\x80\x80\x80\x80\x80\x01"] as $corrupt) {
        try {
            PositionCodec::decode($corrupt);
            throw new TestFailure('Corrupt position blob was accepted.');
        } catch (InvalidArgumentException) {
        }
    }
    try {
        PositionCodec::encode([2, 2]);
        throw new TestFailure('Duplicate position was accepted.');
    } catch (InvalidArgumentException) {
    }
});

test('Unicode Damerau Levenshtein is length-aware and bounded', static function (): void {
    assertSameValue(1, EditDistance::damerauLevenshtein('alpnie', 'alpine', 1), 'Transposition was not recognized.');
    assertSameValue(1, EditDistance::damerauLevenshtein('kava', 'káva', 1), 'Unicode substitution was not recognized.');
    assertSameValue(null, EditDistance::damerauLevenshtein('shirt', 'coffee', 2), 'Distant term exceeded the bound.');
    assertSameValue(null, EditDistance::damerauLevenshtein(str_repeat('a', 20), 'b', 2), 'Length bound did not fail early.');
});

test('phrase normalization preserves deterministic token order', static function (): void {
    $tokenizer = new Tokenizer();
    assertSameValue('crna kava 500', $tokenizer->normalizePhrase('Črna Kava 500 g'), 'Phrase normalization was not accent-folded and ordered.');
    assertSameValue(['blue', 'blue', 'shirt'], $tokenizer->sequence('Blue blue shirt'), 'Sequence must retain positions and repetition.');
});

test('ranking policy is centralized, versioned, and deterministic', static function (): void {
    $profile = RankingProfile::describe();
    assertSameValue('local-default-v1', $profile['version'], 'Ranking profile is not versioned.');
    assertSameValue(1, $profile['analyzer_revision'], 'Analyzer revision is not explicit.');
    assertTrueValue($profile['boosts']['exact_identifier'] > $profile['boosts']['exact_title'], 'Exact identifier must dominate title boost.');
    assertTrueValue($profile['fuzzy_weight_factor'] < 1.0, 'Fuzzy matches must carry a deterministic penalty.');
    assertSameValue($profile, RankingProfile::describe(), 'Ranking profile description is not deterministic.');
});

test('relevance policy applies scoped synonyms and stop words deterministically', static function (): void {
    $ranking = RelevancePolicy::defaults();
    $ranking['stop_words'] = ['en_US' => ['the']];
    $ranking['synonyms'] = [
        ['id' => 'television', 'type' => 'equivalent', 'locale' => 'en_US', 'channel' => 'storefront', 'terms' => ['tv', 'television']],
        ['id' => 'iphone', 'type' => 'directional', 'locale' => 'en_US', 'channel' => 'storefront', 'source' => 'iphone', 'targets' => ['apple phone'], 'starts_at' => '2026-01-01T00:00:00Z', 'ends_at' => '2027-01-01T00:00:00Z'],
    ];
    $policy = RelevancePolicy::fromArray($ranking, new Tokenizer());
    $analysis = $policy->analyze('the iphone', 'en_US', 'storefront', new DateTimeImmutable('2026-08-09T12:00:00Z'));
    assertSameValue(['iphone'], $analysis['tokens'], 'Stop word was not removed.');
    assertSameValue(['apple', 'phone'], array_keys($analysis['synonym_terms']), 'Directional synonym expansion is not deterministic.');
    assertSameValue(['the'], $analysis['stop_words_removed'], 'Removed stop words are not explained.');
    assertSameValue(false, $analysis['all_stop_words'], 'Mixed query was incorrectly treated as all-stop-word input.');

    $inactive = $policy->analyze('iphone', 'sl_SI', 'storefront', new DateTimeImmutable('2026-08-09T12:00:00Z'));
    assertSameValue([], $inactive['synonym_terms'], 'Locale-scoped synonym leaked into another locale.');
    $empty = $policy->analyze('the the', 'en_US', 'storefront');
    assertSameValue(true, $empty['all_stop_words'], 'All-stop-word query was not classified safely.');
});

test('relevance policy rejects synonym cycles and conflicts', static function (): void {
    $ranking = RelevancePolicy::defaults();
    $ranking['synonyms'] = [
        ['id' => 'a-to-b', 'type' => 'directional', 'locale' => 'en_US', 'channel' => 'storefront', 'source' => 'alpha', 'targets' => ['beta']],
        ['id' => 'b-to-a', 'type' => 'directional', 'locale' => 'en_US', 'channel' => 'storefront', 'source' => 'beta', 'targets' => ['alpha']],
    ];
    try {
        RelevancePolicy::validate($ranking);
        throw new TestFailure('Directional synonym cycle was accepted.');
    } catch (InvalidArgumentException) {
    }

    $ranking = RelevancePolicy::defaults();
    $ranking['curations'] = [[
        'id' => 'unsafe-redirect', 'query' => 'sale', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 10,
        'actions' => ['redirect' => 'https://evil.example/steal'],
    ]];
    try {
        RelevancePolicy::validate($ranking);
        throw new TestFailure('External curation redirect was accepted.');
    } catch (InvalidArgumentException) {
    }
});

test('curation resolution is scoped prioritized bounded and deterministic', static function (): void {
    $ranking = RelevancePolicy::defaults();
    $ranking['curations'] = [
        [
            'id' => 'summer-primary', 'query' => 'Summer Sale', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 100,
            'actions' => ['pin' => [12, 10], 'hide' => [13], 'boost' => ['14' => 250], 'redirect' => '/summer-sale/?source=search'],
        ],
        [
            'id' => 'summer-secondary', 'query' => 'summer sale', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 10,
            'actions' => ['bury' => ['15' => 100], 'boost' => ['13' => 300]],
        ],
        [
            'id' => 'sale-rewrite', 'query' => 'sale intent', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 100,
            'actions' => ['rewrite' => 'shirt', 'filter' => ['field' => 'inventory.stock_status', 'op' => 'eq', 'value' => 'instock']],
        ],
    ];
    $policy = RelevancePolicy::fromArray($ranking, new Tokenizer());
    $curation = $policy->curation('  SUMMER sale ', 'en_US', 'storefront');
    assertSameValue(['summer-primary', 'summer-secondary'], $curation['rule_ids'], 'Curation priority order is not deterministic.');
    assertSameValue([12, 10], $curation['pins'], 'Pin order was not preserved.');
    assertSameValue([13], $curation['hidden'], 'Higher-priority hide was not preserved.');
    assertSameValue([14 => 250, 15 => -100], $curation['adjustments'], 'Boost/bury resolution is incorrect.');
    assertSameValue('/summer-sale/?source=search', $curation['redirect']['url'], 'Internal redirect was not retained.');
    assertSameValue([], $policy->curation('summer sale', 'sl_SI', 'storefront')['rule_ids'], 'Curation leaked across locale scope.');
    $rewrite = $policy->curation('sale intent', 'en_US', 'storefront');
    assertSameValue('shirt', $rewrite['rewrite']['query'], 'Query rewrite was not resolved.');
    assertSameValue('sale-rewrite', $rewrite['filter']['rule_id'], 'Curation filter was not attributed.');
});

test('curation rewrite cycles and unsafe filters fail validation', static function (): void {
    $ranking = RelevancePolicy::defaults();
    $ranking['curations'] = [
        ['id' => 'rewrite-a', 'query' => 'alpha', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 100, 'actions' => ['rewrite' => 'beta']],
        ['id' => 'rewrite-b', 'query' => 'beta', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 100, 'actions' => ['rewrite' => 'alpha']],
    ];
    try {
        RelevancePolicy::validate($ranking);
        throw new TestFailure('Curation rewrite cycle was accepted.');
    } catch (InvalidArgumentException) {
    }
    $ranking = RelevancePolicy::defaults();
    $ranking['curations'] = [[
        'id' => 'unsafe-filter', 'query' => 'sale', 'locale' => 'en_US', 'channel' => 'storefront', 'priority' => 100,
        'actions' => ['filter' => ['field' => 'wp_posts.post_status', 'op' => 'eq', 'value' => 'publish']],
    ]];
    try {
        RelevancePolicy::validate($ranking);
        throw new TestFailure('Provider-native curation filter field was accepted.');
    } catch (InvalidArgumentException) {
    }
});
