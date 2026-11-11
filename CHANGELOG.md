# Changelog

## Unreleased

## 0.2.0

- Added filtering of the WooCommerce shop and product archive pages by category, brand, tag, attribute, price range, stock status and on sale. Filter state lives in the URL as `sf_<slug>` parameters.
- Added the `[sffilters]` panel with counts next to every choice. Counts come from the WordPress and WooCommerce tables.
- Added background loading: products, result count, pagination and the panel swap without a page reload. Without JavaScript the panel is a normal form.
- Filtered pages are `noindex`.

## 0.1.0

- Restarted as a small, filters-only plugin. The earlier search and filtering candidate is frozen on the `codex/resume-infrastructure` branch and the `archive/enterprise-2026-09` tag.
- Added the plugin skeleton, a WordPress Playground store with a demo catalog, and a PHP lint check.
