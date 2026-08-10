<?php

declare(strict_types=1);

use Starfiniti\Search\Domain\Catalog\Money;

test('money converts decimal values to minor units without floating point', static function (): void {
    assertSameValue(1999, Money::toMinor('19.99', 2), '19.99 must equal 1999 minor units.');
    assertSameValue(1900, Money::toMinor('19', 2), 'Whole amounts must be scaled.');
    assertSameValue(123, Money::toMinor('1.239', 2), 'Excess precision is deterministically truncated.');
    assertSameValue(null, Money::toMinor(null, 2), 'Missing price must remain null.');
});

test('money rejects negative catalog prices', static function (): void {
    try {
        Money::toMinor('-1.00', 2);
        throw new TestFailure('Negative price was accepted.');
    } catch (InvalidArgumentException) {
        assertTrueValue(true, 'Expected exception.');
    }
});

