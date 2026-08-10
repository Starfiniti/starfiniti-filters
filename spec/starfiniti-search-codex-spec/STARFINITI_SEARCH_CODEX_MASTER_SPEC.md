# Starfiniti Search for WooCommerce

## Complete Codex Master Specification

This file is a merged convenience copy. The split files in the specification pack are authoritative for repository work.


---

# Starfiniti Search for WooCommerce

This repository specification pack defines a production-grade WooCommerce search platform built from the GPL-licensed free FiboSearch codebase, with a new independently implemented local inverted index, a first-class Typesense provider, and a provider architecture that can later support Meilisearch and other engines.

This is not an MVP specification. Internal milestones may be delivered incrementally, but no public release may be called production-ready or enterprise-ready until every mandatory release gate in this pack has passed.

## Working identity

These identifiers are working defaults and must be centralized so a controlled rebrand remains possible before the first public release:

| Item | Default |
|---|---|
| Product name | Starfiniti Search for WooCommerce |
| Plugin slug | `starfiniti-search` |
| Text domain | `starfiniti-search` |
| PHP namespace | `Starfiniti\Search` |
| Database prefix after the WordPress prefix | `sfs_` |
| REST namespace | `starfiniti-search/v1` |
| JavaScript package scope | `@starfiniti-search/*` |
| MCP service name | `starfiniti-search-ops` |

Do not publish under a name that begins with or implies official association with FiboSearch. FiboSearch branding, marks, icons, support URLs, account links, telemetry, licensing code, and Freemius integration are not part of the new product identity.

## Read order

Codex must read these files in order before implementation:

1. `CODEX_START_HERE.md`
2. `docs/00_PRODUCT_CHARTER.md`
3. `docs/01_LICENSING_AND_UPSTREAM.md`
4. `docs/02_ARCHITECTURE.md`
5. `docs/03_CANONICAL_CATALOG_AND_SYNC.md`
6. `docs/04_LOCAL_SEARCH_ENGINE.md`
7. `docs/05_TYPESENSE_PROVIDER.md`
8. `docs/06_STOREFRONT_ADMIN_ANALYTICS.md`
9. `docs/07_SECURITY_PRIVACY_OPERATIONS.md`
10. `docs/08_MCP_CONTROL_PLANE.md`
11. `docs/09_PROVIDER_CONFORMANCE.md`
12. `docs/10_TESTING_PERFORMANCE_RELEASE.md`
13. `docs/11_IMPLEMENTATION_PLAN.md`
14. `docs/12_ASSUMPTIONS_AND_DECISIONS.md`
15. `docs/13_FUTURE_PROVIDERS.md`
16. `docs/REFERENCES.md`

## Core product decision

The plugin owns one consistent search experience and one canonical catalog model. Search engines are replaceable providers:

- `local`: an independently implemented inverted index stored in dedicated WordPress database tables.
- `typesense`: a remote or self-hosted Typesense collection.
- Future providers: Meilisearch, OpenSearch, Algolia, or another engine only after they pass the provider conformance suite.

The UI, WordPress integrations, analytics, relevance configuration, schema definitions, and public extension APIs must not depend on a specific provider.

## Required repository outputs

The implementation repository must ultimately contain at least:

```text
/plugin/starfiniti-search/
/apps/search-ops-mcp/
/packages/contracts/
/contracts/
/docs/
/tests/
/benchmarks/
/fixtures/
/tools/
/docker/
CODE_OF_CONDUCT.md
CONTRIBUTING.md
LICENSE
NOTICE
PRIVACY.md
SECURITY.md
THIRD_PARTY_NOTICES.md
UPSTREAM.md
composer.json
package.json
Makefile
```

## Standard commands

Codex must create and keep these commands working:

```bash
make bootstrap
make lint
make static-analysis
make test-unit
make test-integration
make test-provider
make test-e2e
make test-accessibility
make test-security
make benchmark
make qa
make package
```

`make qa` is the local equivalent of the mandatory CI release gate. `make package` must create a reproducible installable plugin ZIP from tracked source and documented build tooling.


---

# CODEX START HERE

## Role

You are the lead staff engineer, search engineer, WordPress/WooCommerce specialist, security engineer, test architect, and release owner for **Starfiniti Search for WooCommerce**.

Your job is to implement the complete specification in this repository. You are not building a demo, proof of concept, thin wrapper, or MVP. You are building a supportable, secure, observable, upgradeable product that may be installed on revenue-critical WooCommerce stores.

Read every specification file listed in `README.md` before changing production code.

## Mission

Fork the official free FiboSearch release that is current and explicitly GPL-compatible at the time the project is initialized. Preserve the good storefront experience and supported integration behavior that can legally and technically be carried forward. Replace the inherited search core with a provider-neutral platform containing:

1. An independently implemented local inverted-index engine.
2. A first-class Typesense engine.
3. One canonical WooCommerce catalog schema.
4. Reliable incremental and full indexing.
5. One consistent accessible storefront UI and results-page behavior.
6. Enterprise administration, relevance controls, diagnostics, analytics, security, observability, migration, backup, and rollback.
7. A separate secure Search Operations MCP server built over deterministic control APIs.
8. A provider contract and conformance suite that allows Meilisearch or other engines later without rewriting the product.

## Non-negotiable operating rules

1. **No MVP shortcuts.** Internal milestones are allowed. A public release is blocked until all mandatory requirements and release gates pass.
2. **No invented completion.** Compilation, a green unit-test subset, or a working happy path is not completion.
3. **No hidden deferrals.** Do not leave production `TODO`, `FIXME`, placeholder, stub, mock provider, fake health check, empty catch block, or unimplemented branch. Test fixtures and explicit test doubles are allowed only inside test code.
4. **No silent scope reduction.** When a requirement is difficult, decompose it, implement it, test it, and document it. Do not quietly reinterpret it as optional.
5. **No copying FiboSearch Pro code.** The public free fork is the only inherited source unless the repository owner separately supplies code with independently verified rights and provenance. Build the new local index from this specification and public standards.
6. **No trademark confusion.** Remove upstream product branding and commercial integrations while retaining required copyright and license notices.
7. **No provider leakage.** Domain, UI, analytics, and WordPress integration code must not contain Typesense-specific or local-engine-specific conditionals. Provider differences belong behind typed capabilities and adapter contracts.
8. **No synchronous heavy indexing.** Product saves, imports, checkout stock updates, and admin requests may enqueue bounded work only.
9. **No unrestricted credentials.** Never expose provisioning, administration, or write keys to browsers, REST responses, logs, analytics, diagnostics exports, MCP context, exceptions, or source control.
10. **No stale automatic failover.** Switching from Typesense to local is allowed only when continuous dual-write is enabled and a freshness and consistency policy confirms the local index is safe.
11. **No raw arbitrary operations.** Do not expose generic SQL, generic HTTP, arbitrary Typesense API, arbitrary shell, or generic MCP execution tools.
12. **No direct production edits to dependencies.** Patch through wrappers, upstream-compatible patches, or documented forks.
13. **No unmeasured search changes.** Ranking, tokenizer, schema, and provider-mapping changes require relevance and performance evidence.
14. **No public search dependency on MCP.** Search remains fully available when the MCP service and any Starfiniti control plane are unavailable.
15. **No forced external service.** Local search and bring-your-own Typesense must work without a Starfiniti SaaS account.

## Autonomous execution policy

Do not stop to ask routine implementation questions. Record reasonable assumptions in an ADR and proceed. Ask only when implementation is impossible without a genuinely missing secret, repository, legal source, or irreversible product decision that cannot be isolated behind configuration.

When a gate fails, remain on that gate, diagnose it, fix it, and rerun the evidence. Do not skip ahead and do not mark the requirement complete.

## Required first actions

Before feature work:

1. Record the exact upstream free FiboSearch source URL, version, release date, archive checksum, source commit or SVN revision, and license files in `UPSTREAM.md`.
2. Import it into a dedicated `upstream-fibosearch` branch and tag the immutable baseline.
3. Create a software-bill-of-materials and license inventory for every inherited PHP, JavaScript, image, font, and build dependency.
4. Produce `docs/AUDIT_BASELINE.md` containing:
   - inherited architecture and coupling map;
   - complete feature and integration inventory;
   - public hooks, shortcodes, widgets, blocks, REST/AJAX endpoints, options, tables, cron jobs, and assets;
   - all upstream branding, Freemius, telemetry, external calls, commercial links, and premium-condition code that must be removed;
   - security-sensitive entry points;
   - current test coverage and missing tests;
   - a deletion, preservation, and replacement decision for every major subsystem.
5. Build a reproducible baseline environment and behavior test harness before refactoring.
6. Capture baseline screenshots and E2E behavior for desktop, mobile, keyboard navigation, results page, details panel, shortcode, block, menu integration, and representative theme integrations.
7. Add `docs/IMPLEMENTATION_STATUS.md` and `docs/TRACEABILITY_MATRIX.md`. Every mandatory requirement must map to implementation, tests, documentation, and evidence.
8. Create ADR-0001 documenting why the free GPL fork is used, why the Pro index is not copied, and why the replacement uses ports and adapters.

## Engineering workflow

For every requirement:

1. Restate the acceptance condition in a testable form.
2. Add or update the traceability entry.
3. Write or update automated tests before or with implementation.
4. Implement in the correct architectural layer.
5. Add structured errors, metrics, diagnostics, and audit behavior where relevant.
6. Run the smallest relevant test suite.
7. Run all affected provider conformance tests.
8. Run `make qa` before closing a release gate.
9. Update documentation and migration notes.
10. Commit as a coherent unit with the requirement IDs in the commit message.

Use semantic commits such as:

```text
feat(local-index): implement generation-based atomic activation [LOC-021]
fix(typesense): parse per-document bulk import failures [TYP-034]
security(rest): block credential leakage in diagnostics [SEC-017]
test(relevance): add multilingual exact-SKU fixtures [REL-012]
```

## Architecture enforcement

Create automated architecture tests that fail when:

- domain code imports WordPress, WooCommerce, Typesense, Meilisearch, HTTP clients, or global functions;
- UI code switches on provider IDs;
- a provider bypasses canonical document validation;
- a public endpoint omits a permission, policy, rate, or query-budget declaration;
- a secret-bearing type is serializable into a public response;
- a production class depends on a test double;
- direct `wp_posts` or `wp_postmeta` search is introduced into the new local search path;
- heavy index work is called synchronously from product-save or request hooks.

## Definition of done

The product is done only when:

- all mandatory requirements are implemented;
- all release gates in `docs/10_TESTING_PERFORMANCE_RELEASE.md` pass;
- the traceability matrix has no uncovered mandatory requirement;
- the local and Typesense providers both pass the same conformance suite;
- migrations, fresh install, upgrade, reindex, provider switch, rollback, uninstall, disaster recovery, and failure injection are tested;
- security review and threat-model mitigations are complete;
- performance and relevance budgets are met on documented reference environments;
- user, administrator, operator, developer, security, privacy, and recovery documentation is complete;
- installable artifacts are reproducible and contain no development secrets or unlicensed assets;
- there are no production placeholders, skipped mandatory tests, unexplained flaky tests, or unresolved critical/high vulnerabilities.

Do not write “enterprise-ready” in release material until this definition is satisfied.


---

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


---

# 01 Licensing and Upstream Strategy

## 1. Legal posture

This document is an engineering provenance policy, not a substitute for legal advice. The implementation must use only source and assets whose rights and license compatibility have been verified.

The public free FiboSearch plugin is distributed as open-source software through WordPress.org. The project must pin and inspect the actual archive used. Do not rely only on a website statement or an assumed WordPress convention. Record the exact license headers and bundled license files from the imported source.

Use `GPL-2.0-or-later` as the target plugin license unless a qualified review of the exact upstream source requires a different compatible choice.

## 2. What may be inherited

Subject to file-level license verification, the fork may preserve and modify useful parts of the official free release, including:

- storefront form and autocomplete behavior;
- mobile overlay behavior;
- details-panel behavior;
- blocks, shortcodes, widgets, menu integration, and PHP embedding APIs;
- settings and personalization behavior;
- public extension hooks;
- theme and builder integrations;
- multilingual compatibility;
- WooCommerce search-results-page integration;
- public analytics behavior;
- templates, styles, scripts, and images whose licenses are compatible.

Every retained file must preserve existing copyright notices. Modified files must add a Starfiniti modification notice without erasing upstream authorship.

## 3. What must not be copied by default

Do not copy, decompile, reconstruct, extract, or import:

- FiboSearch Pro source or compiled assets;
- premium-only archives obtained through an account, customer site, cache, backup, or third party;
- private APIs, credentials, licensing data, customer data, or telemetry data;
- upstream trademarks, logos, branded illustrations, screenshots, or marketing copy unless separately licensed;
- premium behavior by reproducing non-public implementation details observed from code;
- proprietary support content beyond factual interoperability needs.

A merchant having a paid copy does not automatically establish clean provenance for a public fork. The local inverted index must be an independent implementation based on this specification, search-engine literature, public platform APIs, and internally created tests.

Comparable functionality is allowed to be designed independently. Names, class structures, algorithms, schemas, and code must be ours.

## 4. Mandatory upstream record

Create `UPSTREAM.md` with:

```yaml
project: FiboSearch - Ajax Search for WooCommerce
source_kind: official-wordpress-plugin-directory
source_url: <official source URL>
version: <exact version>
release_date: <exact date>
svn_revision_or_commit: <value if available>
archive_sha256: <sha256>
license_expression: <verified SPDX expression>
import_date_utc: <timestamp>
imported_by: <name or automation identity>
baseline_tag: <git tag>
```

Also include:

- a list of license and notice files;
- a list of upstream authors and copyright holders found in source;
- bundled third-party dependencies and their licenses;
- original and new file counts;
- the branch and procedure used to import future security fixes;
- a chronological log of upstream merges or cherry-picks.

`UPSTREAM.md` is immutable history. Corrections are appended with explanation rather than rewriting provenance.

## 5. Branch and update model

Use:

```text
upstream-fibosearch     immutable imports of official free releases
main                    Starfiniti product
release/*               stabilization only
security/*              embargoed security work where required
```

For each upstream update:

1. Import the exact archive into `upstream-fibosearch`.
2. Verify checksum and license inventory.
3. Tag it.
4. Diff it against the previous upstream baseline.
5. Classify security, compatibility, UX, and commercial-only changes.
6. Port relevant fixes through reviewed commits into `main`.
7. Run fork regression, provider conformance, security, and packaging tests.
8. Update `UPSTREAM.md`.

Do not blindly merge upstream into `main` after architecture divergence.

## 6. Branding and trademark separation

Before the first Starfiniti build:

- rename plugin title, slug, namespace, text domain, blocks, settings labels, menu labels, option prefixes, REST namespace, handles, CSS classes where safe, and JavaScript globals;
- remove FiboSearch logos, icons, screenshots, support links, account links, purchase links, review prompts, and promotional copy;
- remove “Fibo”, “FiboSearch”, “Ajax Search for WooCommerce” branding from user-facing product identity;
- retain factual attribution only in `NOTICE`, `UPSTREAM.md`, source headers, and a modest “About and licenses” administration page;
- do not imply endorsement, continuation, official compatibility, partnership, or upgrade lineage;
- use a unique icon and plugin-directory artwork created for Starfiniti.

Compatibility aliases for old shortcode names or hooks may exist during migration, but must be documented as deprecated technical aliases, not brand usage.

## 7. Freemius, telemetry, and commercial code removal

The baseline audit must identify and remove:

- Freemius SDK files and bootstrap;
- license checks;
- paid-plan gates;
- account and upgrade screens;
- affiliate parameters;
- outbound commercial notices;
- telemetry not essential to an explicitly configured service;
- remote calls not required for plugin operation;
- premium placeholders;
- support-ticket integrations tied to the upstream vendor.

Removal requires regression tests so that no hidden dependency causes activation errors, notices, cron failures, or broken settings.

Starfiniti telemetry must be absent by default. A future opt-in product-improvement program must be separately specified, explicit, documented, revocable, and data-minimized.

## 8. Copyright and notice policy

Create:

- `LICENSE`, containing the full applicable GPL text;
- `NOTICE`, naming the upstream project and authors and describing substantial modifications;
- `THIRD_PARTY_NOTICES.md`, generated from the dependency inventory;
- SPDX headers in new source files;
- preserved upstream headers in inherited files;
- a machine-readable SBOM for release artifacts.

Recommended new-file header:

```text
SPDX-License-Identifier: GPL-2.0-or-later
Copyright (C) <year> Starfiniti d.o.o.
```

For inherited modified files, retain existing notices and add:

```text
Modified by Starfiniti d.o.o. for Starfiniti Search.
See UPSTREAM.md and NOTICE for provenance.
```

## 9. WordPress.org distribution constraints

When preparing a WordPress.org version:

- all distributed plugin code, data, images, and libraries must use GPL-compatible licensing;
- human-readable source and build instructions must be publicly available;
- no trialware behavior may disable included functionality after a period or quota;
- external service functionality must be substantial, clearly documented, and consensually configured;
- the plugin must not contact external servers before an administrator configures and enables that provider;
- no executable JavaScript or CSS may be loaded remotely unless it is an essential documented part of a service and allowed by directory policy;
- public branding must be distinct and must not present upstream work as original;
- minified assets require maintained source and reproducible build instructions;
- plugin-directory submission must be reviewed against the current guidelines at release time, not only the guidelines that existed when development started.

A separate enterprise distribution may include service integrations, but the plugin code remains under its declared GPL-compatible license.

## 10. Provenance release gate

Requirements:

- `LIC-001 MUST`: exact official free upstream is pinned and checksummed.
- `LIC-002 MUST`: actual file-level license inventory has no unknown or incompatible production artifact.
- `LIC-003 MUST`: FiboSearch Pro code is absent.
- `LIC-004 MUST`: upstream copyright notices are preserved.
- `LIC-005 MUST`: user-facing branding is independent.
- `LIC-006 MUST`: Freemius and upstream commercial endpoints are removed.
- `LIC-007 MUST`: source/build instructions reproduce distributed assets.
- `LIC-008 MUST`: release SBOM and third-party notices match the package.
- `LIC-009 MUST`: a current WordPress.org policy review is documented before submission.
- `LIC-010 MUST`: an automated scan fails CI when prohibited upstream domains, marks, credentials, or unapproved binaries enter the release package.

No feature gate may be called complete until the initial provenance gate passes.


---

# 02 Architecture

## 1. Architectural style

Use a modular monolith for the WordPress plugin with explicit ports and adapters, plus a separate MCP application. The WordPress plugin remains deployable as one normal plugin ZIP. Internal boundaries are enforced by namespaces, Composer packages where useful, and architecture tests.

The dependency direction is:

```text
Presentation and WordPress adapters
              |
              v
       Application services
              |
              v
        Domain and contracts

Infrastructure providers implement ports that point inward.
The domain never imports WordPress, WooCommerce, HTTP, SQL, Typesense, or MCP.
```

