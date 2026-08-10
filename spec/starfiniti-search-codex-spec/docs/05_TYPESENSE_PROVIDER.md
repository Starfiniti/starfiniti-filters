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
