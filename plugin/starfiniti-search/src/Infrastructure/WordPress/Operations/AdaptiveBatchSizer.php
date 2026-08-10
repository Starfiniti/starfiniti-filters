<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Operations;

final class AdaptiveBatchSizer
{
    public static function fromRuntime(int $configured, int $hardMaximum = 100): int
    {
        return self::fromBudget($configured, self::memoryBytes((string) ini_get('memory_limit')), memory_get_usage(true), $hardMaximum);
    }

    public static function fromBudget(int $configured, int $memoryLimit, int $memoryUsage, int $hardMaximum = 100): int
    {
        $ceiling = max(1, min(max(1, $hardMaximum), $configured));
        if ($memoryLimit <= 0) {
            return $ceiling;
        }
        $available = max(0, $memoryLimit - max(0, $memoryUsage));
        $ratio = $available / $memoryLimit;
        if ($available < 16 * 1024 * 1024 || $ratio < 0.15) {
            return min($ceiling, 5);
        }
        if ($available < 64 * 1024 * 1024 || $ratio < 0.35) {
            return min($ceiling, 25);
        }
        return $ceiling;
    }

    private static function memoryBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (preg_match('/^(\d+)([KMG])?$/i', $value, $matches) !== 1) {
            return -1;
        }
        $multiplier = match (strtoupper($matches[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
        return (int) $matches[1] * $multiplier;
    }
}
