# Starfiniti Search continuation handoff

Snapshot date: 2026-08-10 (Europe/Ljubljana)

## Read this first

The locally actionable implementation and qualification work is complete. The project is not yet formally enterprise-ready because six mandatory requirements still depend on licensed data, real infrastructure, a production-like runtime, remote authorization, or final release approval.

The next session is expected to run from the main computer with access to the main server, VPS provider, and DNS management. Begin with the read-only discovery steps below. Do not expose the local stdio MCP server or Typesense admin API publicly.

## Git and transfer state

- Repository: `starfiniti-filters`
- Remote: `https://github.com/Starfiniti/starfiniti-filters.git`
- Published branch: `main`
- Initial publication date: **2026-08-10**
- Initial commit: this handoff is included in it; run `git rev-parse HEAD` to obtain the immutable commit ID
- Expected working tree after publication: clean, with `main` tracking `origin/main`

GitHub contains the qualified implementation and is the transfer source for the main computer. Clone it there, or fetch and fast-forward an existing clone. Do not initialize a competing repository from a partially synchronized directory.

Before staging future work, verify that these remain excluded: `audit/packages/`, `audit/source/`, every `dist/`, every `node_modules/`, `.env*`, local runtime directories, database backups, credentials, and private keys.

Suggested continuation sequence on the main computer:

```powershell
git clone https://github.com/Starfiniti/starfiniti-filters.git
Set-Location starfiniti-filters
git status --short --ignored
pnpm install --frozen-lockfile
pnpm test
```

Do not stage ignored commercial upstream archives or generated release artifacts merely to make them available on another machine. Regenerate `dist/` with `pnpm qualify:artifact`, or transfer the exact artifact through an approved release channel.

## Authoritative project state

Requirements ledger:

- 115 mandatory requirements total
- 105 complete
- 2 in progress
- 4 externally blocked
- 4 disabled/not applicable

The two in-progress requirements are:

- `MCP-003`: production remote MCP authorization and transport
- `PER-002`: strict end-to-end latency on a production-like reference runtime

The four externally blocked requirements are:

- `PER-001`: licensed/approved relevance judgments and business thresholds
- `REL-001`: all release gates passing without waiver
- `TYP-001`: exact supported Typesense server-version detection and certification
- `TYP-014`: real supported Typesense container/cloud integration tests

Read the detailed state in:

- `spec/starfiniti-search-codex-spec/project-status.json`
- `docs/IMPLEMENTATION_STATUS.md`
- `docs/QUALIFICATION_EVIDENCE.md`
- `docs/TYPESENSE_ADAPTER.md`
- `docs/CONTROL_API.md`
- `apps/search-ops-mcp/README.md`

## Exact qualified plugin artifact

Version: `0.3.0-alpha.1`

- Files: 74
- Uncompressed plugin bytes: 422590
- Source tree SHA-256: `2080955E96C7BAE8A42316CB4DE9A823342D5FC4AA86B50EC6748F633FEB80F4`
- ZIP SHA-256: `2F2E56DDE074C526BDB9AA672825414D35F5AE749C3950A88CF35B6BD144C8C5`
- CycloneDX SBOM SHA-256: `B6E487BB453B672F45830D3A74205DC3B02169677B01B3866047DA903546BD90`
- Release manifest SHA-256: `57B2B9B981D79B1791CF36CB11B3E75E6E0B5FA92A673BE1C095D26B324C827D`

The generated files are under ignored `dist/`:

- `dist/starfiniti-search.zip`
- `dist/starfiniti-search.cdx.json`
- `dist/release-manifest.json`

Verify transferred files before use:

```powershell
Get-FileHash dist/starfiniti-search.zip,dist/starfiniti-search.cdx.json,dist/release-manifest.json -Algorithm SHA256
```

## Last passing verification

- `pnpm test:all`: passed in 106.2 seconds on the final source tree
- PHP unit tests: 40 passed, 0 failed
- MCP official-SDK protocol tests: 6 passed, 0 failed
- `pnpm qualify:artifact`: passed in 102 seconds
- Official Plugin Check 2.0.0: no errors or warnings
- `pnpm audit --prod --audit-level high`: no known vulnerabilities
- Real MariaDB integration, storefront/schema contracts, generation lifecycle, killed-worker recovery, killed-builder recovery, and isolated database disaster recovery: passed
- Schema 9 to 10 destructive rehearsal: passed repeatedly with a physical-copy cleanup that avoids MariaDB instant-DDL hidden-column accumulation
- MCP live localhost: discovery, status, secret-free configuration, and dry-run reindex plan passed
- Temporary WordPress Application Passwords remaining after MCP test: 0

