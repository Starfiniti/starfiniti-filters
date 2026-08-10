=== Starfiniti Search for WooCommerce ===
Contributors: starfiniti
Tags: woocommerce, search, product search, autocomplete, filters
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.3.0-alpha.1
License: GPL-3.0-only
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Enterprise search and catalog discovery for WooCommerce.

== Description ==

Starfiniti Search builds a dedicated, versioned inverted index from a canonical WooCommerce catalog model. Product changes are recorded in a durable leased outbox with tokenized self-draining workers and processed through Action Scheduler. Catalog cursors use argument-scoped continuations, stale records are removed in bounded paginated batches across live generations, and shadow builds use renewable generation leases, authoritative stored cursors, idempotent replay, automatic expired-build rescheduling, live ready/rollback write targets, and synchronized transactional cutover guards.

The local provider supports exact SKU, Unicode/accent-aware prefix and phrase search, bounded typo and synonym recovery, locale-specific stop words, visibility-safe pins/boosts/buries/hides and internal redirects, safe text highlights, bounded filters and facets, shadow rebuilds, atomic cutover, rollback, immutable configuration history and draft relevance preview, a fail-closed 12-step setup readiness assessment, and accessible search and discovery blocks. External providers remain disabled until their real-service qualification passes.

This alpha is a qualification build. It is not yet a production release.

== Changelog ==

= 0.3.0-alpha.1 =

Qualification build with the independent local search engine, durable synchronization, immutable relevance and operations controls, shared accessible storefront, 12-step setup readiness, defined privacy-preserving aggregate reports, deterministic SBOM-bound packaging, and installed-artifact verification. Locally tested on PHP 8.3.28, WordPress 7.0.2, WooCommerce 10.7.0, MariaDB 11.4.10, and Twenty Twenty-Five. Real Typesense, licensed upstream parity, full scale/chaos/version/theme/assistive-technology matrices, signing/provenance, and final release approval remain mandatory.

Qualification also includes leased shadow-builder and outbox-worker process termination/recovery plus an isolated full-database backup/restore and restored-application exact-SKU/visibility smoke. Production backup storage, point-in-time recovery, and second-operator rehearsal remain deployment gates.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Starfiniti Search.
3. Review the 12-step evidence-backed setup readiness assessment, then use WP-CLI or the administration screen to start the initial index build.
4. Place the Search, Navigation Search, or Discovery block; the classic Starfiniti Search widget; a shortcode; or the documented PHP template API.

== Privacy ==

The local provider keeps indexed catalog data in the WordPress database. No catalog or query data is transmitted externally unless an external provider is explicitly configured.

Analytics is disabled by default. If an administrator opts in through a configuration revision, only daily aggregate buckets and latency counters are retained for the configured inclusive UTC period; raw queries, IP addresses, users, products, customers, and orders are never stored. Reports expose defined weighted metrics with provider/configuration/index context through a separately authorized admin/API surface. Aggregate analytics can be purged independently.
