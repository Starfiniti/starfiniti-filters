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
