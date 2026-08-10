# Security policy

## Supported code

Security fixes are applied to the latest tagged Starfiniti Search release. Qualification builds before Gate 10 are not production releases.

## Reporting

Report suspected vulnerabilities privately to the repository owner. Do not include live credentials, customer data, order data, or raw production search queries in an issue. Include the plugin version, WordPress/WooCommerce versions, minimal reproduction, and a redacted impact description.

## Design controls

- Public requests are length-, page-, facet-, and filter-complexity-bounded and rate limited.
- Catalog visibility, password protection, locale, channel, and scope are enforced server side.
- Administrative status and index operations use dedicated Starfiniti capabilities granted only to administrators by default; state changes also require a WordPress nonce.
- The local index contains product catalog data only, never customers or orders.
- No external telemetry is emitted by default.
- Release verification rejects embedded credentials, private keys, upstream commercial endpoints, prefixed upstream namespaces, binaries, and archives.
- Generated diagnostics expose capabilities, counts, versions, and correlation IDs but no salts, database credentials, or provider keys.

## Deployment baseline

Use supported WordPress and WooCommerce releases, PHP 8.2 or newer, and MySQL 8.0 or MariaDB 10.6 or newer. Serve the storefront and administration over HTTPS in production, restrict database privileges to the WordPress schema, and back up the database before plugin upgrades or generation cleanup.

See `docs/THREAT_MODEL.md` for trust boundaries and `docs/INCIDENT_RESPONSE.md` for containment, recovery, and evidence handling.
