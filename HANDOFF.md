# Starfiniti Search continuation handoff

## Storage Box backup implementation update - 2026-08-11

- The reviewed infrastructure work is committed on branch `codex/resume-infrastructure`: certification/observability/CI hardening is commit `908aad9`, and the backup integration fixes are commit `38fc4ba`. This handoff update is the following documentation commit. Generated artifacts and all credentials remain ignored.
- A 5 TB Hetzner Storage Box is live. SSH/Borg access is enabled, SMB/WebDAV/external reachability remain disabled, and a dedicated sub-account is restricted to the Starfiniti `s2` backup directory. The independently obtained ED25519 host fingerprint matched Hetzner's published fingerprint before credentials or data were sent.
- Borg 1.4 initialized an encrypted `repokey-blake2` repository. The `s2` daily-writer key is forced through `borg-1.4 serve --append-only` and restricted to this one repository. A separate unrestricted maintenance key is not present on `s2`; its temporary recovery copy is protected in the user's restricted `.ssh` directory pending Vault ingestion.
- The real smoke run exposed and fixed two integration defects: the script's `umask 077` prevented unprivileged LXC archive creation, and the systemd sandbox blocked Proxmox/LVM snapshot metadata writes. `vzdump` now receives `umask 0022` only for its subprocess, while secrets remain `0600`; the service writable allowlist is limited to `/etc/lvm`, `/etc/pve`, `/run/lock`, and `/var/lib/vz`. The leaked lock descriptor warning on `lvs` was also removed.
- The smoke backup of host configuration plus LXC `103` passed. Repository check and authenticated dry-run extraction passed. LXC `103` was restored offline to unused ID `9903`, kept stopped, forced link-down, mounted to verify Debian system files, unmounted, and removed. Its temporary 800 MB restore tar and thin volume were removed; the original guest was never modified.
- The complete manual backup passed from 2026-08-11 08:26 to 10:05 Europe/Ljubljana. It archived host configuration and all 18 non-template guests; QEMU template `9000` was intentionally skipped. Borg reported 432.05 GB logical, 175.16 GB compressed, and 174.75 GB deduplicated repository data after the run. The service finished with `Result=success`, `ExecMainStatus=0`.
- A subsequent `borg check --repository-only` plus complete authenticated dry-run extraction of the newest host archive and every one of the 18 guest archives passed at 2026-08-11 10:52 Europe/Ljubljana. Thin-pool use afterward was 58.87 percent data and 41.97 percent metadata.
- After the final changes, `pnpm test:all`, `pnpm test:mcp:live`, `pnpm audit --prod --audit-level high`, `pnpm qualify:artifact`, and `git diff --check` passed. The exact artifact remained ZIP `2F2E56DDE074C526BDB9AA672825414D35F5AE749C3950A88CF35B6BD144C8C5`, SBOM `B6E487BB453B672F45830D3A74205DC3B02169677B01B3866047DA903546BD90`, and manifest `57B2B9B981D79B1791CF36CB11B3E75E6E0B5FA92A673BE1C095D26B324C827D`.
- GitHub's initial `main` qualification and the first PR run both failed before testing because the hosted runner's legacy PowerShell process could not provide `Get-FileHash`; explicitly setting `PSModulePath` was insufficient. Commit `1b2cff9` replaces every qualification-time use with the repository-owned .NET hashing helper in `tools/powershell-compat.ps1`. Its output matched the native cmdlet locally, and both `pnpm test:all` and `pnpm qualify:artifact` passed through the helper. A new branch qualification run is required before treating the GitHub Actions artifact as canonical.
- The next clean-clone run provisioned successfully, then correctly revealed that the default local upstream audit requires ignored commercial/reference archives that must never be uploaded to GitHub. Commit `079a6b1` gives GitHub a recorded-evidence mode that validates every configured identity, metadata field, package checksum, file/byte count, extension inventory, and source-tree checksum against the committed manifest without claiming to re-audit absent bytes. The default local mode remains unchanged and still hashes the real archives and extracted trees. Both modes and the complete CI-mode `pnpm test:all` passed locally; GitHub must rerun this commit.
- The following clean-clone run passed recorded upstream validation but found that requirements verification ran before the ignored `dist/release-manifest.json` existed. Commit `1372473` adds a deterministic `pnpm package` step immediately after runtime provisioning; the later exact-artifact qualifier still rebuilds twice, compares hashes, installs, and tests the ZIP. Local package generation reproduced the published hashes and the subsequent requirements verification passed. GitHub must rerun this commit.
- That run then passed package generation, requirements, architecture, infrastructure, PHP, MCP, and storefront checks before live schema validation found that the hosted Windows runner terminates the WordPress process between workflow steps. Commit `e633040` explicitly restarts the pinned runtime inside the qualification-suite step before any live contracts execute. The same start-plus-live-contract sequence passed locally. GitHub must rerun this commit.
- The restarted hosted runtime reached live schema validation and exposed a fresh-database ordering dependency: initial catalog indexing had not run before `verify:contracts` requested the exact `EXACT-001` fixture. Commit `c3800f0` reorders `test:all` so the integration suite establishes and verifies the canonical indexed fixture before live response schema validation. The complete recorded-evidence CI-mode `pnpm test:all` then passed locally, including live contracts, lifecycle and forced-process recovery, disaster recovery, and the 74-file release scan. GitHub must qualify this commit before its Actions artifact is canonical.
- Durable Vault custody is the only remaining backup gate. Browser device-authentication tabs became unresponsive after unlock, so no private key, password, Borg passphrase, or exported Borg recovery key was submitted to Vault. The writer private key, Borg passphrase, and exported recovery key remain root-only on `s2`; the separate maintenance private key remains off-host in the user's restricted `.ssh` directory. The Storage Box sub-account password must be reset to a newly generated value during Vault ingestion because its current random value is intentionally not recoverable from local files.
- The nightly timer remains disabled and inactive. Next safe action: open a fresh responsive `vault.starfiniti.com` tab, unlock it, store the sub-account login, writer key, maintenance key, Borg passphrase, and exported recovery key, then enable and verify `starfiniti-pve-borg-backup.timer`. Do not enable retention/compaction from `s2`; use only the separate maintenance credential.

