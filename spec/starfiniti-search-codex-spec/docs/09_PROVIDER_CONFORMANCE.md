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
