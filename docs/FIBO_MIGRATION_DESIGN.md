# FiboSearch Pro + FiboFilters migration design

Design version: **1.0.0**, dated 2026-09-07. Status: implementation plan; **semantic migration and coordinated cutover are not implemented**. Acceptance inventory: [compatibility matrix 1.0.0](FIBO_COMPATIBILITY_MATRIX.md).

## Decision and boundaries

Maintain Starfiniti Search for existing WooCommerce sites, using its independent local index and shared search/filter surfaces. Target the FiboSearch Pro behavior and filtering workflows used by those sites. New Medusa.js builds are a separate product/workstream. Typesense deployment and certification are not dependencies of this narrowed implementation track; do not delete its existing adapter, remove provider-neutral contracts, or decommission infrastructure under this plan.

The execution priority follows the user's revised direction and [local delivery plan](LOCAL_DELIVERY_PLAN.md). It does not turn historical Typesense, remote-MCP or enterprise requirements into passing evidence. Before publishing any revised release profile, reconcile the binding specification and all traceability files together under `AGENTS.md`; the existing Gate 10 remains open. No release-gate bypass is introduced here.

This is a maintained **independent replacement**, not an import of FiboSearch Pro. [UPSTREAM](../UPSTREAM.md) and [ADR 0001](adr/0001-dual-upstream-boundary.md) remain binding. Public behavior documentation and merchant-supplied, secret-free settings observations may establish interoperability requirements. Do not inspect or import Pro archives/runtime, private implementation details, credentials, licensing/update records, processed indexes or executable customization exports. Rebuild exclusively from supported WooCommerce catalog APIs. FiboFilters storage remains read-only; cleanup is separate and opt-in.

The default end-state follows the existing plan: Starfiniti owns both search and discovery. A site that retains FiboFilters while replacing only FiboSearch requires a separately qualified public integration bridge; it is **not** implicitly supported by renaming the plugin or mimicking Pro globals.

## Current foundation versus missing work

The repository has a local inverted index, revisioned configuration, durable indexing, verified native generation activation/rollback, shared storefront surfaces, read-only legacy detection, a customer guidance skill and controlled local MCP. The uncommitted September candidate adds configurable filters. Existing unit/lab evidence is recorded in [HANDOFF](../HANDOFF.md), not rerun or promoted by this design.

Missing migration-specific components: complete source inventory, version-supported semantic mappers, paired search/filter comparison, legacy placement/URL translation, coordinated cutover journal, legacy rollback validation and per-site acceptance report. A filter count is not a migrated configuration. Current shared surfaces are not a full Pro UI or theme-compatibility fork.

## 1. Inventory before mapping

Build a bounded read-only inventory adapter in the WordPress infrastructure layer. Keep parsed semantic intent and pure mapping validation outside WordPress/provider dependencies. Do not add a generic option reader or an arbitrary URL fetch tool. Use explicit supported source profiles; unknown versions produce `source_version_unsupported`, not a best-effort import.

Initial intake can proceed through administrator screenshots and a deliberately allowlisted, redacted settings summary. Do not request raw options, database dumps, private catalogs, raw search logs, cookies or credentials in chat. Pro setting storage layouts must not be guessed from names or inferred from prohibited source. A manual normalized profile is acceptable when vendor export semantics are undocumented; label its provenance and require merchant review.

Inventory records:

- Exact source editions/versions, WordPress/WooCommerce, active theme/builder and relevant integrations; whether FiboFilters is actually used.
- Every enabled search source, typo/synonym/relevance/exclusion setting, visible suggestion group/card/detail behavior and cart action.
- Every filter's stable identity, source key/taxonomy, type, label, order, selection/Boolean/count rules, visibility conditions and URL ownership/encoding.
- Each header/mobile/sidebar/archive/results-page placement, shortcode/block/widget/template reference and customization description; separate header query and in-list refinement if present.
- Language, currency/tax, restricted-catalog policy, archive constraints, scheduled price/stock behavior, query-volume/catalog-size ranges and owner-approved acceptance scenarios without personal data.
- Matrix applicability for all 38 IDs, plus new IDs for uncovered behavior. Unknown applicability blocks readiness; `not_used` needs evidence and confirmation.

