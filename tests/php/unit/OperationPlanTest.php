<?php

declare(strict_types=1);

use Starfiniti\Search\Domain\Operations\OperationPlan;

test('operation plans are immutable hash-bound typed contracts', static function (): void {
    $current = ['site_id_hash' => str_repeat('a', 64), 'blog_id' => 1, 'active_generation' => 12, 'configuration_revision' => 4];
    $expires = gmdate(DATE_ATOM, time() + 600);
    $first = OperationPlan::create('123e4567-e89b-42d3-a456-426614174000', 'index.activate', 'release-activate-0001', $current, ['target_generation' => 13], 'Activate verified candidate', $expires)->toArray();
    $second = OperationPlan::create('123e4567-e89b-42d3-a456-426614174000', 'index.activate', 'release-activate-0001', $current, ['target_generation' => 13], 'Activate verified candidate', $expires)->toArray();
    assertSameValue($first['plan_hash'], $second['plan_hash'], 'Equivalent operation plans must hash identically.');
    assertTrueValue((bool) preg_match('/^[a-f0-9]{64}$/', $first['plan_hash']), 'Plan hash is not a SHA-256 digest.');
    assertSameValue(true, $first['dry_run'], 'Planning must remain a dry-run.');
    assertSameValue(true, $first['approval_required'], 'Index activation must require approval.');
    assertSameValue('search.index.activate', $first['required_scope'], 'Activation scope is incorrect.');
    assertSameValue(12, $first['rollback']['target_generation'], 'Rollback did not bind the prior generation.');
});

test('operation plans reject generic or unbounded operations', static function (): void {
    try {
        OperationPlan::create('123e4567-e89b-42d3-a456-426614174000', 'run_sql', 'generic-tool-0001', [], [], 'Unsafe operation', gmdate(DATE_ATOM, time() + 600));
        throw new TestFailure('Generic SQL operation was accepted.');
    } catch (InvalidArgumentException $exception) {
        assertTrueValue(str_contains($exception->getMessage(), 'Unsupported'), 'Unexpected rejection for generic operation.');
    }
});
