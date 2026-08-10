# Typesense adapter qualification boundary

The source contains a provider-neutral Typesense search adapter, but the production plugin intentionally refuses to activate Typesense until real-service certification is available. This prevents mock-only evidence from becoming an operational claim.

## Implemented and tested without a service

- HTTPS-only endpoint syntax with URL credentials, query strings, fragments, localhost, metadata, private, reserved, link-local, and unsafe redirect targets rejected.
- DNS A/AAAA result validation immediately before the WordPress safe HTTP request.
- External credential references limited to approved `constant:STARFINITI_*` and `env:STARFINITI_*` names; no secret values enter configuration history or diagnostics.
- Deterministic collection and alias names isolate installation, environment, locale, schema, and build.
- Idempotent versioned collection creation is followed by an authoritative collection-name verification.
- Bounded NDJSON import parses exactly one result per canonical document and emits only stable document IDs, success state, and bounded redacted error codes.
- Alias activation and retained-collection rollback verify the final alias target and treat an identical replay as a no-op.
- Canonical document identities are bound to Typesense document IDs, so curation resources cannot depend on provider-generated identifiers.
- Typesense v30 synonym sets and curation sets are projected per exact locale/channel scope, hashed, drift-checked, idempotently upserted, read-after-write verified, and selected dynamically at query time. Starfiniti-owned sets are deliberately not linked collection-wide, preventing storefront rules from leaking into another channel; stale Starfiniti collection links are removed while unrelated external links are preserved.
- Reconciliation fail-closes on unmapped server majors and never calls the pre-v30 collection synonym/override endpoints. Equivalent and directional synonyms plus exact-query pins, hides, rewrites, and effective windows map natively. Boost, bury, canonical filters, application redirects, and missing document mappings are returned explicitly as `application_only` rather than silently approximated.
- Mandatory non-removable visibility, password, locale, channel, and public-scope filters.
- Bounded page/query/deadline parameters, a single retry for transient statuses, typed redacted failures, and a 30-second circuit after three consecutive failures.
- Canonical response mapping and explicit structured capability state `adapter_only / real service required`.
- Configuration revisions reject external read-provider activation unless an explicit certification path authorizes it.

## Required before activation

1. Test exact supported Typesense server versions through an official Docker, Linux, or cloud deployment.
2. Validate health/version endpoints, schema behavior, scoped search/index/provisioning and v30 `synonym_sets:*`/`curation_sets:*` credentials, the locally tested JSONL per-document, alias, scoped relevance-resource lifecycle, outage behavior, deadlines, retries, and circuit recovery against the real service.
3. Run the common provider conformance suite for visibility, pagination, filtering, facets, relevance, partial writes, activation, and rollback.
4. Record exact environment/version evidence and approve a configuration revision that removes the certification guard.

Native Windows execution is not used as substitute evidence because it is not a supported Typesense server deployment path.
