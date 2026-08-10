# 07 Security, Privacy, and Operations

## 1. Security objective

Search handles public input, product data, remote credentials, administrative operations, analytics, and potentially restricted B2B catalogs. Treat it as an exposed security boundary.

Create and maintain `docs/THREAT_MODEL.md` using assets, actors, trust boundaries, abuse cases, mitigations, residual risk, and test evidence.

Primary assets:

- product and catalog confidentiality;
- customer-specific price and visibility data;
- Typesense and WordPress credentials;
- index integrity;
- ranking and merchandising integrity;
- storefront availability;
- analytics data;
- administrator authorization;
- update and release supply chain.

## 2. Trust boundaries

Document at least:

```text
anonymous browser -> WordPress public endpoint
anonymous browser -> Typesense public search endpoint
authenticated browser -> WordPress administration
WordPress -> database
WordPress -> Typesense
WordPress -> Action Scheduler workers
MCP client -> MCP server
MCP server -> WordPress control API
build system -> release artifact
third-party extensions -> document and visibility hooks
```

Every boundary has authentication, authorization, validation, rate, timeout, logging, privacy, and failure behavior.

## 3. WordPress authorization

Create dedicated capabilities:

```text
manage_starfiniti_search
operate_starfiniti_search
view_starfiniti_search_analytics
view_starfiniti_search_diagnostics
manage_starfiniti_search_secrets
```

Map them to administrators by default and allow deliberate delegation. Avoid using one broad capability for all operations.

Requirements:

- every admin page and action checks capability;
- every REST endpoint has a permission callback;
- state changes require nonce or authenticated REST authorization;
- background jobs verify stored operation authorization and preconditions, not browser nonce;
- multisite and network administration are explicit;
- destructive operations require a stronger capability and operation approval;
- read-only support roles cannot reveal secrets or restricted query data.

## 4. Input validation and output safety

For every entry point:

- define JSON Schema or typed parameters;
- reject unknown fields on security-sensitive operations;
- normalize once and validate after normalization;
- cap string length, array count, nesting, filter clauses, facets, page size, and query cost;
- sanitize stored administration text;
- escape at output according to HTML, attribute, URL, JavaScript, JSON, SQL, or shell context;
- use prepared SQL;
- never concatenate user input into table names, sort expressions, field names, or provider query syntax;
- allow-list fields and operators;
- validate URLs and schemes;
- validate redirects against safe destinations;
- avoid unserializing untrusted input;
- do not execute shortcodes from indexed product descriptions.

Provider highlight output is untrusted. Convert it to safe marked text through a strict parser or render highlighting from text offsets.

## 5. SSRF and endpoint safety

A configurable Typesense endpoint creates SSRF risk.

Connection policy:

- HTTPS required by default;
- plain HTTP allowed only for loopback or explicitly enabled development environments;
- URL credentials prohibited;
- fragments and unexpected paths prohibited;
- redirects disabled by default;
- hostname resolved and checked against blocked address ranges;
- loopback, link-local, multicast, unspecified, carrier-grade NAT, and cloud metadata ranges blocked by default;
- private RFC1918 addresses blocked unless an administrator enables self-hosted private-network mode through a protected setting or constant;
- DNS rebinding mitigated through resolution checks close to connection and no automatic redirects;
- port allow-list configurable;
- proxy behavior documented;
- response size and time bounded;
- endpoint changes invalidate prior health and require revalidation.

Private-network mode must display risk and still block metadata endpoints and unsafe redirects.

## 6. Secret storage

Preferred order:

1. environment or `wp-config.php` constants containing secret references or values;
2. an external secret manager integrated through a provider;
3. encrypted, non-autoloaded WordPress option as fallback.

For encrypted fallback:

- use libsodium when available;
- use authenticated encryption;
- derive or wrap a site-specific key from WordPress salts plus an installation UUID through a documented KDF;
- store nonce and cipher text separately from metadata;
- never use reversible obfuscation as encryption;
- detect salt changes and provide a recovery workflow;
- prevent secret values from entering revision history;
- zero or release plaintext values as soon as practical;
- redact known values from logs and diagnostics.

Administration fields display only a fingerprint and last rotation time. Saving an empty secret does not erase the existing value unless the user explicitly chooses removal.

## 7. Credential rotation

Support overlapping safe rotation:

1. create or receive new credential;
2. validate least privilege;
3. store as candidate;
4. verify indexing or search;
5. activate reference;
6. revoke previous credential;
7. verify;
8. audit.

Public search-key rotation must not require a full plugin deployment. Browser clients retrieve or receive a safe current key/configuration according to the chosen transport.

## 8. Public endpoint abuse controls

