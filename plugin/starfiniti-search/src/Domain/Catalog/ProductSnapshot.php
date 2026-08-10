<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Catalog;

final readonly class ProductSnapshot
{
    /**
     * @param list<int> $categoryIds
     * @param list<string> $categoryPaths
     * @param list<int> $tagIds
     * @param array<string, list<string|int|float|bool>> $attributes
     * @param array<string, string|int|float|bool> $custom
     * @param list<string> $searchKeywords
     * @param list<string> $scopeTokens
     */
    public function __construct(
        public int $entityId,
        public ?int $parentId,
        public string $siteId,
        public int $blogId,
        public string $locale,
        public string $channel,
        public string $status,
        public bool $catalogVisible,
        public bool $searchVisible,
        public bool $passwordProtected,
        public array $scopeTokens,
        public string $title,
        public string $slug,
        public string $url,
        public ?string $sku,
        public ?string $gtin,
        public string $shortDescription,
        public string $description,
        public string $excerpt,
        public array $searchKeywords,
        public array $categoryIds,
        public array $categoryPaths,
        public array $tagIds,
        public array $attributes,
        public string $currency,
        public ?int $regularPriceMinor,
        public ?int $salePriceMinor,
        public ?int $activePriceMinor,
        public string $taxDisplayMode,
        public string $priceScope,
        public string $stockStatus,
        public ?float $quantity,
        public string $backorders,
        public bool $purchasable,
        public bool $inventorySearchable,
        public ?int $imageId,
        public ?string $imageUrl,
        public ?string $thumbnailUrl,
        public string $imageAlt,
        public int $averageRatingScaled,
        public int $ratingCount,
        public int $salesCount,
        public int $menuOrder,
        public bool $featured,
        public string $createdAt,
        public string $modifiedAt,
        public string $observedAt,
        public array $custom = [],
        public string $entityType = 'product'
    ) {
    }
}
