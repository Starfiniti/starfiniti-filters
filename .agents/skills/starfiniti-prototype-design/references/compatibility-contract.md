# Starfiniti prototype compatibility contract

## Authority to inspect

Read the files relevant to the requested surface before designing or reviewing:

- `spec/starfiniti-search-codex-spec/docs/06_STOREFRONT_ADMIN_ANALYTICS.md`
- `docs/STOREFRONT_INTEGRATION.md`
- `docs/EXTENSION_POINTS.md`
- `spec/starfiniti-search-codex-spec/contracts/search-request.schema.json`
- `spec/starfiniti-search-codex-spec/contracts/search-response.schema.json`
- `spec/starfiniti-search-codex-spec/contracts/provider-capabilities.schema.json`
- `plugin/starfiniti-search/src/Infrastructure/WordPress/Storefront/SearchShortcode.php`
- `plugin/starfiniti-search/assets/search.js`
- `plugin/starfiniti-search/assets/search.css`
- `plugin/starfiniti-search/config/integrations.json`
- `tools/verify-storefront.mjs`
- `tests/php/integration/storefront-contract.php`

The specification governs planned capability. Executable source/tests govern what is compatible now. If they differ, label the design `planned` and identify implementation and qualification work.

## Compatibility classes

- `compatible_now`: maps to current source and passing executable contracts without new behavior.
- `planned`: allowed by the specification but absent from or broader than current qualified source.
- `incompatible`: bypasses a mandatory boundary. Redesign it rather than adding a waiver.

## Universal invariants

- Storefront code consumes canonical contract version `1.0`; it does not branch on provider ID.
- WooCommerce remains catalog and product-page truth. Search indexes are not trusted presentation HTML.
- Visibility, locale, channel, customer scope, filter allow-lists, sorting, and pagination are validated server-side.
- Prices enter the UI as integer minor units and are formatted using locale, currency, and minor-unit metadata.
- Catalog, provider, diagnostic, and highlight content is untrusted. Render text with `textContent`/text nodes; allow only validated HTTP(S) product URLs and same-origin relative redirects.
- Multiple components may coexist. IDs and ARIA relationships must be unique; initialization must be idempotent.
- Provider failure, JavaScript failure, MCP failure, or analytics failure must not disable normal WooCommerce search or cart behavior.
- Public UI targets WCAG 2.2 AA, RTL, forced colors, reduced motion, keyboard, touch, switch, screen reader, 200%/400% zoom, and meaningful localized status.

## Canonical UI data

Autocomplete and discovery may bind to canonical response data such as:

- `query_id`, `total`, `page.number`, `page.size`, `page.has_more`
- `hits[].entity_id`, `hits[].rank`, `hits[].matched_fields`
- `hits[].projection.identity.title`
- `hits[].projection.identity.url`
- `hits[].projection.identity.sku`
- `hits[].projection.content.excerpt`
- `hits[].projection.content.short_description_text`
- `hits[].projection.pricing.active_min_minor`
- `hits[].projection.pricing.currency`
- `hits[].projection.inventory.stock_status`
- `hits[].projection.media.thumbnail_url`
- `hits[].projection.media.primary_image_url`
- `hits[].projection.media.alt`
- `facets['inventory.stock_status']`
- `facets['classification.category_paths']`
- safe highlight segments shaped as `{text, highlighted}`
- `redirect.url` only when it is a same-origin path beginning with one `/`

Do not bind a design directly to Typesense fields, raw scores as business facts, provider HTML, SQL rows, secret references, unrestricted diagnostic bodies, or removable visibility controls.

## Autocomplete

Required design states:

```text
idle
focused_empty
debouncing
loading
success
no_results
degraded
error
closed
```

Required behavior:

- semantic GET form with `s` and hidden `post_type=product` fallback;
- labelled search input with combobox/listbox relationships and polite live region;
- minimum two-character current trigger, 180 ms current debounce, abort previous fetch, monotonic response guard, and bounded context-safe cache;
- IME composition support;
- Arrow Up/Down, Home, End, Escape, Enter, and Tab behavior;
- text-only product-title links in the current implementation;
- loading, count, empty, selected item, and error announcements;
- error copy preserves normal form submission.

