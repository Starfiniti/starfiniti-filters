# FiboSearch Pro + FiboFilters compatibility matrix

Matrix version: **1.0.0**, dated 2026-09-07. Target: maintained legacy WooCommerce sites using Starfiniti's independent local engine. Implementation base: `b8637d1cf374161e67fa7aa88ab1d9c1eac07bf2` plus the uncommitted candidate described in [HANDOFF](../HANDOFF.md). This is a requirements inventory, not a compatibility certificate.

The installed source versions, enabled features and target store are not yet known. The audit pins in [UPSTREAM](../UPSTREAM.md) are not evidence of the versions on a customer's site. No row below is certified for a migrated site. Use with the [migration design](FIBO_MIGRATION_DESIGN.md) and [local delivery plan](LOCAL_DELIVERY_PLAN.md).

## How to qualify a row

- `candidate`: relevant implementation/evidence exists; replay against the exact candidate and source site is still required.
- `partial`: a related implementation exists, but the stated behavior or migration has an identified gap.
- `planned`: this behavior has no established implementation/qualification in the inspected baseline.
- `unqualified`: requires source-site or external testing; no compatibility inference is permitted.

All rows start with site applicability `unknown`. Inventory changes this to `required` or `not_used`, with secret-free evidence and operator confirmation. Every used behavior must pass; unused features remain in the maintenance backlog, not silently removed from the product target. Add rows for site-specific behavior absent here. Never interpret a percentage of rows as readiness. Security, rollback and migration-safety rows always apply.

