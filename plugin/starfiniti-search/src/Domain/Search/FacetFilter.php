<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Search;

/** Remove only positive, conjunctive shopper selections for an alternative count. */
final class FacetFilter
{
    /** @param array<string,mixed>|null $filter @return array<string,mixed>|null */
    public static function withoutSelection(?array $filter, string $field): ?array
    {
        if ($filter === null) {
            return null;
        }
        if (isset($filter['and']) && count($filter) === 1) {
            $children = [];
            foreach ($filter['and'] as $child) {
                $remaining = self::withoutSelection($child, $field);
                if ($remaining !== null) {
                    $children[] = $remaining;
                }
            }
            return match (count($children)) {
                0 => null,
                1 => $children[0],
                default => ['and' => $children],
            };
        }
        // Never relax OR, NOT or negative restrictions. Server visibility and
        // curation filters are supplied separately and must not pass through here.
        return ($filter['field'] ?? null) === $field && in_array($filter['op'] ?? '', ['eq', 'in'], true) ? null : $filter;
    }
}
