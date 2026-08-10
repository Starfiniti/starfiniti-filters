# WordPress Playground baselines

These harnesses provide fast, ephemeral browser baselines. They do not replace the required MariaDB, Typesense, failure-injection, upgrade, scale, and packaging environments.

## Pinned matrix

- WordPress 7.0.3
- PHP 8.3 WebAssembly runtime
- WooCommerce 10.7.0, package SHA-256 `3C3D72BE3A2B7FE4B26D34C09C572F6BC0B230D2B69B968307FCA41AAEEF8BEE`
- FiboFilters 1.12.1 or FiboSearch Free 1.34.0, verified by `tools/audit-upstreams.mjs`
- WordPress Playground CLI 3.1.48

WooCommerce 10.7.0 is the FiboFilters-supported baseline. Forward compatibility with WooCommerce 10.9 and 11.0 is a separate matrix, not inferred from this run.

## Start

```powershell
pnpm run baseline:filters
pnpm run baseline:search
```

The commands stage the verified trees under the operating-system temporary directory before mounting them. This avoids a known performance problem when the Playground filesystem bridge traverses thousands of Nextcloud-backed files. The temporary copy is rebuilt on every start.

The filters blueprint activates only WooCommerce and `Starfiniti Golden Fixtures`; activate FiboFilters explicitly from **Plugins** so activation latency, redirects, notices, and errors remain observable. The search blueprint activates FiboSearch Free directly because all storefront checks are anonymous and the CLI's one-shot external-browser auto-login is not a stable test primitive.

## Ports

- Filters: `http://127.0.0.1:9400`
- Search: `http://127.0.0.1:9401`

The fixture makes the ephemeral administrator credentials deterministic as `admin` / `password`; they are test-only and the site is bound to localhost.

## Limitation

Playground uses SQLite by default. FiboFilters 1.12.1 issues MySQL-specific `SQL_NO_CACHE` syntax during index build, so its full behavioral baseline must run against the required real MariaDB environment.