## Infrastructure review remediation update — 2026-08-11

- The five findings from the post-infrastructure code/security review are fixed in commit `908aad9`. Typesense harness downloads now fail unless their SHA-256 matches `infra/runtime-lock.json`, evidence identifies the actual container image or standalone binary tested, URLs are loopback-only, exact server versions are required, and partial key-creation failures delete every earlier key.
- Docker's Ubuntu signing-key fingerprint is pinned and verified in an isolated temporary GnuPG home before the repository is trusted. The observed official primary fingerprint is `9DC858229FC7DD38854AE2D88D81803C0EBFCD88`.
- Prometheus alone now joins a dedicated egress bridge so it can scrape VM `960`. Loki and node-exporter remain bound to their exact private guest addresses, but deployment now requires a root-owned fail-closed systemd firewall helper that installs source-specific UFW and `DOCKER-USER` allow-then-drop rules. Do not rely on UFW input rules alone for Docker-published ports.
- The complete local executable gate set passed in 96.5 seconds: requirements, architecture, infrastructure, PHP 40/40, MCP 9/9, storefront/contracts, integration, lifecycle, both crash-recovery suites, disaster recovery, and release scan. Semgrep security/secrets rules reported zero findings. Gitleaks' only history finding remains the intentional fake credential in `TypesenseSafetyTest.php`.
- The hardened certifier then passed all 10 checks against the official Typesense 30.2 Linux archive on VM `960`, with the pinned digest enforced. The injected fourth-key failure regression also passed, and all temporary VM processes, data, evidence, keys, and copied test files were removed.
- No stateful application or observability component was deployed. The Storage Box backup and isolated restore proof remain the next external gate.

## Credential-free infrastructure preparation update — 2026-08-10

