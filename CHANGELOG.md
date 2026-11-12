# Changelog

## Unreleased

## 1.0.0

- First stable release. The code is the same as in 0.6.0.
- Not part of 1.0.0: radio buttons and select as filter controls, a canonical link on filtered pages, Bricks query loops, Breakdance product lists outside the shop template and the `[products]` shortcode.

## 0.6.0

- Added updates from GitHub releases. A new release shows up on the WordPress Plugins and Updates screens, with its notes under "View details". Before WordPress installs it, the package is compared with its published SHA-256 checksum.
- Added `npm test`: checks of filtering, counts, settings and updates inside a real WordPress with WooCommerce. On GitHub they run in WordPress Playground and on MariaDB.
- Added `npm run package` and a release job: pushing a `v<version>` tag publishes the ZIP and its checksum.

## 0.5.0

- Added a display setting for taxonomy filters: checkboxes that filter the list, or links that lead to each term's own page, with a link back to all products on those pages. Set it on the settings page, with `wp sffilters display` or through the abilities.
- Buttons (mobile "Filters" button, "Show products", "Load more") now have a plain style of their own where the theme styles none, as in Breakdance. A theme that styles `.button` keeps its look.
- Confirmed with the Breakdance "Shop Page" element: filtering, selected filters, infinite scroll, the mobile drawer and add to cart after a swap.
- Fixed a PHP warning for a filter definition without a label.

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
