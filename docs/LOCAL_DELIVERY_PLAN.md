# Local Search + Filters delivery

Updated 2026-09-07. This is the user's narrowed delivery target, not a claim that the historical enterprise requirements are complete.

## Scope

Maintain one independent WordPress/WooCommerce search-and-filter plugin, replace the FiboSearch Pro behavior used on existing sites, migrate their FiboFilters setup without copying its processed index, serve queries from the plugin's own local index, and support AI through the existing packaged skill and controlled local MCP. Typesense deployment, Medusa site implementation, remote MCP hosting, and unrelated infrastructure are not on this delivery path. Preserve the sibling `starfiniti-search` repository; use this smaller repository as the delivery base.

The versioned [FiboSearch Pro + FiboFilters compatibility matrix](FIBO_COMPATIBILITY_MATRIX.md) and [migration design](FIBO_MIGRATION_DESIGN.md) define the paired migration work, including complete settings inventory, unsupported-feature blockers, search/refinement semantics, placement/URL preservation and rollback without losing commerce writes. These are design deliverables, not an implemented importer or a claim of full Pro parity. The clean-room boundary and historical release gates remain intact.

## Acceptance and remaining work

1. **Runtime and baseline:** resumed with explicit user permission. Pin WordPress 7.0.2 and disable automatic core updates in the disposable lab. Core/database recovery copies are retained. Existing PHP, integration, schema, MCP and package checks must pass on the actual candidate.
2. **Configurable filters:** this candidate adds up to fourteen ordered controls through optional `discovery.filters` in immutable desired configuration. Supported fields are indexed attributes, stock, featured status, category IDs/paths and active minimum price. Controls are checkboxes, radio, select and price range. Stock/category defaults remain unless replaced by configured controls. URLs, clearing, reload and Back are implemented. The UI-fix candidate adds alternative counts, stable keyboard focus, a compact modal panel, selected-filter chips, field-associated price errors and query-preserving clear. This is not full FiboFilters parity: tags/brands/on-sale, richer visibility rules and controls still need explicit implementation/acceptance, and source-specific count semantics require migration validation.
3. **Real migration:** not implemented beyond read-only upstream inventory. Obtain the intended store's public URL or secret-free settings export, inventory actual filters/labels/order/URL parameters, report unsupported items, and create a reviewable immutable configuration operation with verification and rollback. Do not invent a stored FiboFilters format or mark an empty reference configuration as migrated.
4. **Speed:** the fresh ten-request small-catalog baseline measured provider p50/p95/p99 13.0/27.1/27.1 ms and HTTP 275.0/416.4/416.4 ms. The provider sample passes its budget; end-to-end does not. Diagnose WordPress/bootstrap overhead, then test real filters and queries on a representative large catalog, including cold/warm and concurrent requests. The historical synthetic SQL microbenchmark is not full storefront performance evidence.
5. **AI and final candidate:** local MCP discovery/status/configuration/dry-run plan works with temporary credentials revoked. Update customer capability guidance to the final accepted feature set; preserve plan/approve/execute for mutations. Run complete and exact-ZIP gates, replay the real migration, verify uninstall/rollback and publish nothing without authorization.

Do not substitute the old requirements tally, file count or test count for these acceptance outcomes. No percentage-complete estimate is meaningful until the actual migration configuration and performance workload are defined.

## Filter configuration example

Add this optional object to a complete desired-configuration draft; do not overwrite unrelated configuration. Apply through the existing administrator configuration operation, with a reason and rollback revision.

```json
{
  "discovery": {
    "filters": [
      {"id":"color","field":"attributes.pa_color","label":"Colour","type":"checkboxes","parameter":"color"},
      {"id":"price","field":"pricing.active_min_minor","label":"Price","type":"range","parameter":"price"}
    ]
  }
}
```

Use an attribute actually present in the canonical index (`attributes.color` for the browser test's custom attribute, for example). Multi-choice values use repeated query parameters, not an assumed legacy encoding. Price URL values such as `price=19.99-40`, `price=19.99-` and `price=-40` become exact integer minor units before querying. Invalid ranges fail closed with a visible error. Advanced filters require JavaScript; the normal WooCommerce product-search form remains the fallback.

## Reproduce the browser check

Use a Python executable with the pinned dependencies in `.cache/browser-test-dependencies` and install Playwright's matching Chromium headless shell under the lab's `browser-tests` directory. These are ignored test dependencies, not plugin runtime dependencies. Run `tools/test-configured-discovery.ps1 -Python <python-executable>` after the local lab and current plugin are installed. Do not run it concurrently with other database-mutating qualification suites.

The harness creates three marked temporary products, changes configuration through revisions, exercises 1280 px and 360 px, then deletes only its marked products and restores the original configuration. An interrupted run must execute `configured-discovery-fixture.php restore` through the lab's WP-CLI before continuing. A completed browser assertion sequence with failed teardown is not a passing harness.