Apply:

- query-length limits;
- page and facet limits;
- query-cost budget;
- rate limiting;
- burst and sustained limits;
- response-size limit;
- server deadline;
- request cancellation;
- bounded cache;
- bot and abuse hooks;
- no expensive public explain mode;
- no arbitrary regex;
- no unrestricted wildcard or infix;
- no deep pagination beyond configured limits;
- optional proof or challenge integration through extension APIs.

Rate limits must account for reverse proxies and avoid blindly trusting spoofable forwarding headers. Store the minimum data needed and document privacy impact.

## 9. Cache security

- include authorization, locale, currency, pricing, configuration, provider, and index revision in cache keys;
- do not cache private responses publicly;
- prevent cache poisoning through normalized keys;
- cap key and value length;
- sign client-side configuration snapshots where integrity matters;
- clear or version caches after permission and visibility configuration changes;
- never include secrets in cache keys;
- test logged-in and anonymous cross-user leakage.

## 10. SQL and database safety

- use `$wpdb->prepare()` correctly;
- allow-list dynamic table suffixes;
- quote identifiers through controlled helpers;
- use explicit transactions where supported for state changes;
- account for DDL auto-commit;
- design migrations to resume after interruption;
- do not assume MySQL-only features without compatibility fallback;
- cap query execution through plans and application deadlines where possible;
- index every critical filter and join;
- redact SQL values in public logs;
- prohibit public endpoints from accepting raw SQL or SQL-like fields.

## 11. Supply-chain security

Mandatory CI:

- Composer dependency audit;
- npm dependency audit;
- license allow-list;
- SBOM generation;
- secret scan;
- static application security testing;
- PHP and JavaScript linting;
- lockfile integrity;
- prohibited binary and remote-asset scan;
- reproducible build comparison;
- artifact malware scan where available;
- package-content allow-list.

Use pinned dependencies and automated update proposals. Do not auto-merge a dependency update without tests.

Document every vendored library and why it is needed. Prefer maintained small dependencies over abandoned convenience packages.

## 12. Release integrity

For every release:

- build in CI from a clean tagged commit;
- produce plugin ZIP, checksums, SBOM, third-party notices, and test evidence;
- verify the ZIP by installing it in a fresh environment;
- compare source and built assets to expected manifest;
- scan for development files, secrets, test credentials, internal URLs, source maps, and prohibited branding;
- sign release metadata where infrastructure supports it;
- retain immutable artifacts and provenance.

## 13. Vulnerability management

Create `SECURITY.md` with:

- supported versions;
- private reporting method;
- expected information;
- embargo policy;
- severity process;
- coordinated disclosure;
- patch and release process;
- security advisory and CVE handling;
- dependency vulnerability response;
- credit policy.

Do not ask reporters to post exploitable details in a public issue.

Critical or high vulnerabilities block release. Known medium issues require explicit risk acceptance with owner and target release. No security warning may be hidden by a generic success state.

## 14. Privacy model

The core search index must contain catalog data only. Do not index customers, orders, emails, addresses, support tickets, or personal profiles.

Search queries can contain personal data. Treat raw queries as potentially sensitive.

Privacy defaults:

- analytics optional and transparent;
- no raw IP storage;
- anonymous random session identifier with limited lifetime;
- no cross-site identity;
- no advertising identifier;
- query redaction before storage;
- configurable raw-event retention;
- aggregate retention separate;
- no external analytics transmission by default;
- user history stored locally by default;
- privacy policy suggestion text;
- WordPress personal-data exporter and eraser integration when events can relate to a user;
- consent hooks for common consent platforms without hard dependency.

## 15. Query redaction

Provide configurable redaction before durable analytics storage:

- email addresses;
- telephone numbers;
- postal identifiers where patterns are reliable;
- order numbers if configured;
- secrets or API-key patterns;
- long numeric sequences;
- merchant-defined regular expressions evaluated through a safe bounded engine.

Store a redaction reason count, not the removed value. The raw request may exist briefly in process memory to perform search but must not be logged.

Allow merchants to store only a one-way normalized query hash plus aggregate count for stricter privacy mode.

## 16. Retention and deletion

Configure:

- raw analytics retention;
- aggregated analytics retention;
- audit-log retention;
- operation-log retention;
- dead-letter retention;
- query-cache TTL;
- old index-generation retention;
- diagnostics retention.

Cleanup jobs are resumable and bounded. Legal-hold functionality is not assumed. An administrator can purge analytics independently of search configuration and indexes.

Uninstall options:

- keep settings and data;
- remove runtime data but retain configuration export;
- complete removal.

Complete removal requires explicit confirmation and handles multisite safely.

## 17. Logging