Last local deep status after exact artifact installation:

- WordPress: 7.0.2
- WooCommerce: 10.7.0
- PHP: 8.3.28
- MariaDB: 11.4.10
- Plugin: 0.3.0-alpha.1
- Readiness: `ready`
- Active generation: `900000057`
- Active documents: 7
- Pending/failed/dead outbox: 0/0/0
- Quarantine: 0

The Windows localhost runtime lives under `%LOCALAPPDATA%\StarfinitiSearch\starfiniti-filters`. It is machine-specific and intentionally not part of the repository. Do not copy `runtime.env`, database files, logs, application passwords, or generated private state to GitHub.

## What was added in the final session

- Typed allow-listed custom product fields with strict scalar coercion
- Adaptive batch sizing for seed, outbox, generation build, and reconciliation workers
- Versioned Typesense collection creation, per-document import results, and verified alias activation/rollback
- Typesense v30 locale/channel-scoped synonym-set and curation-set reconciliation with deterministic resource IDs, drift repair, read-after-write verification, preservation of unrelated external resources, dynamic query-time selection, explicit application-only actions, and fail-closed incompatible-major behavior
- Canonical document IDs bound to Typesense document IDs for deterministic curation
- Official MCP SDK 1.30.0 TypeScript application under `apps/search-ops-mcp`
- Exact site registry, fixed WordPress control client, server-side credential references, tenant/site/scope checks, bounded untrusted-data envelopes, safe audit records, local stdio mode, mock protocol tests, and real localhost MCP test
- Uniform `contract_version: 1.0` on successful WordPress control responses
- Repeatable MariaDB schema fallback and destructive migration fixture hardening

## Tomorrow: safe server discovery

Do not install or modify services until the existing host is inventoried. Capture outputs in a new secret-free operations note.

On the server, begin with read-only commands appropriate to the OS:

```bash
uname -a
cat /etc/os-release
hostnamectl
timedatectl
df -h
free -h
ss -lntup
docker version
docker compose version
systemctl --failed
```

Also identify, without printing credentials:

- VPS provider, region, reserved/static public IPv4 and IPv6
- current firewall/security-group rules
- existing SSH access policy and recovery console
- reverse proxy and certificate manager
- container runtime and existing networks/volumes
- current WordPress production/staging topology
- DNS provider and whether DNSSEC is enabled
- backup destination, retention, encryption, and restore procedure
- monitoring/logging destination
- OAuth/OIDC authorization-server choice

Record existing services before touching ports, DNS, proxy configuration, or firewall rules. Back up configuration and verify a recovery path first.

## Proposed deployment boundary

The target should be separated into explicit trust zones:

```text
Internet
  -> TCP 443 reverse proxy / TLS
      -> future operator web UI at chat.starfiniti.com
      -> future remote MCP endpoint at mcp-search.starfiniti.com

Private application network
  -> Search Operations MCP service
  -> Typesense v30 service
  -> metrics/log collector

Existing WordPress site
  <- authenticated outbound MCP control calls over HTTPS
```

Important boundaries:

- No operator chat web UI currently exists in this repository. `chat.starfiniti.com` must not be pointed at the stdio MCP process.
- Remote MCP Streamable HTTP is not implemented yet. Do not expose `apps/search-ops-mcp/src/stdio.ts` over a socket wrapper.
- Typesense TCP 8108 and all administration endpoints stay private. Search/index/admin keys are separate; the admin key never reaches browsers, WordPress configuration, logs, or model context.
- WordPress database and MariaDB are not exposed publicly.
- SSH should be key-only and restricted by source IP or a trusted access layer where practical.

## Draft DNS plan

Create records only after a stable reserved IP is assigned and the corresponding service passes local health checks behind TLS.

| Hostname | Purpose | Initial record | Exposure |
| --- | --- | --- | --- |
| `chat.starfiniti.com` | Future operator-facing chat/control UI | `A` and optional `AAAA` to the reverse proxy | Public HTTPS only after a UI exists |
| `mcp-search.starfiniti.com` | Remote authenticated MCP endpoint | `A` and optional `AAAA` to the reverse proxy | Public HTTPS only after OAuth/JWT conformance passes |
| `status-search.starfiniti.com` | Optional external status page | Separate status provider or reverse proxy | Public, no private diagnostics |
| Typesense hostname | Internal service discovery | Private DNS or container-network name | Never public |

