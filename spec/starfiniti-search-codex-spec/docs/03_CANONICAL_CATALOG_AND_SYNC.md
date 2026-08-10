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
