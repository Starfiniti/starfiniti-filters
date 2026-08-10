# 00 Product Charter

## 1. Product statement

Starfiniti Search for WooCommerce is a GPL-compatible product-search platform for WooCommerce. It provides one high-quality search and discovery experience while allowing the merchant to choose where search is executed.

The first generally available release must include:

- a local search engine using a dedicated inverted index in the WordPress database;
- a Typesense provider for self-hosted Typesense and Typesense Cloud;
- one storefront component system and one normalized response contract;
- autocomplete, full results pages, categories, brands, attributes, products, variations, identifiers, filters, sorting, merchandising, and analytics;
- enterprise-grade indexing, migrations, diagnostics, testing, observability, security, privacy, and recovery;
- an optional Search Operations MCP server that configures and operates the platform through safe deterministic APIs.

The product must remain useful without Typesense, without MCP, and without a Starfiniti-hosted service.

## 2. Why the free FiboSearch fork is only the starting point

The free FiboSearch codebase supplies a mature storefront baseline, theme integrations, blocks, widgets, shortcodes, mobile behavior, and years of WooCommerce compatibility work. It does not contain the Pro inverted-index search engine. The new product therefore uses a controlled fork strategy:

1. Preserve legally reusable behavior and compatibility from the official free GPL source.
2. Add regression tests around inherited behavior before changing it.
3. Remove upstream branding, telemetry, account, licensing, and commercial dependencies.
4. Introduce a clean provider-neutral domain and application layer beside the inherited code.
5. Route inherited UI and integrations through the new application contracts.
6. Replace legacy subsystems incrementally.
7. Delete obsolete legacy code only after equivalent or better tested behavior exists.

This is a strangler migration, not a blind rewrite and not a permanent pile of adapters around legacy globals.

## 3. Product principles

### 3.1 One experience, multiple engines

The merchant configures search behavior once. The active provider interprets that intent according to its capabilities. Exact result order may differ because search engines use different tokenization and ranking semantics. The plugin promises consistent UX, data correctness, policy enforcement, configuration concepts, and quality thresholds, not mathematically identical ranking.

### 3.2 Canonical data before provider data

WooCommerce products are normalized into a versioned canonical `SearchDocument`. Local tables and Typesense documents are projections of that same validated model. Provider-specific data extraction from WordPress is prohibited.

### 3.3 Correctness before speed, then measured speed

No product may appear if it is not authorized and catalog-visible for the current search context. Prices, stock, language, and variation identity must be correct. Performance optimizations must retain these invariants and have benchmark evidence.

### 3.4 Asynchronous, idempotent, recoverable operations

All expensive indexing and migration operations must be queued, deduplicated, resumable, observable, and safe to retry. A killed worker, PHP timeout, partial remote import, or temporary database outage must not corrupt the active index.

### 3.5 Safe upgrades and reversible activation

New local generations and remote collections are built beside the active version. They are validated before activation. Activation is atomic, and the immediately previous verified version remains available for rollback for a configurable retention period.

### 3.6 Capability honesty

The administration UI must show which features are available, emulated, degraded, or unsupported for the selected provider. The product must not fake feature parity or silently ignore configuration.

### 3.7 Search is a revenue-critical subsystem

Every release includes compatibility, relevance, performance, privacy, security, observability, migration, rollback, and disaster-recovery evidence. Search is not treated as a cosmetic widget.

## 4. Target users

### 4.1 Small and medium stores

They use the local engine, require no external service, and expect install, index, configure, and search behavior with sensible defaults.

### 4.2 Large or high-traffic stores

They use Typesense for lower latency, larger document volume, horizontal scaling, advanced faceting, curation, and high availability.

### 4.3 B2B and restricted-catalog stores

They require role, customer group, contract, market, or price-list visibility. Search must use signed scopes or server-side revalidation. Public browser keys must never grant access to restricted catalog data.

### 4.4 Agencies and operators

They manage many stores and require health checks, diagnostics, configuration export/import, audit trails, WP-CLI, REST control APIs, and optionally MCP operations.

### 4.5 AI and commerce agents

Read-only agent tools may search and inspect public or authorized catalogs through the same domain gateway. Administrative agents use a separate tightly scoped operations surface.

## 5. First-release scope

### 5.1 Mandatory search objects

- simple products;
- variable product parents;
- variations;
- external and affiliate products;
- grouped products where supported by WooCommerce display semantics;
- product categories;
- product tags;
- WooCommerce Brands and configured compatible brand taxonomies;
- configured product attributes and custom taxonomies;
- configured custom fields;
- optional posts, pages, and custom post types through an extension API.

### 5.2 Mandatory query behavior

