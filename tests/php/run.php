<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = $root . '/plugin/starfiniti-search/src/';
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'Starfiniti\\Search\\';
    if (str_starts_with($class, $prefix)) {
        $path = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
});

final class TestFailure extends RuntimeException {}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

$tests = [];
function test(string $name, Closure $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

require __DIR__ . '/unit/CanonicalJsonTest.php';
require __DIR__ . '/unit/MoneyTest.php';
require __DIR__ . '/unit/TokenizerTest.php';
require __DIR__ . '/unit/SearchDocumentTest.php';
require __DIR__ . '/unit/ConfigurationTest.php';
require __DIR__ . '/unit/TypesenseSafetyTest.php';
require __DIR__ . '/unit/TypesenseProviderTest.php';
require __DIR__ . '/unit/OperationPlanTest.php';
require __DIR__ . '/unit/StructuredLoggerTest.php';
require __DIR__ . '/unit/RelevancePrimitivesTest.php';
require __DIR__ . '/unit/AggregateReportTest.php';
require __DIR__ . '/unit/AdaptiveBatchSizerTest.php';

$failures = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