Use dependency inversion, not a framework-sized service container. Construction belongs in a composition root. Runtime service location from domain code is prohibited.

## 2. Proposed repository layout

```text
plugin/starfiniti-search/
├── starfiniti-search.php
├── uninstall.php
├── readme.txt
├── composer.json
├── src/
│   ├── Bootstrap/
│   ├── Domain/
│   │   ├── Catalog/
│   │   ├── Query/
│   │   ├── Ranking/
│   │   ├── Relevance/
│   │   ├── Analytics/
│   │   ├── Security/
│   │   └── Shared/
│   ├── Application/
│   │   ├── Search/
│   │   ├── Indexing/
│   │   ├── Providers/
│   │   ├── Configuration/
│   │   ├── Diagnostics/
│   │   ├── Analytics/
│   │   └── Operations/
│   ├── Infrastructure/
│   │   ├── WordPress/
│   │   ├── WooCommerce/
│   │   ├── Persistence/
│   │   ├── Queue/
│   │   ├── Cache/
│   │   ├── Http/
│   │   ├── Crypto/
│   │   ├── LocalSearch/
│   │   └── Typesense/
│   ├── Presentation/
│   │   ├── Rest/
│   │   ├── FastEndpoint/
│   │   ├── Admin/
│   │   ├── Blocks/
│   │   ├── Cli/
│   │   ├── SiteHealth/
│   │   └── Compatibility/
│   └── LegacyBridge/
├── assets/
│   ├── src/
│   └── build/
├── templates/
├── migrations/
├── languages/
└── tests/

apps/search-ops-mcp/
├── src/
├── tests/
├── package.json
└── README.md

packages/contracts/
├── schemas/
├── openapi/
├── generated/
└── tests/

contracts/
├── search-document.schema.json
├── search-request.schema.json
├── search-response.schema.json
├── provider-capabilities.schema.json
├── desired-configuration.schema.json
├── health-report.schema.json
└── operation-plan.schema.json

tests/
├── fixtures/
├── provider-conformance/
├── e2e/
├── performance/
├── security/
├── accessibility/
└── upgrade/
```

The final installable ZIP contains only the plugin runtime, required built assets, license notices, and user-facing documentation. MCP and development tooling are separate artifacts.

## 3. Core domain types

Use immutable typed value objects and DTOs. Public contracts must be versioned.

At minimum:

```php
interface SearchReaderInterface
{
    public function search(SearchRequest $request): SearchResponse;
    public function suggest(SuggestRequest $request): SearchResponse;
}

interface IndexWriterInterface
{
    public function upsertBatch(IndexBatch $batch): BatchWriteResult;
    public function deleteBatch(DocumentIdBatch $batch): BatchWriteResult;
}

interface SchemaManagerInterface
{
    public function inspect(): ActualSchema;
    public function plan(DesiredSchema $desired): SchemaPlan;
    public function apply(SchemaPlan $plan, OperationContext $context): OperationResult;
    public function activate(IndexVersion $candidate, OperationContext $context): ActivationResult;
    public function rollback(IndexVersion $target, OperationContext $context): ActivationResult;
}

interface ProviderHealthInterface
{
    public function health(HealthCheckDepth $depth): HealthReport;
}

interface SearchProviderInterface extends
    SearchReaderInterface,
    IndexWriterInterface,
    SchemaManagerInterface,
    ProviderHealthInterface
{
    public function descriptor(): ProviderDescriptor;
    public function capabilities(): ProviderCapabilities;
}
```

Do not force one implementation object to become a god class. Separate implementations may be composed behind `SearchProviderInterface`.

Mandatory domain objects include:

- `SearchDocument`;
- `SearchDocumentId`;
- `CatalogContext`;
- `SearchContext`;
- `SearchRequest`;
- `SuggestRequest`;
- `SearchResponse`;
- `SearchHit`;
- `Highlight`;
- `FacetRequest`;
- `FacetResult`;
- `SortSpecification`;
- `FilterExpression`;
- `QueryPlan`;
- `RankingProfile`;
- `AnalyzerProfile`;
- `ProviderCapabilities`;
- `ProviderConfiguration`;
- `DesiredSchema`;
- `IndexVersion`;
- `IndexGeneration`;
- `HealthReport`;
- `OperationPlan`;
- `OperationResult`;
- `SyncEvent`;
- `AuditEvent`;
- `SecretReference`.

## 4. Provider registry and capability model

Providers are registered in the composition root. A provider descriptor contains:

```text
id
human_name
adapter_version
supported_server_versions
configuration_schema
capabilities
health_check
migration_support
documentation_reference
```

Capabilities must be structured, not a flat list of booleans. Each capability has a state:

```text
native
emulated
degraded
unsupported
```

It may also declare limits and constraints:

```json
{
  "fuzzy_search": {
    "state": "native",
    "max_edit_distance": 2,
    "notes": []
  },
  "semantic_search": {
    "state": "unsupported",
    "notes": ["Not available in the local provider"]
  }
}
```

The admin UI renders controls from desired configuration plus capabilities. Unsupported settings must be disabled with an explanation. The application must reject configuration that cannot be safely mapped. It must not silently discard it.

Mandatory capability categories:

- exact, prefix, phrase, infix, and fuzzy matching;
- token and locale analyzers;
- synonyms and stop words;
- custom ranking and tie breakers;
- filters and nested Boolean expressions;
- facets and facet counts;
- sorting;
- grouping and variation collapse;
- curations and query redirects;
- highlighting;
- pagination modes;
- vector and hybrid search;
- analytics;
- index aliases or atomic generation activation;
- bulk writes;
- scoped security;
- direct-browser query support;
- maximum supported document, field, query, filter, and page sizes.

## 5. Canonical request and response

The frontend and search-results integration produce one `SearchRequest` regardless of provider:

```json
{
  "contract_version": "1.0",
  "query": "waterproof boot 44",
  "context": {
    "site_id": "site-uuid",
    "blog_id": 1,
    "locale": "sl_SI",
    "currency": "EUR",
    "customer_scope": ["public"],
    "channel": "web"
  },
  "fields": ["title", "sku", "brand", "categories", "attributes", "description"],
  "filters": {
    "and": [
      {"field": "stock.searchable", "op": "eq", "value": true},
      {"field": "attributes.size", "op": "contains", "value": "44"}
    ]
  },
  "facets": ["brand", "categories", "attributes.size"],
  "sort": [{"field": "_relevance", "direction": "desc"}],
  "page": {"number": 1, "size": 12},
  "options": {
    "highlight": true,
    "include_explanation": false,
    "suggestion_mode": "autocomplete"
  }
}
```

The provider returns one normalized `SearchResponse`:

```json
{
  "contract_version": "1.0",
  "provider": "typesense",
  "index_version": "products-v12",
  "query_id": "opaque-id",
  "hits": [
    {
      "document_id": "product:123",
      "entity_type": "product",
      "entity_id": 123,
      "parent_id": null,
      "score": 0.982,
      "rank": 1,
      "highlights": {},
      "matched_fields": ["title", "attributes.size"],
      "projection": {}
    }
  ],
  "facets": {},
  "total": 125,
  "page": {"number": 1, "size": 12, "has_more": true},
  "timing": {
    "provider_ms": 18,
    "application_ms": 7,
    "total_ms": 25
  },
  "warnings": []
}
```

Provider-native scores are not exposed as directly comparable values. Each provider normalizes score to a documented diagnostic range while rank remains authoritative.

## 6. Search gateway

All user-facing search enters through `SearchGateway`. It performs:

1. request contract validation;
2. query-budget validation;
3. context and visibility policy construction;
4. query normalization;
5. ranking-profile resolution;
6. provider selection;
7. cache lookup;
8. provider execution with deadline and cancellation;
9. result validation;
10. dynamic hydration and authorization recheck where required;
11. canonical analytics emission;
12. cache write;
13. normalized response.

The gateway never directly creates provider query syntax. Provider-specific query compilation belongs in the provider adapter.

## 7. Configuration architecture

Configuration has four layers, merged in a deterministic order:

1. product defaults;
2. site configuration;
3. environment overrides;
4. request-safe context overrides.

Secrets are references, not ordinary configuration values.

Use a versioned `DesiredSearchConfiguration` document containing:

- provider selection;
- provider connection reference;
- canonical schema version;
- document projection;
- searchable fields and weights;
- analyzer profiles by locale;
- filters and facets;
- ranking profiles;
- variation behavior;
- stock and visibility policy;
- caching;
- analytics and retention;
- UI presentation;
- safe fallback policy;
- index and reconciliation policy.

Every configuration update creates a new immutable revision with:

```text
revision_id
parent_revision_id
author
timestamp
reason
schema_version
content_hash
validation_result
activation_status
```

Configuration changes that alter an index schema or analyzer must generate an operation plan rather than mutating the active index in place.

## 8. Desired state and reconciliation

The system maintains:

```text
desired configuration
actual local state
actual remote state
observed catalog state
```

A reconciler compares them and emits a deterministic plan. Plans contain no secrets and have:

- unique operation ID;
- idempotency key;
- preconditions;
- affected provider and index versions;
- estimated document and storage impact;
- steps;
- safety checks;
- activation action;
- rollback target;
- required authorization;
- dry-run result.

The admin UI, WP-CLI, REST control API, and MCP call the same application commands. There is no AI-only or admin-page-only implementation.

## 9. Read and write topology

Read provider and write targets are distinct concepts:

```text
active_read_provider: local | typesense
write_targets:
  - local
  - typesense
```

Supported modes:

### Local only

```text
read: local
write: local
```

### Typesense only

```text
read: typesense
write: typesense
```

### Shadow migration

```text
read: local
write: local + typesense
compare_shadow_reads: sampled
```

### Verified failover-ready

```text
read: typesense
write: local + typesense
local_freshness_required: true
```

A transition is a state machine with explicit validation and audit events. Directly changing an option from `local` to `typesense` is prohibited.

## 10. Query transport modes

The same UI may use different transports.

### WordPress REST transport

Use for:

- logged-in, B2B, restricted, or user-specific catalogs;
- dynamic pricing requiring WooCommerce execution;
- administrative search laboratory;
- environments where direct external access is unavailable.

### Local fast public endpoint

An optional, independently security-reviewed endpoint may use a minimal WordPress bootstrap and read only dedicated index tables plus a generated immutable safe configuration snapshot.

It is allowed only when:

- catalog data is public;
- results contain no user-specific price or visibility data;
- endpoint configuration has a verified generation and signature;
- query complexity, rate, and output are bounded;
- full REST fallback exists;
- security and performance gates pass.

It must never bootstrap arbitrary plugins or execute merchant-configurable PHP.

### Direct Typesense browser transport

Allowed only when:

- every returned document field is public;
- a search-only or scoped key is used;
- the key cannot list, write, delete, or alter schema;
- mandatory filters cannot be removed by the browser;
- tenant, locale, and catalog restrictions are enforced;
- dynamic result fields are safely hydrated or omitted;
- CORS and origin policy are explicit.

### Server-proxied Typesense transport

Required for restricted catalogs, user-specific visibility, sensitive facets, or dynamic policy.

The transport decision is made by a `QueryTransportPolicy`, not by UI conditionals.

## 11. Extension architecture

Document and version all supported extension points:

- canonical document enrichment;
- identifier providers;
- brand taxonomy providers;
- visibility scopes;
- price projections;
- analyzers and token filters;
- ranking signals;
- facets;
- curations;
- provider registration;
- result projection;
- storefront templates;
- analytics event filters;
- health checks.

Extensions must receive immutable typed values where possible. Legacy WordPress filters may bridge to typed events but must be deprecated through a documented schedule if unsafe.

Every public extension point needs:

- stable name;
- input and output contract;
- security and performance rules;
- example;
- version introduced;
- deprecation policy;
- tests.

## 12. Error model

Use typed error categories:

```text
validation
authorization
rate_limit
query_budget
provider_unavailable
provider_timeout
provider_misconfigured
schema_mismatch
index_not_ready
partial_write
catalog_hydration
stale_index
internal
```

Errors include:

- safe public code;
- correlation ID;
- retryability;
- provider ID where safe;
- operator details in protected logs;
- redacted context;
- user-safe localized message.

Never return stack traces, SQL, hosts containing credentials, API keys, raw provider bodies, or filesystem paths publicly.

## 13. Backward compatibility

During migration from the free fork:

- retain legacy shortcodes as aliases;
- preserve expected theme integration hooks where safe;
- map legacy options into new configuration through a one-time migration;
- keep explicit deprecation telemetry only in local logs, not remote telemetry;
- provide admin notices with remediation, not permanent compatibility code without sunset;
- test upgrade from the exact pinned baseline and at least two earlier supported free releases if their option schemas differ.

Compatibility aliases must not force provider-specific legacy constraints into the new domain.

## 14. Architecture requirements

- `ARC-001 MUST`: domain and application layers are provider-neutral.
- `ARC-002 MUST`: UI does not branch on provider IDs.
- `ARC-003 MUST`: canonical contracts are versioned and schema-validated.
- `ARC-004 MUST`: provider capability states are explicit.
- `ARC-005 MUST`: read provider and write targets are separately modeled.
- `ARC-006 MUST`: configuration is immutable, revisioned, and auditable.
- `ARC-007 MUST`: schema-affecting changes use planned, reversible operations.
- `ARC-008 MUST`: every operational surface calls the same application commands.
- `ARC-009 MUST`: architecture tests enforce dependency boundaries.
- `ARC-010 MUST`: transport policy protects restricted or dynamic catalogs.
- `ARC-011 MUST`: errors are typed, redacted, and correlated.
- `ARC-012 MUST`: extension points are documented, versioned, and tested.


---

# 03 Canonical Catalog Model and Synchronization

## 1. Source of truth

WooCommerce remains the catalog source of truth. Read products through supported WooCommerce CRUD objects and data stores. Do not assume that products permanently live in `wp_posts` and `wp_postmeta`. Direct SQL may be used for the search index and carefully documented reconciliation optimizations, but not as the primary product-domain API.

The indexing pipeline is:

```text
WooCommerce event or reconciliation discovery
                    |
                    v
          Catalog snapshot loader
                    |
                    v
       Canonical document builder
                    |
                    v
       Schema and policy validation
                    |
                    v
        Durable indexing outbox
                    |
                    v
       Provider projection and write
                    |
                    v
        Verification and checkpoint
```

## 2. Canonical document

Create a versioned `SearchDocument` contract. Provider projections may omit unsupported fields but may not invent or reinterpret business data.

A product document must support at least:

```json
{
  "contract_version": "1.0",
  "schema_version": 1,
  "document_id": "product:123",
  "entity_type": "product",
  "entity_id": 123,
  "parent_id": null,
  "site_id": "uuid",
  "blog_id": 1,
  "locale": "sl_SI",
  "channel": "web",
  "status": "publish",
  "visibility": {
    "catalog": true,
    "search": true,
    "password_protected": false,
    "scope_tokens": ["public"]
  },
  "identity": {
    "title": "Example product",
    "slug": "example-product",
    "url": "https://example.invalid/product/example-product/",
    "sku": "ABC-123",
    "gtin": "1234567890123",
    "other_identifiers": []
  },
  "content": {
    "short_description_text": "",
    "description_text": "",
    "search_keywords": [],
    "excerpt": ""
  },
  "classification": {
    "category_ids": [],
    "category_paths": [],
    "tag_ids": [],
    "brands": [],
    "taxonomies": {}
  },
  "attributes": {},
  "pricing": {
    "currency": "EUR",
    "regular_min_minor": 0,
    "regular_max_minor": 0,
    "sale_min_minor": 0,
    "sale_max_minor": 0,
    "active_min_minor": 0,
    "active_max_minor": 0,
    "tax_display_mode": "inclusive",
    "price_scope": "public"
  },
  "inventory": {
    "stock_status": "instock",
    "quantity": null,
    "backorders": "no",
    "purchasable": true,
    "searchable": true
  },
  "media": {
    "primary_image_id": 0,
    "primary_image_url": "",
    "thumbnail_url": "",
    "alt": ""
  },
  "quality": {
    "average_rating_scaled": 0,
    "rating_count": 0,
    "sales_count": 0,
    "menu_order": 0,
    "featured": false
  },
  "variation": {
    "strategy": "parent_collapsed",
    "attribute_signature": {},
    "matching_variation_ids": []
  },
  "custom": {},
  "timestamps": {
    "created_at": "",
    "modified_at": "",
    "source_observed_at": ""
  },
  "checksum": ""
}
```

Use integer minor currency units, not floats, for indexed price fields. Preserve the source currency and price scope.

## 3. Document identity and lifecycle

`document_id` is stable and globally unambiguous within an installation:

```text
product:<id>
variation:<id>
term:<taxonomy>:<term_taxonomy_id>
post:<post_type>:<id>
```

Never recycle an ID across entity types. A delete is a first-class tombstone event. Provider projections must be able to delete by document ID without loading the product again.

Every document has:

- canonical schema version;
- normalized content checksum;
- source modification timestamp;
- provider projection checksum;
- last successful write checkpoint;
- visibility scope hash.

The system skips a provider write only when the current canonical checksum, projection checksum, configuration revision, and provider schema version all match.

## 4. Product and variation strategies

Support two explicitly tested strategies.

### 4.1 Parent-collapsed, default

- one primary result represents the variable parent;
- variation identifiers and attributes contribute to matching;
- an exact variation SKU may identify and link to the matching variation;
- the response may include matching variation IDs and selected attributes;
- duplicate parent cards are prevented;
- price and stock projection follows configured WooCommerce semantics.

### 4.2 Variation-as-result

- eligible variations are independent documents and results;
- parent data may be denormalized into each variation projection;
- exact identifiers point directly to the variation;
- filters and price use variation values;
- parent and variation duplication rules are explicit;
- add-to-cart links carry a valid variation selection.

Switching strategy is a schema-affecting change and requires a shadow rebuild.

## 5. Language and translation model

Index language-specific documents, not one ambiguous multilingual blob.

Each document has exactly one locale and language analyzer profile. Integrations must support:

- WordPress site locale;
- WPML languages, including hidden-language policy;
- Polylang;
- TranslatePress where product content is available through supported APIs;
- fallback-to-default-language policy;
- separate URLs by locale;
- locale-specific synonyms, stop words, stemming, and normalization.

The provider may use separate indexes or a locale filter, but the canonical contract remains identical. The active query context always includes a locale.

A translation deletion or visibility change must remove or update only the affected locale document.

## 6. Categories, brands, attributes, and custom fields

### Categories

Store:

- stable IDs;
- names by locale;
- full ancestor path;
- slugs;
- optional image projection;
- searchable and facetable values separately.

### Brands

Use a provider registry. Support WooCommerce Brands and configured third-party taxonomies without hard-coding one plugin into the domain. A brand value contains ID, taxonomy, localized name, slug, URL, and optional image.

