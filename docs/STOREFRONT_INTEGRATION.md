# Storefront integration

## Render surfaces

- `[starfiniti_search]` and `starfiniti/search` render the accessible autocomplete form.
- `[starfiniti_discovery]` and `starfiniti/discovery` render faceted product results, sorting, URL state, and bounded pagination.
- `starfiniti/navigation-search` is a dynamic child of `core/navigation` for block-theme menus and template parts.
- The classic `Starfiniti Search` widget selects autocomplete or discovery mode. `[starfiniti_product_search]` is a deprecated compatibility alias for autocomplete.
- PHP templates use `starfiniti_search_render($attributes)`, `starfiniti_search($attributes)`, `starfiniti_discovery_render($attributes)`, or `starfiniti_discovery($attributes)`. The `*_render` functions return escaped markup; the shorter functions echo it.
- Headless or dynamically inserted DOM calls `window.StarfinitiSearch.initialize(root)`. Initialization accepts a container or component root and is idempotent, so repeated calls cannot attach duplicate listeners.
- Every surface delegates to the same PHP renderer and `assets/search.js`/`assets/search.css`, supports multiple instances with unique IDs, emits one runtime configuration, and submits to normal WooCommerce product search when JavaScript is unavailable or autocomplete fails.
- At 42 rem and below, autocomplete uses a single-active, full-viewport modal overlay with a visible close control, contained Tab order, Escape handling from any owned control, body-scroll restoration, opener focus restoration, and preserved-query cache reuse. Wider viewports remain inline.
- Discovery cards expose typed text-only details through an `aria-expanded` disclosure and named region. The panel links to the canonical WooCommerce product page; it does not attempt direct cart mutation or unsafe variable-product hydration. Failed product images remove themselves without breaking the card.

The discovery URL parameters are `s`, repeated `sfs_stock`, repeated `sfs_category`, `sfs_sort`, and `sfs_page`. Only allow-listed values are compiled into the canonical request; the server independently validates all filters, sorting, pagination, locale, channel, public visibility, and scope.

## JavaScript events

Events bubble from the owning component and contain normalized identifiers and counts, never raw product HTML or credentials:

- `starfiniti:suggestions-returned`: `{queryId, count, provider}`
- `starfiniti:results-rendered`: `{queryId, total, page, provider, indexVersion}`
- `starfiniti:search-error`: `{code}`

Product and facet values are untrusted data. Rendering uses `createTextNode`/`textContent`; URLs are limited to HTTP(S); no response HTML is evaluated or inserted. Requests are abortable and sequence-guarded, IME composition is handled, browser Back restores state, and live regions announce loading, counts, empty results, and errors.

## Integration registry

`config/integrations.json` is the versioned source of truth for theme/builder detection, supported range, assets, DOM strategy, limitations, evidence, last verification, and certification state. Deep health output reports the detected entry and complete registry. Only Twenty Twenty-Five currently has automated qualification evidence; Storefront and common/open or licensed builders remain explicitly pending instead of inheriting a false compatibility claim. Integrations use owned component roots and never globally replace unrelated search forms.

## Current certified boundary

The local qualification evidence covers the WordPress Twenty Twenty-Five block theme at desktop and a 360 px viewport, the shared shortcode/block/navigation/widget/PHP/headless contract, keyboard autocomplete, modal role/focus/scroll lifecycle, preserved-query reopening, keyboard/touch details disclosure, failed-image recovery, live stock/category intersection, sorting, URL reload/Back restoration, and error-free browser logs. Real iOS/Android virtual-keyboard/orientation testing, broad theme/builder and assistive-technology coverage, variable-product/cart hydration, multilingual plugins, and the 200/400-percent zoom matrix remain GA gates.
