# Starfiniti Filters for WooCommerce

Product filters for the WooCommerce shop your theme already renders. The plugin adds filter conditions to the product query of the theme or page builder, so the shop keeps its own product cards, layout and pagination.

## Status

Version 0.2.0 filters the WooCommerce shop, category, tag and brand pages. Version 1.0 is in development on the `v1` branch.

Working now:

- Filters by category (as a tree), brand, tag, attribute, price range, stock status and on sale
- Counts next to every choice, recalculated for the current selection
- Products, result count, pagination and panel swap without a page reload, with working back and forward buttons
- Filtered pages are `noindex`

Not yet: page builder product lists (Breakdance, Bricks), the `[products]` shortcode, the settings page, mobile panel, price slider, load more and Slovenian.

## Usage

Put the `[sffilters]` shortcode where the panel should appear, for example in a sidebar widget or a page builder template of the shop page.

Until the settings page exists, the default filters are categories, brands, price, availability and on sale. To change them, store the list in the `sffilters_settings` option or use the `sffilters_filter_definitions` filter:

```php
add_filter(
	'sffilters_filter_definitions',
	static function ( array $definitions ): array {
		$definitions[] = array(
			'slug'     => 'color',
			'source'   => 'taxonomy',
			'taxonomy' => 'pa_color',
		);

		return $definitions;
	}
);
```

## How it works

1. A shopper picks a filter. The URL gets a parameter such as `?sf_brand=anker,xiaomi&sf_price=50-200`.
2. The plugin adds those conditions to the product query the theme already runs.
3. Counts next to each value come from WordPress and WooCommerce tables. There is no separate index to sync.
4. A small script loads the new URL in the background and swaps the products, result count, pagination and filter panel. Without JavaScript the panel is a normal form.

## Planned for 1.0

- Sources: categories, tags, brands, attributes, price, stock status, on sale
- Controls: checkboxes, radio buttons, select, price range, category tree
- Sidebar and mobile panel, selected filters with remove, clear all
- Pagination, load more and infinite scroll
- One settings page under WooCommerce, the same settings through WP-CLI and the WordPress Abilities API
- English and Slovenian
- Filtered pages are `noindex` with a canonical link to the unfiltered page

Not in 1.0: external search engines, search and autocomplete, SEO landing pages for filter combinations, per-variation stock filtering.

## Requirements

WordPress 6.6+, WooCommerce 9.6+, PHP 8.1+.

## Local development

Node 22+ is required for the local store. It runs in WordPress Playground with WooCommerce, the Storefront theme and a small demo catalog.

```bash
npm install
npm run playground
```

The store opens at `http://127.0.0.1:9410/shop/`.

```bash
npm run lint:php
```

## History

This repository previously held a larger search and filtering candidate. That work is frozen on the `codex/resume-infrastructure` branch and the `archive/enterprise-2026-09` tag.

## License

GPL-3.0-or-later. See `LICENSE`.
