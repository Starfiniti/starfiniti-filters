# Gate 0 audit baseline

## Scope

This baseline covers static provenance and architecture discovery for FiboFilters 1.12.1 and FiboSearch Free 1.34.0 plus dynamic runs on pinned WordPress/WooCommerce localhost environments.

## FiboFilters observations

- 841 extracted files in the deterministic audit tree, including 662 PHP files.
- Modular subsystems include filtering, filter descriptors, indexing, blocks, shortcodes, widgets, SEO rules, settings, integrations, REST, WP-CLI, and WooCommerce adapters.
- Custom storage uses `fibofilters_` tables and multiple candidate/active index roles.
- Index execution uses Action Scheduler plus WordPress cron health checks; no product-owned durable outbox was found.
- Runtime boot is coupled to the upstream loader, dependency container, Freemius SDK, and upstream product identity.
- A bundled FiboFilters logo and marketplace/update plumbing are release blockers, not Starfiniti assets.
- Uninstall behavior drops upstream-prefixed data; migration must be read-only against the legacy installation and cleanup must be a separate, explicit operation.

## FiboSearch Free observations

- 447 extracted files.
- Public free package provides autocomplete UI, blocks, widgets, shortcodes, theme integrations, WordPress-native search, analytics, and settings.
- The package declares GPLv2 or later and includes Freemius runtime code.
- The free package is not an inverted-index engine and is not a source for Starfiniti's independently implemented local engine.
- Release 1.34.0 includes security fixes for thumbnail XSS and password-protected content visibility; the golden baseline must cover both cases.

## Required dynamic baseline

The localhost harness must capture:

1. FiboFilters archive/shop behavior for representative filter types, URLs, pagination, sorting, no-result states, multilingual visibility, variation combinations, and Blocks/FSE.
2. DOM snapshots, normalized network transcripts, screenshots, accessibility trees, query counts, and response timing.
3. FiboSearch Free autocomplete behavior for keyboard, screen reader, mobile overlay, exact SKU, password-protected products, and thumbnail escaping.
4. Activation, deactivation, reindex, interrupted job, and rollback behavior.

No inherited engine or renderer may be refactored before these golden fixtures are committed and replayable.

## Open blockers

- `BASE-003`: complete licensed FiboFilters semantic captures; the legitimate vendor key is not available.
- `SEC-001`: define the automated security baseline and secret/telemetry redaction scans.

## First dynamic evidence

The 2026-08-09 Playground run is recorded in `docs/baselines/2026-08-09-playground.md`. It proves the pinned localhost and fixtures work, and it demonstrates why FiboFilters golden parity requires real MariaDB: upstream index queries use MySQL-only `SQL_NO_CACHE` syntax that the SQLite bridge rejects.

The real MariaDB run is recorded in `docs/baselines/2026-08-09-fibofilters-mariadb.md`. It proves the proprietary plugin boots and creates storage, and records the license-key boundary without bypassing it. The independent Starfiniti localhost on port 8088 passes canonical document, durable outbox, inverted-index, visibility, REST authorization, update propagation, accessible combobox, mobile-layout, shadow activation, and rollback checks.