Images, multiple suggestion groups, recent/popular history, promotional cards, and richer product metadata are specification-permitted but currently planned.

## Discovery

Required design states: `loading`, `success`, `no_results`, `error`.

Current controls and URL contract:

- query `s`;
- repeated stock `sfs_stock`: `instock`, `outofstock`, or restored `onbackorder` state;
- repeated category `sfs_category`, at most 16 selected and 30 displayed current facet values;
- sort `sfs_sort`: `relevance`, `price_asc`, `price_desc`, `title_asc`;
- page `sfs_page`, bounded from 1 to 10000;
- current page size 12 and Previous/Next controls;
- browser reload and Back restore the same controls and result state.

Current card behavior:

- optional square lazy image removed cleanly on failure;
- product title link, localized price, SKU/stock text;
- keyboard/touch details disclosure with `aria-expanded`, `aria-controls`, and a named region;
- excerpt/short description, availability, and canonical “View product” link;
- no current direct add-to-cart or variable-product hydration.

## Mobile overlay

The current breakpoint is 42 rem and below. Prototype at least a 360 px viewport and a desktop viewport.

Model:

- only one active overlay across all search instances;
- full viewport including safe-area insets and dynamic viewport height;
- visible named close button;
- role `dialog`, `aria-modal=true`, and “Product search” accessible label while open;
- body scroll lock without page jump and exact restoration on close;
- intentional Tab containment only while modal;
- Escape from any owned control closes;
- focus returns to the opener and the query may be preserved;
- leaving the mobile media query closes without forcing focus;
- no duplicate IDs, header/checkout interference, or hover-only behavior.

Virtual keyboard, orientation, real iOS/Android, broad assistive-technology, and 200%/400% zoom matrices remain qualification work.

## Shared render surfaces

The same behavior must be expressible through:

- `[starfiniti_search]` and `starfiniti/search`;
- `[starfiniti_discovery]` and `starfiniti/discovery`;
- `starfiniti/navigation-search` within `core/navigation`;
- the classic widget;
- PHP return/echo APIs;
- idempotent `window.StarfinitiSearch.initialize(root)` for headless or inserted DOM.

Do not create a design that needs separate behavior forks for a block, shortcode, menu, widget, theme, or provider.

## Administration

### Setup

Represent all twelve steps: environment, catalog, visibility, provider, storage/connection, language/variation, fields/identifiers, price/stock/visibility, index plan, build/verification, storefront placement, and smoke/activation. Readiness must use real evidence and must not equate connectivity with activation safety.

### Operations

Represent `healthy`, `degraded`, `building`, `verification_required`, `stale`, `misconfigured`, and `unavailable`. Show active read provider, write targets, generation/candidate/rollback, configuration revision, document counts, queue/dead-letter state, reconciliation/drift, latency/errors/circuit, storage, credential age without secret values, incidents, and remediation.

High-impact changes use immutable `plan -> approve -> execute`, preconditions, explicit identity, idempotency, verification, audit, and rollback. Never prototype a generic SQL, shell, HTTP proxy, raw provider admin, or one-click unverified mutation.

### Relevance

Use `draft`, `preview`, `approved`, `active`, and `retired`. Business concepts may be shown, but provider-native parameter names are expert projection details, not the canonical UX. Protected comparison may show local and Typesense side by side during shadow mode. Preview must not mutate active configuration.

### Analytics

Analytics are first-party, provider-neutral, bounded, optional, and unable to block search/cart. Minimize query and identity data, show retention, define metrics, and retain provider/configuration/generation context. Do not send private live queries or catalog data to an external AI service by default.

## Evidence and claim boundary

Automated compatibility currently covers Twenty Twenty-Five `1.x`, desktop, and 360 px behavior. Storefront, Astra, OceanWP, Kadence, Blocksy, GeneratePress, Elementor, Bricks, Flatsome, WoodMart, Divi, Avada, XStore, and Impreza remain pending/manual/controlled-environment according to `integrations.json`.

A visual prototype is not accessibility, device, theme, performance, security, or release evidence. Record those as follow-up qualification items.
