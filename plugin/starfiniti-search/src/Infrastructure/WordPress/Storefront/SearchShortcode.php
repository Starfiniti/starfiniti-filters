<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Storefront;

final class SearchShortcode
{
    private static bool $configurationAdded = false;

    public function register(): void
    {
        add_shortcode('starfiniti_search', [$this, 'render']);
        add_shortcode('starfiniti_discovery', [$this, 'renderDiscovery']);
        add_shortcode('starfiniti_product_search', [$this, 'render']);
        wp_register_style('starfiniti-search', plugins_url('assets/search.css', STARFINITI_SEARCH_FILE), [], STARFINITI_SEARCH_VERSION);
        wp_register_script('starfiniti-search', plugins_url('assets/search.js', STARFINITI_SEARCH_FILE), [], STARFINITI_SEARCH_VERSION, true);
        register_block_type(STARFINITI_SEARCH_DIR . '/blocks/search');
        register_block_type(STARFINITI_SEARCH_DIR . '/blocks/discovery');
        register_block_type(STARFINITI_SEARCH_DIR . '/blocks/navigation-search');
        if (did_action('widgets_init')) {
            register_widget(SearchWidget::class);
        } else {
            add_action('widgets_init', static function (): void {
                register_widget(SearchWidget::class);
            });
        }
    }

    /** @param array<string,mixed> $attributes */
    public function render(array $attributes = []): string
    {
        $this->enqueue();
        $attributes = shortcode_atts(['label' => __('Search products', 'starfiniti-search'), 'placeholder' => __('Search products…', 'starfiniti-search')], $attributes, 'starfiniti_search');
        $label = mb_substr(sanitize_text_field((string) $attributes['label']), 0, 120);
        $placeholder = mb_substr(sanitize_text_field((string) $attributes['placeholder']), 0, 120);
        $id = wp_unique_id('starfiniti-search-');
        return sprintf(
            '<form class="sfs-search" data-starfiniti-search role="search" method="get" action="%4$s"><label class="screen-reader-text" for="%1$s">%2$s</label><input id="%1$s" name="s" class="sfs-search__input" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="%1$s-results" autocomplete="off" placeholder="%3$s"><button class="sfs-search__close" type="button" data-sfs-close hidden>%5$s</button><input type="hidden" name="post_type" value="product"><button class="screen-reader-text" type="submit">%2$s</button><div id="%1$s-status" class="screen-reader-text" role="status" aria-live="polite"></div><ul id="%1$s-results" class="sfs-search__results" role="listbox" hidden></ul></form>',
            esc_attr($id),
            esc_html($label !== '' ? $label : __('Search products', 'starfiniti-search')),
            esc_attr($placeholder !== '' ? $placeholder : __('Search products…', 'starfiniti-search')),
            esc_url(home_url('/')),
            esc_html__('Close search', 'starfiniti-search')
        );
    }

    /** @param array<string,mixed> $attributes */
    public function renderDiscovery(array $attributes = []): string
    {
        $this->enqueue();
        $attributes = shortcode_atts(['title' => __('Product discovery', 'starfiniti-search')], $attributes, 'starfiniti_discovery');
        $title = mb_substr(sanitize_text_field((string) $attributes['title']), 0, 120);
        $id = wp_unique_id('starfiniti-discovery-');
        return sprintf(
            '<section class="sfs-discovery" data-starfiniti-discovery aria-labelledby="%1$s-title"><h2 id="%1$s-title">%2$s</h2><form class="sfs-discovery__controls" role="search" method="get" action="%3$s"><div class="sfs-discovery__query"><label for="%1$s-query">%4$s</label><input id="%1$s-query" name="s" type="search" autocomplete="off"><input type="hidden" name="post_type" value="product"><button type="submit">%5$s</button></div><fieldset data-sfs-stock><legend>%6$s</legend><label><input type="checkbox" name="sfs_stock" value="instock"> %7$s</label><label><input type="checkbox" name="sfs_stock" value="outofstock"> %8$s</label></fieldset><fieldset data-sfs-categories><legend>%9$s</legend><div data-sfs-category-options></div></fieldset><label class="sfs-discovery__sort">%10$s <select name="sfs_sort"><option value="relevance">%11$s</option><option value="price_asc">%12$s</option><option value="price_desc">%13$s</option><option value="title_asc">%14$s</option></select></label><button type="button" data-sfs-clear>%15$s</button></form><div id="%1$s-status" class="sfs-discovery__status" role="status" aria-live="polite"></div><div class="sfs-discovery__results" data-sfs-results aria-busy="false"></div><nav class="sfs-discovery__pagination" aria-label="%16$s" hidden><button type="button" data-sfs-previous>%17$s</button><span data-sfs-page></span><button type="button" data-sfs-next>%18$s</button></nav><noscript><p>%19$s</p></noscript></section>',
            esc_attr($id), esc_html($title !== '' ? $title : __('Product discovery', 'starfiniti-search')), esc_url(home_url('/')),
            esc_html__('Search products', 'starfiniti-search'), esc_html__('Search', 'starfiniti-search'),
            esc_html__('Availability', 'starfiniti-search'), esc_html__('In stock', 'starfiniti-search'), esc_html__('Out of stock', 'starfiniti-search'),
            esc_html__('Categories', 'starfiniti-search'), esc_html__('Sort by', 'starfiniti-search'), esc_html__('Relevance', 'starfiniti-search'),
            esc_html__('Price: low to high', 'starfiniti-search'), esc_html__('Price: high to low', 'starfiniti-search'), esc_html__('Name', 'starfiniti-search'),
            esc_html__('Clear filters', 'starfiniti-search'), esc_attr__('Product result pages', 'starfiniti-search'), esc_html__('Previous', 'starfiniti-search'),
            esc_html__('Next', 'starfiniti-search'), esc_html__('JavaScript is unavailable. Submit the search form to use the normal WooCommerce product search.', 'starfiniti-search')
        );
    }

    private function enqueue(): void
    {
        wp_enqueue_style('starfiniti-search');
        wp_enqueue_script('starfiniti-search');
        if (self::$configurationAdded) {
            return;
        }
        self::$configurationAdded = true;
        wp_add_inline_script('starfiniti-search', 'window.StarfinitiSearchConfig=' . wp_json_encode([
            'endpoint' => esc_url_raw(rest_url('starfiniti-search/v1/search')),
            'messages' => [
                'loading' => __('Searching…', 'starfiniti-search'), 'empty' => __('No products found.', 'starfiniti-search'),
                'error' => __('Search is temporarily unavailable.', 'starfiniti-search'),
                /* translators: %d is the number of products returned by search. */
                'results' => __('%d products found.', 'starfiniti-search'),
                'mobileSearch' => __('Product search', 'starfiniti-search'), 'details' => __('Product details', 'starfiniti-search'),
                'viewDetails' => __('View details', 'starfiniti-search'), 'hideDetails' => __('Hide details', 'starfiniti-search'),
                'viewProduct' => __('View product', 'starfiniti-search'), 'availability' => __('Availability', 'starfiniti-search'),
            ],
            'mobileMode' => 'overlay',
            'locale' => determine_locale(),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'currencyMinorUnit' => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2,
        ]) . ';', 'before');
    }
}