- The user selected a 5 TB Hetzner Storage Box as the primary off-host backup target. The endpoint, sub-account, SSH key, Borg passphrase, and exported recovery key do not exist yet and no repository has been initialized.
- `infra/backup` now contains fail-closed Borg 1.4 streaming backup, separate unrestricted maintenance, complete latest-archive extraction verification, systemd scheduling, and a restore-proof runbook. `vzdump` streams uncompressed guest snapshots into encrypted/deduplicated Borg archives, avoiding local staging. Borg 1.4.0 and `jq` were installed from Debian 13 on `s2`. The validated daily-writer script and systemd unit/timer are installed but disabled and inactive because no repository exists.
- A dedicated Storage Box SSH identity exists only on `s2` at `/root/.ssh/starfiniti-storage-box-backup`; its public-key fingerprint is `SHA256:csv2Isir1KVJ58MSPB8PuSr5HyvpJnqvKn/FBIVBbDg`. Add its public key to the future restricted Storage Box sub-account. The Borg passphrase and exported repository recovery key must use separate durable custody and must never enter Git or the handoff.
- `infra/runtime-lock.json` pins Docker Engine packages, Typesense 30.2, Prometheus, Loki, Grafana, Alloy, and node-exporter. Typesense is pinned to manifest `sha256:610f2d34b1f93d00762869da2c67736775e5798d19a2c8b91b014b8a0cc1e110` and Linux/amd64 child `sha256:b405d4443bdb9254fab4792b7fd9ee95d969f3b5485975917a3a3f84274eba27`.
- The Typesense service probe passed 10 checks against the official 30.2 Linux binary on VM `960` loopback: anonymous health, exact version, separate temporary search/index/provisioning/relevance keys, collection creation, per-document partial import, exact-SKU top-1 plus forbidden-result exclusion, denied administration with the search key, alias activation/rollback, and v30 synonym/curation sets. The process, data, keys, and resources were removed afterward. This validates the probe only; it does not complete real container, installed-plugin, relevance, outage, snapshot/restore, or performance certification.
- `infra/observability` defines a private seven-day Prometheus/Loki/Grafana/Alloy stack capped at 1,504 MiB for LXC `105`, plus a 224 MiB VM agent. Grafana and Prometheus remain loopback-only; Loki is designed for one exact private source rule. Native Prometheus 3.13.2, Loki 3.6.15, and Alloy 1.18.1 validation passed. Nothing was deployed or exposed.
- The read-only `s2` audit recorded thin-pool data at 58.07 percent, metadata at 41.67 percent, and 64 LVM thin snapshots. VM 920/921 account for 48 snapshots. No snapshot was deleted; owner-reviewed cleanup remains separate from this project.
- `s2` has 85 pending Debian/PVE updates, including security packages and a new Proxmox kernel. No upgrade or reboot was attempted because off-host recovery is not yet proven and no maintenance window exists.
- After the infrastructure additions, `pnpm test:all` passed in 112.2 seconds, live MCP passed with zero remaining temporary qualification passwords, the production dependency audit found no known vulnerabilities, and `pnpm qualify:artifact` passed in 108.6 seconds. The plugin remained the exact 74-file tree `2080955E96C7BAE8A42316CB4DE9A823342D5FC4AA86B50EC6748F633FEB80F4`; ZIP `2F2E56DDE074C526BDB9AA672825414D35F5AE749C3950A88CF35B6BD144C8C5`; SBOM `B6E487BB453B672F45830D3A74205DC3B02169677B01B3866047DA903546BD90`; manifest `57B2B9B981D79B1791CF36CB11B3E75E6E0B5FA92A673BE1C095D26B324C827D`.
- Next gate: purchase the Storage Box, create the restricted sub-account and dedicated SSH key, provide the non-secret endpoint/username, establish durable passphrase/recovery-key custody, initialize Borg, run a complete backup, and prove an isolated restore. Stateful Docker/Typesense/observability deployment remains blocked until that proof passes.

## MCP v2 implementation update — 2026-08-10

- Implementation checkpoint: commit `f9820c1` on branch `codex/resume-infrastructure`. The branch is local and has not been pushed.
- The stable split MCP SDK was upgraded to `@modelcontextprotocol/server` and `@modelcontextprotocol/client` 2.0.0. Local stdio remains compatible, and a modern-only MCP 2026-07-28 HTTP entry now performs `server/discover` negotiation.
- Remote request authentication now uses RS256/JWKS verification, the Auth0 RFC 9068 `at+jwt` profile, exact issuer and full-resource audience, a namespaced tenant claim, a 15-minute maximum lifetime, request-scoped principals, and an atomically managed local `jti` revocation file. OAuth protected-resource metadata, authorization-server metadata, rate/body/concurrency controls, private health/readiness/metrics, systemd, and Caddy examples are included.
- `pnpm test:mcp` passes 9/9, including a real modern client negotiation and authenticated tool call. `pnpm test` passes all 115 requirements, PHP 40/40, and MCP 9/9. `MCP-003` remains `in_progress` until the Auth0 tenant, TLS/proxy/firewall, observability, and Codex/MCP Inspector/official TypeScript client checks pass on deployed infrastructure.
- `pnpm test:all` passed in 108.1 seconds; `pnpm test:mcp:live` passed and an independent inventory found zero temporary qualification passwords; `pnpm audit --prod --audit-level high` found no known vulnerabilities; `pnpm qualify:artifact` passed in 89 seconds with the unchanged 74-file plugin tree and exact published ZIP/SBOM/manifest hashes.
- Proxmox provisioning is complete at the user-approved reduced footprint. VM `960` (`starfiniti-search-cert`, `10.10.10.60`) has 4 vCPU, 8 GiB RAM, and a thin-provisioned 160 GiB disk. Unprivileged LXC `105` (`starfiniti-search-ops`, `10.10.10.61`) has 2 vCPU, 2 GiB RAM, 512 MiB swap, and a thin-provisioned 40 GiB disk. Both use the private `vmbr10` network and start automatically; no unrelated workload was stopped or resized.
- Both guests run Ubuntu 24.04, are fully patched, use the dedicated `starfiniti-admin` account with key-only SSH, reject root SSH, and run UFW with only TCP/22 allowed from `10.10.10.0/24`. The VM runs kernel `6.8.0-137-generic`; QEMU Guest Agent is active and visible to Proxmox. No application, Typesense, observability, DNS, Caddy, Auth0, or public listener has been deployed.
- `local-lvm` remains physically healthy at approximately 56.7 percent used after provisioning, but the host-wide thin pool was already logically oversubscribed and has no useful unallocated VG extent for auto-extension. Treat physical usage and thin-pool metadata as monitored capacity gates; do not allocate against advertised logical free space without checking them.
- The required pre-stateful backup step is in progress. Read-only discovery confirmed that the existing `starfiniti` account on `s1.starfiniti.com` has Restic installed and its home filesystem has approximately 576 GiB available. Repository creation is paused before secret generation because no approved Vault ingest path or local Vault credential was found. Choose a durable recovery-secret custody path, then initialize the encrypted off-host repository and prove restore before deploying Typesense.
- No DNS, Caddy, Auth0, reverse-proxy, public firewall, backup repository, or public-service configuration has been mutated in this continuation.