Use structured logs through a product logger with adapters for WooCommerce logging and optional external handlers.

Fields:

```text
timestamp
level
event_code
message
correlation_id
operation_id
provider
index_version
site_id
duration_ms
retryable
safe_context
```

Rules:

- secrets and raw restricted documents prohibited;
- raw queries omitted or redacted by default;
- stack traces protected to authorized diagnostics;
- repeated errors sampled or aggregated;
- log volume and retention bounded;
- user-facing error references correlation ID;
- log level configurable;
- debug mode has prominent warnings and automatic expiry.

## 18. Metrics and health

Collect:

- search requests, errors, and latency percentiles;
- provider latency and timeout rate;
- cache hit rate;
- query-budget rejection rate;
- queue depth and oldest age;
- processing throughput;
- retry and dead-letter count;
- full-build progress;
- expected and actual document count;
- drift rate;
- schema mismatch;
- circuit state;
- active and rollback index versions;
- analytics ingestion failure;
- fast-endpoint health;
- MCP/control API health separately.

Metrics may be shown in administration and exported through a protected endpoint or extension. Do not expose sensitive labels or high-cardinality raw queries.

## 19. Health levels

### Liveness

The plugin can execute and its core dependencies load.

### Readiness

The configured read provider has a valid active index and can answer a smoke query within policy.

### Deep health

Checks schema, aliases, permissions, counts, sample documents, queue state, drift, and latency.

Deep checks are rate-limited and never run on every storefront request.

WordPress Site Health displays actionable tests and safe debug information.

## 20. Alerting

Optional alerts:

- provider unavailable;
- circuit open;
- queue age over threshold;
- dead-letter increase;
- index drift;
- candidate verification failed;
- credential nearing configured rotation age;
- active index rollback unavailable;
- analytics cleanup failed;
- storage threshold;
- repeated query timeout.

Delivery adapters may include email and webhook. Webhook destinations use SSRF-safe validation and signed payloads. Alerts are deduplicated and have recovery notifications.

## 21. Backup and disaster recovery

Document what must be backed up:

- WordPress database, including configuration, outbox, analytics if retained, and local index tables;
- secret references and recovery method;
- Typesense snapshot or managed backup policy;
- current configuration export;
- index schema and relevance configuration;
- release artifact.

The index is rebuildable, but rebuild time is an operational concern. Recovery procedures cover:

- lost local index tables;
- corrupted active generation;
- lost Typesense collection;
- accidental alias switch;
- lost or revoked key;
- WordPress salts changed;
- failed plugin update;
- partial database migration;
- control plane unavailable.

Run recovery drills in CI or a controlled environment for all automatable cases.

## 22. Operational maintenance

Provide:

- database storage estimator;
- old-generation cleanup;
- analytics cleanup;
- queue maintenance;
- orphan operation cleanup;
- credential rotation helper;
- configuration backup;
- index verification schedule;
- slow-query reporting;
- safe reset;
- support bundle;
- read-only maintenance mode;
- provider migration wizard.

Search must continue on the last verified active index during non-destructive maintenance.

## 23. Security, privacy, and operations requirements

- `SEC-001 MUST`: threat model covers all documented trust boundaries.
- `SEC-002 MUST`: administrative actions use least-privilege capabilities.
- `SEC-003 MUST`: public input is schema-validated and cost-bounded.
- `SEC-004 MUST`: Typesense endpoint validation mitigates SSRF and unsafe redirects.
- `SEC-005 MUST`: secrets are encrypted or externally referenced and always redacted.
- `SEC-006 MUST`: direct browser keys cannot perform write or administration actions.
- `SEC-007 MUST`: supply-chain and artifact security gates run in CI.
- `SEC-008 MUST`: public and admin output is contextually escaped.
- `SEC-009 MUST`: cache tests prove no cross-scope leakage.
- `SEC-010 MUST`: vulnerability reporting and patch policy exist.
- `PRI-001 MUST`: catalog indexes contain no customer or order PII.
- `PRI-002 MUST`: raw queries are treated as potentially sensitive.
- `PRI-003 MUST`: analytics retention, purge, exporter, and eraser behavior are implemented.
- `PRI-004 MUST`: no external telemetry occurs without explicit opt-in.
- `OPS-001 MUST`: structured redacted logs and correlation IDs exist.
- `OPS-002 MUST`: liveness, readiness, and deep health are distinct.
- `OPS-003 MUST`: queue, drift, index, provider, and latency metrics are available.
- `OPS-004 MUST`: backup, rollback, and disaster recovery are documented and tested.
- `OPS-005 MUST`: maintenance tasks are bounded and do not replace the active verified index prematurely.
