<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Storefront;

use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;

final class SearchShortcode
{
    private static bool $configurationAdded = false;

    public function __construct(private readonly ?ConfigurationRepository $configuration = null)
    {
    }

    public function register(): void
    {
        add_shortcode('starfiniti_search', [$this, 'render']);
        add_shortcode('starfiniti_discovery', [$this, 'renderDiscovery']);
        add_shortcode('starfiniti_product_search', [$this, 'render']);
        wp_register_style('starfiniti-search', plugins_url('assets/search.css', STARFINITI_SEARCH_FILE), [], STARFINITI_SEARCH_VERSION);
        wp_register_script('starfiniti-discovery-state', plugins_url('assets/discovery-state.js', STARFINITI_SEARCH_FILE), [], STARFINITI_SEARCH_VERSION, true);
        wp_register_script('starfiniti-search', plugins_url('assets/search.js', STARFINITI_SEARCH_FILE), ['starfiniti-discovery-state'], STARFINITI_SEARCH_VERSION, true);
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
        $fields = array_column($this->configuration?->current()?->toArray()['discovery']['filters'] ?? [], 'field');
        $defaultFilters = '';
        if (!in_array('inventory.stock_status', $fields, true)) {
            $defaultFilters .= '<fieldset data-sfs-stock><legend>' . esc_html__('Availability', 'starfiniti-search') . '</legend><div data-sfs-stock-options><label><input type="checkbox" name="sfs_stock" value="instock"> ' . esc_html__('In stock', 'starfiniti-search') . '</label><label><input type="checkbox" name="sfs_stock" value="outofstock"> ' . esc_html__('Out of stock', 'starfiniti-search') . '</label></div></fieldset>';
        }
        if (array_intersect($fields, ['classification.category_paths', 'classification.category_ids']) === []) {
            $defaultFilters .= '<fieldset data-sfs-categories><legend>' . esc_html__('Categories', 'starfiniti-search') . '</legend><div data-sfs-category-options></div></fieldset>';
        }
        return sprintf(
            '<section class="sfs-discovery" data-starfiniti-discovery aria-labelledby="%1$s-title">
              <h2 id="%1$s-title">%2$s</h2>
              <form class="sfs-discovery__controls" role="search" method="get" action="%3$s">
                <div class="sfs-discovery__toolbar">
                  <div class="sfs-discovery__query"><label for="%1$s-query">%4$s</label><div class="sfs-discovery__query-field"><input id="%1$s-query" name="s" type="search" autocomplete="off"><input type="hidden" name="post_type" value="product"><button type="submit">%5$s</button></div></div>
                  <label class="sfs-discovery__sort">%6$s<select name="sfs_sort"><option value="relevance">%7$s</option><option value="price_asc">%8$s</option><option value="price_desc">%9$s</option><option value="title_asc">%10$s</option></select></label>
                  <button class="sfs-discovery__filter-toggle" type="button" data-sfs-open-filters aria-controls="%1$s-filters" aria-expanded="false" hidden>%11$s</button>
                </div>
                <dialog id="%1$s-filters" class="sfs-discovery__panel" data-sfs-panel aria-labelledby="%1$s-filter-title" open>
                  <div class="sfs-discovery__panel-heading"><h3 id="%1$s-filter-title">%11$s</h3><button type="button" data-sfs-close-filters hidden>%12$s</button></div>
                  <div class="sfs-discovery__filter-groups">%13$s</div>
                  <div class="sfs-discovery__panel-footer"><p data-sfs-panel-status role="status" aria-live="polite"></p><button type="button" class="sfs-discovery__clear" data-sfs-panel-clear>%16$s</button><button type="button" data-sfs-show-results>%14$s</button></div>
                </dialog>
                <div class="sfs-discovery__catalog">
                  <div class="sfs-discovery__selection" data-sfs-selection hidden><div class="sfs-discovery__chips" data-sfs-chips aria-label="%15$s"></div><button type="button" class="sfs-discovery__clear" data-sfs-clear>%16$s</button></div>
                  <div id="%1$s-status" class="sfs-discovery__status" data-sfs-status role="status" aria-live="polite"></div>
                  <div class="sfs-discovery__notice" data-sfs-notice hidden><p data-sfs-notice-text></p><button type="button" data-sfs-retry hidden>%17$s</button><button type="button" data-sfs-reset hidden>%18$s</button></div>
                  <div class="sfs-discovery__results" data-sfs-results aria-busy="false"></div>
                  <nav class="sfs-discovery__pagination" aria-label="%19$s" hidden><button type="button" data-sfs-previous>%20$s</button><span data-sfs-page></span><button type="button" data-sfs-next>%21$s</button></nav>
                </div>
              </form><noscript><p>%22$s</p></noscript>
            </section>',
            esc_attr($id), esc_html($title !== '' ? $title : __('Product discovery', 'starfiniti-search')), esc_url(home_url('/')),
            esc_html__('Search products', 'starfiniti-search'), esc_html__('Search', 'starfiniti-search'), esc_html__('Sort by', 'starfiniti-search'),
            esc_html__('Relevance', 'starfiniti-search'), esc_html__('Price: low to high', 'starfiniti-search'), esc_html__('Price: high to low', 'starfiniti-search'), esc_html__('Name', 'starfiniti-search'),
            esc_html__('Filters', 'starfiniti-search'), esc_html__('Close filters', 'starfiniti-search'), $defaultFilters . $this->configuredFilters($id),
            esc_html__('Show results', 'starfiniti-search'), esc_attr__('Selected filters', 'starfiniti-search'), esc_html__('Clear filters', 'starfiniti-search'),
            esc_html__('Try again', 'starfiniti-search'), esc_html__('Reset search', 'starfiniti-search'), esc_attr__('Product result pages', 'starfiniti-search'),
            esc_html__('Previous', 'starfiniti-search'), esc_html__('Next', 'starfiniti-search'), esc_html__('JavaScript is unavailable. Submit the search form to use the normal WooCommerce product search.', 'starfiniti-search')
        );
    }

