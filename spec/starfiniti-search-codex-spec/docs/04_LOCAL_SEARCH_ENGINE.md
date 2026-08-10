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
