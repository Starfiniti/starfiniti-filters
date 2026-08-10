# Starfiniti Search for WooCommerce

Starfiniti Search is an enterprise-oriented WooCommerce discovery plugin built as an independent implementation from audited GPL-compatible behavior and a binding specification pack. The current qualification build provides canonical WooCommerce documents, a durable outbox, a versioned positional local inverted index, exact/prefix/phrase search, bounded typo and synonym recovery, locale-specific stop words, visibility-safe pins/boosts/buries/hides and internal redirects, safe highlights and administrator explanations, bounded filters and facets, accessible autocomplete and discovery blocks, shadow rebuilds, atomic activation, rollback, immutable configuration history and draft relevance preview, a fail-closed 12-step setup readiness assessment, defined opt-in aggregate-only analytics with provider/version context, health diagnostics, structured redacted logs, and an auditable plan/approve/execute control plane shared by REST, admin, and WP-CLI.

## Release status

The project is a **qualification build**. Multiple implementation gates now have executable evidence, but it is not yet production-ready or enterprise-certified. A secret-safe Typesense adapter exists but is deliberately activation-gated until real-service conformance passes. Release claims remain blocked until the outstanding licensed-upstream parity, real Typesense, scale/chaos, compatibility, and signed-release gates in `docs/IMPLEMENTATION_STATUS.md` pass.

## Upstream roles

- **FiboFilters 1.12.1** is the primary filtering behavior and migration upstream.
- **FiboSearch Free 1.34.0** is an optional, public GPL storefront/search-UX reference.
- FiboSearch Pro code and artifacts are prohibited inputs.
- The Starfiniti production package contains no upstream runtime code. A read-only compatibility reader inventories legacy storage without importing a processed index.

See `UPSTREAM.md`, `NOTICE`, and `docs/adr/0001-dual-upstream-boundary.md` for the exact boundary.

## Reproduce the source audit

The original packages and extracted sources live under ignored `audit/packages/` and `audit/source/` directories. With those inputs present, run:

```powershell
node tools/audit-upstreams.mjs
```

The command verifies pinned package checksums and writes `audit/generated/upstream-manifest.json`.

## Specifications

The binding search specification is vendored under `spec/starfiniti-search-codex-spec/`. Conflicts are resolved by explicit ADRs; no hidden Pro implementation is treated as a source of behavior or code.

## Local qualification

```powershell
pnpm runtime:setup
pnpm wordpress:setup
pnpm test:php
pnpm test:mcp
pnpm test:mcp:live
pnpm verify:contracts
pnpm test:integration
pnpm test:lifecycle
pnpm test:process-kill
pnpm test:builder-kill
pnpm test:disaster-recovery
pnpm benchmark:local
pnpm benchmark:http
pnpm qualify:artifact
```

The pinned localhost runs at `http://127.0.0.1:8088` with OPcache loaded. `test:mcp` compiles and protocol-tests the official-SDK operations adapter; `test:mcp:live` creates a temporary WordPress Application Password, exercises discovery/status/configuration/dry-run planning through MCP, and revokes the credential in `finally`. `benchmark:http` enforces provider latency and reports built-in-server HTTP latency; `benchmark:http:strict` enforces the end-to-end budget and is reserved for the production-like reference runtime. `qualify:artifact` builds twice, compares ZIP/SBOM/manifest hashes, installs the exact ZIP, byte-compares every installed file, runs the installed protected contracts and schema validator, and finishes with public visibility smoke. The reproducible ZIP is written to `dist/starfiniti-search.zip`; packaging performs PHP syntax validation and a prohibited-code/secret/binary scan first. See `docs/OPERATIONS.md` for build, cutover, rollback, and recovery procedures.

Use `[starfiniti_search]`, `starfiniti/search`, `starfiniti/navigation-search`, or the classic widget for autocomplete. Use `[starfiniti_discovery]`, `starfiniti/discovery`, or widget discovery mode for the faceted surface. PHP template functions and the idempotent headless initializer use the same component. See `docs/STOREFRONT_INTEGRATION.md` for APIs, URL state, events, safe-rendering guarantees, integration registry, and the certified compatibility boundary.
