<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Catalog;

use DateTimeInterface;
use Starfiniti\Search\Domain\Catalog\Money;
use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Catalog\ProductSnapshot;
use Starfiniti\Search\Domain\Catalog\SearchDocument;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use WC_Product;

final class WooProductSource
{
    public function __construct(
        private readonly ProductDocumentFactory $factory,
        private readonly ?ConfigurationRepository $configuration = null
    )
    {
    }

    public function get(int $productId): ?SearchDocument
    {
        $post = get_post($productId);
        if (!$post
            || !in_array($post->post_type, ['product', 'product_variation'], true)
            || !in_array($post->post_status, ['publish', 'private', 'draft', 'pending'], true)) {
            return null;
        }

        $product = wc_get_product($productId);
        if (!$product instanceof WC_Product) {
            return null;
        }

        $decimals = (int) wc_get_price_decimals();
        $visibility = $product->get_catalog_visibility('edit');
        $published = $product->get_status('edit') === 'publish';
        $passwordProtected = $post->post_password !== '';
        $searchVisible = $published && !$passwordProtected && in_array($visibility, ['visible', 'search'], true);
        $catalogVisible = $published && !$passwordProtected && in_array($visibility, ['visible', 'catalog'], true);
        $stockSearchable = !(get_option('woocommerce_hide_out_of_stock_items') === 'yes' && $product->get_stock_status('edit') === 'outofstock');

        $categoryIds = array_values(array_map('intval', $product->get_category_ids('edit')));
        $categoryPaths = [];
        foreach ($categoryIds as $categoryId) {
            $term = get_term($categoryId, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $ancestors = array_reverse(get_ancestors($categoryId, 'product_cat'));
                $names = [];
                foreach ($ancestors as $ancestorId) {
                    $ancestor = get_term($ancestorId, 'product_cat');
                    if ($ancestor && !is_wp_error($ancestor)) {
                        $names[] = $this->text((string) $ancestor->name);
                    }
                }
                $names[] = $this->text((string) $term->name);
                $categoryPaths[] = implode(' > ', $names);
            }
        }

        $attributes = [];
        foreach ($product->get_attributes() as $attribute) {
            $name = sanitize_key($attribute->get_name());
            $values = $attribute->is_taxonomy()
                ? wc_get_product_terms($productId, $attribute->get_name(), ['fields' => 'names'])
                : $attribute->get_options();
            $attributes[$name] = array_values(array_map('strval', is_array($values) ? $values : []));
        }

        $imageId = (int) $product->get_image_id('edit');
        $globalId = method_exists($product, 'get_global_unique_id') ? $product->get_global_unique_id('edit') : null;
        $entityType = $product->is_type('variation') ? 'variation' : 'product';
        $created = $product->get_date_created('edit');
        $modified = $product->get_date_modified('edit');
        $now = gmdate(DateTimeInterface::ATOM);

        return $this->factory->create(new ProductSnapshot(
            entityId: $productId,
            parentId: $product->get_parent_id('edit') ?: null,
            siteId: (string) get_option('starfiniti_search_installation_uuid'),
            blogId: get_current_blog_id(),
            locale: determine_locale(),
            channel: 'storefront',
            status: $product->get_status('edit'),
            catalogVisible: $catalogVisible,
            searchVisible: $searchVisible,
            passwordProtected: $passwordProtected,
            scopeTokens: $searchVisible ? ['public'] : ['restricted'],
            title: $this->text($product->get_name('edit')),
            slug: $product->get_slug('edit'),
            url: get_permalink($productId) ?: home_url('/?p=' . $productId),
            sku: ($product->get_sku('edit') ?: null),
            gtin: ($globalId ?: null),
            shortDescription: $this->text($product->get_short_description('edit')),
            description: $this->text($product->get_description('edit')),
            excerpt: $this->text($product->get_short_description('edit')),
            searchKeywords: [],
            categoryIds: $categoryIds,
            categoryPaths: $categoryPaths,
            tagIds: array_values(array_map('intval', $product->get_tag_ids('edit'))),
            attributes: $attributes,
            currency: get_woocommerce_currency(),
            regularPriceMinor: Money::toMinor($product->get_regular_price('edit') ?: null, $decimals),
            salePriceMinor: Money::toMinor($product->get_sale_price('edit') ?: null, $decimals),
            activePriceMinor: Money::toMinor($product->get_price('edit') ?: null, $decimals),
            taxDisplayMode: get_option('woocommerce_tax_display_shop') === 'incl' ? 'inclusive' : 'exclusive',
            priceScope: 'public:' . get_woocommerce_currency(),
            stockStatus: in_array($product->get_stock_status('edit'), ['instock', 'outofstock', 'onbackorder'], true) ? $product->get_stock_status('edit') : 'unknown',
            quantity: $product->get_stock_quantity('edit') !== null ? (float) $product->get_stock_quantity('edit') : null,
            backorders: in_array($product->get_backorders('edit'), ['no', 'notify', 'yes'], true) ? $product->get_backorders('edit') : 'unknown',
            purchasable: $product->is_purchasable(),
            inventorySearchable: $stockSearchable,
            imageId: $imageId > 0 ? $imageId : null,
            imageUrl: $imageId > 0 ? (wp_get_attachment_image_url($imageId, 'full') ?: null) : null,
            thumbnailUrl: $imageId > 0 ? (wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail') ?: null) : null,
            imageAlt: $imageId > 0 ? (string) get_post_meta($imageId, '_wp_attachment_image_alt', true) : '',
            averageRatingScaled: (int) round((float) $product->get_average_rating('edit') * 2000),
            ratingCount: max(0, $product->get_rating_count('edit')),
            salesCount: max(0, (int) $product->get_total_sales('edit')),
            menuOrder: (int) $product->get_menu_order('edit'),
            featured: $product->get_featured('edit'),
            createdAt: $created ? $created->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM) : $now,
            modifiedAt: $modified ? $modified->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM) : $now,
            observedAt: $now,
            custom: $this->customFields($productId),
            entityType: $entityType
        ));
    }

    /** @return array<string, string|int|float|bool> */
    private function customFields(int $productId): array
    {
        $configuration = $this->configuration?->current()?->toArray();
        $rules = $configuration['catalog']['custom_fields'] ?? [];
        $custom = [];
        foreach ($rules as $rule) {
            $metaKey = (string) ($rule['meta_key'] ?? '');
            if ($metaKey === '' || !metadata_exists('post', $productId, $metaKey)) {
                continue;
            }
            $value = $this->typedMeta(get_post_meta($productId, $metaKey, true), (string) ($rule['type'] ?? ''));
            if ($value !== null) {
                $custom[(string) $rule['field']] = $value;
            }
        }
        return $custom;
    }

    private function typedMeta(mixed $value, string $type): string|int|float|bool|null
    {
        if ($type === 'string' && is_scalar($value)) {
            return mb_substr($this->text((string) $value), 0, 2048);
        }
        if ($type === 'integer' && (is_int($value) || (is_string($value) && preg_match('/^-?\\d+$/', $value) === 1))) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            return $validated === false ? null : $validated;
        }
        if ($type === 'number' && is_numeric($value)) {
            $number = (float) $value;
            return is_finite($number) ? $number : null;
        }
        if ($type === 'boolean' && (is_bool($value) || is_scalar($value))) {
            $normalized = strtolower(trim((string) $value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return null;
    }

    private function text(string $html): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($html, true), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
