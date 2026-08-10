# 06 Storefront, Administration, Relevance, and Analytics

## 1. Storefront objective

Preserve the proven useful behavior of the free FiboSearch storefront while replacing provider coupling and improving accessibility, security, performance, and maintainability.

The storefront must be progressively enhanced:

```text
semantic HTML search form
        |
        +-- no JavaScript: normal WooCommerce product search
        |
        +-- JavaScript available: accessible autocomplete and optional details panel
```

Search must never become unusable because an autocomplete asset, provider, MCP server, or analytics endpoint fails.

## 2. Frontend implementation

Use TypeScript with a small framework-independent component layer for the public storefront. Do not require jQuery for new code. Do not ship a large UI framework solely for one search box. WordPress React components may be used in administration and blocks where appropriate.

Requirements:

- source maps excluded from production unless intentionally published;
- source code and reproducible build commands included or publicly linked;
- no remote executable assets;
- no `eval`, dynamic code generation, or unsafe template execution;
- strict TypeScript;
- abortable requests;
- deterministic state machine;
- CSP-compatible operation;
- graceful behavior with multiple search instances on one page;
- shadow DOM only if theme integration and accessibility evidence justify it;
- CSS tokens and scoped selectors to avoid theme collisions;
- RTL and reduced-motion support.

## 3. Accessible combobox

Follow the current WAI-ARIA combobox/listbox interaction pattern and test with real assistive technology.

Mandatory behavior:

- correctly associated label or accessible name;
- `role="combobox"` and valid expanded, controls, and active-descendant relationships where that pattern is used;
- keyboard navigation with Arrow keys, Home, End, Escape, Enter, Tab, and printable input;
- focus remains understandable;
- selected result is announced;
- result count and loading state use a polite live region;
- errors and no-result states are announced;
- mouse, touch, keyboard, switch, and screen-reader use;
- focus is restored when a mobile overlay closes;
- details are available without hover;
- no focus trap on desktop;
- intentional, tested focus trap only in modal mobile mode;
- minimum touch target and contrast requirements;
- zoom to 200 and 400 percent without loss of core operation;
- motion respects `prefers-reduced-motion`.

Target WCAG 2.2 AA for plugin-owned UI.

## 4. Request state machine

Use an explicit state machine:

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

Rules:

- minimum-character setting is validated;
- debounce is configurable within safe bounds;
- each query has a monotonically increasing sequence;
- previous fetches are aborted;
- late responses cannot replace newer results;
- composition events support IME input;
- paste, speech input, autofill, and browser back behavior are tested;
- repeated identical requests use a context-safe cache;
- empty focus may show history, popular queries, categories, or merchant-configured content;
- provider errors preserve form submission;
- status text is localized.

## 5. Suggestion groups

Support groups:

- products;
- variations where configured;
- categories;
- brands;
- tags;
- attributes or facet suggestions;
- query suggestions;
- content types registered through extension APIs;
- recent searches;
- popular searches;
- configured promotional or redirected destinations.

Each group has:

- stable machine type;
- localized heading;
- result limit;
- rendering contract;
- keyboard order;
- analytics type;
- visibility and permission policy.

No group may render untrusted HTML. Highlighting uses safe text segments or a strict allow-list.

## 6. Product result card

Configurable fields:

- image;
- product name;
- brand;
- category breadcrumb;
- short text excerpt;
- SKU or safe identifier;
- price;
- sale state;
- stock message;
- rating;
- selected matching variation;
- configured badges;
- add-to-cart action where safe.

The index is not trusted as presentation HTML. Render from typed projection values and escape at output. Dynamic or restricted fields are hydrated server-side.

A missing image, malformed URL, deleted product, or failed hydration must not break the result list.

## 7. Details panel

Preserve the useful expanded-product concept but modernize interaction:

- open by click, keyboard command, focus action, or pointer intent;
- not dependent on hover;
- configurable on desktop and mobile;
- stable layout and no content shift beyond documented bounds;
- correct variable-product handling;
- quantity validation;
- add-to-cart uses WooCommerce-supported APIs and nonces where required;
- no accidental cart action on navigation;
- clear loading and failure state;
- analytics distinguish open, click, and add-to-cart;
- disabled automatically for contexts that cannot safely hydrate details.

## 8. Mobile mode

Provide a configurable full-screen overlay or responsive inline mode.

Requirements:

- single active overlay even when multiple search widgets exist;
- body-scroll management without page jump;
- safe viewport handling on iOS and Android;
- virtual keyboard and orientation tests;
- visible close control with accessible name;
- focus restoration to opener;
- no duplicate IDs;
- preserved query when appropriate;
- results remain reachable at 200 percent zoom;
- no toolbar or checkout interference;
- tested with common mobile theme headers.

## 9. Search history

Recent search history is optional and off where local privacy policy requires.

Default storage is browser-local, not server-side. Requirements:

- maximum entries and retention;
- clear control;
- no account synchronization by default;
- no storage for queries classified as sensitive by configured redaction rules;
- no display in shared-device mode;
- accessible empty and populated states;
- consent integration where jurisdiction or merchant policy requires it.

## 10. Search results page

Autocomplete and the results page use the same canonical request builder and ranking profile.

The integration must:

- preserve WooCommerce templates and hooks where possible;
- request only one provider page, not all matching IDs;
- hydrate current products through WooCommerce;
- preserve provider rank;
- expose provider total and pagination safely;
- revalidate visibility;
- integrate configured facets and sorting;
- maintain query and filter URLs;
- handle deleted or newly restricted candidates by bounded over-fetch;
- prevent duplicate parent and variation results according to strategy;
- support normal search submission without JavaScript;
- work with block and classic themes;
- expose a documented integration contract for filtering plugins.

Do not inject a huge `post__in` list for the entire result set.

## 11. Blocks, shortcodes, menus, widgets, and PHP API

Required public integrations:

```text
[starfiniti_search]
```

A deprecated compatibility alias may support the inherited shortcode during migration.

Provide:

- dynamic Gutenberg search block;
- navigation/menu integration;
- classic widget compatibility where WordPress supports it;
- template function;
- PHP render API;
- optional headless initialization API;
- documented JavaScript events with typed detail;
- server-render callback for block themes.

Each integration renders the same semantic form and initializes the same component. No separate behavior forks.

## 12. Theme and builder compatibility

Create an integration registry with:

```text
integration_id
theme_or_plugin
supported_version_range
detection
assets
DOM strategy
known limitations
test evidence
last_verified
```

Preserve and audit useful inherited integrations. Prioritize:

- Storefront;
- Astra;
- Flatsome;
- WoodMart;
- Divi;
- Elementor;
- Bricks;
- Avada;
- OceanWP;
- Kadence;
- Blocksy;
- XStore;
- GeneratePress;
- Impreza;
- current WordPress block themes.

Licensed themes may require a controlled manual certification environment. The release documentation must distinguish automated, manual, and community-reported compatibility.

An integration may manipulate only the expected search container and must not globally rewrite unrelated forms.

## 13. Administration information architecture

Use WooCommerce navigation conventions and WordPress components. Suggested sections:

1. Overview
2. Setup
3. Provider
4. Indexing
5. Search behavior
6. Relevance
7. Facets and sorting
8. Appearance
9. Analytics
10. Integrations
11. Operations
12. Diagnostics
13. Privacy
14. Advanced
15. About and licenses

The administration UI must remain usable without JavaScript for critical recovery actions where practical.

## 14. Setup wizard

Wizard steps:

1. environment and compatibility preflight;
2. catalog analysis;
3. public versus restricted or dynamic catalog detection;
4. provider selection;
5. local storage estimate or Typesense connection;
6. language and variation strategy;
7. searchable fields and identifiers;
8. price, stock, and visibility policy;
9. initial index plan;
10. build and verification;
11. storefront placement;
12. smoke test and activation.

The wizard must explain tradeoffs and block unsafe direct-browser configuration. It must not pretend a connection is ready until schema, credentials, index, and smoke queries are valid.

## 15. Operations dashboard

Show:

- active read provider;
- write targets;
- transport mode;
- active index or generation;
- candidate and rollback versions;
- canonical configuration revision;
- health status;
- indexed versus expected documents;
- queue depth and oldest age;
- dead-letter count;
- last incremental sync;
- last reconciliation;
- drift summary;
- search latency percentiles;
- error rate;
- provider circuit state;
- storage estimate;
- credential age and safe rotation status;
- current incidents and remediation.

Use meaningful status states:

```text
healthy
degraded
building
verification_required
stale
misconfigured
unavailable
```

A green indicator may not be based only on endpoint reachability.

## 16. Relevance studio

Provide an administrator-only search laboratory.

Features:

- enter query and context;
- select locale, currency, customer scope, provider, and ranking profile;
- compare local and Typesense side by side during shadow mode;
- see hits, ranks, matched fields, safe score explanation, filters, curations, and timing;
- mark expected relevant and irrelevant products;
- define known-item and category-discovery test cases;
- preview field-weight, synonym, stop-word, typo, and curation changes;
- calculate quality deltas before activation;
- save test cases into a versioned relevance set;
- export and import sanitized relevance fixtures;
- prevent production configuration changes without an operation plan.

