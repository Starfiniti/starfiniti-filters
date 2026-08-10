# Search control API

The versioned WordPress control API is the deterministic boundary for operator tooling and a future external MCP server. It is not used by storefront search and exposes no arbitrary HTTP, SQL, PHP, shell, option mutation, or raw provider-administration primitive.

## Authentication and authorization

Production callers must use WordPress HTTPS authentication such as a narrowly scoped Application Password behind the site's normal identity, TLS, rate-limit, and revocation controls. Cookie clients require the normal WordPress REST nonce. Dedicated Starfiniti capabilities are checked for every route; administrators receive them by default and shop managers do not.

Read routes are under `/wp-json/starfiniti-search/v1/control/`: `status`, `capabilities`, `configuration`, `operations`, `operations/{uuid}`, and `audit`. Every successful read, plan, approval, and execution response carries `contract_version: 1.0`; responses use `Cache-Control: no-store`, never contain credential values, and bind state to a hashed installation identifier plus WordPress blog ID.

`POST control/relevance/preview` is a configuration-administrator-only, non-mutating relevance laboratory endpoint. It accepts a bounded query, configured locale/channel, and complete draft ranking object; validates synonym scope, dates, conflicts, cycles, stop words, and expansion bounds; and returns safe result explanations, the active configuration revision, a deterministic draft checksum, and `mutation_performed: false`. It does not write the draft or record the preview query in analytics. Activation still requires a `configuration.apply` operation plan.

`GET control/analytics?days=30` requires the dedicated analytics-view capability, accepts an inclusive UTC window from 1 through 365 days, and returns the versioned aggregate report defined in `ANALYTICS.md` with `Cache-Control: no-store`. General health endpoints omit analytics so health-read authority does not imply analytics-read authority.

## Mutations

`POST control/operations/plan` accepts only `index.build`, `index.activate`, `index.rollback`, `analytics.purge`, `reconciliation.start`, or `configuration.apply`, together with a caller-generated idempotency key, reason, and bounded desired state. Configuration apply validates the complete secret-free desired contract before persistence and refuses uncertified provider activation. The returned JSON follows `contracts/operation-plan.schema.json` and includes a SHA-256-bound immutable plan, observed preconditions, required scope, risks, verification, rollback information, approval policy, and a 30-minute expiry.

High-impact plans are approved with `POST control/operations/{uuid}/approve`, passing the exact `plan_hash`. Execution uses `POST control/operations/{uuid}/execute` with that same hash. A changed payload under an existing idempotency key is rejected; exact replays return the persisted operation. Execution fails closed when site, blog, configuration revision, or active generation changed after planning. Audit records include caller, action, operation, plan hash, result, approver, correlation ID, and duration without arguments, queries, tokens, or secrets.

The WordPress admin actions use the same planner/executor. WP-CLI build also uses it; activation and rollback additionally require `--approve --approver=<WordPress user ID>`. Use `--idempotency-key=<stable-key>` for timeout-safe automation and `--reason=<change-ticket>` for operator context.

## MCP boundary

`apps/search-ops-mcp` is the official-SDK TypeScript adapter over this boundary. Its local stdio mode is explicitly process-trusted and exposes five fixed tools plus tenant-scoped status resources; it has no generic URL, SQL, shell, raw provider, or execution primitive. Registered destinations are exact versioned Starfiniti endpoints, credentials are server-side environment references, caller tokens are never forwarded, downstream text is marked and bounded as untrusted data, and every call produces a secret-free MCP audit record. The real-localhost gate proves authenticated status, configuration, and dry-run planning before revoking its temporary Application Password.

A remote Streamable HTTP deployment remains intentionally disabled until its separate OAuth 2.1 protected-resource metadata, authorization-server discovery, PKCE, cryptographic JWT/JWKS validation, issuer/audience policy, revocation, TLS, health/readiness, and deployment observability are available. The included claim validator is post-signature policy only and must never receive an unverified token. See `apps/search-ops-mcp/README.md`.