New intake contract limits: 256 KiB UTF-8 JSON, depth 8, at most 500 semantic settings/filter entries combined, 100 placement descriptors, 512 characters per non-query scalar, and no executable markup/objects. Reject duplicate JSON keys, unknown contract keys, invalid UTF-8 and oversized input; do not truncate. These are proposed limits, not current runtime guarantees. More than the current fourteen target controls is an explicit unsupported mapping until capacity is safely extended and tested. Keep sensitive rejected values out of errors and audit records.

## 2. Deterministic mapping report

Introduce a versioned `MigrationProfile` and `MigrationReport` contract before production importer code. Names here describe proposed types, not existing APIs. A report binds:

| Field group | Required content |
| --- | --- |
| Identity | Contract/design/matrix version, site/blog binding, source profile/version, target plugin/contract version |
| Preconditions | Sanitized semantic inventory hash, configuration revision/hash, generation identity, owned placement fingerprints, relevant integration versions |
| Mapping | Stable source ID, matrix ID, admitted source intent, target configuration path/value, disposition, explanation and fixture references |
| Disposition | `exact`, `transformed`, `unsupported`, `unknown`; transformed items require explicit acceptance and passing semantic tests |
| Readiness | Blocking items, safe warnings, bounded capacity/storage estimate, evidence references and expiry |
| Rollback | Prior owned configuration/routing references, verified generation, legacy readiness evidence and retention policy |

Hash canonical admitted semantic data only; never hash or retain secrets as a substitute for redaction. Keep source-site values and reports outside Git unless reduced to synthetic fixtures. Reports shown to an assistant are guidance-only, bounded and secret-free. Audit stable IDs/hashes/result codes, not raw settings or queries.

Rules:

1. Preserve unrelated target configuration. Build a complete draft from the expected current revision and explicit typed changes, not a partial overwrite or unrestricted JSON patch.
2. Resolve taxonomies/custom fields by stable source identifiers and approved typed resolvers; never by labels alone. Require actual source availability and safe projection eligibility.
3. Map every admitted source item, including disabled settings where needed for faithful restoration. Reject collisions in IDs, URL parameters, field ownership and sort values.
4. Never equate similar names with equivalent semantics: Pro fuzzy presets, sale/featured, variable-price ranges and taxonomy exclusion groups need explicit transformations/tests.
5. `unsupported` or `unknown` on any used behavior blocks cutover. A warning acknowledgement cannot make it supported. A deliberate feature removal is a separately approved scope change, not a successful migration.
6. Preview must not activate configuration, change source settings, register public aliases or alter indexes. Identical inputs produce identical semantic output hashes; volatile observation timestamps are separate.

## 3. Search and filter correctness contract

Use one provider-neutral request builder and versioned canonical contracts. Proposed additions for refinement/filter ownership must be schema-validated and capability-checked across adapters; an adapter that cannot implement them returns unsupported. Do not smuggle raw query syntax, provider IDs or unvalidated options through the browser.

