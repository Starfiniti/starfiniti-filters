<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Catalog;

use InvalidArgumentException;

final class Money
{
    public static function toMinor(?string $decimal, int $scale): ?int
    {
        if ($decimal === null || trim($decimal) === '') {
            return null;
        }
        if ($scale < 0 || $scale > 6) {
            throw new InvalidArgumentException('Currency scale must be between zero and six.');
        }

        $value = trim($decimal);
        if (!preg_match('/^(?<sign>-?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Invalid decimal money value.');
        }
        if ($matches['sign'] === '-') {
            throw new InvalidArgumentException('Catalog prices cannot be negative.');
        }

        $fraction = str_pad(substr($matches['fraction'] ?? '', 0, $scale), $scale, '0');
        $minor = ((int) $matches['whole'] * (10 ** $scale)) + (int) ($fraction === '' ? '0' : $fraction);
        return $minor;
    }
}