Snapshot date: 2026-08-10 (Europe/Ljubljana)

## Continuation update — 2026-08-10

- Published source restored at commit `b21763b2b685efd44d3fe763b475ce4ff7edeb03` on local branch `codex/resume-infrastructure`, tracking `origin/main`.
- Work is uncommitted. The only source change before this handoff update is a repository-wide LF rule in `.gitattributes`, added because the global Windows `core.autocrlf=true` setting changed qualified package bytes and made deterministic manifests fail on this machine.
- The partial pre-publication working copy was preserved in the sibling directory `starfiniti-filters-pre-handoff-backup-20260810-1500`; it contained 29 specification files, of which 24 matched `origin/main` and five were older/different. Nothing from that backup was merged.
- The pinned public FiboSearch 1.34.0 archive was restored from the official WordPress download URL. Its package SHA-256 is `2631D9CB5450D6A8F3B2BEBBE2DC27EBB633A1A7F423E1B6C8AF78D7B241F40D`, and the extracted 447-file tree is `EE0F8FF6D5BFF5A69DBB11179F8072C73C650E91A0C26F179A7A30F88F7AB330`.
- The commercial FiboFilters reference remained ignored and unchanged. Its package SHA-256 is `3E8FEFBFE1C1FBA3126E691F67F2D1C5437E39AC33D5AB6DE44BD912456355D2`, and the extracted 841-file tree is `4E50D148170407C48FB46AEEDB654DE05253541B2A144B99DE28546916A2E40A`.
- `pnpm install --frozen-lockfile`: passed through a temporary Corepack shim using pnpm 11.21.0 because the bundled global pnpm launcher referenced a missing module.
- `pnpm package`: passed and reproduced the exact qualified 74-file, 422590-byte source tree and artifact hashes recorded below.
- `pnpm test`: passed; 115 requirements and 28 specification hashes verified, PHP 40/40 passed, and MCP 6/6 passed.
- `pnpm test:all`: passed in 138.1 seconds, including integration, lifecycle, forced worker/builder recovery, disaster recovery, schemas, storefront, and release scan.
- `pnpm audit --prod --audit-level high`: passed with no known vulnerabilities.
- `pnpm test:mcp:live`: passed discovery, status, secret-free configuration, and dry-run planning. Temporary MCP Application Passwords remaining after an independent inventory: 0.
- `pnpm qualify:artifact`: passed in 147.4 seconds. Plugin Check 2.0.0 reported no errors; exact installation, installed contracts, process recovery, disaster recovery, schema validation, and public smoke passed.
- The pinned localhost runtime is installed and running at `127.0.0.1:8088`: PHP 8.3.28, MariaDB 11.4.10, WordPress 7.0.2, WooCommerce 10.7.0, and WP-CLI 2.12.0. Runtime credentials and machine state remain outside the repository.
- No VPS, DNS, firewall, reverse-proxy, OAuth provider, Typesense service, or other remote resource was inspected or changed in this continuation.
- External blockers remain unchanged: licensed relevance judgments/thresholds, real Typesense v30 certification, production-like strict end-to-end latency, remote MCP OAuth/JWT transport, broad external matrices, signing/provenance, and final release approval.
- Next safe action: identify the intended VPS/SSH target and perform the read-only server inventory in this handoff before proposing any infrastructure or remote MCP changes.