### Attributes

Normalize global and custom product attributes into stable keys. Attribute keys must not depend only on a translated display label. Keep:

```text
canonical key
source taxonomy or source name
localized label
normalized values
display values
variation flag
facet eligibility
```

### Custom fields

Administrators explicitly allow-list custom fields. Each field declares:

- canonical key;
- source resolver;
- type;
- searchable, filterable, facetable, sortable, or display-only use;
- visibility scope;
- sanitizer;
- maximum length and cardinality;
- locale behavior.

Never index arbitrary metadata by wildcard. Block secrets, internal tokens, serialized objects, and personal data by default.

## 7. Visibility and authorization

Search visibility is a policy, not a single post-status check.

The canonical builder must evaluate:

- publication status;
- catalog visibility;
- password protection;
- WooCommerce out-of-stock visibility;
- scheduled publication;
- scheduled sale and availability;
- product and category exclusions;
- language visibility;
- role or customer-group restrictions;
- membership, wholesale, or B2B catalog restrictions through registered adapters;
- explicit merchant curation exclusions;
- custom extension policies.

For public catalogs, index only public-safe fields.

For restricted catalogs, choose one of:

1. **Scope-token indexing:** documents carry scope tokens and the query receives non-removable authorized filters.
2. **Server-side candidate hydration:** provider returns candidate IDs, then WooCommerce and registered policies revalidate each result before response.
3. **Separate indexes:** high-isolation catalogs use separate provider indexes and keys.

The policy must guarantee no unauthorized product title, image, price, stock, category, facet count, or existence signal leaks. Facets are data too.

## 8. Price and currency policy

Search must not cache or expose the wrong price.

Support documented modes:

- one public currency and tax display;
- one document projection per currency;
- one document projection per price list or customer scope;
- server-side price hydration;
- display no price in direct search when safe price cannot be determined.

The configuration wizard detects common multi-currency, role-pricing, wholesale, and dynamic-pricing plugins through adapters. Unknown dynamic pricing causes direct-browser projection to be disabled unless the administrator explicitly confirms a safe public price policy.

Scheduled sales generate time-based sync events. A periodic verifier catches missed scheduler events.

## 9. Media and output safety

Store media IDs and provider-safe URLs. Before rendering:

- validate URL scheme and origin policy;
- escape attributes and text;
- use WordPress image helpers when executing inside WordPress;
- never render stored arbitrary HTML from the index;
- use text extraction for descriptions;
- sanitize highlight fragments through an allow-list generated by the application, not trusted provider HTML.

Image changes must not require re-tokenizing unrelated text when the provider supports partial document updates, but checksums and consistency must remain correct.

## 10. Event capture

Listen to WooCommerce and WordPress lifecycle APIs appropriate to the supported version matrix, including:

- product create, update, trash, restore, and permanent delete;
- variation create, update, and delete;
- stock status and quantity updates;
- price and scheduled-sale changes;
- product type changes;
- taxonomy create, update, assignment, and delete;
- product attribute changes;
- image and relevant attachment changes;
- translation create, update, delete, and visibility changes;
- configured custom-field changes;
- imports through supported WooCommerce and third-party import APIs;
- REST and CLI updates;
- bulk edits;
- plugin integration invalidation events.

Do not rely only on `save_post`. Direct database writes by unsupported systems cannot be intercepted reliably, which is why reconciliation is mandatory.

## 11. Durable outbox

Create a dedicated outbox table. An event contains:

```text
event_id
event_type
entity_type
entity_id
locale_hint
reason
deduplication_key
source_timestamp
observed_at
not_before
priority
attempt_count
lease_owner
lease_expires_at
status
last_error_code
correlation_id
```

Required behavior:

- enqueue is lightweight;
- duplicate events collapse without losing a later change;
- delete tombstones outrank stale upserts;
- jobs are idempotent;
- leases expire safely after worker death;
- retries use exponential backoff with jitter;
- poison jobs move to a dead-letter state after policy limits;
- operators can inspect, retry, or discard with audit records;
- processing is bounded by time, memory, documents, and provider bytes;
- backpressure adapts batch size;
- high-frequency stock changes coalesce;
- urgent visibility deletions receive higher priority.

Use Action Scheduler as the WordPress execution substrate where available through WooCommerce, but keep the durable business state in product-owned tables so queue history and correctness do not depend exclusively on Action Scheduler retention.

## 12. Full builds

A full build is an operation with phases:

```text
planned
preflight
snapshotting
building
writing
verifying
ready_to_activate
activating
active
retained_for_rollback
cleaning
completed
failed
cancelled
```

Requirements:

- build beside the active version;
- lock only the operation, not normal product writes or search;
- record a catalog high-water mark;
- process bounded pages with resumable cursors;
- merge changes that happen during the build through the outbox;
- verify count, sample checksums, visibility, identifiers, facets, and known queries;
- block activation on unresolved critical mismatches;
- activate atomically;
- retain the old version;
- clean old versions asynchronously after retention.

A browser refresh or worker restart must not restart the build from zero.

## 13. Incremental synchronization

For each event:

1. acquire a lease;
2. load current canonical entity or confirm deletion;
3. validate document and visibility;
4. project for each configured write target;
5. write idempotently;
6. parse per-document provider status;
7. checkpoint checksums and versions;
8. complete the event;
9. emit metrics and audit only where appropriate.

A partial provider failure leaves the event retryable for failed targets without duplicating successful work.

When dual-write is enabled, maintain independent checkpoints for local and Typesense. Do not mark the event globally complete until policy-required targets have succeeded or the operation is explicitly degraded.

## 14. Reconciliation and drift

Run periodic reconciliation independent of normal hooks.

Levels:

### Quick reconciliation

- compare WooCommerce eligible counts against index counts by locale and entity type;
- compare latest source and provider checkpoints;
- detect stale queue leases;
- detect orphan provider versions and aliases;
- verify active schema hash.

### Sample reconciliation

- deterministic random sample of source entities;
- rebuild canonical checksums;
- compare provider projection checksums or selected fields;
- run exact SKU and visibility checks.

### Full reconciliation

- enumerate eligible source identities;
- compare all indexed identities;
- repair missing, stale, and orphan documents;
- produce an auditable report.

Automatic repair must be bounded. Large discrepancies create a planned rebuild and require operator awareness.

## 15. Batch and resource policy

Every batch operation declares:

- maximum documents;
- maximum serialized bytes;
- maximum execution time;
- memory soft limit;
- provider deadline;
- retry policy;
- cancellation token.

Adaptive batching may reduce size after timeouts or memory pressure and cautiously increase after sustained success. Persist learned safe batch sizes per environment and provider, within configured bounds.

Never construct an unbounded array of all product IDs or documents.

## 16. Synchronization CLI

Implement at least:

```bash
wp starfiniti-search status
wp starfiniti-search index plan --provider=local
wp starfiniti-search index build --provider=local
wp starfiniti-search index build --provider=typesense
wp starfiniti-search index verify --provider=typesense
wp starfiniti-search index activate --operation=<id>
wp starfiniti-search index rollback --to=<version>
wp starfiniti-search sync run --limit=500
wp starfiniti-search sync retry --event=<id>
wp starfiniti-search reconcile --level=sample
wp starfiniti-search reconcile --level=full --dry-run
wp starfiniti-search document inspect --id=product:123 --locale=sl_SI
wp starfiniti-search diagnostics export
```

Mutating commands support `--dry-run`, machine-readable JSON, correlation IDs, and explicit exit codes.

## 17. Canonical and synchronization requirements

- `CAT-001 MUST`: all providers consume validated canonical documents.
- `CAT-002 MUST`: document IDs and schema versions are stable.
- `CAT-003 MUST`: WooCommerce CRUD is the primary product source API.
- `CAT-004 MUST`: price uses integer minor units and explicit scope.
- `CAT-005 MUST`: language documents are distinct.
- `CAT-006 MUST`: custom fields are allow-listed and typed.
- `CAT-007 MUST`: restricted catalog data cannot leak through hits or facets.
- `CAT-008 MUST`: variation strategies are explicit and tested.
- `SYN-001 MUST`: lifecycle hooks enqueue bounded durable events.
- `SYN-002 MUST`: events are idempotent, leased, retried, and dead-lettered.
- `SYN-003 MUST`: full builds are resumable and do not interrupt active search.
- `SYN-004 MUST`: writes during a full build are merged before activation.
- `SYN-005 MUST`: dual-write has per-target checkpoints.
- `SYN-006 MUST`: periodic reconciliation detects missed direct writes and drift.
- `SYN-007 MUST`: batch resource use is bounded and adaptive.
- `SYN-008 MUST`: operator and CLI controls expose progress, failure, retry, verification, activation, and rollback.


---

# 04 Local Search Engine

## 1. Objective

Implement a real inverted-index search engine in dedicated WordPress database tables. It must not be a wrapper around `WP_Query`, repeated `LIKE '%query%'`, unbounded `postmeta` joins, or WordPress full bootstrap for every public query.

The local provider must support useful search on ordinary hosting while remaining correct, observable, upgradeable, and recoverable. Large catalogs may choose Typesense, but the local provider is a first-class product, not a deliberately weak free tier.

## 2. Independent implementation rule

Do not reproduce FiboSearch Pro classes, table names, SQL, binary formats, query structure, or code. Design the local engine from this document and public information-retrieval concepts.

Document original design decisions in ADRs:

- tokenizer and analyzer model;
- term dictionary;
- posting format;
- phrase-position encoding;
- prefix and fuzzy candidate strategy;
- ranking formula;
- facet storage;
- generation activation;
- storage estimates and tested limits.

## 3. Storage design

Use explicit versioned migrations. Do not rely on `dbDelta()` alone for complex index changes. Migrations must be repeatable, inspectable, and safe across supported MySQL and MariaDB versions. Avoid foreign keys for WordPress hosting compatibility. Use carefully chosen composite indexes and binary collations for normalized tokens.

Recommended logical tables, with the actual WordPress prefix prepended:

```text
sfs_index_generations
sfs_index_documents
sfs_index_terms
sfs_index_postings
sfs_index_term_ngrams
sfs_index_identifiers
sfs_index_facet_values
sfs_index_document_facets
sfs_index_stats
sfs_index_checkpoints
sfs_sync_outbox
sfs_sync_dead_letters
sfs_operation_runs
sfs_operation_steps
sfs_query_cache
sfs_synonyms
sfs_stop_words
sfs_curations
sfs_query_redirects
sfs_analytics_events
sfs_analytics_daily
sfs_audit_log
```

The implementation may consolidate tables only when benchmarks and query plans prove equivalent correctness and performance.

### 3.1 Generations

`sfs_index_generations` records:

```text
generation_id
provider_id
state
schema_version
configuration_revision
analyzer_revision
created_at
build_started_at
build_completed_at
activated_at
retire_after
document_count
term_count
posting_count
storage_bytes_estimate
catalog_high_water_mark
verification_summary
content_hash
```

A single atomic active-generation pointer is maintained per site, locale, and document collection. Search reads only the active generation. Candidate generations are invisible until verified and activated.

### 3.2 Documents

`sfs_index_documents` contains compact ranking and projection data:

```text
generation_id
internal_document_id
external_document_id
entity_type
entity_id
parent_entity_id
locale
document_length
title_length
visibility_scope_hash
rank_popularity
rank_rating
rank_sales
rank_menu_order
stock_searchable
price_min_minor
price_max_minor
modified_epoch
canonical_checksum
projection_checksum
stored_projection_json
```

Do not put every searchable token into a single text field and scan it.

### 3.3 Term dictionary

`sfs_index_terms` stores one normalized term per generation, locale, and analyzer:

```text
term_id
generation_id
locale
analyzer_id
term_binary
term_display
document_frequency
collection_frequency
flags
```

Use a deterministic binary collation for lookup. Define a safe upper-bound algorithm for prefix range queries rather than using a leading wildcard.

### 3.4 Postings

`sfs_index_postings` stores one row per term, document, and field group, unless a measured packed format performs better:

```text
generation_id
term_id
internal_document_id
field_id
term_frequency
first_position
positions_blob
field_length
flags
```

Position data is optional per configured field but mandatory for title and configured phrase-search fields. Encode positions compactly with a versioned format and property tests. A corrupted blob must fail safely and be repairable through rebuild.

### 3.5 Fuzzy vocabulary index

`sfs_index_term_ngrams` maps normalized term trigrams or language-appropriate grams to term IDs. Fuzzy search:

1. derives grams from the query term;
2. retrieves a bounded vocabulary candidate set;
3. calculates Damerau-Levenshtein or a documented equivalent in application code;
4. applies length-aware maximum edit distance;
5. limits expansions by quality and cost.

Never compare a fuzzy term against every document or every dictionary entry.

### 3.6 Identifiers

`sfs_index_identifiers` handles SKU, GTIN, MPN, ISBN, and configured identifiers separately:

```text
generation_id
identifier_type
normalized_value
reversed_value_if_enabled
internal_document_id
is_primary
visibility_scope_hash
```

Support exact and prefix matching by default. Controlled infix matching is allowed for identifier fields only, with a minimum length and indexed strategy. Exact identifier match receives a strong deterministic boost and must not be lost through ordinary token normalization.

### 3.7 Facets

Store normalized facet values separately from display labels. Facets must support:

- category hierarchy;
- brand;
- attributes;
- price ranges;
- stock;
- rating;
- configured custom fields.

Facet counts must be computed over the filtered result set, not the whole catalog. Query plans must cap requested facets and values. High-cardinality fields require explicit opt-in and warnings.

## 4. Analyzer pipeline

Create locale-aware analyzer profiles composed of versioned stages:

```text
Unicode normalization
case folding
HTML-to-text extraction before indexing
separator and punctuation policy
tokenization
diacritic policy
possessive and apostrophe policy
hyphen and model-number policy
stop-word filtering
optional stemming
optional decompounding
synonym indexing or query expansion policy
```

The exact analyzer revision is part of the generation identity.

### 4.1 Required behavior

- preserve original display text separately;
- normalize Unicode consistently on indexing and query paths;
- make diacritic folding configurable by locale and field;
- preserve meaningful model tokens such as `AB-123`, `M.2`, `3/4`, and `10x20`;
- distinguish human text from identifiers;
- avoid removing one-character tokens when configured product domains require them;
- provide pluggable analyzers for CJK and languages without whitespace tokenization;
- provide RTL-safe text handling;
- cap token length and document token count with diagnostics;
- never execute HTML, shortcodes, or embedded scripts during tokenization.

### 4.2 Stemming

Stemming is optional and locale-specific. Use only a maintained GPL-compatible implementation with a recorded license. Exact and unstemmed tokens remain available so product names and identifiers are not damaged.

## 5. Query parsing and planning

The query parser produces a provider-neutral abstract syntax tree. It supports:

- terms;
- quoted phrases;
- configured field-specific operators for administrative search only;
- Boolean filters generated by UI, not arbitrary public query syntax;
- query redirects;
- equivalent and directional synonyms;
- exact identifier detection;
- locale normalization;
- typo and prefix policy by term length.

Public query syntax must be deliberately small to prevent expensive or surprising queries.

A local `QueryPlanner` estimates cost before execution:

```text
number of query terms
synonym expansions
prefix expansions
fuzzy vocabulary candidates
requested facets
filter complexity
page depth
highlight fields
scope complexity
```

Requests over budget return a typed safe error or a simplified plan. They must not run an unbounded query.

## 6. Matching strategy

Execute in ordered stages:

1. query redirect check;
2. exact primary identifier lookup;
3. exact title or configured phrase boost;
4. exact normalized terms;
5. prefix term expansions;
6. synonym expansions;
7. controlled fuzzy expansions;
8. filters and visibility scopes;
9. scoring;
10. curations and deterministic tie breakers;
11. bounded facets and highlights.

The planner may skip expensive fuzzy expansion when enough high-confidence exact results exist. This policy must be configurable and measured.

## 7. Ranking

Implement a documented BM25-like or equivalent probabilistic text score. Do not use unexplained magic constants scattered through SQL.

The canonical ranking profile contains:

- searchable fields and integer-scaled weights;
- exact identifier boost;
- exact title boost;
- phrase and proximity boost;
- exact token versus prefix versus fuzzy penalties;
- synonym penalty where directional;
- field-length normalization;
- popularity, sales, rating, featured, stock, recency, and menu-order signals;
- configurable business boosts;
- deterministic final tie breakers.

A conceptual score is:

```text
text_relevance
+ exact_and_phrase_boosts
+ controlled_business_signals
+ curation_adjustments
- prefix_fuzzy_synonym_penalties
```

Business signals must not allow a poorly matching product to outrank a strong exact match unless an explicit curation rule says so.

Store ranking configuration as versioned data. A change that affects postings or analyzers requires rebuild. A pure weight change may be hot-applied when the index contains required statistics.

### 7.1 Explain mode

Admin-only explain mode returns:

- normalized query;
- recognized identifiers;
- expanded terms and reasons;
- matched fields;
- term statistics;
- component scores;
- filters;
- curation effects;
- tie-break values;
- provider timing.

Explain mode is disabled for public traffic and redacts restricted data.

## 8. Query execution

Use prepared SQL and bounded intermediate sets.

Preferred approach:

1. resolve a bounded list of term IDs;
2. calculate query term weights;
3. aggregate candidate document scores through indexed joins;
4. restrict by generation, locale, visibility scope, and filters early;
5. retain only a bounded candidate window;
6. apply final scoring and curation;
7. load projections for one page;
8. compute requested facet counts with separately measured plans.

Use `EXPLAIN` snapshots in performance tests. CI must detect accidental full table scans on critical reference queries where practical.

Do not create unbounded temporary tables. If a database temporary table is used, name it uniquely, bound it, clean it in `finally`, and test worker termination. Prefer derived tables or product-owned temporary structures where portable.

## 9. Prefix, fuzzy, and typo policy

Default policy:

- one and two-character terms: exact or prefix only, no fuzzy;
- three to five characters: maximum edit distance 1;
- longer terms: maximum edit distance 2 where candidate quality allows;
- numeric identifiers: no general fuzzy correction;
- mixed model numbers: restricted transposition policy;
- maximum expanded terms per input token;
- maximum total query expansions;
- language-specific override.

Every fuzzy match includes its edit distance and penalty in explain output.

## 10. Synonyms, stop words, redirects, and curations

### Synonyms

Support:

- equivalent groups: `tv`, `television`;
- directional mappings: `iphone` to `apple phone`;
- locale and channel scope;
- effective dates;
- import/export;
- validation against cycles and explosion.

Query-time expansion is the default because it avoids rebuild. Index-time synonyms require explicit justification and schema revision.

### Stop words

Stop words are locale-specific. The planner must not turn an all-stop-word query into an unbounded match-all request. It may fall back to prefix, category suggestions, popular searches, or a safe no-result response.