Use a temporary TTL such as 300 seconds during cutover, then increase it after validation. Decide whether the DNS provider proxy/CDN is compatible with MCP Streamable HTTP before enabling proxying. Keep origin access restricted once a proxy is authoritative.

Do not create a wildcard record unless there is a documented need and matching certificate/security policy.

## VPS build sequence

1. Provision or inventory the VPS and reserve its IP.
2. Patch the OS, configure time synchronization, create a non-root operator, enforce key-only SSH, and configure firewall/security groups.
3. Configure encrypted backups and prove restore before deploying stateful services.
4. Install the pinned container/runtime stack from trusted repositories.
5. Create private application networks and persistent volumes with explicit ownership.
6. Deploy a reverse proxy with TLS, security headers, request/body/time limits, access-log redaction, and separate health/readiness routing.
7. Deploy Typesense using an exact official image digest for the selected v30 release. Record version and digest. Keep it private.
8. Create separate secret-store entries and least-privilege Typesense keys for search, indexing, provisioning, `synonym_sets:*`, and `curation_sets:*` actions.
9. Implement and test remote MCP transport/auth before binding a public MCP hostname.
10. Run Typesense real-service conformance, import/partial-failure, alias activation/rollback, relevance reconciliation, outage, deadline, retry, circuit, snapshot, and restore tests.
11. Run `benchmark:http:strict` against the production-like WordPress/PHP-FPM or equivalent runtime and record p50/p95/p99 evidence.
12. Create DNS records with low TTL, validate TLS/health/auth, then raise TTL.
13. Run the exact artifact qualifier and smoke tests against staging before any production cutover.

## Remote MCP work still required

Implement this as a separate reviewed change; local claim validation is not sufficient:

- official MCP Streamable HTTP server transport
- `/healthz` and `/readyz` outside the MCP transport
- OAuth 2.1 protected-resource metadata
- authorization-server discovery and PKCE client compatibility
- cryptographic JWT signature verification against pinned/discovered JWKS
- exact issuer, audience, subject, tenant, client, scope, expiry, not-before, issued-at, token lifetime, and token-ID checks
- revocation strategy and key-rotation behavior
- TLS and proxy-aware origin policy
- per-tenant rate, request-size, concurrency, and audit controls
- OpenTelemetry-compatible metrics/traces with strict secret and catalog-content redaction
- restart, multi-instance, replay, malformed-token, wrong-audience, revoked-token, and tenant-crossing tests
- no forwarding of MCP bearer tokens to WordPress

The current `validateVerifiedClaims` function is a post-signature claim-policy layer only. Never call it with an unverified token.

## Typesense certification still required

Reverify the latest supported v30 release before provisioning, then pin the chosen image digest. The adapter intentionally rejects unmapped server majors and uses the v30 top-level `/synonym_sets` and `/curation_sets` APIs, not legacy collection-level synonyms/overrides.

Certification must include:

- version and health detection
- exact collection schema creation
- canonical import and every NDJSON result
- visibility filters and scoped search keys
- locale/channel relevance-set selection
- drift replacement and read-after-write verification
- partial failures and retries
- alias activation, idempotent replay, retained rollback
- key permission denial tests
- network loss, timeout, 429/5xx, restart, and circuit recovery
- snapshots, restore, and retained-resource cleanup
- latency and concurrent-load evidence

Do not remove the activation guard until these pass against the real service.

## Decisions needed from the user on the main computer

- Which VPS/provider/region and whether a server already exists
- Whether `chat.starfiniti.com` will host a new UI, an existing application, or only redirect elsewhere
- Final remote MCP hostname
- DNS provider and access method
- OAuth/OIDC issuer: existing identity platform or a new deployment
- WordPress staging URL and how a least-privilege Application Password will be provisioned
- Backup target and retention policy
- Monitoring/logging platform
- Whether IPv6 will be enabled at launch
- Approval to create the first Git commit and push to GitHub

## Suggested resume prompt

Use this on the main computer:

> Continue Starfiniti Search from `HANDOFF.md` and obey `AGENTS.md`. First inspect Git, the synchronized files, and the server read-only. Confirm what is already running, then propose the exact VPS, DNS, TLS, OAuth, private Typesense, backup, and verification changes. Do not expose stdio MCP or Typesense publicly, do not print secrets, and do not claim enterprise readiness until the remaining requirement gates pass.
