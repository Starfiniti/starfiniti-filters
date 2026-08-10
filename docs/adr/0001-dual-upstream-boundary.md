# ADR 0001: Dual upstream boundary

- Status: Accepted for Gate 0
- Date: 2026-08-09

## Decision

Use FiboFilters 1.12.1 as the sole primary filtering behavior and migration upstream. Use official FiboSearch Free 1.34.0 only as a public storefront/search-UX compatibility reference. Implement Starfiniti's canonical document, local inverted index, provider contracts, durable outbox, migration orchestration, and operations plane independently from the binding specifications.

FiboSearch Pro is prohibited. FiboFilters legacy tables and options are read-only during discovery and migration. Cutover writes only Starfiniti-owned state; cleanup is separate and opt-in.

## Rationale

The linked fork plan is filtering-centric, while the supplied Search specification adds a broader search platform. Treating either source as an unbounded code donor would blur licensing, product identity, and engine responsibilities. Explicit roles preserve golden compatibility where required without importing a proprietary engine or making the storefront depend on a provider.

## Consequences

- Combined distributable work uses GPL-3.0-only unless a later legal review selects a compatible alternative.
- Upstream identity, telemetry, licensing SDKs, and update endpoints are not inherited into production.
- Provider parity is measured through canonical contracts, not shared implementation.
- Meilisearch remains a post-GA provider until it passes the same conformance suite; the architecture keeps the adapter slot stable from Gate 1.

