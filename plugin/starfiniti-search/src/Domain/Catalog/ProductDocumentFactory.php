<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Catalog;

final class ProductDocumentFactory
{
    public function create(ProductSnapshot $source): SearchDocument
    {
        return new SearchDocument([
            'contract_version' => '1.0',
            'schema_version' => 1,
            'document_id' => $source->entityType . ':' . $source->blogId . ':' . $source->entityId . ':' . $source->locale,
            'entity_type' => $source->entityType,
            'entity_id' => $source->entityId,
            'parent_id' => $source->parentId,
            'site_id' => $source->siteId,
            'blog_id' => $source->blogId,
            'locale' => $source->locale,
            'channel' => $source->channel,
            'status' => $source->status,
            'visibility' => [
                'catalog' => $source->catalogVisible,
                'search' => $source->searchVisible,
                'password_protected' => $source->passwordProtected,
                'scope_tokens' => array_values(array_unique($source->scopeTokens)),
            ],
            'identity' => [
                'title' => $source->title,
                'slug' => $source->slug,
                'url' => $source->url,
                'sku' => $source->sku,
                'gtin' => $source->gtin,
                'other_identifiers' => [],
            ],
            'content' => [
                'short_description_text' => $source->shortDescription,
                'description_text' => $source->description,
                'search_keywords' => $source->searchKeywords,
                'excerpt' => $source->excerpt,
            ],
            'classification' => [
                'category_ids' => $source->categoryIds,
                'category_paths' => $source->categoryPaths,
                'tag_ids' => $source->tagIds,
                'brands' => [],
                'taxonomies' => [],
            ],
            'attributes' => $source->attributes,
            'pricing' => [
                'currency' => $source->currency,
                'regular_min_minor' => $source->regularPriceMinor,
                'regular_max_minor' => $source->regularPriceMinor,
                'sale_min_minor' => $source->salePriceMinor,
                'sale_max_minor' => $source->salePriceMinor,
                'active_min_minor' => $source->activePriceMinor,
                'active_max_minor' => $source->activePriceMinor,
                'tax_display_mode' => $source->taxDisplayMode,
                'price_scope' => $source->priceScope,
            ],
            'inventory' => [
                'stock_status' => $source->stockStatus,
                'quantity' => $source->quantity,
                'backorders' => $source->backorders,
                'purchasable' => $source->purchasable,
                'searchable' => $source->inventorySearchable,
            ],
            'media' => [
                'primary_image_id' => $source->imageId,
                'primary_image_url' => $source->imageUrl,
                'thumbnail_url' => $source->thumbnailUrl,
                'alt' => $source->imageAlt,
            ],
            'quality' => [
                'average_rating_scaled' => $source->averageRatingScaled,
                'rating_count' => $source->ratingCount,
                'sales_count' => $source->salesCount,
                'menu_order' => $source->menuOrder,
                'featured' => $source->featured,
            ],
            'variation' => [
                'strategy' => $source->entityType === 'variation' ? 'variation_as_result' : 'not_applicable',
                'attribute_signature' => [],
                'matching_variation_ids' => [],
            ],
            'custom' => $source->custom,
            'timestamps' => [
                'created_at' => $source->createdAt,
                'modified_at' => $source->modifiedAt,
                'source_observed_at' => $source->observedAt,
            ],
        ]);
    }
}