### Redirects

A normalized exact query may redirect to a product, category, landing page, or safe internal URL. Redirects are audited and cannot point to disallowed schemes or external origins unless explicitly allowed.

### Curations

Rules may:

- pin;
- boost;
- bury;
- hide;
- apply filters;
- rewrite a query;
- activate by locale, channel, time, or customer scope.

Rules have priority, conflict resolution, preview, audit, and expiration. Regex is prohibited by default for public-query rules unless a bounded safe engine is used.

## 11. Query cache

Cache only canonical safe responses or provider candidate results. The key includes:

```text
generation_id
configuration_revision
ranking_revision
locale
currency or price scope
visibility scope hash
query
filters
facets
sort
page
projection version
```

Generation-based keys make invalidation cheap. Do not cache user-specific responses under a shared key. Cache TTL and maximum size are bounded. A persistent object cache may be used, but database or memory fallback must work.

## 12. Fast local endpoint

The optional fast endpoint must:

- load only the minimum WordPress database configuration and plugin search runtime;
- read an immutable signed runtime snapshot generated by the full WordPress environment;
- reject logged-in or restricted scopes;
- support only public-safe response fields;
- validate origin policy where configured;
- enforce query length, rate, cost, timeout, and response-size limits;
- expose no admin operations;
- use the same local query engine and response validator as the REST path;
- fall back to normal search submission when unavailable.

The fast endpoint is a separate security release gate. It may not be enabled by default until it passes external review.

## 13. Local engine operations

The admin and CLI must show:

- active and candidate generation;
- schema and analyzer revisions;
- build phase and cursor;
- counts for documents, terms, postings, identifiers, and facets;
- storage estimate;
- last incremental sync;
- queue lag;
- drift status;
- failed documents;
- slow-query samples with redaction;
- last verification result;
- retained rollback generations.

Operations:

- plan;
- build;
- pause;
- resume;
- cancel safely;
- verify;
- activate;
- rollback;
- repair selected documents;
- full reconcile;
- cleanup old generation;
- export diagnostic metadata.

## 14. Local provider certification

Mandatory fixture sets:

- 1,000 products;
- 10,000 products;
- 100,000 products;
- at least 300,000 variation documents or equivalent high-variation fixture;
- multilingual and diacritic-heavy data;
- long SKU and model-number data;
- high-cardinality facets;
- hidden, scheduled, password-protected, and restricted products;
- dynamic stock and sale changes.

Release requirements:

- `LOC-001 MUST`: dedicated inverted index, no linear catalog scan.
- `LOC-002 MUST`: shadow generations and atomic activation.
- `LOC-003 MUST`: bounded exact, prefix, phrase, and fuzzy matching.
- `LOC-004 MUST`: exact SKU and configured identifier correctness.
- `LOC-005 MUST`: locale-aware analyzers are versioned.
- `LOC-006 MUST`: ranking is documented, configurable, deterministic, and explainable.
- `LOC-007 MUST`: facets are correct for the filtered result set.
- `LOC-008 MUST`: all SQL is prepared and critical plans are benchmarked.
- `LOC-009 MUST`: index corruption or worker termination cannot corrupt the active generation.
- `LOC-010 MUST`: query and resource budgets prevent unbounded work.
- `LOC-011 MUST`: cache keys include all visibility and pricing dimensions.
- `LOC-012 MUST`: fast endpoint, if enabled, passes its security and parity gates.
- `LOC-013 MUST`: tested catalog limits are documented and enforced with warnings, not marketing guesses.


---

# 05 Typesense Provider

## 1. Objective

Provide a first-class Typesense adapter for self-hosted Typesense and Typesense Cloud. It must use the same canonical documents, search requests, response contracts, relevance configuration, analytics events, and operational state machine as the local provider.

Typesense is not a bolt-on HTTP call inside inherited FiboSearch classes. It is a provider implementation behind the platform contracts.

## 2. Supported deployment models

### 2.1 Bring-your-own self-hosted cluster

The merchant supplies:

- HTTPS endpoint;
- server or cluster version;
- provisioning credential or pre-created resource credentials;
- optional private-network policy;
- connection timeout and region metadata.

### 2.2 Typesense Cloud

The merchant supplies a cluster endpoint and least-privilege keys. Optional future managed provisioning may use a separate Starfiniti control plane, but the WordPress plugin must not require it.

### 2.3 Starfiniti-managed service, future-compatible

The architecture may later provision and operate clusters through a service. That service must be substantial, opt-in, documented, independently secured, and separable from the GPL plugin.

## 3. Server-version policy

At release time, certify the current stable Typesense major and the immediately previous supported major when feasible. Detect the server version during connection validation and expose it in diagnostics.

Maintain a compatibility matrix covering:

- collection schema behavior;
- aliases;
- document import;
- search parameters;
- scoped keys;
- synonyms or synonym sets;
- curations or curation sets;
- analytics;
- conversation/vector features if enabled;
- breaking API changes.

Do not send version-specific parameters blindly. Centralize version adaptation in `TypesenseCompatibilityProfile`.

## 4. Connection and preflight validation

The connection wizard and API perform:

1. endpoint syntax and policy validation;
2. DNS and network safety checks;
3. TLS verification;
4. server health check;
5. server version detection;
6. credential permission probes using safe operations;
7. clock-skew observation if signed or expiring credentials are used;
8. latency sampling;
9. existing collection and alias discovery within authorized scope;
10. schema-name collision checks;
11. write and rollback capability validation;
12. a redacted report.

A successful TCP connection is not a successful configuration.

## 5. Credential separation

Use separate credentials or capabilities:

### Provisioning credential

May create collections, aliases, search keys, synonyms, curation sets, and analytics rules. It is optional when an operator pre-provisions resources. It is never used for routine storefront search.

### Indexing credential

Limited to required document import, update, delete, and narrowly necessary collection inspection for the merchant's collection names.

### Search credential

Search-only. For public browser use, it is restricted to specific collection aliases and safe search actions.

### Scoped or derived search credential

Adds non-removable filters, limits, expiration, tenant, locale, or catalog scope for restricted use cases.

The plugin must validate that a supplied key does not have more privilege than expected where the server API allows inspection. It must warn and refuse public exposure of a broad key.

Secrets are stored as `SecretReference` values and resolved only inside the infrastructure adapter.

## 6. Collection naming and isolation

Use collision-resistant names:

```text
sfs_<installation_uuid>_<environment>_<collection>_<locale>_v<schema>_<build>
```

Stable aliases:

```text
sfs_<installation_uuid>_<environment>_<collection>_<locale>
```

Rules:

- installation UUID is random and not a domain name;
- environment is `production`, `staging`, `development`, or a sanitized configured value;
- names are length-bounded and valid for supported Typesense versions;
- production and staging must never share aliases or write credentials by default;
- multisite blog ID or site UUID is represented;
- collection discovery is restricted to the expected prefix;
- deleting arbitrary collections is prohibited.

## 7. Projection schema

Build a `TypesenseDocumentProjector` from validated canonical documents.

Recommended fields:

```text
id: string
entity_type: string
entity_id: int64
parent_id: int64 optional
locale: string
scope_tokens: string[]
title: string
title_exact: string
sku: string optional
sku_normalized: string optional
gtin: string optional
identifiers: string[]
short_description: string
description: string
categories: string[]
category_ids: int64[]
category_paths: string[]
brands: string[]
brand_ids: int64[]
attributes.<safe_key>: string[]
price_min_minor: int64
price_max_minor: int64
stock_status: string
stock_searchable: bool
rating_scaled: int32
rating_count: int32
sales_count: int64
menu_order: int32
featured: bool
modified_epoch: int64
canonical_checksum: string
projection_checksum: string
projection: object or selected display fields
```

Provider field names come from a schema registry. Sanitize dynamic attribute names deterministically and retain a reversible mapping. A merchant adding or removing a facetable field may require a new collection version.

Do not enable a broad wildcard schema for arbitrary product metadata without explicit field controls.

## 8. Locale and collection strategy

Default to one stable alias per locale when analyzer, stop-word, stemming, or ranking behavior differs substantially. A single collection with a mandatory locale filter is permitted when:

- schema and analyzer behavior are compatible;
- scoped keys enforce locale where needed;
- tests prove no cross-language leakage;
- operations remain understandable.

The strategy is configuration, but changing it is a planned rebuild.

## 9. Desired schema reconciliation

The adapter compares:

```text
desired canonical projection and settings
actual Typesense schema, settings, aliases, keys, synonyms, curations, analytics
```

It produces a plan classified as:

- no change;
- safe settings update;
- document re-projection;
- new collection and full rebuild;
- credential update;
- unsupported server capability;
- destructive conflict requiring operator decision.

Never mutate the active collection schema destructively when a versioned collection and alias switch is safer.

## 10. Bulk indexing

Use the document import endpoint with bounded JSON Lines batches.

Mandatory behavior:

- bound documents and bytes per request;
- set explicit connect and total deadlines;
- use idempotent upsert semantics;
- parse every response line;
- treat an HTTP success status as transport success only, not document success;
- classify each document outcome;
- retry only retryable failures;
- split failing batches to isolate poison documents;
- record safe excerpts and error codes without logging full sensitive documents;
- checkpoint successful documents independently;
- support cancellation;
- adapt batch size after latency or payload failures.

A document that fails schema validation should be quarantined with an actionable source-path error. It must not be retried forever.

## 11. Deletion and orphan cleanup

Deletes use stable document IDs. Full verification identifies remote orphans.

Never delete by an unconstrained query or collection prefix. Every destructive operation includes:

- exact expected collection or alias;
- installation UUID;
- environment;
- operation ID;
- dry-run count;
- upper safety bound;
- approval for collection deletion;
- audit event.

Retired collections are deleted only after rollback retention and verification.

## 12. Zero-downtime build and activation

Flow:

1. create versioned candidate collection;
2. apply desired schema;
3. create or attach candidate-specific safe keys if needed;
4. bulk import canonical projections;
5. merge outbox changes that occurred during build;
6. verify counts, checksums, samples, known queries, visibility, filters, facets, and latency;
7. mark candidate ready;
8. atomically update the stable alias;
9. verify alias and smoke queries;
10. retain previous collection;
11. update active version record;
12. clean after retention.

Activation must be idempotent. If alias update succeeds but the WordPress request times out, reconciliation discovers actual state and completes the operation safely.

Rollback is an alias switch to a verified retained collection, followed by smoke verification.

## 13. Search request compilation

`TypesenseQueryCompiler` maps canonical intent into version-compatible parameters:

- `q`;
- `query_by`;
- `query_by_weights`;
- prefix policy;
- typo policy;
- infix only for configured identifiers;
- filters;
- facets;
- sort;
- grouping or collapse;
- highlight fields and tags;
- pagination;
- pinned or hidden hits;
- preset or curation behavior;
- scoped mandatory filters.

Mapping decisions are covered by contract tests and documented in explain output.

Do not expose raw Typesense query parameters to unauthenticated users. An advanced administrator API may permit a safe allow-list, but not arbitrary pass-through.

## 14. Direct browser search

Direct browser search reduces WordPress load but is allowed only for public-safe catalogs.

Requirements:

- search-only or scoped key;
- key restricted to stable aliases;
- mandatory scope and locale filters enforced beyond browser control;
- no secret or write capability;
- CORS origins explicitly configured;
- response fields allow-listed;
- maximum page size and query limits;
- no internal checksums, private custom fields, cost signals, or unrestricted facet data;
- key rotation without a storefront deployment;
- server-proxy fallback for key provisioning failures;
- a browser security test that attempts forbidden actions.

For public catalog search keys that are intentionally visible, treat them as authorization-constrained identifiers, not secrets. The authority behind them must remain minimal.

## 15. Restricted and dynamic catalogs

Use server-proxied search or short-lived scoped keys.

The server constructs mandatory filters from an authenticated `SearchContext`. It must not accept raw scope tokens from the browser.

After search, rehydrate or revalidate results when:

- prices depend on user or session;
- product visibility comes from an integration that cannot be expressed safely as a filter;
- stock or purchasing state must be real-time;
- sensitive result fields are omitted from Typesense;
- a provider response may be stale beyond configured tolerance.

Over-fetch candidates in bounded increments to fill a page after authorization filtering. Cap rounds and report partial-page warnings to operators.

## 16. Relevance mapping

Canonical relevance settings include intent, not Typesense implementation names. The adapter maps:

- field weights to query field weights;
- exact identifier policy to exact or infix identifier fields;
- prefix and typo policies;
- business tie breakers to sort expressions;
- synonyms to supported synonym resources;
- curations to current curation APIs;
- query redirects to application-level redirects when more portable;
- stop words and locale settings where supported.

If a canonical rule cannot be represented safely, mark it degraded or unsupported. Do not silently approximate a security-related filter.

## 17. Curations and synonyms

Version-aware management must support current Typesense curation resources and avoid legacy APIs on incompatible versions.

Operations:

- validate canonical rule;
- preview against fixture and live-safe sample queries;
- calculate provider diff;
- apply under an operation ID;
- verify;
- store provider resource IDs;
- audit author and reason;
- rollback to prior configuration revision.

Canonical configuration remains the source of truth. Typesense resources are projections and may be reconciled.

## 18. Analytics

Canonical first-party analytics remain provider-neutral. Optional Typesense analytics may be configured for popular and no-hit queries, clicks, and conversions when useful.

Rules:

- no duplicated counting without clear source labels;
- provider analytics credentials remain server-side;
- analytics configuration is reconciled and versioned;
- privacy and retention rules apply;
- provider analytics failure cannot break search;
- the admin UI identifies canonical versus provider-derived metrics.

## 19. Resilience

Implement:

- connect and total timeouts;
- retry only for retryable and idempotent operations;
- exponential backoff with jitter;
- circuit breaker per endpoint and operation type;
- bounded concurrency;
- health-based degradation;
- DNS and TLS failure classification;
- response-schema validation;
- rate-limit handling;
- correlation IDs;
- optional multi-node endpoint policy for self-hosted deployments.

Search failure behavior:

1. return cached safe response when within configured stale tolerance;
2. use verified local fallback only when dual-write freshness and consistency pass;
3. otherwise show a localized degraded autocomplete state and preserve normal search-form submission;
4. never show unrestricted or stale-sensitive data merely to avoid an error.

## 20. High availability guidance

The plugin must expose cluster topology and health information available through authorized APIs, but it must not claim to make a single-node Typesense installation highly available.

Document recommended production topology, TLS, backups, key rotation, monitoring, and region placement. For managed or self-hosted multi-node clusters, verify that the configured endpoint strategy is compatible with the cluster setup.

## 21. Secret-safe diagnostics

Diagnostics include:

- endpoint hostname and port, with query and credentials removed;
- TLS status;
- server version;
- latency buckets;
- permission-test results;
- alias and collection names;
- schema hash;
- document counts;
- last import summary;
- circuit state;
- last health status;
- credential reference IDs and last rotation time, never key values.

A diagnostics export scanner must fail if it detects known secret patterns or exact configured keys.

## 22. Typesense requirements

- `TYP-001 MUST`: supported server versions are detected and certified.
- `TYP-002 MUST`: credentials are separated by privilege and never leaked.
- `TYP-003 MUST`: collection names isolate installation, environment, locale, and version.
- `TYP-004 MUST`: schema changes use versioned collections and aliases when required.
- `TYP-005 MUST`: bulk import parses and records every document result.
- `TYP-006 MUST`: activation and rollback are idempotent and verified.
- `TYP-007 MUST`: direct browser transport is permitted only for public-safe scoped data.
- `TYP-008 MUST`: restricted catalogs use non-removable authorization policy.
- `TYP-009 MUST`: query compilation is bounded, version-aware, and not raw pass-through.
- `TYP-010 MUST`: canonical synonyms and curations reconcile to provider resources.
- `TYP-011 MUST`: resilience includes deadlines, retry policy, circuit breaking, and safe degradation.
- `TYP-012 MUST`: diagnostics contain operational evidence and no secrets.
- `TYP-013 MUST`: local fallback is unavailable unless freshness and consistency policy passes.
- `TYP-014 MUST`: integration tests run against real supported Typesense containers, not only mocks.


---

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


---

# 07 Security, Privacy, and Operations

## 1. Security objective

Search handles public input, product data, remote credentials, administrative operations, analytics, and potentially restricted B2B catalogs. Treat it as an exposed security boundary.

Create and maintain `docs/THREAT_MODEL.md` using assets, actors, trust boundaries, abuse cases, mitigations, residual risk, and test evidence.

Primary assets:

- product and catalog confidentiality;
- customer-specific price and visibility data;
- Typesense and WordPress credentials;
- index integrity;
- ranking and merchandising integrity;
- storefront availability;
- analytics data;
- administrator authorization;
- update and release supply chain.

## 2. Trust boundaries

Document at least:

```text
anonymous browser -> WordPress public endpoint
anonymous browser -> Typesense public search endpoint
authenticated browser -> WordPress administration
WordPress -> database
WordPress -> Typesense
WordPress -> Action Scheduler workers
MCP client -> MCP server
MCP server -> WordPress control API
build system -> release artifact
third-party extensions -> document and visibility hooks
```

Every boundary has authentication, authorization, validation, rate, timeout, logging, privacy, and failure behavior.

## 3. WordPress authorization

Create dedicated capabilities:

```text
manage_starfiniti_search
operate_starfiniti_search
view_starfiniti_search_analytics
view_starfiniti_search_diagnostics
manage_starfiniti_search_secrets
```

Map them to administrators by default and allow deliberate delegation. Avoid using one broad capability for all operations.

Requirements:

- every admin page and action checks capability;
- every REST endpoint has a permission callback;
- state changes require nonce or authenticated REST authorization;
- background jobs verify stored operation authorization and preconditions, not browser nonce;
- multisite and network administration are explicit;
- destructive operations require a stronger capability and operation approval;
- read-only support roles cannot reveal secrets or restricted query data.

## 4. Input validation and output safety

For every entry point:

- define JSON Schema or typed parameters;
- reject unknown fields on security-sensitive operations;
- normalize once and validate after normalization;
- cap string length, array count, nesting, filter clauses, facets, page size, and query cost;
- sanitize stored administration text;
- escape at output according to HTML, attribute, URL, JavaScript, JSON, SQL, or shell context;
- use prepared SQL;
- never concatenate user input into table names, sort expressions, field names, or provider query syntax;
- allow-list fields and operators;
- validate URLs and schemes;
- validate redirects against safe destinations;
- avoid unserializing untrusted input;
- do not execute shortcodes from indexed product descriptions.

Provider highlight output is untrusted. Convert it to safe marked text through a strict parser or render highlighting from text offsets.

## 5. SSRF and endpoint safety

A configurable Typesense endpoint creates SSRF risk.

Connection policy:

