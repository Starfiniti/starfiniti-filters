<?php

declare(strict_types=1);

use Starfiniti\Search\Infrastructure\WordPress\Operations\StructuredLogger;

test('structured logger emits bounded fields and redacts sensitive context', static function (): void {
    $captured = null;
    $logger = new StructuredLogger(static function (array $record) use (&$captured): void { $captured = $record; });
    $correlation = $logger->log('error', 'Search.Provider Failed!', '<b>Safe message</b>', [
        'provider' => 'local',
        'retryable' => true,
        'safe_context' => [
            'query' => 'private medical phrase',
            'api_key' => 'super-secret',
            'nested' => ['password' => 'never-log-me', 'count' => 2],
        ],
    ]);
    assertTrueValue(is_array($captured), 'Structured record was not emitted.');
    assertSameValue('search.providerfailed', $captured['event_code'], 'Event code was not normalized.');
    assertSameValue('Safe message', $captured['message'], 'Log message was not safely normalized.');
    assertSameValue('[redacted]', $captured['safe_context']['query'], 'Raw query was not redacted.');
    assertSameValue('[redacted]', $captured['safe_context']['api_key'], 'API key was not redacted.');
    assertSameValue('[redacted]', $captured['safe_context']['nested']['password'], 'Nested secret was not redacted.');
    assertSameValue($correlation, $captured['correlation_id'], 'Correlation ID was not returned to the caller.');
});