## Sync recovery and infrastructure planning update — 2026-08-10

- Nextcloud synchronization produced eight conflicted copies and displaced the LF rule and continuation evidence from their tracked files. Every conflicted copy was preserved and hash-verified outside the synchronized directory before reconciliation.
- Work now continues from a clean Git clone outside Nextcloud on `codex/resume-infrastructure` at `b21763b2b685efd44d3fe763b475ce4ff7edeb03`. Only the reviewed `.gitattributes` LF rule and handoff updates are included in the checkpoint.
- Six synchronized PowerShell files contained an added `Import-Module Microsoft.PowerShell.Utility` line. Those variants were preserved but not merged because the clean published scripts passed the complete qualification chain without the imports.
- The clean clone reproduced the exact 74-file, 422590-byte plugin tree and the published ZIP, SBOM, and release-manifest hashes. `pnpm test`, `pnpm test:all`, `pnpm test:mcp:live`, the production dependency audit, and `pnpm qualify:artifact` passed. An independent inventory found zero remaining temporary MCP Application Passwords.
- Read-only infrastructure discovery found a single Proxmox node `s2`, the existing Caddy reverse-proxy container at `10.10.10.30`, and free VM/LXC identifiers and private addresses suitable for the planned certification environment. No VM, container, DNS, proxy, firewall, OAuth, backup, or public-service configuration has been changed yet.
- The approved implementation direction is a dedicated Ubuntu certification VM, private Typesense 30.2, a private self-hosted observability container, public MCP only at `mcp-search.starfiniti.com`, Auth0 EU with Google Workspace federation, and encrypted off-host restic recovery on the existing `starfiniti`/`s1` host before GA.
- The approved relevance gate uses a clean-room corpus with exact-SKU top-1 100 percent, forbidden-result rate 0, MRR at least 0.90, NDCG@10 at least 0.85, and Precision@5 at least 0.80. The recovery target is 24-hour RPO and 8-hour RTO with a separate internal operator validating the runbook.

The historical continuation evidence below remains authoritative for the published qualification artifact. Infrastructure implementation must begin only from the clean clone and must preserve the fail-closed release boundary.

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
- Stable split MCP SDK v2.0.0 TypeScript application under `apps/search-ops-mcp`
- Exact site registry, fixed WordPress control client, server-side credential references, tenant/site/scope checks, bounded untrusted-data envelopes, safe audit records, local stdio mode, modern HTTP/Auth0 resource-server mode, protocol tests, and real localhost MCP test
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
- Remote MCP Streamable HTTP is implemented locally but not deployment-certified. Do not expose `apps/search-ops-mcp/src/stdio.ts` over a socket wrapper or publish the HTTP service before the remaining `MCP-003` gates pass.
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
9. Deploy and certify the implemented remote MCP transport/auth before binding a public MCP hostname.
10. Run Typesense real-service conformance, import/partial-failure, alias activation/rollback, relevance reconciliation, outage, deadline, retry, circuit, snapshot, and restore tests.
11. Run `benchmark:http:strict` against the production-like WordPress/PHP-FPM or equivalent runtime and record p50/p95/p99 evidence.
12. Create DNS records with low TTL, validate TLS/health/auth, then raise TTL.
13. Run the exact artifact qualifier and smoke tests against staging before any production cutover.

## Remote MCP work still required

The implementation exists and passes local protocol/security tests. The remaining work is deployed certification:

- provision Auth0 EU, the Google Workspace domain connection, RFC 9068 access-token profile, Resource Parameter Compatibility Profile, DCR/CIMD posture, tenant ACL, least-privilege default API permissions, and the namespaced tenant claim
- deploy behind TLS with the exact Caddy route allowlist; keep `/livez`, `/readyz`, and `/metrics` private
- validate Codex desktop/CLI, MCP Inspector, and the official TypeScript v2 client through the real OAuth authorization-code/PKCE flow
- exercise JWKS rotation, restart, replay, malformed-token, wrong-audience, revoked-token, tenant-crossing, rate, body-size, concurrency, and proxy-origin cases
- connect metrics/logs to the private observability stack with strict secret and catalog-content redaction
- obtain the external security review and retain evidence that MCP bearer tokens are never forwarded to WordPress

`validateVerifiedClaims` remains a post-signature claim-policy layer. Only `RemoteJwtVerifier` may feed it remote claims after successful JOSE verification.

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