- HTTPS required by default;
- plain HTTP allowed only for loopback or explicitly enabled development environments;
- URL credentials prohibited;
- fragments and unexpected paths prohibited;
- redirects disabled by default;
- hostname resolved and checked against blocked address ranges;
- loopback, link-local, multicast, unspecified, carrier-grade NAT, and cloud metadata ranges blocked by default;
- private RFC1918 addresses blocked unless an administrator enables self-hosted private-network mode through a protected setting or constant;
- DNS rebinding mitigated through resolution checks close to connection and no automatic redirects;
- port allow-list configurable;
- proxy behavior documented;
- response size and time bounded;
- endpoint changes invalidate prior health and require revalidation.

Private-network mode must display risk and still block metadata endpoints and unsafe redirects.

## 6. Secret storage

Preferred order:

1. environment or `wp-config.php` constants containing secret references or values;
2. an external secret manager integrated through a provider;
3. encrypted, non-autoloaded WordPress option as fallback.

For encrypted fallback:

- use libsodium when available;
- use authenticated encryption;
- derive or wrap a site-specific key from WordPress salts plus an installation UUID through a documented KDF;
- store nonce and cipher text separately from metadata;
- never use reversible obfuscation as encryption;
- detect salt changes and provide a recovery workflow;
- prevent secret values from entering revision history;
- zero or release plaintext values as soon as practical;
- redact known values from logs and diagnostics.

Administration fields display only a fingerprint and last rotation time. Saving an empty secret does not erase the existing value unless the user explicitly chooses removal.

## 7. Credential rotation

Support overlapping safe rotation:

1. create or receive new credential;
2. validate least privilege;
3. store as candidate;
4. verify indexing or search;
5. activate reference;
6. revoke previous credential;
7. verify;
8. audit.

Public search-key rotation must not require a full plugin deployment. Browser clients retrieve or receive a safe current key/configuration according to the chosen transport.

## 8. Public endpoint abuse controls

Apply:

- query-length limits;
- page and facet limits;
- query-cost budget;
- rate limiting;
- burst and sustained limits;
- response-size limit;
- server deadline;
- request cancellation;
- bounded cache;
- bot and abuse hooks;
- no expensive public explain mode;
- no arbitrary regex;
- no unrestricted wildcard or infix;
- no deep pagination beyond configured limits;
- optional proof or challenge integration through extension APIs.

Rate limits must account for reverse proxies and avoid blindly trusting spoofable forwarding headers. Store the minimum data needed and document privacy impact.

## 9. Cache security

- include authorization, locale, currency, pricing, configuration, provider, and index revision in cache keys;
- do not cache private responses publicly;
- prevent cache poisoning through normalized keys;
- cap key and value length;
- sign client-side configuration snapshots where integrity matters;
- clear or version caches after permission and visibility configuration changes;
- never include secrets in cache keys;
- test logged-in and anonymous cross-user leakage.

## 10. SQL and database safety

- use `$wpdb->prepare()` correctly;
- allow-list dynamic table suffixes;
- quote identifiers through controlled helpers;
- use explicit transactions where supported for state changes;
- account for DDL auto-commit;
- design migrations to resume after interruption;
- do not assume MySQL-only features without compatibility fallback;
- cap query execution through plans and application deadlines where possible;
- index every critical filter and join;
- redact SQL values in public logs;
- prohibit public endpoints from accepting raw SQL or SQL-like fields.

## 11. Supply-chain security

Mandatory CI:

- Composer dependency audit;
- npm dependency audit;
- license allow-list;
- SBOM generation;
- secret scan;
- static application security testing;
- PHP and JavaScript linting;
- lockfile integrity;
- prohibited binary and remote-asset scan;
- reproducible build comparison;
- artifact malware scan where available;
- package-content allow-list.

Use pinned dependencies and automated update proposals. Do not auto-merge a dependency update without tests.

Document every vendored library and why it is needed. Prefer maintained small dependencies over abandoned convenience packages.

## 12. Release integrity

For every release:

- build in CI from a clean tagged commit;
- produce plugin ZIP, checksums, SBOM, third-party notices, and test evidence;
- verify the ZIP by installing it in a fresh environment;
- compare source and built assets to expected manifest;
- scan for development files, secrets, test credentials, internal URLs, source maps, and prohibited branding;
- sign release metadata where infrastructure supports it;
- retain immutable artifacts and provenance.

## 13. Vulnerability management

Create `SECURITY.md` with:

- supported versions;
- private reporting method;
- expected information;
- embargo policy;
- severity process;
- coordinated disclosure;
- patch and release process;
- security advisory and CVE handling;
- dependency vulnerability response;
- credit policy.

Do not ask reporters to post exploitable details in a public issue.

Critical or high vulnerabilities block release. Known medium issues require explicit risk acceptance with owner and target release. No security warning may be hidden by a generic success state.

## 14. Privacy model

The core search index must contain catalog data only. Do not index customers, orders, emails, addresses, support tickets, or personal profiles.

Search queries can contain personal data. Treat raw queries as potentially sensitive.

Privacy defaults:

- analytics optional and transparent;
- no raw IP storage;
- anonymous random session identifier with limited lifetime;
- no cross-site identity;
- no advertising identifier;
- query redaction before storage;
- configurable raw-event retention;
- aggregate retention separate;
- no external analytics transmission by default;
- user history stored locally by default;
- privacy policy suggestion text;
- WordPress personal-data exporter and eraser integration when events can relate to a user;
- consent hooks for common consent platforms without hard dependency.

## 15. Query redaction

Provide configurable redaction before durable analytics storage:

- email addresses;
- telephone numbers;
- postal identifiers where patterns are reliable;
- order numbers if configured;
- secrets or API-key patterns;
- long numeric sequences;
- merchant-defined regular expressions evaluated through a safe bounded engine.

Store a redaction reason count, not the removed value. The raw request may exist briefly in process memory to perform search but must not be logged.

Allow merchants to store only a one-way normalized query hash plus aggregate count for stricter privacy mode.

## 16. Retention and deletion

Configure:

- raw analytics retention;
- aggregated analytics retention;
- audit-log retention;
- operation-log retention;
- dead-letter retention;
- query-cache TTL;
- old index-generation retention;
- diagnostics retention.

Cleanup jobs are resumable and bounded. Legal-hold functionality is not assumed. An administrator can purge analytics independently of search configuration and indexes.

Uninstall options:

- keep settings and data;
- remove runtime data but retain configuration export;
- complete removal.

Complete removal requires explicit confirmation and handles multisite safely.

## 17. Logging

Use structured logs through a product logger with adapters for WooCommerce logging and optional external handlers.

Fields:

```text
timestamp
level
event_code
message
correlation_id
operation_id
provider
index_version
site_id
duration_ms
retryable
safe_context
```

Rules:

- secrets and raw restricted documents prohibited;
- raw queries omitted or redacted by default;
- stack traces protected to authorized diagnostics;
- repeated errors sampled or aggregated;
- log volume and retention bounded;
- user-facing error references correlation ID;
- log level configurable;
- debug mode has prominent warnings and automatic expiry.

## 18. Metrics and health

Collect:

- search requests, errors, and latency percentiles;
- provider latency and timeout rate;
- cache hit rate;
- query-budget rejection rate;
- queue depth and oldest age;
- processing throughput;
- retry and dead-letter count;
- full-build progress;
- expected and actual document count;
- drift rate;
- schema mismatch;
- circuit state;
- active and rollback index versions;
- analytics ingestion failure;
- fast-endpoint health;
- MCP/control API health separately.

Metrics may be shown in administration and exported through a protected endpoint or extension. Do not expose sensitive labels or high-cardinality raw queries.

## 19. Health levels

### Liveness

The plugin can execute and its core dependencies load.

### Readiness

The configured read provider has a valid active index and can answer a smoke query within policy.

### Deep health

Checks schema, aliases, permissions, counts, sample documents, queue state, drift, and latency.

Deep checks are rate-limited and never run on every storefront request.

WordPress Site Health displays actionable tests and safe debug information.

## 20. Alerting

Optional alerts:

- provider unavailable;
- circuit open;
- queue age over threshold;
- dead-letter increase;
- index drift;
- candidate verification failed;
- credential nearing configured rotation age;
- active index rollback unavailable;
- analytics cleanup failed;
- storage threshold;
- repeated query timeout.

Delivery adapters may include email and webhook. Webhook destinations use SSRF-safe validation and signed payloads. Alerts are deduplicated and have recovery notifications.

## 21. Backup and disaster recovery

Document what must be backed up:

- WordPress database, including configuration, outbox, analytics if retained, and local index tables;
- secret references and recovery method;
- Typesense snapshot or managed backup policy;
- current configuration export;
- index schema and relevance configuration;
- release artifact.

The index is rebuildable, but rebuild time is an operational concern. Recovery procedures cover:

- lost local index tables;
- corrupted active generation;
- lost Typesense collection;
- accidental alias switch;
- lost or revoked key;
- WordPress salts changed;
- failed plugin update;
- partial database migration;
- control plane unavailable.

Run recovery drills in CI or a controlled environment for all automatable cases.

## 22. Operational maintenance

Provide:

- database storage estimator;
- old-generation cleanup;
- analytics cleanup;
- queue maintenance;
- orphan operation cleanup;
- credential rotation helper;
- configuration backup;
- index verification schedule;
- slow-query reporting;
- safe reset;
- support bundle;
- read-only maintenance mode;
- provider migration wizard.

Search must continue on the last verified active index during non-destructive maintenance.

## 23. Security, privacy, and operations requirements

- `SEC-001 MUST`: threat model covers all documented trust boundaries.
- `SEC-002 MUST`: administrative actions use least-privilege capabilities.
- `SEC-003 MUST`: public input is schema-validated and cost-bounded.
- `SEC-004 MUST`: Typesense endpoint validation mitigates SSRF and unsafe redirects.
- `SEC-005 MUST`: secrets are encrypted or externally referenced and always redacted.
- `SEC-006 MUST`: direct browser keys cannot perform write or administration actions.
- `SEC-007 MUST`: supply-chain and artifact security gates run in CI.
- `SEC-008 MUST`: public and admin output is contextually escaped.
- `SEC-009 MUST`: cache tests prove no cross-scope leakage.
- `SEC-010 MUST`: vulnerability reporting and patch policy exist.
- `PRI-001 MUST`: catalog indexes contain no customer or order PII.
- `PRI-002 MUST`: raw queries are treated as potentially sensitive.
- `PRI-003 MUST`: analytics retention, purge, exporter, and eraser behavior are implemented.
- `PRI-004 MUST`: no external telemetry occurs without explicit opt-in.
- `OPS-001 MUST`: structured redacted logs and correlation IDs exist.
- `OPS-002 MUST`: liveness, readiness, and deep health are distinct.
- `OPS-003 MUST`: queue, drift, index, provider, and latency metrics are available.
- `OPS-004 MUST`: backup, rollback, and disaster recovery are documented and tested.
- `OPS-005 MUST`: maintenance tasks are bounded and do not replace the active verified index prematurely.


---

# 08 Search Control API and MCP Server

## 1. Principle

MCP is an operations and agent-integration interface. It is not the search engine, configuration source of truth, storefront query path, queue, schema migrator, or authorization system.

The deterministic application and control APIs must work without an LLM. MCP tools call those APIs. The model may propose or request operations, but deterministic code validates, plans, authorizes, executes, verifies, audits, and rolls back them.

## 2. Components

```text
MCP client
    |
    | MCP over authenticated transport
    v
Search Operations MCP server
    |
    | typed internal client
    v
WordPress Search Control REST API
    |
    v
Application command/query bus
    |
    +--> configuration and operation planner
    +--> local provider
    +--> Typesense provider
    +--> indexing orchestration
    +--> health and diagnostics
```

A future centralized Starfiniti control plane may sit between MCP and store sites. The first implementation may connect to one or more registered WordPress sites, but tenant isolation must exist from the beginning.

## 3. MCP application

Implement `apps/search-ops-mcp` in TypeScript using the current stable official MCP SDK and specification at implementation time. Pin the supported protocol version and test negotiation.

Runtime requirements:

- supported maintained Node.js release, with the exact minimum documented;
- strict TypeScript;
- schema validation;
- structured logging with secret redaction;
- OpenTelemetry-compatible instrumentation where practical;
- health and readiness endpoints separate from MCP transport;
- no direct database access to WordPress;
- no unrestricted shell;
- no arbitrary HTTP proxy;
- no raw Typesense administration key in model context;
- no storefront availability dependency.

## 4. Authentication and authorization

Use enterprise-capable OAuth 2.1 or the current MCP-recommended authorization model for remote HTTP deployments. Local stdio development mode may use local process trust but must be clearly separated from production.

Scopes:

```text
search.read
search.diagnostics.read
search.analytics.read
search.config.read
search.config.write
search.index.plan
search.index.execute
search.index.activate
search.index.rollback
search.secrets.rotate
search.audit.read
```

Rules:

- least privilege;
- tenant and site binding;
- short-lived access tokens;
- audience and issuer validation;
- PKCE where applicable;
- no token forwarding to downstream services;
- protected refresh-token storage;
- revocation;
- operator identity in audit records;
- stronger authorization for activation, rollback, deletion, and secret rotation.

The MCP server maps caller scopes to WordPress control capabilities. It does not trust a tool argument that claims a role.

## 5. Site registration

A site registration stores:

```text
tenant_id
site_id
display_name
environment
control_api_base
credential_reference
allowed_operations
certificate_or_tls_policy
last_verified_at
status
```

Credentials are held in a proper secret store. They are never written to a model-visible resource, tool result, log, analytics event, or configuration export.

Registration requires a handshake that verifies:

- site identity and installation UUID;
- TLS;
- plugin version;
- control-contract version;
- requested scopes;
- current environment;
- nonce or challenge;
- operator authorization.

Production and staging registrations are distinct.

## 6. WordPress control REST API

Use OpenAPI 3.1 and versioned JSON contracts. Suggested protected endpoints:

```text
GET  /wp-json/starfiniti-search/v1/control/status
GET  /wp-json/starfiniti-search/v1/control/capabilities
GET  /wp-json/starfiniti-search/v1/control/configuration
GET  /wp-json/starfiniti-search/v1/control/schema
GET  /wp-json/starfiniti-search/v1/control/operations
GET  /wp-json/starfiniti-search/v1/control/operations/{id}
GET  /wp-json/starfiniti-search/v1/control/diagnostics
POST /wp-json/starfiniti-search/v1/control/search/test
POST /wp-json/starfiniti-search/v1/control/providers/validate
POST /wp-json/starfiniti-search/v1/control/configuration/plan
POST /wp-json/starfiniti-search/v1/control/configuration/apply
POST /wp-json/starfiniti-search/v1/control/index/plan
POST /wp-json/starfiniti-search/v1/control/index/start
POST /wp-json/starfiniti-search/v1/control/index/verify
POST /wp-json/starfiniti-search/v1/control/index/activate
POST /wp-json/starfiniti-search/v1/control/index/rollback
POST /wp-json/starfiniti-search/v1/control/synonyms/plan
POST /wp-json/starfiniti-search/v1/control/curations/plan
POST /wp-json/starfiniti-search/v1/control/secrets/rotation/plan
```

Every mutating call requires:

- operation type;
- idempotency key;
- expected configuration or active-index revision;
- dry-run flag;
- reason;
- caller identity;
- approval token when required.

Responses return operation plans and safe state, never credentials.

## 7. Resources

MCP resources are read-only context surfaces.

Suggested URIs:

```text
search://tenants/{tenant}/sites/{site}/status
search://tenants/{tenant}/sites/{site}/capabilities
search://tenants/{tenant}/sites/{site}/configuration
search://tenants/{tenant}/sites/{site}/schema
search://tenants/{tenant}/sites/{site}/index-status
search://tenants/{tenant}/sites/{site}/sync-errors
search://tenants/{tenant}/sites/{site}/relevance-tests
search://tenants/{tenant}/sites/{site}/analytics-summary
search://tenants/{tenant}/sites/{site}/audit
search://tenants/{tenant}/sites/{site}/operations/{operation}
```

Resource output:

- is size-bounded and paginated;
- carries freshness timestamp and contract version;
- redacts secrets and sensitive raw queries;
- respects caller scopes and site permissions;
- distinguishes observed state from desired state;
- provides links or IDs for follow-up tools, not arbitrary URLs.

## 8. Read-only tools

Implement narrowly defined tools such as:

```text
search_list_sites
search_get_status
search_get_capabilities
search_get_configuration
search_validate_configuration
search_test_query
search_compare_providers
search_inspect_document
search_inspect_schema
search_get_operation
search_list_sync_failures
search_export_diagnostics
search_get_analytics_summary
search_run_relevance_suite
```

`search_test_query` accepts a safe canonical request and returns redacted normalized results. It must not allow an LLM to bypass visibility scopes.

`search_compare_providers` runs only against providers already authorized for the selected site. It returns overlap, rank differences, latency, warnings, and quality-test impact. It does not automatically switch providers.

## 9. Planning tools

Planning is read-like but may perform safe probes.

```text
search_plan_configuration_change
search_plan_provider_migration
search_plan_full_reindex
search_plan_index_activation
search_plan_rollback
search_plan_synonym_change
search_plan_curation_change
search_plan_secret_rotation
search_plan_reconciliation
```

Every plan returns:

```json
{
  "operation_id": "uuid",
  "plan_hash": "sha256",
  "dry_run": true,
  "current_state": {},
  "desired_state": {},
  "steps": [],
  "risks": [],
  "preconditions": [],
  "estimated_impact": {},
  "verification": [],
  "rollback": {},
  "required_scope": "search.index.activate",
  "approval_required": true,
  "expires_at": ""
}
```

A plan is immutable. Execution references its ID and hash.

## 10. Execution tools

Mutating tools are separate and explicit:

```text
search_start_reindex
search_pause_operation
search_resume_operation
search_cancel_candidate_build
search_verify_candidate
search_activate_candidate
search_rollback_active_index
search_apply_configuration_revision
search_apply_synonym_revision
search_apply_curation_revision
search_retry_sync_failure
search_start_reconciliation
search_rotate_secret
```

Prohibited:

```text
execute_arbitrary_typesense_request
execute_wordpress_rest
run_sql
run_php
run_shell
set_option
delete_collection_by_name
```

The execution tool cannot change the plan. If actual state changed, preconditions fail and a new plan is required.

## 11. Human approval

High-impact operations require human approval:

- switching active provider;
- activating a new index;
- rolling back;
- deleting a retained index or collection;
- changing visibility or scope policy;
- changing a search key exposed to browsers;
- rotating write or provisioning credentials;
- enabling private-network endpoints;
- purging analytics or audit data;
- complete uninstall cleanup.

Approval may be represented by a short-lived signed token bound to:

```text
tenant
site
operation_id
plan_hash
action
approver
expiration
```

The model cannot mint or modify approval. A tool result states `approval_required` and the user-facing client handles confirmation.

