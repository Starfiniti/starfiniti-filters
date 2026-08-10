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
