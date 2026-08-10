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