Low-risk operations such as deep health checks or test-query comparisons may execute without separate approval when scope permits.

## 12. Dry-run and idempotency

Every mutating operation supports dry-run.

Idempotency keys are stored with operation results. Repeating the same request:

- returns the existing operation;
- does not create duplicate collections, jobs, synonyms, or keys;
- detects a changed payload under the same key and rejects it;
- remains safe after network timeout.

## 13. Deterministic operation sequence

The MCP server may not rely on a model to call tools in a magical order. Each tool validates prerequisites. Where a workflow must be ordered, expose operation state and next legal actions.

Example:

```text
plan -> start candidate -> wait/inspect -> verify -> request approval -> activate
```

Calling `activate` before successful verification returns a typed precondition failure.

Long-running operations return an operation ID. Status is polled or delivered through supported progress notifications. No tool invocation remains open indefinitely.

## 14. Search catalog tools for commerce agents

A separate read-only profile may expose:

```text
catalog_search_products
catalog_get_product
catalog_get_variations
catalog_list_facets
catalog_compare_products
catalog_get_related_products
catalog_check_availability
```

These tools call `SearchGateway` and WooCommerce hydration with the caller's authorized scope. They work with local or Typesense without exposing provider details.

Rules:

- no write operations;
- no hidden or restricted products;
- current price and availability revalidated;
- bounded result counts;
- clear currency and locale;
- no invented product claims;
- traceable result IDs and URLs;
- optional separation into another MCP server or scope set.

## 15. Prompt-injection and untrusted data

Product titles, descriptions, metadata, and diagnostics are untrusted data. They may contain text instructing an AI to ignore policy.

MCP responses must:

- structure catalog data as data, not instructions;
- mark untrusted content;
- cap text length;
- omit HTML and scripts;
- never let product text select tools or scopes;
- keep authorization and operation policy outside model-controllable fields;
- avoid placing secrets near model context;
- require deterministic validation regardless of model output.

## 16. Audit

Every MCP interaction records:

```text
timestamp
tenant
site
caller identity
client identity
tool
scope
arguments hash
safe argument summary
operation ID
result code
approval identity
correlation ID
duration
```

Do not store access tokens, secrets, full sensitive queries, or unrestricted product documents.

Audit reads are paginated and protected. Audit retention is configurable but destructive audit purge requires strong approval and a separate record where policy allows.

## 17. MCP testing

Mandatory:

- protocol negotiation;
- schema validation;
- OAuth happy and failure paths;
- tenant isolation;
- site isolation;
- scope enforcement per tool and resource;
- token audience, issuer, expiration, and revocation;
- approval binding;
- idempotency;
- changed-state precondition failure;
- secret-redaction property tests;
- prompt-injection fixtures in product data;
- malformed downstream response;
- WordPress unavailable;
- Typesense unavailable;
- long-running operation status;
- cancellation;
- rate limiting;
- replay attempts;
- no arbitrary URL or tool injection;
- compatibility against the pinned MCP SDK and protocol version.

## 18. MCP requirements

- `MCP-001 MUST`: MCP is outside the storefront query dependency chain.
- `MCP-002 MUST`: tools call deterministic versioned control APIs.
- `MCP-003 MUST`: remote production auth uses the current secure MCP authorization model with least privilege.
- `MCP-004 MUST`: resources and tools enforce tenant, site, and scope isolation.
- `MCP-005 MUST`: no generic HTTP, SQL, shell, or raw provider tool exists.
- `MCP-006 MUST`: all high-impact writes use immutable plans, dry-run, preconditions, and human approval.
- `MCP-007 MUST`: operations are idempotent and resumable through operation IDs.
- `MCP-008 MUST`: secrets never enter model-visible content.
- `MCP-009 MUST`: catalog data is treated as untrusted data against prompt injection.
- `MCP-010 MUST`: MCP audit records identify caller, tool, operation, approval, and result safely.


---

# 09 Provider Contract and Conformance Suite

## 1. Purpose

A provider is supported only when it passes a common conformance suite. The suite validates platform invariants, not exact provider-native implementation or identical ranking.

The suite prevents provider-specific behavior from leaking into storefront, administration, analytics, security, or synchronization code.

## 2. Provider SDK

Create an internal provider SDK with:

- PHP interfaces and immutable DTOs;
- canonical JSON Schemas;
- error types;
- capability schema;
- test fixture loader;
- conformance test base;
- reference in-memory test double for application tests only;
- provider authoring guide;
- compatibility and deprecation policy.

The in-memory test double is not a production provider and must never appear in a release provider list.

## 3. Registration

A provider registration supplies:

```php
final class ProviderRegistration
{
    public ProviderId $id;
    public string $humanName;
    public string $adapterVersion;
    public array $supportedServerVersions;
    public ProviderCapabilities $capabilities;
    public ProviderConfigurationSchema $configurationSchema;
    public SearchProviderFactoryInterface $factory;
}
```

Registration is allowed only during bootstrap. A provider cannot replace an existing ID without an explicit extension priority and conflict error.

## 4. Conformance categories

### 4.1 Contract conformance

- accepts valid canonical requests;
- rejects invalid contracts;
- returns valid canonical responses;
- preserves document IDs and entity identity;
- reports provider and index version;
- returns typed errors;
- respects deadlines and cancellation;
- never returns raw credentials or internal exception data.

### 4.2 Index lifecycle

- create candidate;
- apply schema;
- bounded batch upsert;
- bounded batch delete;
- partial failure reporting;
- resume;
- verify;
- atomic activation or equivalent safe switch;
- active-version inspection;
- rollback;
- retained-version cleanup;
- idempotent repeat after timeout.

### 4.3 Query semantics

Test invariants:

- exact SKU is found and ranks in the required position;
- exact title is not lost to weak popularity signals;
- prefix search works within declared capability;
- phrase behavior matches declared capability;
- fuzzy behavior respects configured term length and declared limits;
- synonyms apply by locale and direction;
- stop words do not create match-all;
- filters are Boolean-correct;
- facet counts reflect the filtered result set;
- sorting is stable;
- pagination has no duplicates or missing items under stable index;
- variation grouping follows strategy;
- highlights cannot inject markup;
- query budget is enforced;
- deterministic tie breakers produce stable order.

Exact ordering is required only for curated invariant fixtures and provider-specific golden tests, not every cross-provider result.

### 4.4 Visibility and scope

- unpublished product excluded;
- trash and deleted product excluded;
- password-protected product excluded;
- catalog-hidden and search-hidden policy;
- out-of-stock policy;
- scheduled publication;
- locale isolation;
- B2B scope isolation;
- price-list isolation;
- facet non-leakage;
- direct browser key cannot remove mandatory filter;
- hydrated revalidation removes stale unauthorized candidate.

Visibility failures are release-blocking.

### 4.5 Synchronization

- duplicate event;
- out-of-order update and delete;
- update during full build;
- worker death after provider success but before local checkpoint;
- partial batch remote failure;
- retry;
- dead-letter;
- reconciliation repair;
- orphan removal;
- dual-write independent checkpoint;
- provider switch and rollback.

### 4.6 Relevance

Use a versioned fixture corpus with judgments:

```text
query
locale
context
expected relevant documents and grades
known-item target
forbidden documents
expected facets
notes
```

Calculate:

- MRR for known-item queries;
- NDCG@10;
- precision@k;
- recall@k where corpus permits;
- zero-result rate;
- exact-identifier top-rank rate;
- cross-provider result overlap;
- click-model metrics in staged data.

A provider passes canonical minimums and has its own baseline to prevent regression.

### 4.7 Performance

Measure:

- cold and warm search latency;
- p50, p95, and p99;
- indexing throughput;
- incremental visibility delay;
- full-build time;
- memory;
- database query count and slow plans;
- remote request count and bytes;
- facet cost;
- concurrent search while indexing;
- failure and retry overhead.

Results include hardware, software, dataset, configuration, and network conditions.

### 4.8 Resilience

Inject:

- provider unavailable;
- slow provider;
- malformed response;
- TLS failure;
- DNS failure;
- permission revoked;
- schema mismatch;
- disk full simulation where feasible;
- database deadlock;
- Action Scheduler delay;
- corrupted candidate;
- alias activation timeout;
- stale local fallback;
- cache unavailable.

The suite checks safe error and recovery behavior.

## 5. Fixture catalog

Build deterministic generators and committed small fixtures.

Catalog characteristics:

- simple and variable products;
- duplicate and near-duplicate names;
- exact SKU and long SKU;
- GTIN and model numbers;
- diacritics;
- Slovenian, English, German, and one non-whitespace language fixture;
- synonyms;
- stop words;
- category hierarchy;
- brands;
- many attributes;
- missing images and prices;
- zero price;
- scheduled sale;
- stock transitions;
- backorders;
- hidden and restricted products;
- dynamic scope;
- custom fields;
- malicious HTML and prompt-injection strings;
- very long content;
- high-cardinality facet;
- deleted and orphaned documents.

Large fixtures are generated from stable seeds and recorded generator versions.

## 6. Capability verification

Provider-declared capabilities are tested. A provider claiming `native` fuzzy search must pass the native fuzzy cases. A provider marked `unsupported` must reject or disable the setting with a clear capability response.

CI compares declared capabilities to test registration. A new capability cannot be declared without tests.

## 7. Provider-specific tests

Common conformance does not replace provider-specific tests.

Local provider adds:

- database migrations;
- term and posting integrity;
- query plans;
- packed-position encoding;
- generation pointer;
- analyzer determinism;
- fuzzy candidate bounds;
- SQL compatibility.

Typesense adds:

- server-version matrix;
- key permissions;
- collection aliases;
- JSONL import line errors;
- scoped filters;
- curation API versions;
- direct-browser forbidden operations;
- circuit breaker;
- remote schema drift.

Future Meilisearch adds task waiting, settings, index swap, tenant tokens, and version matrix.

## 8. Shadow comparison

During migration or certification, sample safe production queries where the merchant enables it.

Compare:

- top-k overlap;
- rank correlation;
- exact identifier behavior;
- forbidden-hit count;
- latency;
- zero-result difference;
- facet difference;
- provider errors.

Never send a query to an external shadow provider without configured consent and privacy policy. Sensitive-query redaction rules apply.

Shadow data cannot automatically activate the provider. It informs verification and operator approval.

## 9. Provider certification record

Each provider release produces:

```yaml
provider: typesense
adapter_version: 1.0.0
server_versions:
  - <certified versions>
contract_version: 1.0
fixture_version: 1.0
capability_hash: <sha256>
tests:
  passed: <count>
  failed: 0
relevance:
  mrr: <value>
  ndcg_at_10: <value>
performance:
  reference_environment: <id>
  p95_ms: <value>
security_review: <reference>
certified_at: <timestamp>
commit: <sha>
```

The administration UI may show certification data but must not claim certification for untested server versions.

## 10. Conformance requirements

- `CON-001 MUST`: all production providers implement the same versioned contracts.
- `CON-002 MUST`: every declared capability maps to tests.
- `CON-003 MUST`: visibility and scope invariants pass for every provider.
- `CON-004 MUST`: lifecycle includes verified activation and rollback.
- `CON-005 MUST`: partial writes are represented per document.
- `CON-006 MUST`: provider-specific tests supplement common tests.
- `CON-007 MUST`: relevance and performance results are reproducible.
- `CON-008 MUST`: provider certification names exact tested versions and environments.
- `CON-009 MUST`: a future provider can be added without modifying storefront or domain code.


---

# 10 Testing, Performance, and Release Gates

## 1. Quality strategy

Testing must prove behavior across layers and failure modes. Mock-only confidence is insufficient for WordPress, database, queue, browser, and external-search integration.

Required layers:

- pure domain unit tests;
- property and fuzz tests;
- architecture tests;
- WordPress and WooCommerce integration tests;
- real database tests on MySQL and MariaDB;
- provider conformance tests;
- real Typesense container tests;
- API contract tests;
- browser E2E tests;
- accessibility tests;
- compatibility tests;
- upgrade and migration tests;
- security tests;
- performance and relevance benchmarks;
- failure-injection and recovery tests;
- installable-artifact smoke tests.

## 2. Tooling baseline

Codex chooses maintained tools compatible with the supported stack, documenting any substitution.

Expected categories:

- PHPUnit;
- WordPress core test framework;
- WooCommerce test helpers and QIT where applicable;
- PHPStan or Psalm at a strict useful level;
- PHPCS with WordPress and WooCommerce standards plus project rules;
- Infection or equivalent mutation testing for critical domain code;
- ESLint;
- TypeScript strict compiler;
- Vitest or Jest;
- Playwright;
- axe-core plus manual assistive-technology checks;
- k6, Artillery, or equivalent for load;
- Composer and npm audit;
- SBOM and secret scanning;
- Docker Compose for reproducible services.

Static analysis exceptions require local explanation and owner. A global ignore file must not become a dumping ground.

## 3. Supported runtime matrix

The release matrix is finalized against current supported software at release time. The initial engineering target is:

- WordPress minimum inherited from the pinned baseline unless an ADR raises it;
- latest stable WordPress plus the previous two supported release lines;
- WooCommerce latest stable plus at least the previous two minor release lines;
- PHP syntax floor chosen by ADR, with enterprise certification only on maintained PHP branches;
- MySQL 8.x;
- MariaDB versions supported by the certified WooCommerce matrix;
- multisite single-site and network activation paths;
- object cache present and absent;
- persistent cron and normal WP-Cron;
- current and previous certified Typesense major where feasible;
- current stable Chrome, Firefox, Safari, and Edge;
- representative current iOS Safari and Android Chrome.

Do not claim support for a combination not run or explicitly reviewed.

## 4. Unit tests

Cover:

- value objects;
- query parser;
- analyzer stages;
- identifier normalization;
- filter AST;
- ranking formula;
- capability resolution;
- configuration merge and validation;
- operation-plan preconditions;
- secret redaction;
- analytics redaction;
- cache-key construction;
- visibility policy;
- provider error normalization;
- state machines;
- idempotency.

Use mutation testing on ranking, visibility, authorization, plan preconditions, and redaction. Surviving material mutants must be addressed.

## 5. Property and fuzz tests

Generate:

- arbitrary Unicode and malformed UTF-8 boundaries where runtime permits;
- long tokens;
- combining marks;
- RTL;
- CJK;
- punctuation and model numbers;
- filter ASTs within and beyond budget;
- position-encoding round trips;
- synonym graphs;
- cache-context combinations;
- document schemas;
- provider partial-result bodies;
- malicious HTML and URLs.

Properties include:

- normalization idempotence;
- analyzer same input and revision gives same output;
- encode/decode round trip;
- cache keys differ when security scope differs;
- redactor never returns a configured exact secret;
- query planner never exceeds declared expansion bounds;
- provider response validator rejects unknown unsafe shapes.

## 6. Architecture tests

Fail CI for prohibited dependencies and patterns listed in `CODEX_START_HERE.md`.

Additional checks:

- no production use of `$_GET`, `$_POST`, or `$_REQUEST` outside entry adapters;
- no direct echo of provider content;
- no direct product search against posts/meta in new local engine;
- no raw API keys in serializable DTOs;
- no network call from domain/application tests;
- no synchronous full index call from hooks;
- no provider-specific class import from storefront packages;
- no remote asset URL in production manifests;
- no Freemius or prohibited upstream branding in package.

## 7. WordPress and WooCommerce integration tests

Cover:

- activation;
- missing WooCommerce;
- unsupported runtime;
- database table install;
- migrations;
- product CRUD;
- variations;
- stock;
- prices;
- scheduled sales;
- categories, tags, brands, attributes;
- custom fields;
- trash, restore, delete;
- password protection;
- visibility;
- REST updates;
- bulk edit;
- imports;
- HPOS compatibility and absence of order assumptions;
- multisite;
- capabilities;
- nonces;
- REST permission callbacks;
- privacy exporter and eraser;
- uninstall choices;
- cron and Action Scheduler.

## 8. Provider tests

Run common conformance against:

- local MySQL;
- local MariaDB;
- real Typesense current certified version;
- real Typesense previous certified version where supported.

Do not replace real Typesense tests with an HTTP mock. Use mocks only for deterministic transport fault injection.

## 9. Browser E2E

Scenarios:

- first render;
- no JavaScript;
- keyboard-only;
- screen-reader semantics;
- IME;
- quick typing and request race;
- network delay;
- provider error;
- mobile overlay;
- multiple forms;
- details panel;
- exact SKU;
- fuzzy query;
- filters and facets;
- results page;
- add to cart;
- variable product;
- dynamic price hydration;
- logged-in restricted scope;
- search history;
- clear history;
- RTL;
- reduced motion;
- zoom;
- block editor preview;
- theme integrations;
- checkout and cart non-interference.

Use visual regression selectively for component-owned layouts, not brittle full-site screenshots alone.

## 10. Accessibility certification

Automated scans are necessary but insufficient.

For each GA release or material UI change:

- axe or equivalent;
- keyboard manual test;
- screen reader on Windows, for example NVDA;
- screen reader on macOS or iOS, for example VoiceOver;
- mobile touch and zoom;
- high contrast or forced colors;
- reduced motion;
- RTL;
- error and loading announcements;
- modal focus restoration.

Record browser, assistive technology, version, tester, and result. Critical barriers block release.

## 11. Compatibility testing

Automated where open and available, manual where licensed.

Test:

- classic and block themes;
- prioritized theme registry;
- Elementor, Bricks, Divi, and current block editor;
- WPML, Polylang, TranslatePress;
- WooCommerce Brands and supported brand plugins;
- representative multi-currency;
- representative B2B or role-based visibility;
- common caching and optimization plugins;
- object cache;
- common import tools;
- security plugins;
- CDN/proxy configuration;
- PHP deprecation settings.

Compatibility statements include exact versions and date.

## 12. Upgrade tests

Maintain fixtures from:

- the pinned FiboSearch free baseline;
- earlier upstream option layouts where supported;
- every released Starfiniti schema version;
- interrupted migration states;
- candidate build in progress;
- dual-write in progress;
- retired local generation;
- existing Typesense alias.

Test:

- normal upgrade;
- repeated migration;
- interrupted migration resume;
- rollback plugin version where supported;
- old configuration import;
- uninstall;
- fresh reinstall with retained data;
- WordPress database prefix variation;
- multisite.

A migration must never silently delete a verified active index.

## 13. Security tests

Required automated tests:

- XSS in titles, descriptions, taxonomy names, images, highlights, admin configuration, analytics;
- SQL injection;
- REST authorization;
- nonce and CSRF;
- SSRF and DNS/IP policy;
- redirect bypass;
- secret storage and redaction;
- diagnostics leak scan;
- cache scope leakage;
- scoped-key forbidden actions;
- rate limit;
- query-budget DoS;
- oversized body;
- malformed JSON;
- dependency and artifact scans;
- prompt injection in MCP catalog data;
- OAuth scope and tenant isolation;
- idempotency replay;
- approval-token binding.

