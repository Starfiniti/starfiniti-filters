# Threat model

Last reviewed: 2026-08-09. This model covers the qualification architecture; it must be reviewed when a new provider, transport, migration writer, or public surface is enabled.

## Assets

- Public and restricted product visibility, pricing scope, inventory, and search relevance.
- WordPress administrative authority, database integrity, index generations, outbox, configuration history, and rollback state.
- External-provider provisioning, indexing, and search credentials.
- Potentially sensitive search intent; raw queries are never persisted by the built-in analytics implementation.
- Availability and resource budgets of WordPress, MariaDB, Action Scheduler, and external search services.

## Trust boundaries

1. Anonymous browser to public WordPress REST search and storefront assets.
2. Authenticated browser to WordPress admin/status/operation actions.
3. WordPress application to WooCommerce CRUD/catalog data.
4. WordPress workers to dedicated MariaDB index/outbox/configuration/analytics tables.
5. WordPress to an optional external Typesense HTTPS endpoint.
6. Administrators/automation to WP-CLI and immutable configuration operations.
7. Read-only legacy compatibility reader to upstream options/storage.
8. Build/release tooling to ignored upstream archives and the distributable plugin tree.

## Primary threats and controls

| Threat | Control | Residual / gate |
| --- | --- | --- |
| Hidden, private, password-protected, wrong-locale, or wrong-scope products leak | Canonical visibility fields; mandatory server-side generation/locale/channel/scope/password predicates; fixture tests | Multi-role B2B scope matrix remains pending |
| Public query causes CPU/memory exhaustion | 512-character, token, page, facet, filter-node/depth, candidate, response-size, deadline, and per-IP rate bounds | Concurrent-load/soak and distributed rate limiting remain pending |
| Synonym graph or stop-word policy causes expansion explosion or match-all | Immutable schema validation, per-scope cycle/conflict denial, 256-rule/eight-term/16-expansion bounds, 512 stop words per locale, weighted query-time expansion, all-stop and untokenizable fail-closed responses | Multilingual relevance corpus and sustained adversarial-load evidence remain pending |
| Curation leaks a restricted product, bypasses filters, duplicates pagination/facets, or creates an open redirect | Candidate-stage mandatory visibility/scope/filter predicates, higher-priority deterministic conflict resolution, 50-product bounds, exact totals/facet union/deduplication, internal-path validation, and independent browser same-origin check | Customer-role curation matrix and Typesense projection remain pending |
| SQL injection | Prepared values; provider fields/operators come from allowlists; no public SQL/raw provider query input | Static-analysis policy tooling remains partial |
| XSS through catalog or highlights | Server output escaping; highlight contract returns text-only `{text, highlighted}` segments; client uses `textContent`/text nodes and prohibits HTML sinks | Thumbnail/browser-theme matrix remains pending |
| CSRF or privilege escalation in operations | Dedicated Starfiniti capabilities, nonces, permission callbacks, admin-only default grants, hidden unauthorized controls | Delegation UI and multisite role matrix pending |
| SSRF/DNS rebinding/redirect abuse through Typesense endpoint | HTTPS-only syntax, no URL credentials/query/fragment, DNS A/AAAA validation, public-address policy, metadata/loopback/link-local blocks, safe HTTP API, zero redirects | Real service/network revalidation and controlled private-network mode pending |
| Credential disclosure | External constant/environment references only; privilege-specific design; release secret scan; typed/redacted errors and diagnostics | Rotation workflow and real permission probes pending |
| Partial/duplicate/lost catalog writes | Durable per-generation dedupe, leases, retries, failed state/reset, tokenized self-draining successors, argument-scoped cursor continuations, dual writes during build, exact count gate, paginated stale cleanup; expired-lease, stale-token, retry-exhaustion, actual OS worker-kill/reclaim, 101-record continuation, and content/type/status direct-DB drift tests | Concurrent-load, full ingestion, and distributed large-replay chaos remain pending |
| Corrupt, stale, or incomplete index becomes active | Shadow generations; transactional recorded/actual/source counts; ready and previous live write targets; pending-event refusal; multi-generation reconciliation; locked option/database consistency and exactly-one-active guards; retained rollback generation | Full source checksum validation at cutover and distributed cutover chaos remain pending |
| Analytics captures PII, crosses an authorization boundary, or breaks search | Disabled default; aggregate buckets only; no raw query/user/IP/product/order columns; dedicated view capability/no-store endpoint; exact bounded retention/purge; swallowed analytics failures | Jurisdiction review and any future consented query/click event model remain pending |
| Shadow builder dies or overlaps another attempt | Generation-scoped renewable lease, authoritative stored cursor, idempotent replay, ownership-bound progress/finalization, expired-build startup rescheduling, active-generation isolation | Full 100k ingestion and distributed multi-webhead chaos remain pending |
| Supply-chain contamination or upstream commercial code enters artifact | Pinned hashes/tree manifests; ignored sources; release scan for namespaces/endpoints/secrets/binaries/archives; deterministic ZIP | SBOM/signature/CI provenance pending |
| Legacy migration mutates or imports unsafe processed indexes | Reader is read-only, reports only version/table/definition count, never loads upstream runtime | Semantic mapping requires licensed baseline and separate approved operation |

## Security invariants

- Public callers cannot weaken visibility or inject provider-native filters.
- No external provider becomes active from mock-only evidence.
- Secret values never enter desired configuration, revision history, analytics, logs, status, or search responses.
- Exactly one configuration revision and one local generation are active.
- Analytics failure cannot fail search.
- Destructive cleanup is never an implicit uninstall behavior.
- MCP or other control planes, when added, remain outside the storefront query dependency chain.
