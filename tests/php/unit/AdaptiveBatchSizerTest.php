<?php

declare(strict_types=1);

use Starfiniti\Search\Infrastructure\WordPress\Operations\AdaptiveBatchSizer;

test('adaptive batch sizing enforces configured and hard ceilings', static function (): void {
    assertSameValue(50, AdaptiveBatchSizer::fromBudget(50, -1, 0), 'Unlimited memory changed the configured batch.');
    assertSameValue(100, AdaptiveBatchSizer::fromBudget(500, -1, 0), 'Hard batch ceiling was not enforced.');
    assertSameValue(1, AdaptiveBatchSizer::fromBudget(0, -1, 0), 'Minimum batch size was not enforced.');
});

test('adaptive batch sizing shrinks under deterministic memory pressure', static function (): void {
    $mib = 1024 * 1024;
    assertSameValue(5, AdaptiveBatchSizer::fromBudget(80, 128 * $mib, 118 * $mib), 'Critical memory pressure did not select the emergency batch.');
    assertSameValue(25, AdaptiveBatchSizer::fromBudget(80, 256 * $mib, 200 * $mib), 'Moderate memory pressure did not select the reduced batch.');
    assertSameValue(80, AdaptiveBatchSizer::fromBudget(80, 512 * $mib, 128 * $mib), 'Healthy memory headroom reduced the configured batch.');
});
