# Starfiniti Search for WooCommerce

This repository specification pack defines a production-grade WooCommerce search platform built from the GPL-licensed free FiboSearch codebase, with a new independently implemented local inverted index, a first-class Typesense provider, and a provider architecture that can later support Meilisearch and other engines.

This is not an MVP specification. Internal milestones may be delivered incrementally, but no public release may be called production-ready or enterprise-ready until every mandatory release gate in this pack has passed.

## Working identity

These identifiers are working defaults and must be centralized so a controlled rebrand remains possible before the first public release:

| Item | Default |
|---|---|
| Product name | Starfiniti Search for WooCommerce |
| Plugin slug | `starfiniti-search` |
| Text domain | `starfiniti-search` |
| PHP namespace | `Starfiniti\Search` |
| Database prefix after the WordPress prefix | `sfs_` |
| REST namespace | `starfiniti-search/v1` |
| JavaScript package scope | `@starfiniti-search/*` |
| MCP service name | `starfiniti-search-ops` |

Do not publish under a name that begins with or implies official association with FiboSearch. FiboSearch branding, marks, icons, support URLs, account links, telemetry, licensing code, and Freemius integration are not part of the new product identity.

## Read order

Codex must read these files in order before implementation:

1. `CODEX_START_HERE.md`
2. `docs/00_PRODUCT_CHARTER.md`
3. `docs/01_LICENSING_AND_UPSTREAM.md`
4. `docs/02_ARCHITECTURE.md`
5. `docs/03_CANONICAL_CATALOG_AND_SYNC.md`
6. `docs/04_LOCAL_SEARCH_ENGINE.md`
7. `docs/05_TYPESENSE_PROVIDER.md`
8. `docs/06_STOREFRONT_ADMIN_ANALYTICS.md`
9. `docs/07_SECURITY_PRIVACY_OPERATIONS.md`
10. `docs/08_MCP_CONTROL_PLANE.md`
11. `docs/09_PROVIDER_CONFORMANCE.md`
12. `docs/10_TESTING_PERFORMANCE_RELEASE.md`
13. `docs/11_IMPLEMENTATION_PLAN.md`
14. `docs/12_ASSUMPTIONS_AND_DECISIONS.md`
15. `docs/13_FUTURE_PROVIDERS.md`
16. `docs/REFERENCES.md`

## Core product decision

The plugin owns one consistent search experience and one canonical catalog model. Search engines are replaceable providers:

- `local`: an independently implemented inverted index stored in dedicated WordPress database tables.
- `typesense`: a remote or self-hosted Typesense collection.
- Future providers: Meilisearch, OpenSearch, Algolia, or another engine only after they pass the provider conformance suite.

The UI, WordPress integrations, analytics, relevance configuration, schema definitions, and public extension APIs must not depend on a specific provider.

## Required repository outputs

The implementation repository must ultimately contain at least:

```text
/plugin/starfiniti-search/
/apps/search-ops-mcp/
/packages/contracts/
/contracts/
/docs/
/tests/
/benchmarks/
/fixtures/
/tools/
/docker/
CODE_OF_CONDUCT.md
CONTRIBUTING.md
LICENSE
NOTICE
PRIVACY.md
SECURITY.md
THIRD_PARTY_NOTICES.md
UPSTREAM.md
composer.json
package.json
Makefile
```

## Standard commands

Codex must create and keep these commands working:

```bash
make bootstrap
make lint
make static-analysis
make test-unit
make test-integration
make test-provider
make test-e2e
make test-accessibility
make test-security
make benchmark
make qa
make package
```

`make qa` is the local equivalent of the mandatory CI release gate. `make package` must create a reproducible installable plugin ZIP from tracked source and documented build tooling.
