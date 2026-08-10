# Changelog

## 0.3.0-alpha.1 — qualification build

This is not a production or enterprise-certified release.

Implemented: canonical WooCommerce documents, durable outbox and reconciliation, generation-leased resumable shadow builds, schema-v3 positional local index, exact/prefix/phrase/Unicode/typo search, immutable synonyms/stop words/curations, filters/facets/sorting, shadow activation/rollback, accessible shared storefront renderer, focus-managed mobile overlay, typed details disclosure, immutable configuration and control operations, defined aggregate-only analytics with provider/version context and protected reporting, structured redacted diagnostics, fail-closed 12-stage setup readiness, isolated full-database restore verification, deterministic ZIP/SBOM/manifest, and exact installed-artifact qualification.

Locally tested on PHP 8.3.28, WordPress 7.0.2, WooCommerce 10.7.0, MariaDB 11.4.10, WP-CLI 2.12.0, and Twenty Twenty-Five 1.x. The supported metadata floor is PHP 8.2, WordPress 6.7, and WooCommerce 9.0, but the full version matrix remains a release gate.

Known mandatory gaps: licensed FiboFilters semantic parity, real Typesense certification, production-like end-to-end latency, full 100k WooCommerce ingestion/load/soak/chaos and shadow-builder termination, broad browser/theme/builder/assistive-technology/device/zoom/multisite/multilingual/variable-product/cart matrices, external OAuth MCP deployment, remote CI evidence, provenance/signing, second-operator recovery validation, and final release approval.