Before GA, perform a focused independent security review or penetration test of:

- public REST and fast endpoints;
- direct Typesense key model;
- secret storage;
- provider setup;
- admin operations;
- MCP authorization and write approvals.

## 14. Failure injection

Automate where practical:

- kill index worker mid-batch;
- process provider success but lose local acknowledgment;
- database connection loss;
- deadlock;
- table missing;
- disk or quota exhaustion simulation;
- Typesense timeout;
- partial JSONL import failure;
- malformed Typesense line;
- revoked key;
- alias change timeout;
- DNS failure;
- TLS error;
- Action Scheduler backlog;
- stale lock;
- corrupt candidate;
- cache unavailable;
- analytics endpoint failure;
- MCP server unavailable;
- WordPress control API unavailable.

Expected behavior includes no active-index corruption, bounded retry, actionable status, and safe recovery.

## 15. Reference datasets

### Small

- 1,000 products;
- realistic variations and taxonomies;
- used in CI.

### Medium

- 10,000 products;
- used in regular integration and performance CI.

### Large local certification

- 100,000 products;
- at least 300,000 total product/variation documents;
- realistic text and facets;
- used in scheduled or release performance runs.

### External scale

- 500,000 and 1,000,000 documents for Typesense certification where infrastructure permits;
- results must name infrastructure and not be generalized beyond it.

Generators use deterministic seeds and publish corpus statistics.

## 16. Search quality budgets

Create a versioned relevance suite with human judgments.

Mandatory initial targets, adjusted only through reviewed ADR with evidence:

- exact primary SKU top-1 rate: 100 percent for eligible products;
- exact configured identifier top-1 rate: 100 percent unless duplicate identifier policy is explicitly configured;
- forbidden result rate: 0;
- known-item MRR: at least 0.90 on the certified corpus;
- NDCG@10: initial baseline established before GA, no release regression greater than 2 percent without approved relevance rationale;
- zero-result rate on the canonical acceptance-query set: within defined expected set;
- provider conformance invariants: 100 percent.

Production analytics targets are merchant-specific and not release guarantees.

## 17. Performance SLOs

All figures are measured after request dispatch, excluding configured UI debounce. Publish exact reference environment and dataset.

### Local provider, large certification environment

Initial release targets:

- warm autocomplete server p50 at or below 60 ms;
- warm autocomplete server p95 at or below 150 ms;
- warm autocomplete server p99 at or below 300 ms;
- no critical query performs a full document-table scan;
- 100,000-product full candidate build completes within the documented operational window without active-search downtime;
- incremental eligible product change visible in active search p95 within 30 seconds under normal queue load;
- worker peak memory within configured budget, default certification target 256 MB;
- concurrent search p95 degradation during rebuild less than 30 percent.

The exact full-build time target is set after the first baseline benchmark and then becomes a non-regression budget. Do not invent a marketing number before measured implementation exists.

### Typesense provider

With network RTT at or below 30 ms on the reference setup:

- adapter overhead excluding network and Typesense execution p95 at or below 30 ms;
- proxied autocomplete end-to-end server p95 at or below 200 ms;
- direct-browser provider latency reported separately;
- incremental product change visible p95 within 30 seconds under normal queue load;
- bulk import has no unreported per-document failure;
- WordPress search worker concurrency remains bounded.

### UI

- search component JavaScript budget is established and enforced, with an initial target below 60 KB gzip for storefront code excluding optional integrations;
- no layout shift caused by plugin-owned search loading beyond an established small budget;
- stale response race rate: 0 in automated stress tests;
- interaction remains usable on a representative mid-range mobile device and throttled network.

## 18. Reliability SLOs

- no active-index downtime during successful full rebuild and activation;
- no unauthorized hit or facet leak;
- no lost delete after reconciliation;
- no operation duplicated by retry;
- queue oldest age warning default at 120 seconds, configurable;
- drift alert on critical mismatch immediately after deep verification;
- previous verified index retained for rollback for at least the configured minimum;
- search remains usable through normal form submission when autocomplete is unavailable;
- MCP outage has zero effect on storefront availability.

## 19. CI tiers

### Pull request

- lint;
- static analysis;
- unit;
- architecture;
- small integration;
- local and one Typesense conformance smoke;
- frontend tests;
- security fast suite;
- package manifest check.

### Main branch

- complete integration matrix subset;
- E2E;
- accessibility automated;
- provider full conformance;
- migration tests;
- medium performance smoke;
- reproducible package.

### Nightly

- expanded runtime matrix;
- failure injection;
- compatibility;
- fuzz;
- mutation;
- medium load;
- dependency and license refresh.

### Release candidate

- complete matrix;
- large local benchmark;
- external scale benchmark;
- manual accessibility;
- security review evidence;
- upgrade from all supported versions;
- disaster recovery;
- clean install of packaged ZIP;
- SBOM and provenance;
- release checklist.

## 20. Release gates

### Gate 0: Provenance and baseline

Pass when:

- official source pinned;
- license inventory clean;
- upstream behavior captured;
- Freemius and branding removal plan complete;
- baseline build and tests reproducible.

### Gate 1: Fork stabilization

Pass when:

- independent branding works;
- commercial integrations removed;
- inherited feature regression suite passes;
- no prohibited upstream calls remain;
- package activates cleanly.

### Gate 2: Architecture and contracts

Pass when:

- domain boundaries and architecture tests pass;
- canonical schemas versioned;
- control API skeleton is contract-complete;
- provider SDK and capability model exist;
- traceability matrix is current.

### Gate 3: Catalog and synchronization

Pass when:

- canonical documents cover required entities;
- visibility, price, language, and variation policies pass;
- outbox, retries, dead letters, full build, incremental sync, and reconciliation pass failure tests;
- dual-write checkpoints work.

### Gate 4: Local provider certification

Pass when:

- local engine requirements pass;
- correctness and visibility conformance pass;
- large benchmark and resource budgets pass;
- atomic generation activation and rollback pass;
- query plans reviewed.

### Gate 5: Typesense provider certification

Pass when:

- supported versions pass real integration tests;
- credential and scoped-key tests pass;
- bulk import partial errors are correct;
- alias activation and rollback pass;
- direct and proxy transport policies pass;
- resilience and safe degradation pass.

### Gate 6: Storefront and results

Pass when:

- no-JavaScript, desktop, mobile, keyboard, screen-reader, result page, cart, and variation behavior pass;
- race and error handling pass;
- priority integrations are certified;
- bundle and UI performance budgets pass.

### Gate 7: Administration, relevance, and analytics

Pass when:

- setup, operations, relevance studio, synonyms, curations, analytics, audit, diagnostics, and privacy controls pass;
- configuration planning and rollback pass;
- reports have defined metrics.

### Gate 8: Security, privacy, and operations

Pass when:

- threat model complete;
- independent focused review complete;
- no unresolved critical/high issue;
- secret, SSRF, access control, leakage, supply-chain, backup, and recovery gates pass;
- support and incident documentation complete.

### Gate 9: MCP

Pass when:

- current protocol and authorization behavior certified;
- tenant and scope isolation pass;
- immutable plan, dry-run, approval, idempotency, audit, and prompt-injection tests pass;
- MCP failure has no storefront impact.

### Gate 10: GA release

Pass when:

- every prior gate is green;
- traceability is complete;
- full release matrix is green;
- relevance and performance budgets pass;
- installable ZIP is reproducible and clean;
- migration and recovery docs are validated by a second operator;
- release notes state exact tested limits and known non-critical limitations;
- no mandatory item is deferred.

## 21. Waivers

A mandatory gate may not be waived for GA. A test may be temporarily quarantined only when:

- issue is documented;
- root cause is understood;
- it does not cover a security, privacy, visibility, data-loss, migration, or active-index invariant;
- a deterministic replacement check exists;
- owner and expiry are set.

Flaky mandatory tests block release until fixed.

## 22. QA requirements

- `QA-001 MUST`: all test layers named in this document exist.
- `QA-002 MUST`: real WordPress, WooCommerce, database, and Typesense integration tests run.
- `QA-003 MUST`: visibility and security failures are release-blocking.
- `QA-004 MUST`: large reference benchmarks are reproducible.
- `QA-005 MUST`: accessibility includes manual assistive-technology evidence.
- `QA-006 MUST`: upgrade and interrupted-migration tests cover every released schema.
- `QA-007 MUST`: failure injection proves active-index safety.
- `PER-001 MUST`: relevance metrics meet or exceed approved budgets.
- `PER-002 MUST`: provider and UI performance meet documented budgets.
- `REL-001 MUST`: all release gates pass without mandatory waiver.
- `REL-002 MUST`: packaged artifact is installed and tested, not only source checkout.


---

# 11 Implementation Plan for Codex

## 1. Execution model

Implement in gates, but maintain one production-quality target. A gate is an internal integration milestone, not an MVP or public release.

For every gate:

1. confirm prerequisite evidence;
2. create or update requirement rows;
3. implement vertical slices with tests;
4. keep inherited behavior working or explicitly migrate it;
5. update ADRs and operator documentation;
6. run the gate suite;
7. record evidence in `docs/IMPLEMENTATION_STATUS.md`;
8. commit and tag the internal gate only after passing.

Do not implement all files as empty scaffolding before behavior. Build complete vertical slices while preserving boundaries.

## 2. Required project-control files

Create:

```text
docs/IMPLEMENTATION_STATUS.md
docs/TRACEABILITY_MATRIX.md
docs/AUDIT_BASELINE.md
docs/THREAT_MODEL.md
docs/COMPATIBILITY_MATRIX.md
docs/PERFORMANCE_RESULTS.md
docs/RELEVANCE_RESULTS.md
docs/RECOVERY_RUNBOOK.md
docs/UPGRADE_GUIDE.md
docs/PROVIDER_AUTHORING_GUIDE.md
docs/adr/
```

`IMPLEMENTATION_STATUS.md` must list:

- current gate;
- last passing commit;
- requirements complete, in progress, blocked, and not started;
- tests and benchmarks last run;
- known failures;
- current active architectural risks;
- next legal actions.

A requirement is not “complete” without evidence links.

## 3. Gate 0 work: provenance and baseline

### Tasks

1. Fetch the current official free FiboSearch archive.
2. Verify archive hash, plugin header, readme license, file headers, bundled dependencies, and images.
3. Import to `upstream-fibosearch`.
4. Create immutable tag.
5. Generate SBOM and license report.
6. Build the upstream plugin in a reproducible WordPress/WooCommerce environment.
7. Inventory:
   - plugin entry points;
   - classes and namespaces;
   - options;
   - custom tables;
   - AJAX and REST;
   - shortcodes;
   - blocks;
   - widgets;
   - menu integration;
   - theme integrations;
   - JavaScript and CSS build;
   - translations;
   - analytics;
   - remote calls;
   - Freemius;
   - premium gates and notices;
   - cron;
   - uninstall;
   - public hooks and filters.
8. Capture baseline E2E and screenshots.
9. Add security regression tests for known classes of bugs from recent changelogs, including output escaping, password-protected products, nonce handling, duplicate results, and mobile focus.
10. Write preservation/replacement/deletion map.

### Output

`docs/AUDIT_BASELINE.md` must label every major inherited subsystem:

```text
retain temporarily
retain and refactor
replace
remove
quarantine pending license review
```

### Exit

Gate 0 in the release document passes.

## 4. Gate 1 work: independent fork stabilization

### Tasks

1. Introduce working identity constants in one composition file.
2. Rename user-facing product identity.
3. Preserve legal notices.
4. Remove Freemius and commercial code.
5. Remove upstream telemetry and external calls.
6. Replace icons and screenshots with placeholders only in development, then original Starfiniti assets before packaging.
7. Migrate option and handle prefixes where safe.
8. Add compatibility aliases for inherited shortcode and essential hooks.
9. Add deprecation mechanism.
10. Fix baseline security issues discovered by the audit.
11. Create clean install, activation, deactivation, and uninstall tests.
12. Produce a package and install it from ZIP.

### Important

Do not mass search-and-replace namespaces without tests. Some serialized options, block names, DOM hooks, CSS selectors, and third-party theme integrations may depend on identifiers. Use explicit migration maps.

### Exit

The fork behaves at least as well as the free baseline for retained features, has independent branding, and contains no prohibited commercial integration.

## 5. Gate 2 work: contracts and architecture

### Tasks

1. Create domain and application packages.
2. Add JSON Schemas for canonical contracts.
3. Add DTOs and validators.
4. Add provider interfaces and capabilities.
5. Add `SearchGateway`.
6. Add provider registry.
7. Add error model.
8. Add immutable configuration repository and revisions.
9. Add operation-plan model and state machine.
10. Add control API OpenAPI document.
11. Add architecture tests.
12. Add a test-only in-memory provider.
13. Bridge inherited UI to `SearchGateway` behind a feature flag.
14. Add local legacy adapter only as a temporary baseline bridge if needed.
15. Prove the UI can switch test providers without provider-specific code.

### Exit

A provider can be replaced in integration tests without modifying domain or UI code.

## 6. Gate 3 work: canonical catalog and synchronization

Implement vertical slices in this order.

### Slice A: simple public product

- canonical document;
- create/update/delete event;
- outbox;
- local test provider write;
- status and inspect CLI;
- reconciliation.

### Slice B: variable product and exact SKU

- parent-collapsed strategy;
- variation identity;
- exact variation SKU;
- stock and price projection;
- delete and change strategy.

### Slice C: taxonomy and facets

- categories;
- tags;
- brands;
- attributes;
- hierarchy and localized labels.

### Slice D: language

- site locale;
- WPML;
- Polylang;
- TranslatePress;
- fallback and deletion.

### Slice E: restricted and dynamic context

- scope tokens;
- server revalidation;
- price scopes;
- direct-browser policy disablement.

### Slice F: custom fields and extensions

- allow-list;
- typed resolvers;
- security limits;
- extension tests.

### Orchestration

Implement:

- durable outbox;
- leases;
- retries;
- dead letters;
- Action Scheduler adapter;
- full-build state machine;
- resumable cursors;
- high-water mark;
- catch-up events;
- verification;
- per-target checkpoints;
- quick, sample, and full reconciliation;
- CLI and admin status.

### Exit

Catalog and sync requirements pass against the test provider and failure injection before building search providers.

## 7. Gate 4 work: local provider

### 7.1 Storage and migrations

1. Create generation and document tables.
2. Create term dictionary and postings.
3. Create identifier index.
4. Create facets.
5. Create fuzzy term vocabulary.
6. Create stats and checkpoints.
7. Add repeatable migration runner.
8. Add MySQL and MariaDB integration tests.
9. Add storage estimator.

### 7.2 Analyzer

1. Unicode normalization.
2. text extraction;
3. tokenization;
4. punctuation/model policy;
5. diacritic policy;
6. locale profiles;
7. stop words;
8. synonyms;
9. optional stemming behind verified dependency;
10. property and fixture tests.

### 7.3 Index writer

1. canonical projection;
2. term and posting generation;
3. compact position encoding;
4. identifiers;
5. facets;
6. bounded batch write;
7. delete;
8. generation build;
9. checksums;
10. resume;
11. verification.

### 7.4 Query engine

1. parser and cost planner;
2. exact identifiers;
3. exact terms;
4. prefix;
5. phrase;
6. fuzzy vocabulary;
7. filters;
8. ranking;
9. curations;
10. facets;
11. highlights;
12. pagination;
13. explain;
14. query cache.

### 7.5 Activation and operations

1. candidate build;
2. catch-up;
3. verify;
4. atomic active pointer;
5. smoke test;
6. rollback;
7. cleanup;
8. health;
9. metrics;
10. slow query diagnostics.

### 7.6 Fast endpoint

Implement only after the normal REST local provider passes all conformance and security tests. Keep disabled by default until the dedicated gate passes.

### Exit

Local provider passes conformance, large certification, failure injection, and query-plan review.

## 8. Gate 5 work: Typesense provider

### 8.1 Transport and compatibility

1. official or carefully implemented typed HTTP client;
2. endpoint safety;
3. TLS;
4. server-version detection;
5. compatibility profiles;
6. typed errors;
7. deadlines;
8. retries;
9. circuit breaker.

### 8.2 Credentials

1. provisioning, indexing, and search references;
2. permission probe;
3. encrypted storage;
4. fingerprint;
5. rotation;
6. diagnostics redaction;
7. direct-browser safety validation.

### 8.3 Schema and lifecycle

1. collection naming;
2. canonical projection;
3. schema diff;
4. candidate collection;
5. bulk JSONL import;
6. per-line results;
7. catch-up;
8. verification;
9. alias activation;
10. rollback;
11. cleanup.

### 8.4 Query

1. canonical compiler;
2. fields and weights;
3. prefix;
4. typo;
5. identifier infix policy;
6. filters;
7. facets;
8. sorting;
9. grouping;
10. highlights;
11. curations;
12. synonyms;
13. pagination;
14. direct and proxied transports.

### 8.5 Operations

1. deep health;
2. schema drift;
3. document count and samples;
4. provider analytics optional adapter;
5. safe degradation;
6. verified dual-write fallback;
7. shadow comparison.

### Exit

Typesense passes real-version conformance, security, alias rollback, partial-import, and performance gates.

## 9. Gate 6 work: storefront and results

### Tasks

1. Replace inherited public JavaScript incrementally with the new TypeScript state machine.
2. Preserve semantic server-rendered form.
3. Implement accessible combobox.
4. Implement grouped results.
5. Implement mobile mode.
6. Implement details panel.
7. Implement recent history.
8. Implement safe highlights.
9. Add provider-neutral transport.
10. Add results-page integration.
11. Add facets and sorting.
12. Add blocks, shortcode, menu, widget compatibility, PHP API.
13. Migrate theme integrations into registry.
14. Add browser, accessibility, visual, and theme tests.
15. Remove obsolete inherited public code after parity.

### Exit

All UX and results requirements pass on local and Typesense.

## 10. Gate 7 work: administration, relevance, and analytics

### Administration

1. setup wizard;
2. provider configuration;
3. operation dashboard;
4. index status;
5. configuration revisions;
6. safe secret forms;
7. diagnostics;
8. Site Health;
9. support bundle;
10. data retention.

### Relevance

1. ranking profiles;
2. synonyms;
3. stop words;
4. redirects;
5. curations;
6. search lab;
7. explain;
8. provider comparison;
9. relevance fixtures and metrics;
10. preview and activation plan.

### Analytics

1. event contracts;
2. ingestion;
3. redaction;
4. raw and aggregate storage;
5. cleanup;
6. query IDs;
7. click and conversion attribution;
8. reports;
9. privacy exporter and eraser;
10. optional Typesense analytics projection.

### Exit

The merchant can safely configure, test, operate, diagnose, and measure search without editing code.

## 11. Gate 8 work: security, privacy, and operations

### Tasks