- instant autocomplete;
- exact, prefix, token, phrase, and controlled fuzzy matching;
- exact and partial SKU search;
- exact and controlled partial GTIN or other configured identifier search;
- localized normalization and diacritic handling;
- synonyms, stop words, and query redirects;
- field weighting and deterministic tie breakers;
- category, brand, attribute, price, stock, rating, and configured custom facets;
- filters, sorting, pagination, grouping, and result limits;
- pinned, boosted, buried, and hidden results;
- zero-result and typo recovery;
- full search-results page using the same request semantics as autocomplete.

### 5.3 Mandatory interface behavior

- progressively enhanced server-rendered form;
- accessible desktop autocomplete;
- accessible mobile full-screen or modal mode;
- keyboard and screen-reader operation;
- grouped suggestions;
- product image, name, price, stock, identifier, and configured metadata;
- details panel that works by keyboard, focus, pointer, and touch;
- configurable add-to-cart support where product state permits;
- recent search history with privacy controls;
- shortcode, block, navigation/menu, PHP API, widget compatibility, and theme integrations;
- no-JavaScript form fallback;
- cancellation of stale requests and protection against out-of-order responses.

### 5.4 Mandatory administration

- guided setup and provider selection;
- local index build and Typesense connection wizard;
- health and sync dashboard;
- schema and index-version visibility;
- field, analyzer, weight, filter, facet, and sorting configuration;
- synonym, stop-word, redirect, and curation management;
- search laboratory with provider comparison and score explanation;
- search analytics and relevance-quality reports;
- configuration export/import;
- diagnostics bundle with automatic secret redaction;
- audit log;
- safe data-retention and uninstall controls;
- WordPress Site Health integration;
- WP-CLI operations.

### 5.5 Mandatory operational behavior

- full rebuild with no search downtime;
- incremental indexing;
- periodic reconciliation;
- retries and dead-letter handling;
- provider migration and shadow comparison;
- provider rollback;
- schema migration;
- queue lag and index drift detection;
- structured logging and correlation IDs;
- health, readiness, and capability reports;
- documented backup and recovery.

## 6. Explicit non-goals for the first GA release

These items are not required in the first GA, but the architecture must not prevent them:

- a production Meilisearch provider;
- OpenSearch, Elasticsearch, or Algolia providers;
- an AI-generated merchandising autopilot that applies changes without approval;
- a universal replacement for every WooCommerce layered-navigation plugin;
- personalized ranking based on personally identifiable profiles;
- cross-store federated search across unrelated WordPress installations;
- vector or semantic search as a mandatory dependency;
- a Starfiniti-hosted Typesense control-plane subscription.

A non-goal is not permission to hard-code the architecture against it. Provider contracts, document schemas, and APIs must be extensible.

## 7. Enterprise-ready definition

“Enterprise-ready” means all of the following, not merely a large feature list:

- **Provenance:** every inherited and third-party file has a known compatible license.
- **Correctness:** visibility, language, price, stock, and variation policies are enforced.
- **Reliability:** indexing is idempotent, resumable, reconcilable, and recoverable.
- **Availability:** active search remains available during rebuilds and control-plane outages.
- **Performance:** documented SLOs pass on reference environments.
- **Security:** threat model, least privilege, secret handling, SSRF defense, abuse controls, dependency checks, and vulnerability process exist.
- **Privacy:** analytics are first-party, minimized, configurable, and integrated with WordPress privacy tools.
- **Observability:** operators can see provider health, queue lag, drift, errors, latency, and active versions.
- **Upgrade safety:** every database and schema change has forward migration, compatibility handling, verification, and rollback planning.
- **Testability:** local and external providers pass one conformance suite, plus unit, integration, E2E, accessibility, security, performance, and failure tests.
- **Supportability:** diagnostic data is actionable and secret-safe.
- **Documentation:** installation, architecture, extension, operations, security, privacy, migration, and recovery are documented.
- **Honesty:** tested limits and unsupported combinations are clearly stated.

## 8. Product requirement tracking

All mandatory requirements use stable identifiers:

| Prefix | Area |
|---|---|
| `LIC` | Licensing and upstream provenance |
| `ARC` | Architecture and contracts |
| `CAT` | Canonical catalog model |
| `SYN` | Synchronization and indexing orchestration |
| `LOC` | Local search engine |
| `TYP` | Typesense provider |
| `UX` | Storefront and accessibility |
| `ADM` | Administration and relevance |
| `ANA` | Analytics |
| `SEC` | Security |
| `PRI` | Privacy |
| `OPS` | Operations and observability |
| `MCP` | MCP and control API |
| `CON` | Provider conformance |
| `QA` | Testing and quality |
| `PER` | Performance and relevance |
| `REL` | Packaging and release |

Every requirement marked `MUST` must have a traceability row containing implementation files, test IDs, documentation, benchmark or review evidence, and release gate.
