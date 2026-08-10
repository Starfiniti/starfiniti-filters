# 12 Assumptions and Initial Decisions

These decisions allow implementation to start without blocking questions. Codex may change one only through a documented ADR with evidence and no reduction of mandatory quality.

## A-001 Working product identity

Use:

- `Starfiniti Search for WooCommerce`;
- slug `starfiniti-search`;
- namespace `Starfiniti\Search`;
- text domain `starfiniti-search`;
- database prefix segment `sfs_`;
- REST namespace `starfiniti-search/v1`.

Centralize public identity. A controlled rebrand before first public release is allowed, but no upstream trademark may be used as the new identity.

## A-002 Upstream baseline

At project initialization, use the latest official free FiboSearch release available from WordPress.org and pin it exactly. Research at specification time identified version 1.34.0 dated 2026-08-03, but Codex must verify the exact current official release and archive because repository initialization may happen later.

Do not automatically move to a newer upstream after work begins. Review and port security and compatibility fixes deliberately.

## A-003 Premium source

No FiboSearch Pro code is available or authorized for reuse. The local index is an original implementation.

## A-004 License target

Target `GPL-2.0-or-later`, subject to file-level upstream and dependency verification. Preserve notices and produce full source/build materials.

## A-005 Product tiers

The code architecture must not intentionally cripple the local provider. Commercial packaging and hosted-service strategy are separate business decisions. The plugin remains fully functional with local search and bring-your-own Typesense.

## A-006 First GA providers

Production providers in the first GA:

1. Local.
2. Typesense.

Meilisearch is the first planned additional provider, but it is not allowed to delay the quality of Local and Typesense. No production Meilisearch stub appears in the UI.

## A-007 MCP

The Search Operations MCP server is part of the complete enterprise deliverable. It is packaged and deployed separately from the WordPress plugin and is never required for storefront search.

## A-008 Search-result parity

The product guarantees common contracts and quality invariants, not identical rank order between engines. Provider-specific relevance baselines are accepted when canonical minimums pass.

## A-009 Variation default

Default to parent-collapsed results with exact variation SKU awareness. Offer variation-as-result as a schema-affecting option.

## A-010 Local provider scale

Certify local search on the documented 100,000-product large fixture and high-variation fixture. Do not state an absolute hard maximum until measurement exists. Recommend Typesense when catalog, traffic, facets, or hosting exceed certified local limits.

## A-011 Runtime syntax

Choose a modern PHP syntax floor through ADR after auditing current WordPress/WooCommerce adoption and maintained PHP branches. Do not inherit PHP 7.4 merely because the upstream baseline supports it. Enterprise certification is only for maintained PHP versions.

The code may support a broader floor if doing so does not materially harm architecture or security, but support claims must identify certified branches.

## A-012 Frontend

Use a small TypeScript public component with no jQuery requirement for new code. Preserve a semantic no-JavaScript form. Use WordPress React components in admin and blocks where appropriate.

## A-013 Queue

Use Action Scheduler as execution substrate because WooCommerce is required, with product-owned durable outbox and operation state as the source of correctness.

## A-014 Typesense transport

Default:

- direct browser transport only for public-safe catalogs;
- server proxy for restricted, dynamic, B2B, or customer-specific catalogs;
- indexing always server-side;
- provisioning and write credentials never in browser.

## A-015 Configuration

Canonical desired configuration is the source of truth. Provider settings, schemas, synonyms, and curations are projections and are reconciled.

## A-016 Analytics

First-party canonical analytics are the source of truth. Provider-native analytics are optional supplementary sources. No external telemetry by default.

## A-017 Search page

The full WooCommerce search-results page uses the same `SearchGateway`, provider, ranking profile, visibility context, and query semantics as autocomplete.

## A-018 Fast local endpoint

A minimal-bootstrap public local endpoint is allowed as an optional certified performance mode. It is not enabled by default until security and parity gates pass. Restricted or dynamic catalogs use the full WordPress path.

## A-019 Managed control plane

A Starfiniti-hosted control plane is not required for first GA. Contracts must permit it later. Bring-your-own Typesense and local operation remain independent.

## A-020 Questions and ambiguity

Codex records non-blocking ambiguity as an ADR and proceeds. It asks only for:

- unavailable required repository or source;
- credentials needed for a real configured external environment after container tests exist;
- legal authorization that cannot be inferred from source;
- an irreversible public brand decision immediately before publication;
- a destructive production operation.

## Initial ADR list

Create:

```text
ADR-0001 Fork provenance and independent local engine
ADR-0002 Modular monolith and provider ports
ADR-0003 Canonical document and request contracts
ADR-0004 Durable outbox plus Action Scheduler
ADR-0005 Local inverted-index storage and ranking
ADR-0006 Generation and alias activation model
ADR-0007 Typesense credentials and transport policy
ADR-0008 Restricted catalog visibility model
ADR-0009 Frontend component and accessibility model
ADR-0010 Canonical analytics and privacy
ADR-0011 Search Control API and MCP authorization
ADR-0012 Supported runtime matrix
```