1. complete threat model;
2. dedicated capabilities;
3. SSRF policy;
4. encryption and secret rotation;
5. rate and query budgets;
6. supply-chain pipeline;
7. artifact scans;
8. structured logs;
9. metrics and alerts;
10. backup and recovery;
11. incident runbook;
12. privacy policy text;
13. retention and purge;
14. vulnerability process;
15. independent focused security review;
16. fix all critical and high findings;
17. rerun complete security suite.

### Exit

Gate 8 passes with review evidence.

## 12. Gate 9 work: control API and MCP

### WordPress control API

1. OpenAPI contract;
2. read endpoints;
3. plan endpoints;
4. execution endpoints;
5. idempotency;
6. approval verification;
7. audit;
8. rate and capability controls;
9. contract tests.

### MCP server

1. current official SDK;
2. protocol negotiation;
3. OAuth and scopes;
4. site registration;
5. resources;
6. read tools;
7. planning tools;
8. execution tools;
9. human approval;
10. prompt-injection protection;
11. structured logs and metrics;
12. complete security and isolation tests.

### Exit

MCP can safely inspect, plan, execute, verify, and audit operations without bypassing deterministic controls and without affecting storefront availability.

## 13. Gate 10 work: release certification

1. freeze release candidate;
2. run full matrix;
3. run large local and Typesense scale benchmarks;
4. run relevance suite;
5. complete manual accessibility;
6. complete security retest;
7. run upgrade and recovery drills;
8. install packaged ZIP into fresh and upgrade environments;
9. generate SBOM and notices;
10. review WordPress.org current rules if submitting;
11. write exact release notes and tested limits;
12. have a second operator execute install, provider setup, reindex, activation, rollback, diagnostics, and recovery from documentation;
13. tag and build release from CI;
14. retain immutable evidence.

No `1.0.0` GA tag before this gate passes.

## 14. Commit and pull-request policy

A pull request must include:

- requirement IDs;
- problem and design;
- migration impact;
- security and privacy impact;
- tests;
- performance impact;
- screenshots for UI;
- documentation;
- rollback;
- known limitations.

Large refactors are decomposed into safe preparatory changes and behavior activation. Feature flags are temporary migration tools, not permanent alternate products. Every flag has owner, default, removal condition, and test matrix.

## 15. Database migration policy

Each migration has:

```text
version
description
preconditions
forward steps
verification
resume behavior
rollback or recovery strategy
estimated impact
tests
```

Rules:

- never block activation on a full catalog build;
- never drop active-generation data in the same release that stops reading it;
- expand, migrate, switch, contract;
- retain compatibility reads during migration where necessary;
- backup recommendation before destructive contract step;
- record migration state;
- rerun safely;
- test prefixed and multisite tables.

## 16. Public API versioning

Use semantic versioning.

- additive compatible hooks and fields: minor;
- bug fixes: patch;
- breaking public contracts: major with deprecation period where possible;
- internal schema versions independent from plugin semantic version;
- REST and MCP contracts explicitly versioned;
- provider adapter version independent but compatible with plugin range.

Document deprecations and emit protected operator warnings, not public output.

## 17. Decision policy

When specification details conflict with measured platform behavior:

1. preserve security and correctness;
2. preserve data and rollback;
3. preserve provider neutrality;
4. choose the simplest supportable design;
5. record ADR with alternatives and evidence;
6. update specification and traceability;
7. do not make a hidden local exception.

## 18. Final code-review checklist

Before closing any gate, inspect for:

- leaked upstream branding;
- unlicensed assets;
- legacy global state;
- direct provider conditionals;
- unbounded arrays or queries;
- synchronous indexing;
- missing deadlines;
- swallowed exceptions;
- stale caches;
- scope-insensitive keys;
- raw secrets;
- unsafe URLs;
- incomplete partial-failure handling;
- non-idempotent operations;
- missing rollback;
- provider response trust;
- untranslated strings;
- accessibility regressions;
- unsupported runtime assumptions;
- documentation drift;
- production TODOs.

## 19. Codex completion response

When implementation truly passes Gate 10, Codex must provide:

- release commit and tag;
- installable plugin artifact path and checksum;
- MCP artifact path and version;
- SBOM and license report;
- traceability completion;
- test summary by layer and matrix;
- performance and relevance results;
- security review summary;
- migration and rollback confirmation;
- exact certified versions and tested limits;
- known non-critical limitations;
- evidence links.

Do not provide a celebratory completion claim without this evidence.


---

# 12 Assumptions and Initial Decisions

These decisions allow implementation to start without blocking questions. Codex may change one only through a documented ADR with evidence and no reduction of mandatory quality.

## A-001 Working product identity

Use:

- `Starfiniti Search for WooCommerce`;
- slug `starfiniti-search`;
- namespace `Starfiniti\Search`;
- text domain `starfiniti-search`;
- database prefix segment `sfs_`;
- REST namespace `starfiniti-search/v1`.

Centralize public identity. A controlled rebrand before first public release is allowed, but no upstream trademark may be used as the new identity.

## A-002 Upstream baseline

At project initialization, use the latest official free FiboSearch release available from WordPress.org and pin it exactly. Research at specification time identified version 1.34.0 dated 2026-08-03, but Codex must verify the exact current official release and archive because repository initialization may happen later.

Do not automatically move to a newer upstream after work begins. Review and port security and compatibility fixes deliberately.

## A-003 Premium source

No FiboSearch Pro code is available or authorized for reuse. The local index is an original implementation.

## A-004 License target

Target `GPL-2.0-or-later`, subject to file-level upstream and dependency verification. Preserve notices and produce full source/build materials.

## A-005 Product tiers

The code architecture must not intentionally cripple the local provider. Commercial packaging and hosted-service strategy are separate business decisions. The plugin remains fully functional with local search and bring-your-own Typesense.

## A-006 First GA providers

Production providers in the first GA:

1. Local.
2. Typesense.

Meilisearch is the first planned additional provider, but it is not allowed to delay the quality of Local and Typesense. No production Meilisearch stub appears in the UI.

## A-007 MCP

The Search Operations MCP server is part of the complete enterprise deliverable. It is packaged and deployed separately from the WordPress plugin and is never required for storefront search.

## A-008 Search-result parity

The product guarantees common contracts and quality invariants, not identical rank order between engines. Provider-specific relevance baselines are accepted when canonical minimums pass.

## A-009 Variation default

Default to parent-collapsed results with exact variation SKU awareness. Offer variation-as-result as a schema-affecting option.

## A-010 Local provider scale

Certify local search on the documented 100,000-product large fixture and high-variation fixture. Do not state an absolute hard maximum until measurement exists. Recommend Typesense when catalog, traffic, facets, or hosting exceed certified local limits.

## A-011 Runtime syntax

Choose a modern PHP syntax floor through ADR after auditing current WordPress/WooCommerce adoption and maintained PHP branches. Do not inherit PHP 7.4 merely because the upstream baseline supports it. Enterprise certification is only for maintained PHP versions.

The code may support a broader floor if doing so does not materially harm architecture or security, but support claims must identify certified branches.

## A-012 Frontend

Use a small TypeScript public component with no jQuery requirement for new code. Preserve a semantic no-JavaScript form. Use WordPress React components in admin and blocks where appropriate.

## A-013 Queue

Use Action Scheduler as execution substrate because WooCommerce is required, with product-owned durable outbox and operation state as the source of correctness.

## A-014 Typesense transport

Default:

- direct browser transport only for public-safe catalogs;
- server proxy for restricted, dynamic, B2B, or customer-specific catalogs;
- indexing always server-side;
- provisioning and write credentials never in browser.

## A-015 Configuration

Canonical desired configuration is the source of truth. Provider settings, schemas, synonyms, and curations are projections and are reconciled.

## A-016 Analytics

First-party canonical analytics are the source of truth. Provider-native analytics are optional supplementary sources. No external telemetry by default.

## A-017 Search page

The full WooCommerce search-results page uses the same `SearchGateway`, provider, ranking profile, visibility context, and query semantics as autocomplete.

## A-018 Fast local endpoint

A minimal-bootstrap public local endpoint is allowed as an optional certified performance mode. It is not enabled by default until security and parity gates pass. Restricted or dynamic catalogs use the full WordPress path.

## A-019 Managed control plane

A Starfiniti-hosted control plane is not required for first GA. Contracts must permit it later. Bring-your-own Typesense and local operation remain independent.

## A-020 Questions and ambiguity

Codex records non-blocking ambiguity as an ADR and proceeds. It asks only for:

- unavailable required repository or source;
- credentials needed for a real configured external environment after container tests exist;
- legal authorization that cannot be inferred from source;
- an irreversible public brand decision immediately before publication;
- a destructive production operation.

## Initial ADR list

Create:

```text
ADR-0001 Fork provenance and independent local engine
ADR-0002 Modular monolith and provider ports
ADR-0003 Canonical document and request contracts
ADR-0004 Durable outbox plus Action Scheduler
ADR-0005 Local inverted-index storage and ranking
ADR-0006 Generation and alias activation model
ADR-0007 Typesense credentials and transport policy
ADR-0008 Restricted catalog visibility model
ADR-0009 Frontend component and accessibility model
ADR-0010 Canonical analytics and privacy
ADR-0011 Search Control API and MCP authorization
ADR-0012 Supported runtime matrix
```


---

# 13 Future Providers: Meilisearch and Others

## 1. Clarification

The likely search engine intended by “ammelia search” is **Meilisearch**. There is no widely used general-purpose commerce search backend known as “Amelia Search” that should shape this product architecture.

Meilisearch is a realistic future provider. The provider-neutral design also allows OpenSearch, Elasticsearch-compatible services, Algolia, or specialized engines, but each has different operational and ranking semantics.

## 2. Recommendation

Ship first GA with:

- Local;
- Typesense.

After both pass conformance and the provider SDK is stable, implement Meilisearch as the next provider. This sequencing avoids multiplying production and support risk while the canonical catalog, visibility, relevance, and operations contracts are still evolving.

Do not advertise a provider before it passes the complete conformance, security, visibility, migration, and recovery gates.

## 3. Why Meilisearch fits

Meilisearch provides concepts compatible with this platform:

- search-as-you-type;
- typo tolerance;
- searchable attributes;
- filterable and sortable attributes;
- ranking rules;
- synonyms and stop words;
- facets;
- API keys and tenant tokens;
- asynchronous indexing tasks;
- index swaps for low-downtime migration;
- optional vector or semantic capabilities depending on version and configuration;
- self-hosted and cloud deployment.

The common storefront and canonical request can map to these capabilities.

## 4. Why Meilisearch will not be identical

Differences may include:

- tokenization;
- typo behavior;
- ranking rule order;
- phrase and proximity semantics;
- filter syntax;
- facet distribution;
- pagination;
- asynchronous task behavior;
- index settings;
- tenant-token model;
- vector features;
- operational topology.

The product must report capability differences. It must not claim exact local, Typesense, and Meilisearch rank parity.

## 5. Meilisearch adapter outline

A future adapter implements the same interfaces.

### Configuration

```text
host
server version
master or administration secret reference
indexing key reference
search key reference
tenant-token policy
index prefix
timeouts
task polling
TLS and private-network policy
```

The master key is used only to manage narrower keys where required. Routine search and indexing use least-privilege keys.

### Index lifecycle

1. create versioned candidate index;
2. apply searchable, displayed, filterable, sortable, ranking, typo, synonym, stop-word, dictionary, pagination, and locale settings;
3. batch documents;
4. wait for asynchronous tasks with deadlines;
5. parse task failures;
6. merge catch-up changes;
7. verify;
8. atomically swap candidate and active index names where supported;
9. smoke test;
10. retain rollback index;
11. clean later.

### Query mapping

Map canonical:

- searchable fields and weights to ordered searchable attributes and ranking configuration;
- typo policy;
- filters and facets;
- sorting;
- crop and highlight output;
- pagination;
- scope to tenant token or server-side filter;
- curations through application-level rules where provider support differs.

### Security

- browser key or tenant token only for public-safe or securely scoped data;
- no master key in WordPress response or browser;
- same SSRF policy;
- same restricted-catalog rules;
- task and diagnostics output redacted.

## 6. Meilisearch release prerequisites

Before implementation:

1. provider SDK stable in a released internal contract;
2. no Typesense-specific UI or domain code;
3. fixture and conformance suites reusable;
4. server-version matrix chosen;
5. task and swap semantics verified in real containers;
6. security review of API keys and tenant tokens;
7. operational backup and recovery documented.

Before release:

- complete common conformance;
- exact identifier and visibility invariants;
- real server integration;
- task failure and timeout tests;
- swap and rollback;
- direct browser forbidden-action tests;
- performance and relevance certification;
- compatibility documentation.

## 7. OpenSearch or Elasticsearch-compatible provider

Possible and useful for organizations already operating it.

Strengths:

- advanced analyzers;
- complex Boolean search;
- aggregations;
- large scale;
- mature operations;
- vector and hybrid capabilities.

Costs:

- much heavier operational footprint;
- complicated schema and mapping management;
- security and cluster administration;
- more tuning choices;
- greater risk of exposing raw query DSL.

Never expose raw Query DSL through the plugin or MCP. Implement a canonical compiler and strict allow-list.

## 8. Algolia provider

Possible as a hosted SaaS adapter.

Strengths:

- mature hosted operations;
- low-latency global search;
- merchandising and analytics ecosystem.

Costs:

- usage-based commercial dependency;
- proprietary semantics and lock-in;
- record-size and operation-cost considerations;
- credential and secured-filter model;
- plugin-directory service disclosure and consent.

The core product must remain usable without it.

## 9. PostgreSQL or other embedded providers

A PostgreSQL search provider is only relevant if the WordPress deployment has an external service or compatible catalog replica. It is not a replacement for the normal local MySQL provider in ordinary WordPress hosting.

SQLite FTS, Redis search, and other engines may be explored, but no provider is added simply because a library exists. It must solve a supported deployment need and pass the same gates.

## 10. Provider acceptance rule

A new provider requires:

- documented user need;
- maintainer and support owner;
- server-version policy;
- license and dependency review;
- configuration and secret model;
- schema and lifecycle mapping;
- visibility model;
- query compiler;
- capability declaration;
- complete conformance;
- relevance and performance evidence;
- backup, migration, and rollback;
- security review;
- operations and diagnostics;
- documentation.

“Connects successfully” is not provider support.


---

# References Used to Form the Specification

These are research inputs, not a substitute for inspecting the exact source and current documentation during implementation. Codex must re-check current stable versions, licenses, breaking changes, and platform support before release.

## FiboSearch

- WordPress.org plugin page, source links, current free features, changelog, runtime requirements, and open-source statement:  
  https://wordpress.org/plugins/ajax-search-for-woocommerce/
- FiboSearch Free versus Pro comparison, including the statement that the free version uses WooCommerce linear search and Pro uses a custom inverted index:  
  https://fibosearch.com/should-i-go-pro-fibosearch-free-vs-pro/
- FiboSearch 2.0 architecture and reliability discussion:  
  https://fibosearch.com/fibosearch-2-0-is-on-the-way/
- FiboSearch indexing documentation for public behavior and lifecycle research only, not source copying:  
  https://fibosearch.com/documentation/developers/indexing/
- FiboSearch changelog:  
  https://fibosearch.com/changelog/

## WordPress licensing and plugin policy

- WordPress GPL license statement:  
  https://wordpress.org/about/license/
- Detailed Plugin Directory Guidelines:  
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Plugin Developer FAQ:  
  https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- Taking over or forking an existing plugin, attribution and identity guidance:  
  https://developer.wordpress.org/plugins/wordpress-org/take-over-an-existing-plugin/
- WordPress security, sanitization, escaping, REST, privacy, and database documentation under the Plugin Handbook:  
  https://developer.wordpress.org/plugins/

## WooCommerce

- WooCommerce extension development and best practices:  
  https://developer.woocommerce.com/
- CRUD and data-store principles:  
  https://developer.woocommerce.com/docs/best-practices/data-management/crud-objects
- Action Scheduler:  
  https://actionscheduler.org/
- WooCommerce Quality Insights Toolkit:  
  https://qit.woo.com/docs/
- WooCommerce testing guidance:  
  https://developer.woocommerce.com/docs/extensions/core-concepts/testing

## Typesense

- Documentation home and current API version:  
  https://typesense.org/docs/
- Search API:  
  https://typesense.org/docs/30.2/api/search.html
- Documents and bulk import:  
  https://typesense.org/docs/30.2/api/documents.html
- API keys and scoped keys:  
  https://typesense.org/docs/30.2/api/api-keys.html
- Collection aliases:  
  https://typesense.org/docs/30.2/api/collection-alias.html
- High availability guide:  
  https://typesense.org/docs/guide/high-availability.html
- Official API clients:  
  https://typesense.org/docs/guide/install-typesense.html#api-clients
- Typesense Cloud management API, if managed provisioning is later implemented:  
  https://cloud.typesense.org/docs/

## Meilisearch

- Documentation home:  
  https://www.meilisearch.com/docs/
- Index settings, including searchable, filterable, sortable attributes, ranking, typo tolerance, synonyms, and stop words:  
  https://www.meilisearch.com/docs/reference/api/settings
- API keys and security:  
  https://www.meilisearch.com/docs/learn/security/master_api_keys
- Tenant tokens:  
  https://www.meilisearch.com/docs/learn/security/tenant_tokens
- Index swap:  
  https://www.meilisearch.com/docs/reference/api/indexes/swap_indexes

## Model Context Protocol

- Current specification root:  
  https://modelcontextprotocol.io/specification/
- Server tools:  
  https://modelcontextprotocol.io/specification/2026-07-28/server/tools
- Server resources:  
  https://modelcontextprotocol.io/specification/2026-07-28/server/resources
- Authorization:  
  https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization
- TypeScript SDK:  
  https://github.com/modelcontextprotocol/typescript-sdk
- Security best practices:  
  https://modelcontextprotocol.io/docs/tutorials/security/security_best_practices

## Standards and engineering references

- WAI-ARIA Authoring Practices, combobox pattern:  
  https://www.w3.org/WAI/ARIA/apg/patterns/combobox/
- WCAG 2.2:  
  https://www.w3.org/TR/WCAG22/
- OpenAPI 3.1:  
  https://spec.openapis.org/oas/v3.1.0
- JSON Schema:  
  https://json-schema.org/
- SPDX license identifiers:  
  https://spdx.org/licenses/
- OWASP SSRF Prevention Cheat Sheet:  
  https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
- OWASP API Security Top 10:  
  https://owasp.org/www-project-api-security/
- OAuth 2.1 work and current security best current practices should be verified at implementation time.

## Research rule

For every external API and platform:

1. use current official primary documentation;
2. pin exact supported versions;
3. record the date and version reviewed;
4. add an integration test for any behavior relied upon;
5. do not use a blog post or remembered behavior as the production contract;
6. re-run the review before each major release.
