<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Configuration;

use InvalidArgumentException;

/** Presentation and URL bindings for already-indexed, public filter fields. */
final class DiscoveryConfiguration
{
    /** @param array<string,mixed> $data */
    public static function validate(array $data): void
    {
        if (array_diff(array_keys($data), ['filters']) !== []
            || !isset($data['filters']) || !is_array($data['filters'])
            || !array_is_list($data['filters']) || count($data['filters']) > 14) {
            // Reserve two of the canonical request's sixteen facets for the default controls.
            throw new InvalidArgumentException('Discovery requires at most fourteen ordered filters.');
        }
        $ids = [];
        $parameters = [];
        foreach ($data['filters'] as $filter) {
            if (!is_array($filter) || array_diff(array_keys($filter), ['id', 'field', 'label', 'type', 'parameter']) !== []
                || count($filter) !== 5) {
                throw new InvalidArgumentException('A discovery filter requires id, field, label, type and parameter.');
            }
            foreach (['id', 'field', 'label', 'type', 'parameter'] as $key) {
                if (!is_string($filter[$key] ?? null)) {
                    throw new InvalidArgumentException('Discovery filter properties must be strings.');
                }
            }
            if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $filter['id']) !== 1
                || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $filter['parameter']) !== 1
                || isset($ids[$filter['id']]) || isset($parameters[$filter['parameter']])
                || in_array($filter['parameter'], ['s', 'q', 'page', 'paged', 'post_type', 'sfs_sort', 'sfs_page', 'orderby', 'lang', 'currency', 'rest_route', 'preview', 'sfs_stock', 'sfs_category'], true)) {
                throw new InvalidArgumentException('Discovery filter IDs and URL parameters must be unique and non-reserved.');
            }
            if ($filter['label'] === '' || trim($filter['label']) !== $filter['label']
                || mb_strlen($filter['label'], 'UTF-8') > 120 || !mb_check_encoding($filter['label'], 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f<>]/', $filter['label']) === 1) {
                throw new InvalidArgumentException('A discovery label must be bounded plain text.');
            }
            $range = $filter['field'] === 'pricing.active_min_minor';
            if (!self::supportsField($filter['field'])
                || ($range && $filter['type'] !== 'range')
                || (!$range && !in_array($filter['type'], ['checkboxes', 'radio', 'select'], true))) {
                throw new InvalidArgumentException('Discovery filter field and control type are incompatible.');
            }
            $ids[$filter['id']] = true;
            $parameters[$filter['parameter']] = true;
        }
    }

    public static function supportsField(string $field): bool
    {
        return in_array($field, ['pricing.active_min_minor', 'inventory.stock_status', 'quality.featured', 'classification.category_ids', 'classification.category_paths'], true)
            || preg_match('/\Aattributes\.[a-z0-9_-]{1,120}\z/', $field) === 1;
    }
}
