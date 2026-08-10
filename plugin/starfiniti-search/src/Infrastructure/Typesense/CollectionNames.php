<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

use InvalidArgumentException;

final class CollectionNames
{
    public static function alias(string $installationUuid, string $environment, string $locale): string
    {
        return self::prefix($installationUuid, $environment) . '_products_' . self::segment($locale);
    }

    public static function versioned(string $installationUuid, string $environment, string $locale, int $schema, int $build): string
    {
        return self::versionedFromAlias(self::alias($installationUuid, $environment, $locale), $schema, $build);
    }

    public static function versionedFromAlias(string $alias, int $schema, int $build): string
    {
        if (preg_match('/^[a-z0-9_]{8,160}$/', $alias) !== 1 || $schema < 1 || $build < 1) {
            throw new InvalidArgumentException('Invalid versioned collection identity.');
        }
        return $alias . '_v' . $schema . '_' . $build;
    }

    private static function prefix(string $uuid, string $environment): string
    {
        return 'sfs_' . substr(hash('sha256', strtolower($uuid)), 0, 16) . '_' . self::segment($environment);
    }

    private static function segment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        if ($value === '' || strlen($value) > 40) {
            throw new InvalidArgumentException('Invalid collection-name segment.');
        }
        return $value;
    }
}