Public vendor documentation establishes **what to investigate**, not how to copy implementation or how an unknown version stores settings. Search inventory references: [feature comparison](https://fibosearch.com/pro-vs-free/) and [search sources](https://fibosearch.com/feature/search-engine/). Filter inventory references: [control types](https://fibofilters.com/documentation/how-it-works/filter-types/) and [documentation sections](https://fibofilters.com/documentation/). Reviewed 2026-09-07; recheck for each supported source version.

## Search behavior

| ID | Behavior to preserve | Current state | Acceptance / remaining work |
| --- | --- | --- | --- |
| S01 | Product title, description and short-description search | candidate | Baseline query fixtures for each enabled field, including disabled-field negatives; prove matching and display separately. See [local relevance](LOCAL_RELEVANCE.md). |
| S02 | Exact, partial and variation SKU; configured identifiers | partial | Exact identifier foundations exist. Prove each enabled partial-match mode, parent collapse, variation identity/link and duplicate policy on real WooCommerce variations. Eligible unique exact SKU must rank first. |
| S03 | Category, tag, brand and attribute search sources | partial | Canonical fields do not prove all names are searchable. Inventory actual taxonomy providers, enabled fields and localized labels; test positive and excluded-source queries. |
| S04 | Explicit custom fields, including ACF-backed values | partial | Typed allowlisting exists in [custom-field tests](../tests/php/integration/custom-fields.php). Map only admitted public scalar/list values; prove resolver/type/locale and reject private fields, objects and wildcard imports. |
| S05 | Typo tolerance and configured fuzzy mode | partial | Bounded local typo matching exists, but does not imply equivalent Pro presets. Qualify disabled and each used mode with judged examples and query-cost limits; never copy preset names into unsupported settings. See [public modes](https://fibosearch.com/documentation/features/fuzzy-search/). |
| S06 | Synonyms, normalization and relevance settings | partial | Versioned local synonyms/ranking exist; importer is absent. Preserve direction, locale and phrase intent, identify unsupported cycles/expansions, and approve measured ranking differences. |
| S07 | Product exclusions, including combined rules | partial | Visibility and query curations exist; these are not a generic source exclusion-rule importer. Preserve Boolean groups globally where required, including hits, totals and facets; exclusions override pins/SKU. |
| S08 | Non-product suggestions and group ordering | planned | Add only used groups with typed entity identity, localized heading, limits, links and visibility. Do not render a term/post/page as a purchasable product. |
| S09 | Suggestion images, prices, SKU and description | partial | Autocomplete links and discovery cards are not identical surfaces. Inventory visible fields per surface; verify current price/tax/currency, safe text/URLs and missing-media behavior. |
| S10 | Details panel, quantity and quick cart actions | partial | Text disclosure exists; cart mutation and variable-product selection remain unqualified. Use supported WooCommerce APIs; test failure, quantity, stock, valid variations and no accidental cart actions. |
| S11 | Header submission and WooCommerce results-page synchronization | partial | Starfiniti discovery and ordinary form fallback exist. Prove the actual theme results page retains query semantics, rank, totals, pagination and filtering without materializing all matching IDs. |
| S12 | Input thresholds, mobile mode, keyboard, styling and overlay | partial | Shared renderer has desktop/360 px evidence. Map supported options explicitly; qualify actual headers, duplicate instances, IME, focus, touch, RTL, zoom and no-JS fallback. |
| S13 | Voice input, analytics and external analytics integration | unqualified | Inventory each independently; test supported browser fallback and event definitions/consent. Existing aggregate analytics is not proof of source reporting parity; do not import raw query history or identifiers. |

## Filters and combined search

| ID | Behavior to preserve | Current state | Acceptance / remaining work |
| --- | --- | --- | --- |
| F01 | Ordered filters, labels and data-source bindings | partial | Candidate supports at most fourteen validated definitions. Map actual IDs/keys/order; never truncate extra filters or identify taxonomies only by translated labels. See [configuration validator](../plugin/starfiniti-search/src/Domain/Configuration/DiscoveryConfiguration.php). |
| F02 | Checkbox, radio and select interactions | candidate | Candidate renderer/state tests exist. Prove actual single/multi-selection rules, defaults, clear behavior and URL encoding. Same-facet OR is not assumed for every source filter. |
| F03 | Price and other numeric ranges | partial | Candidate range is active minimum price only. Prove variable-price interpretation, tax/currency, zero/open endpoints and invalid input; other numeric sources, sliders and presets need explicit support. |
| F04 | Categories, hierarchy and archive context | partial | Category IDs/paths exist; flat/tree links and breadcrumbs need parity. Child/ancestor choices must retain the archive's base restriction, totals and navigation. |
| F05 | Brands, tags, stock, featured, sale, rating and custom sources | partial | Candidate controls cover stock/featured/categories/attributes/price, not every canonical field. Map registered taxonomies and sale schedules; do not substitute featured for on-sale. |
| F06 | Buttons, color swatches, toggles and other used controls | planned | Preserve selection semantics, labels and accessible keyboard/touch states; a checkbox-only substitute is a reported transformation, not an exact migration. |
| F07 | Same-facet alternatives and cross-filter counts | partial | Same-facet work is concurrently uncommitted and not qualified by this matrix. Drop only the owned user selection for the counted facet; retain other selections, query, archive, visibility, exclusions and curation filters. Test zero-count selections and multiple controls bound to one field. |
| F08 | Header query plus independent in-list search refinement | planned | Preserve base query and separate refinement, including distinct URL state and clearing. Do not concatenate strings or replace the base query. See the public [search-filter behavior](https://fibofilters.com/documentation/filter-types/search/) and the design's query-set contract. |
| F09 | Full matching universe, pagination and sort | partial | Prove facets/totals beyond the 250 autocomplete ranking-candidate limit and beyond one page. No filtering only visible cards; no list of every product ID sent to WordPress. |
| F10 | Filter visibility, value ordering and applied-filter UI | partial | Candidate retains selected zero-count values and clearing. Automatic visibility, dependent filters, search-within-values, ordering, chips/labels and collapse rules need per-site mapping. |
| F11 | Sidebar, horizontal and mobile placement/layout | unqualified | Test every owned placement in the real theme, responsive opening/closing, focus and multiple instances. Do not claim layout parity from stock-theme evidence. |
| F12 | Legacy URLs, reload, Back/Forward and SEO rules | partial | Starfiniti URL state exists; legacy codecs and SEO mapping do not. Preserve exact parameter ownership, encoding and base query; qualify permitted canonical/noindex rules and existing inbound links. No broad redirect or global rewrite. |

## Integration, operations and migration

| ID | Behavior to preserve | Current state | Acceptance / remaining work |
| --- | --- | --- | --- |
| I01 | Existing shortcodes, blocks, menus, widgets and templates | partial | Native Starfiniti surfaces exist; its deprecated alias is not evidence of FiboSearch shortcode compatibility. Inventory each source entry and attributes; controlled replacement or tested non-conflicting alias only. |
| I02 | Theme/builder hooks, snippets and custom CSS/JS | unqualified | Qualify exact versions/containers and every actual customization. Do not import executable settings or blanket-replace vendor classes/global symbols. See [integration boundary](STOREFRONT_INTEGRATION.md). |
| I03 | Multilingual, multicurrency, B2B and visibility adapters | unqualified | Test real enabled integrations and each scope; fail closed if dynamic price/visibility cannot be represented. No cross-locale or facet existence leaks. |
| I04 | Freshness during imports, stock/sale changes and rebuilds | candidate | Existing [generation tests](../tests/php/integration/generation-lifecycle.php) are foundations. Replay source updates/deletes during migration, scheduled changes and missed-hook reconciliation. |
| I05 | WordPress/WooCommerce/database/browser compatibility | unqualified | Record exact runtime matrix. Current handoff reports the failed tested-up-to artifact check; do not solve it by changing a support claim without testing. |
| I06 | Customer skill and controlled local MCP | partial | Guidance-only packaged skill and five-tool local MCP exist. Neither currently migrates a site. Add qualified capability/mapping guidance later; preserve fixed typed operations, approval and no storefront dependency. |
| M01 | Read-only source inventory and semantic export | partial | [Legacy reader](../plugin/starfiniti-search/src/Infrastructure/WordPress/Migration/LegacyFiboFiltersReader.php) reports detection/count, not mappings. Add versioned redacted inventory; zero/malformed/limited count is not success. |
| M02 | Deterministic mapping and immutable preview | planned | Every source item gets exact/transformed/unsupported/unknown disposition and evidence. No generic option copy, guessed Pro schema, credentials, processed index or silent omission. |
| M03 | Shadow build, comparison and coordinated routing | partial | Native generation lifecycle exists; coordinated legacy placements/routes are not implemented. Verify the pair before switching any public search/filter ownership. |
| M04 | Retry, interruption, drift and conflict safety | partial | Native operations are immutable/idempotent. Add migration-specific interruption tests at every durable boundary; stale config/placement/source fingerprint requires replan. |
| M05 | Rollback without lost commerce writes | partial | Native index rollback exists. Legacy routing/configuration rollback and legacy index freshness need rehearsal. Never restore the whole database to undo a search migration. |
| M06 | Representative speed, memory and index storage | unqualified | Small-lab provider timing is not storefront or full-catalog certification. Test cold/warm, concurrent traffic/rebuilds, high-cardinality facets, HTTP and browser latency on target-class hosting. |
| M07 | Privacy, provenance, artifact and final acceptance | unqualified | No Pro code/index/license import; no PII/log/secret transfer. Exact ZIP, all applicable gates, site replay and second-operator rollback must pass before a release/cutover claim. |

## Evidence and versioning

The initial matrix contains 38 stable IDs. Append IDs rather than reusing retired ones. Change the major matrix version when acceptance semantics change incompatibly, minor for new rows, patch for clarifications. A site's result binds the matrix version, exact source editions/versions, target Git/artifact hashes, sanitized inventory hash, configuration revision, index generation, theme/integration versions, fixture IDs, command outcomes and reviewer approval. Changed inputs invalidate affected evidence and cutover approval.

Candidate references above are entry points, not fresh test results. Existing summaries are in [qualification evidence](QUALIFICATION_EVIDENCE.md); historical requirement statuses are unchanged. A clean-room fixture validates Starfiniti behavior but cannot establish an unknown legacy site's parity.
