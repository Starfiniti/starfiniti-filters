# Upstream provenance

This document records the only source packages admitted to the Gate 0 audit.

| Upstream | Version | Role | Source | Package SHA-256 | Declared license |
| --- | --- | --- | --- | --- | --- |
| FiboFilters | 1.12.1 | Primary filtering behavior, compatibility baseline, and migration source | User-supplied licensed package `fibofilters-pro.1.12.1.zip` | `3E8FEFBFE1C1FBA3126E691F67F2D1C5437E39AC33D5AB6DE44BD912456355D2` | GPLv3 |
| FiboSearch – Ajax Search for WooCommerce | 1.34.0 | Optional public storefront/search-UX reference only | `https://downloads.wordpress.org/plugin/ajax-search-for-woocommerce.1.34.0.zip` | `2631D9CB5450D6A8F3B2BEBBE2DC27EBB633A1A7F423E1B6C8AF78D7B241F40D` | GPLv2 or later |

The FiboSearch version was verified against the official WordPress.org plugin directory on 2026-08-09. Package content is never committed verbatim under `audit/`; the audit output is committed instead.

## Prohibited inputs

- Any FiboSearch Pro archive, source, symbol map, or derived implementation detail.
- A processed FiboFilters index as migration input.
- Credentials, license keys, remote telemetry identifiers, or marketplace update endpoints from either upstream.

The user-owned `ajax-search-for-woocommerce-premium.1.34.0.zip` located outside this repository is intentionally not opened, hashed, copied, extracted, or referenced by build tooling.

## Rebrand boundary

Before distributable code is produced, inherited trademarks, logos, Freemius wiring, vendor update endpoints, product identifiers, options, REST namespaces, database prefixes, and text domains must be inventoried and either replaced or isolated behind migration-only readers. Attribution and license notices remain.