    private function configuredFilters(string $id): string
    {
        $filters = $this->configuration?->current()?->toArray()['discovery']['filters'] ?? [];
        $markup = '';
        foreach ($filters as $filter) {
            $controlId = $id . '-filter-' . $filter['id'];
            $markup .= sprintf(
                '<fieldset data-sfs-filter="%1$s" data-sfs-field="%2$s" data-sfs-parameter="%3$s" data-sfs-type="%4$s"><legend>%5$s</legend>',
                esc_attr($filter['id']), esc_attr($filter['field']), esc_attr($filter['parameter']), esc_attr($filter['type']), esc_html($filter['label'])
            );
            if ($filter['type'] === 'range') {
                $decimals = max(0, min(6, (int) wc_get_price_decimals()));
                $step = number_format(10 ** -$decimals, $decimals, '.', '');
                $markup .= sprintf(
                    '<p class="sfs-discovery__hint">%5$s</p><div class="sfs-discovery__range"><label for="%1$s-min">%2$s<input id="%1$s-min" type="number" min="0" step="%4$s" inputmode="decimal" data-sfs-min></label><label for="%1$s-max">%3$s<input id="%1$s-max" type="number" min="0" step="%4$s" inputmode="decimal" data-sfs-max></label></div><p id="%1$s-error" class="sfs-discovery__field-error" data-sfs-range-error hidden></p>',
                    esc_attr($controlId), esc_html__('Minimum', 'starfiniti-search'), esc_html__('Maximum', 'starfiniti-search'), esc_attr($step), esc_html(get_woocommerce_currency())
                );
            } elseif ($filter['type'] === 'select') {
                $markup .= sprintf('<select data-sfs-filter-options aria-label="%1$s"><option value="">%2$s</option></select>', esc_attr($filter['label']), esc_html__('All', 'starfiniti-search'));
            } else {
                $markup .= '<div data-sfs-filter-options></div>';
            }
            $markup .= '</fieldset>';
        }
        return $markup;
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
                'all' => __('All', 'starfiniti-search'),
                'inStock' => __('In stock', 'starfiniti-search'), 'outOfStock' => __('Out of stock', 'starfiniti-search'), 'onBackorder' => __('On backorder', 'starfiniti-search'),
                'featured' => __('Featured', 'starfiniti-search'), 'notFeatured' => __('Not featured', 'starfiniti-search'),
                'filters' => __('Filters', 'starfiniti-search'), 'categories' => __('Categories', 'starfiniti-search'),
                /* translators: %s is the selected filter label. */
                'removeFilter' => __('Remove %s', 'starfiniti-search'),
                'emptyHint' => __('Try removing a filter or changing your search.', 'starfiniti-search'),
                'unavailableHint' => __('Your filters are saved. Try loading the products again.', 'starfiniti-search'),
                'priceError' => __('Enter valid prices with the minimum no greater than the maximum.', 'starfiniti-search'),
                'invalidFilters' => __('Check the price range: use valid non-negative prices, with the minimum no greater than the maximum.', 'starfiniti-search'),
            ],
            'mobileMode' => 'overlay',
            'locale' => determine_locale(),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'currencyMinorUnit' => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2,
        ]) . ';', 'before');
    }
}
