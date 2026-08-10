# Third-party notices

This inventory is a Gate 0 baseline and must be regenerated and reviewed before every release. The distributable plugin contains independently implemented Starfiniti source under GPL-3.0-only and no bundled third-party runtime libraries.

## Direct upstream packages

### FiboFilters 1.12.1

- Declared license: GPLv3 (`readme.txt`).
- Package checksum: recorded in `UPSTREAM.md`.
- Intended use: behavioral baseline, compatibility reader, and migration source.
- Known bundled runtime: Freemius WordPress SDK 2.13.1, GPL-3.0-only.
- Known prefixed libraries: `psr/container`, `swaggest/json-diff`, Symfony DependencyInjection, Symfony deprecation contracts, Symfony PHP 8.0/8.1 polyfills, Symfony service contracts, and `thanks-to-it/wp-dich`.
- These files are audit references only and are excluded from `plugin/starfiniti-search` and every release archive.

### FiboSearch Free 1.34.0

- Declared license: GPLv2 or later (`readme.txt`).
- Package checksum: recorded in `UPSTREAM.md`.
- Intended use: public storefront and search-UX compatibility reference only.
- Known bundled runtime: Freemius WordPress SDK 2.13.4, GPL-3.0-only. It will not be carried into Starfiniti production code.

## Development runtime

The reproducible local harness downloads the curl CA extract dated 2026-07-16 under MPL-2.0. It is stored outside the repository release tree and is not part of the WordPress plugin package.

## Audit limitation and release decision

The proprietary FiboFilters archive strips exact Composer version/license metadata for several prefixed dependencies. The evidence is recorded without guessing in `audit/generated/upstream-dependency-inventory.json`. This does not create an unknown component in the production artifact: `tools/verify-release.mjs` fails when upstream namespaces, commercial endpoints, credentials, binaries, or archives enter `plugin/starfiniti-search`.
