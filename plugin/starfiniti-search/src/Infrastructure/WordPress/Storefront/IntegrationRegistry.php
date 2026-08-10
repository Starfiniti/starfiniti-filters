<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Storefront;

use RuntimeException;

final class IntegrationRegistry
{
    /** @return list<array<string,mixed>> */
    public function entries(): array
    {
        $path = STARFINITI_SEARCH_DIR . '/config/integrations.json';
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($raw) || strlen($raw) > 262144) {
            throw new RuntimeException('Storefront integration registry is unavailable or oversized.');
        }
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1 || !is_array($decoded['integrations'] ?? null) || !array_is_list($decoded['integrations'])) {
            throw new RuntimeException('Storefront integration registry contract is invalid.');
        }
        $ids = [];
        foreach ($decoded['integrations'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Storefront integration entry is invalid.');
            }
            foreach (['integration_id', 'theme_or_plugin', 'supported_version_range', 'detection', 'assets', 'dom_strategy', 'known_limitations', 'test_evidence', 'last_verified', 'status'] as $field) {
                if (!array_key_exists($field, $entry)) {
                    throw new RuntimeException('Storefront integration entry is incomplete.');
                }
            }
            $id = (string) $entry['integration_id'];
            if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $id) !== 1 || isset($ids[$id])) {
                throw new RuntimeException('Storefront integration identifier is invalid or duplicated.');
            }
            $ids[$id] = true;
        }
        return array_values($decoded['integrations']);
    }

    /** @return list<array<string,mixed>> */
    public function detected(): array
    {
        $theme = wp_get_theme();
        $slugs = array_filter([(string) $theme->get_stylesheet(), (string) $theme->get_template()]);
        $activePlugins = array_map(static fn (string $plugin): string => dirname($plugin), (array) get_option('active_plugins', []));
        $detected = [];
        foreach ($this->entries() as $entry) {
            $values = array_map('strval', (array) ($entry['detection']['values'] ?? []));
            if (array_intersect($values, [...$slugs, ...$activePlugins]) !== []) {
                $detected[] = $entry;
            }
        }
        return $detected;
    }
}
