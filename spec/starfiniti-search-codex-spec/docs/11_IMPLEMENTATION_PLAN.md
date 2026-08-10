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
