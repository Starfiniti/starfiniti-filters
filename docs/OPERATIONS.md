# Operations runbook

## Readiness and health

Authorized administrators can inspect `GET /wp-json/starfiniti-search/v1/status`, WooCommerce > Starfiniti Search, or `wp starfiniti-search status --deep`. Liveness means WordPress and the plugin can execute. Readiness also evaluates the active generation, pending/failed outbox work, quarantine count, provider capabilities, and index schema.

Deep health also reports the active immutable configuration revision/checksum, redacted revision history, provider topology, and the fail-closed 12-step setup assessment. Analytics is intentionally separated behind its dedicated capability and endpoint. Credential values are never part of configuration or diagnostics. Setup `ready` is deliberately distinct from release certification.

## Rebuild and cutover

1. Start a shadow build with the admin button or `wp starfiniti-search build`.
2. The builder indexes bounded batches into a new generation. Product writes are written to the active, building or ready cutover target, and retained previous generation. Each builder attempt holds a generation-scoped renewable lease. The database cursor is authoritative; stale scheduled-action arguments are ignored. Startup detects an expired or unleased `building` generation and reschedules it from the stored cursor, while a `ready` generation remains a live write target until cutover.
3. Activation is refused unless the generation is ready, has no pending synchronization events, and its transactional recorded/actual document counts exactly match the current WooCommerce source count. The transaction locks generation state, requires the option and database active pointer to agree, and preserves exactly one active generation.
4. Activate with the admin screen or `wp starfiniti-search activate --generation=<id> --approve --approver=<user-id> --idempotency-key=<change-id> --reason=<ticket>`.
5. Validate search and health. The former generation remains intact as the rollback target.

An interrupted builder can safely replay its last incomplete batch because document upserts are idempotent and progress is persisted only while the worker still owns the generation lease. Deep generation diagnostics expose attempts, lease expiry, and the last build update but never expose the ownership token. A `failed` generation is never activated automatically.

## Rollback

Run `wp starfiniti-search rollback --approve --approver=<user-id> --idempotency-key=<change-id> --reason=<ticket>` or use the admin button. The immutable plan captures the active/configuration preconditions and the human approver before the transactional pointer switch. The retained previous generation receives normal product events and periodic bypass-write reconciliation; rollback still refuses pending work, count drift, or option/database disagreement. Validate an exact-SKU search and health immediately after rollback.

## Controlled operations

All admin and WP-CLI index mutations use immutable operation plans. High-impact activation, rollback, and analytics purge require an authenticated human approval bound to the plan hash. Exact retries are idempotent; changed payloads, expired plans, hash mismatches, concurrent claims, and changed site state fail closed. Protected REST endpoints and the complete boundary are documented in `CONTROL_API.md`.

Structured operational errors carry a correlation ID and emit bounded JSON through the WooCommerce logger. Raw queries, product documents, credentials, cookies, authorization values, email addresses, and IP addresses are redacted or excluded. Operation audit is separate from logs and is readable only with the dedicated audit capability.

## Failure handling

Outbox work uses leases, idempotent per-generation deduplication, exponential retry, and a terminal failed state after eight attempts. A failed event makes readiness unavailable and remains visible for diagnosis. Error codes are derived and do not contain product payloads or secrets. Never clear failed work or old generations until the root cause is understood and a database backup exists.

Catalog seed and reconciliation cursors use argument-scoped, non-unique Action Scheduler continuations because Action Scheduler's database uniqueness is hook/group-wide. The outbox schedules one tokenized successor whenever work remains; database leases make overlapping workers safe. Daily semantic reconciliation repairs direct database writes, immediately removes ineligible product types/statuses through ordinary upsert events, and paginates stale-record deletion in batches of 100. A cursor/status option records whether stale pagination completed.

Autocomplete uses a bounded 250-posting ranking set and includes exact SKU through a separate indexed lookup. When more matches exist, `warnings` contains `total_is_lower_bound:250` and `candidate_limit_applied:250`; callers must not present the bounded `total` as an exact catalog count. `full_results` mode uses a larger ranking budget and exact totals and should be used for result pages that require exact pagination, not latency-sensitive suggestions.

## Backup and recovery

Back up all `sfs_` tables and the relevant WordPress options with the normal database backup. Indexes are rebuildable from WooCommerce CRUD data; the outbox is not disposable during recovery because it records changes not yet applied. Restore the WordPress database atomically, start workers, verify readiness, and build a new shadow generation before removing any restored retired generation.

`pnpm test:disaster-recovery` performs the local recovery rehearsal without touching the live database: it takes a single-transaction full logical backup, creates a PID-scoped database whose name must match `starfiniti_search_dr_<digits>`, restores into it, compares every `wp_sfs_*` table row count plus all Starfiniti option counts, boots the application search stack against the restored connection, and verifies schema/configuration/generation identity, exact SKU, and restricted-product isolation. A `finally` path drops only the validated disposable database and removes its backup directory after an absolute-path containment check. Operators must still use the platform's encrypted backup storage, restore credentials/grants, retention, and point-in-time procedures in production.

The certification host uses the credential-free preparation under `infra/backup`.
It streams Proxmox `vzdump` output directly to customer-side encrypted Borg 1.4
archives on a restricted Hetzner Storage Box sub-account. Backup creation,
unrestricted retention maintenance, and recovery-key custody are separate roles.
Repository checks and full dry-run extraction are automated, but the platform
gate also requires a timed restore to a new stopped guest ID, isolation from
production networking, application verification, and recorded owner approval.
Never restore over an original guest or enable stateful certification workloads
before the off-host restore proof passes.

## Certification infrastructure

`infra/runtime-lock.json` is the authoritative container/package lock. The
Typesense Compose asset binds only to `127.0.0.1:8108`, drops Linux capabilities,
runs read-only apart from explicit data/log/snapshot paths, and caps Typesense at
2 CPU and 3 GiB RAM. The 30.2 service probe under `infra/certification` creates
separate temporary least-privilege credentials and deletes every test resource in
a `finally` path. Its result is a prerequisite, not provider activation approval.

The observability assets under `infra/observability` are constrained to the 2 GiB
ops container. Prometheus, Loki, Grafana, Alloy, and node-exporter have individual
hard memory limits totaling 1,504 MiB and seven-day retention. Grafana and
Prometheus are loopback-only. Loki may accept logs only from the exact private
certification VM rule documented in that directory. No public dashboard,
anonymous access, or paging destination is implied.

## Analytics privacy

Analytics is disabled by default. When an immutable configuration revision opts in with a 1-365 day retention, only daily aggregate dimensions are stored: provider, configuration revision, generation, query-length bucket, result-count bucket, counts, latency totals/maxima, and latency observation counts. Raw queries, IP addresses, emails, users, sessions, products, and customer/order data are never persisted. The daily purge enforces an exact inclusive UTC-date retention window; `purgeAll()` can remove analytics without touching configuration or indexes. WordPress privacy exporters and erasers explicitly report that there is no user-linked analytics data. Metric formulas, undefined denominators, sampling scope, protected interfaces, and deliberately unavailable reports are defined in `ANALYTICS.md`.

## Upstream migration boundary

Diagnostics can inventory a legacy FiboFilters version, storage table, and filter-definition count through a read-only reader. It never imports a processed upstream index or loads proprietary runtime code. Full semantic migration remains gated on reviewed mapping rules and a legitimately licensed upstream baseline.
