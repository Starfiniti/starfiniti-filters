<?php

declare(strict_types=1);

use Starfiniti\Search\Domain\Search\Tokenizer;

test('tokenizer keeps Unicode terms and emits deterministic accent-folded forms', static function (): void {
    $tokens = (new Tokenizer())->tokenize('Črna Kava, crème café Straße smörgås');
    foreach (['črna', 'crna', 'kava', 'crème', 'creme', 'café', 'cafe', 'straße', 'strasse', 'smörgås', 'smorgas'] as $expected) {
        assertTrueValue(in_array($expected, $tokens, true), "Missing token {$expected}.");
    }
});

test('exact identifier normalization removes separators', static function (): void {
    assertSameValue('exact001', (new Tokenizer())->normalizeExact('EXACT-001'), 'SKU normalization mismatch.');
});

test('numeric tokens remain strings', static function (): void {
    $tokens = (new Tokenizer())->tokenize('Kava 500 g');
    assertTrueValue(in_array('500', $tokens, true), 'Numeric token must be preserved as a string.');
});