Do not send private live queries or catalog data to an external AI service by default.

## 17. Ranking configuration

Expose business-friendly concepts:

- title importance;
- SKU and identifier priority;
- brand, category, attribute, and description importance;
- exact, phrase, prefix, synonym, and typo behavior;
- stock policy;
- popularity, sales, rating, featured, and recency tie breakers;
- query-specific pins, boosts, buries, hides, and redirects.

Provide expert details without requiring the merchant to know provider parameter names. Validate extreme settings and offer safe presets.

Configuration changes have draft, preview, approved, active, and retired states.

## 18. Synonym and stop-word management

Features:

- locale-specific lists;
- equivalent and directional synonyms;
- CSV/JSON import and export;
- duplicate, cycle, conflict, and expansion warnings;
- preview against relevance tests;
- effective date;
- author and reason;
- rollback;
- provider projection status.

Stop-word changes warn when they may make common catalog queries empty.

## 19. Curations and redirects

Curations support conditions:

- normalized query;
- locale;
- channel;
- customer scope where safe;
- date range;
- active filters;
- device class only when justified.

Actions:

- pin;
- boost;
- bury;
- hide;
- add a filter;
- rewrite query;
- redirect to a safe destination.

A preview shows provider-specific mapping and any degraded behavior. All changes are auditable.

## 20. First-party analytics

Analytics are provider-neutral and first-party.

Event types:

```text
search_submitted
suggestions_returned
suggestion_impression
suggestion_clicked
results_page_viewed
facet_applied
sort_changed
details_opened
add_to_cart_from_search
purchase_attributed
zero_results
search_error
```

Event schema includes:

- opaque query ID;
- normalized or redacted query according to privacy policy;
- provider and index version;
- configuration and ranking revision;
- locale and coarse channel;
- anonymous session or consented user reference;
- result IDs or rank where permitted;
- timing;
- event timestamp;
- attribution data;
- no raw secret or unrestricted user metadata.

## 21. Analytics ingestion

Use a bounded first-party endpoint:

- request schema validation;
- accepted event allow-list;
- payload and batch limits;
- rate limiting;
- replay and duplication protection where material;
- CSRF or origin policy appropriate to anonymous events;
- IP not stored by default;
- query redaction before durable storage;
- asynchronous aggregation;
- configurable raw-event retention;
- daily aggregate tables;
- cleanup jobs;
- exporter and eraser integration where data can relate to a person.

Analytics failure never blocks search or cart actions.

## 22. Analytics reports

Mandatory reports:

- top queries;
- zero-result queries;
- low-click queries;
- click-through rate;
- add-to-cart rate;
- attributed conversion rate;
- average clicked rank;
- search exit rate where measurable;
- provider latency and error rate;
- local versus Typesense shadow comparison;
- query trends;
- query-to-category opportunities;
- identifier searches;
- typo and synonym recovery;
- curation impact;
- relevance test trend.

Reports clearly state metric definitions and sampling.

## 23. UX and administration requirements

- `UX-001 MUST`: no-JavaScript search fallback works.
- `UX-002 MUST`: autocomplete meets tested WCAG 2.2 AA behavior.
- `UX-003 MUST`: stale and out-of-order responses cannot overwrite current results.
- `UX-004 MUST`: UI uses normalized safe response data and no untrusted HTML.
- `UX-005 MUST`: mobile overlay focus and viewport behavior are certified.
- `UX-006 MUST`: details panel is keyboard and touch accessible.
- `UX-007 MUST`: results-page semantics match autocomplete.
- `UX-008 MUST`: blocks, shortcode, menu, widget compatibility, and PHP API share one component.
- `UX-009 MUST`: useful inherited theme integrations are audited and recorded.
- `ADM-001 MUST`: setup validates actual readiness, not only connectivity.
- `ADM-002 MUST`: health, indexing, drift, errors, and rollback are visible.
- `ADM-003 MUST`: relevance changes are previewable and versioned.
- `ADM-004 MUST`: capability differences are explicit.
- `ADM-005 MUST`: diagnostics are actionable and secret-safe.
- `ANA-001 MUST`: analytics are provider-neutral and cannot break search.
- `ANA-002 MUST`: query and identity data are minimized and retention-controlled.
- `ANA-003 MUST`: reports have documented definitions and provider/version context.