The vendor documents that its in-list Search filter can use Pro and refine results independently of the header form; the header phrase is not automatically populated into that filter. Preserve that distinction when enabled on the source site. This is behavioral input, not permission to copy internals. [Official search-filter documentation](https://fibofilters.com/documentation/filter-types/search/)

Define the result universe before sorting/paging:

```text
authorized, visible catalog in locale/currency/archive context
  intersect base search result membership (header query, if present)
  intersect refinement result membership (in-list query, if present)
  intersect user filter expression
  intersect mandatory merchant exclusions / curation constraints
  -> deterministic rank/sort -> one bounded result page
```

Each query keeps its own normalization, synonym/typo and curation evaluation. Do not concatenate query strings: an OR-like matcher could widen results instead of refining. Preserve base-query ranking by default; any different refinement ranking needs explicit source evidence and approved transformation. Refinement must never introduce a product excluded by the base query, including through a pin. Rewrites/pins cannot bypass visibility, archive restrictions or mandatory exclusions.

Facet counts use this full eligible universe, not the visible page, autocomplete top-k candidates or hydrated cards. For disjunctive counts, omit only the relevant **user-owned selection group**, not every predicate mentioning that field. Preserve base/refinement queries, other facet groups and same-field security/archive/curation predicates. If ownership cannot be established for a Boolean tree, reject that count mode rather than removing restrictions. Same-facet OR and cross-facet AND are supported intents, not assumed universal source settings.

No request materializes all matching IDs or scans the catalog in PHP. Indexed bounded queries and deterministic pagination must remain compatible with exact counts; a budget limit is a typed failure, not a silently capped total. Add cases with more than 250 matches, high-cardinality values, selected zero-count values and alternate controls sharing a field. Count products or variations consistently with the approved collapse strategy.

Legacy URL translation belongs in an explicit adapter with an allowlist of owned parameters. Preserve base/refinement query separation, unknown unrelated parameters, pagination resets, reload/Back and localized decimal semantics. Reserved/colliding parameters block automatic mapping. Redirects stay same-origin and cannot globally rewrite unrelated pages. Existing no-JS submission remains usable; if full legacy no-JS filter parity is needed, qualify it separately rather than claiming the fallback provides it.

## 4. Shadow rehearsal and evidence

Keep both source plugins serving their existing public routes while Starfiniti is built and exercised in an operator-only preview/staging environment. No simultaneous interception of the same shortcode, form, AJAX route or DOM container. Native Starfiniti index activation in a lab is not approval to replace a live route.

Build from WooCommerce with a high-water mark, bounded outbox catch-up and verified candidate configuration. Test create/update/delete, variation/stock/sale changes and visibility revocation during build. Native candidate/retained generations receive their existing synchronization; do not assume that also keeps a disabled legacy index fresh.

Use a versioned synthetic corpus plus merchant-approved secret-free known-item/discovery judgments. Each applicable matrix row gets a fixture/scenario and expected outcome. Compare exact eligible identifiers, expected membership, forbidden hits, facet counts, URLs, pagination, placement and failure states. Full rank equality is required only for specified invariants; other relevance differences require judged thresholds, documented rationale and approval. An unavailable licensed baseline remains a parity blocker; synthetic tests alone cannot remove it.

The rehearsal suite must include:

- Exact SKU/variation and all enabled search fields; typo/synonym negatives; exclusions against pins and exact identifiers.
- Base search plus refinement, clear only refinement, shop/archive context, same-facet alternatives, intersections, range/zero/invalid input and more than 250 matching products.
- Hidden/password/deleted/restricted products absent from hits, suggestions, counts and labels; current price/stock correctness.
- Exact theme placements at desktop/360 px, keyboard/IME/mobile/errors, reload/Back/Forward, ordinary no-JS form, cart non-interference and every used rich action.
- Cold/warm and concurrent HTTP/provider/browser measurements while building and serving a representative catalog. Record hardware, runtime, corpus, concurrency and run duration. Existing small-lab HTTP p95 416.4 ms is a failing baseline, not a target.
- Interruptions before/after revision creation, candidate build, approval, routing change and verification; stale approval, replay, partial failure, expired lease and rollback conflicts.

## 5. Coordinated cutover and rollback

Use the existing deterministic [plan/approve/execute boundary](CONTROL_API.md). Add a narrowly typed migration coordinator only after its contracts/tests exist; today's MCP tools do not expose it. Never add `set_option`, raw SQL, PHP execution or arbitrary plugin-management tools to solve migration.

```text
inventoried -> mapped -> shadow_built -> verified -> approval_required
  -> switching -> observing -> accepted
failure before switch: keep legacy serving
failure after switch: rollback_required -> rolling_back -> legacy_verified
```

Every durable step carries an idempotency key, expected state, exact owned resources and read-after-write verification. Approval binds report/plan hash, exact target artifact, source/configuration/placement fingerprints, candidate verification and expiry. Any drift requires replan; hold a bounded migration lease and reject a second concurrent cutover.

Prefer one Starfiniti-owned routing revision for paired search/discovery selection. WordPress plugin activation, theme editor writes and CDN invalidation are **not** one atomic database transaction. Where a site needs them, enumerate separate operator-approved placement steps with before/after fingerprints and compensation. Keep legacy ownership until all replacement routes can be verified. If that cannot be done without mixed ownership, the site is blocked pending a qualified bridge or an explicitly agreed maintenance window. Never promise universal zero downtime.

Preserve the original plugins, settings and rollback-ready source environment through an explicit observation window (proposed default: seven days; confirm capacity and merchant policy before execution). Externally populated caches and old HTML must be accounted for; prove one owner per form and valid old URLs. Disable legacy public ownership only through qualified scoped routing or a separately approved administrator action, not by editing its options. Legacy uninstall/cleanup is never part of initial cutover.

Rollback reverses only migration-owned configuration/routing and the qualified index pointer. It must not restore a full database snapshot or overwrite orders, catalog updates, stock changes or unrelated administrator edits. Compare-and-swap each owned artifact; conflicts require operator resolution. Before routing back, verify the legacy plugin can run and its index reflects intervening updates. If the old writer stopped, perform its supported catch-up/rebuild and test it first. Retaining old files alone is not rollback readiness.

After switch or rollback, verify public query/filters/placements, hidden-item denial, fresh stock/price, count consistency and absence of duplicate handlers. Record the durable state even when verification fails; never report success solely because a pointer write succeeded. Candidate cleanup and original-plugin retirement are separate scoped approved operations after acceptance and retention.

## 6. Implementation sequence

| Slice | Work | Exit condition |
| --- | --- | --- |
| A: contract and intake | Add versioned semantic profile/report schemas, pure validators, synthetic fixtures, redacted read-only inventory and mapping preview | All bounds/collision/unknown/source-version tests pass; no source or active-state mutation; every matrix row accounted for |
| B: search/filter semantics | Finish ongoing facet work, complete full-result counts, independent refinement, needed search fields/relevance and typed filter mappings | Actual enabled behaviors pass integration and browser fixtures without visibility or performance regressions |
| C: placements and Pro experience | Implement used groups/details/cart actions, source aliases/URLs and exact theme adapters from the mapping report | No missing used feature, duplicate owner or broken inbound route; no-JS fallback and real theme tests pass |
| D: migration coordinator | Add immutable plan, shadow comparison, drift checks, paired routing journal, observation and compensating rollback | Repeated and interrupted rehearsals pass, commerce writes survive, second operator can execute the runbook |
| E: qualify and document | Representative speed/security review, runtime matrix, final customer-skill capability/installation guidance and exact artifact gates | Exact candidate evidence passes; no pending source-site parity issue; publish/cutover still requires explicit approval |

Safe work without the live store: Slice A contracts and synthetic validators, and bounded semantic gaps in B. Source-specific mappings and cutover approval require the actual versioned settings/placement inventory; do not invent it. Do not touch the ongoing filter implementation merely to update this design.

Before claiming code completion run the complete commands required by `AGENTS.md`, including `pnpm test:all`, `pnpm test:mcp:live` with credential cleanup, dependency audit and `pnpm qualify:artifact`. Add migration tests to those gates as implemented; documentation validation alone is not runtime qualification. Update all requirement/evidence/hash files together when statuses actually change. The packaged skill remains guidance-only and must label unimplemented migration features as planned.
