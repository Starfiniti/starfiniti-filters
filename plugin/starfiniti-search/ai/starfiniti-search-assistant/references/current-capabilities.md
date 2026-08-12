# Current customer capabilities

This reference matches Starfiniti Search `0.3.0-alpha.1`. It is a qualification build, not a released or production-ready product.

## Storefront surfaces available now

- `[starfiniti_search]` and block `starfiniti/search`: accessible product autocomplete with normal WooCommerce search submission as fallback.
- `[starfiniti_discovery]` and block `starfiniti/discovery`: product results with stock/category filters, relevance/price/title sorting, URL state, and bounded pagination.
- Block `starfiniti/navigation-search`: shared search renderer inside `core/navigation`.
- Classic `Starfiniti Search` widget: autocomplete or discovery mode.
- PHP: `starfiniti_search_render()`, `starfiniti_search()`, `starfiniti_discovery_render()`, and `starfiniti_discovery()`.
- Headless/inserted DOM: idempotent `window.StarfinitiSearch.initialize(root)`.

All surfaces use one renderer. Do not create provider-specific or theme-specific behavior forks. The currently automated theme evidence covers Twenty Twenty-Five on desktop and at 360 px. Other themes/builders require controlled testing and must not be called certified.

## Behavior available now

- Exact SKU, prefix/phrase, bounded typo, synonym, stop-word, and curated relevance behavior.
- Server-authoritative public visibility and customer scope; restricted products cannot be exposed through suggestions, filters, pins, or synonyms.
- Stock and category facets, 12-result pages, canonical product links, text-only details disclosure, and failed-image recovery.
- Full-viewport mobile autocomplete at 42 rem and below with close, Escape, focus, scroll, and query restoration behavior.
- Twelve-step setup readiness, versioned index builds, activation/rollback, bounded reconciliation, immutable configuration revisions, relevance preview, and optional aggregate analytics.

## Planned or unqualified

- Quick add-to-cart, variable-product selection in results, search history, and multiple suggestion groups.
- Broad theme/builder, multilingual, multisite, assistive-technology, device/orientation, virtual-keyboard, and 200%/400% zoom matrices.
- Real Typesense production use, remote authenticated MCP operation, production-like end-to-end latency certification, signed provenance, and final release approval.

## Safe configuration guidance

- Use WooCommerce -> Starfiniti Search for readiness, operations, configuration, relevance preview, and aggregate analytics.
- Use blocks or shortcodes for normal customer placement; use PHP/headless interfaces only when a builder owns the integration container.
- Treat any activation, rollback, reconciliation, purge, or configuration save as a controlled operation. Require a reason, verify the result, and retain rollback context.
- Do not recommend raw SQL, option editing, direct provider administration, generic HTTP proxying, or client-side visibility logic.
