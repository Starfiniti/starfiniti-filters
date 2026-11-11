# Changelog

## Unreleased

## 0.4.0

- Added the settings page under WooCommerce > Filters: add, title, reorder and remove filters, and choose page links, "Load more" or infinite scroll.
- Added WP-CLI commands: `wp sffilters list`, `sources`, `add`, `remove`, `move`, `label`, `pagination` and `reset`.
- Added two abilities for the WordPress Abilities API (WordPress 6.9+): `starfiniti-filters/get-settings` and `starfiniti-filters/update-settings`.
- A filter definition may name its taxonomy directly as `source`, for example `pa_color`.
- A store that saves an empty list of filters now shows no panel. Before, the default filters came back.

## 0.3.0

- Added a bar above the products with one removable chip per selected value and a "Clear all" link.
- Added the mobile panel: on screens up to 768px a "Filters" button opens the panel as a drawer.
- Added a two-handle price slider above the price inputs.
- Added "Load more" and infinite scroll as alternatives to page links, set with `pagination` in `sffilters_settings` or the `sffilters_pagination` filter. After Back from a product the loaded pages and the scroll position return.
- The sorting dropdown now reorders the list without a page reload.
- Added the Slovenian translation.

## 0.2.0

- Added filtering of the WooCommerce shop and product archive pages by category, brand, tag, attribute, price range, stock status and on sale. Filter state lives in the URL as `sf_<slug>` parameters.
- Added the `[sffilters]` panel with counts next to every choice. Counts come from the WordPress and WooCommerce tables.
- Added background loading: products, result count, pagination and the panel swap without a page reload. Without JavaScript the panel is a normal form.
- Filtered pages are `noindex`.

## 0.1.0

- Restarted as a small, filters-only plugin. The earlier search and filtering candidate is frozen on the `codex/resume-infrastructure` branch and the `archive/enterprise-2026-09` tag.
- Added the plugin skeleton, a WordPress Playground store with a demo catalog, and a PHP lint check.
