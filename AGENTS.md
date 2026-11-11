# Starfiniti Filters: working rules

Read this before changing anything.

## What this plugin is

A small WooCommerce plugin that filters the shop the theme already renders. It replaces FiboFilters on Starfiniti client stores. It is filters only. Search lives in a separate plugin.

## Keep it small

The previous attempt in this repository grew into an enterprise search platform and was never released. Do not repeat that.

- No external services. No Typesense, no hosted index, no remote MCP server.
- No custom database tables unless a measurement on a real catalog shows the WooCommerce tables are too slow. Record the measurement in the pull request.
- No build step. Plain PHP, plain JavaScript, plain CSS.
- No approval workflows, configuration history or analytics.
- A feature goes in only when a real store needs it.

## How it works

1. Filter state lives in the URL as `sf_<slug>` parameters, for example `?sf_brand=anker,xiaomi&sf_price=50-200`.
2. One function turns that state into standard `WP_Query` arguments (`tax_query`, `meta_query`). Every integration uses it.
3. Integrations attach it to the query that draws products: the WooCommerce main query, the `[products]` shortcode, Bricks query loops and Breakdance.
4. Counts come from one grouped SQL query per filter over the filtered product set.
5. `assets/filters.js` fetches the new URL and swaps the products, result count, pagination and panel. Without JavaScript every filter is a normal link or form.

## Where things are

- `src/Filter/` holds one class per filter source: taxonomy, price, stock, sale. A filter parses its URL value, returns query conditions and lists its choices with counts.
- `src/State.php` reads the filter values from the URL.
- `src/Query.php` turns state into `WP_Query` vars. Price, stock and sale go through the WooCommerce product lookup table.
- `src/Integration/` holds one class per product list the plugin can filter. `MainQuery` covers the WooCommerce shop and archive pages.
- `src/Panel.php` renders the `[sffilters]` form and the bar above the products (mobile button, selected filters). `assets/filters.js` swaps the results and the panel, and adds the drawer, the price slider and "Load more".
- `languages/` holds the Slovenian `.po` and the compiled `.mo`. Strings the script shows come from PHP through `sffiltersConfig.strings`.
- `src/Settings.php` reads and writes `sffilters_settings` and builds the filters. Every way of changing settings goes through `Settings::save()`: `src/Admin/SettingsPage.php` (WooCommerce > Filters, with `assets/admin.js`), `src/Cli.php` (`wp sffilters`) and `src/Abilities.php` (Abilities API, WordPress 6.9+).

## Conventions

- Namespace `Starfiniti\Filters`, PSR-4 under `src/`.
- Prefix `sffilters_` for options, hooks and handles. CSS classes start with `.sffilters-`.
- Text domain `starfiniti-filters`. Source strings in English, Slovenian translation in `languages/`.
- WordPress coding standards: tabs, Yoda conditions, escaped output, sanitized input, nonces and capability checks on every write.
- Settings are one option, `sffilters_settings`.
- Requirements: WordPress 6.6+, WooCommerce 9.6+, PHP 8.1+.

## Commands

- `npm run playground` starts the local store at `http://127.0.0.1:9410/shop/`.
- `npm run lint:php` checks PHP syntax.
- `npm run i18n` compiles `languages/*.po` into `.mo`.

## Git

Work on the `v1` branch until 1.0 is released. Commit and push after every finished step. The repository is public: never commit credentials, server addresses or client data.
