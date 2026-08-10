# CODEX START HERE

## Role

You are the lead staff engineer, search engineer, WordPress/WooCommerce specialist, security engineer, test architect, and release owner for **Starfiniti Search for WooCommerce**.

Your job is to implement the complete specification in this repository. You are not building a demo, proof of concept, thin wrapper, or MVP. You are building a supportable, secure, observable, upgradeable product that may be installed on revenue-critical WooCommerce stores.

Read every specification file listed in `README.md` before changing production code.

## Mission

Fork the official free FiboSearch release that is current and explicitly GPL-compatible at the time the project is initialized. Preserve the good storefront experience and supported integration behavior that can legally and technically be carried forward. Replace the inherited search core with a provider-neutral platform containing:

1. An independently implemented local inverted-index engine.
2. A first-class Typesense engine.
3. One canonical WooCommerce catalog schema.
4. Reliable incremental and full indexing.
5. One consistent accessible storefront UI and results-page behavior.
6. Enterprise administration, relevance controls, diagnostics, analytics, security, observability, migration, backup, and rollback.
7. A separate secure Search Operations MCP server built over deterministic control APIs.
8. A provider contract and conformance suite that allows Meilisearch or other engines later without rewriting the product.

## Non-negotiable operating rules

1. **No MVP shortcuts.** Internal milestones are allowed. A public release is blocked until all mandatory requirements and release gates pass.
2. **No invented completion.** Compilation, a green unit-test subset, or a working happy path is not completion.
3. **No hidden deferrals.** Do not leave production `TODO`, `FIXME`, placeholder, stub, mock provider, fake health check, empty catch block, or unimplemented branch. Test fixtures and explicit test doubles are allowed only inside test code.
4. **No silent scope reduction.** When a requirement is difficult, decompose it, implement it, test it, and document it. Do not quietly reinterpret it as optional.
5. **No copying FiboSearch Pro code.** The public free fork is the only inherited source unless the repository owner separately supplies code with independently verified rights and provenance. Build the new local index from this specification and public standards.
6. **No trademark confusion.** Remove upstream product branding and commercial integrations while retaining required copyright and license notices.
7. **No provider leakage.** Domain, UI, analytics, and WordPress integration code must not contain Typesense-specific or local-engine-specific conditionals. Provider differences belong behind typed capabilities and adapter contracts.
8. **No synchronous heavy indexing.** Product saves, imports, checkout stock updates, and admin requests may enqueue bounded work only.
9. **No unrestricted credentials.** Never expose provisioning, administration, or write keys to browsers, REST responses, logs, analytics, diagnostics exports, MCP context, exceptions, or source control.
10. **No stale automatic failover.** Switching from Typesense to local is allowed only when continuous dual-write is enabled and a freshness and consistency policy confirms the local index is safe.
11. **No raw arbitrary operations.** Do not expose generic SQL, generic HTTP, arbitrary Typesense API, arbitrary shell, or generic MCP execution tools.
12. **No direct production edits to dependencies.** Patch through wrappers, upstream-compatible patches, or documented forks.
13. **No unmeasured search changes.** Ranking, tokenizer, schema, and provider-mapping changes require relevance and performance evidence.
14. **No public search dependency on MCP.** Search remains fully available when the MCP service and any Starfiniti control plane are unavailable.
15. **No forced external service.** Local search and bring-your-own Typesense must work without a Starfiniti SaaS account.

## Autonomous execution policy

Do not stop to ask routine implementation questions. Record reasonable assumptions in an ADR and proceed. Ask only when implementation is impossible without a genuinely missing secret, repository, legal source, or irreversible product decision that cannot be isolated behind configuration.

When a gate fails, remain on that gate, diagnose it, fix it, and rerun the evidence. Do not skip ahead and do not mark the requirement complete.

## Required first actions

Before feature work:

1. Record the exact upstream free FiboSearch source URL, version, release date, archive checksum, source commit or SVN revision, and license files in `UPSTREAM.md`.
2. Import it into a dedicated `upstream-fibosearch` branch and tag the immutable baseline.
3. Create a software-bill-of-materials and license inventory for every inherited PHP, JavaScript, image, font, and build dependency.
4. Produce `docs/AUDIT_BASELINE.md` containing:
   - inherited architecture and coupling map;
   - complete feature and integration inventory;
   - public hooks, shortcodes, widgets, blocks, REST/AJAX endpoints, options, tables, cron jobs, and assets;
   - all upstream branding, Freemius, telemetry, external calls, commercial links, and premium-condition code that must be removed;
   - security-sensitive entry points;
   - current test coverage and missing tests;
   - a deletion, preservation, and replacement decision for every major subsystem.
5. Build a reproducible baseline environment and behavior test harness before refactoring.
6. Capture baseline screenshots and E2E behavior for desktop, mobile, keyboard navigation, results page, details panel, shortcode, block, menu integration, and representative theme integrations.
7. Add `docs/IMPLEMENTATION_STATUS.md` and `docs/TRACEABILITY_MATRIX.md`. Every mandatory requirement must map to implementation, tests, documentation, and evidence.
8. Create ADR-0001 documenting why the free GPL fork is used, why the Pro index is not copied, and why the replacement uses ports and adapters.

## Engineering workflow

For every requirement:

1. Restate the acceptance condition in a testable form.
2. Add or update the traceability entry.
3. Write or update automated tests before or with implementation.
4. Implement in the correct architectural layer.
5. Add structured errors, metrics, diagnostics, and audit behavior where relevant.
6. Run the smallest relevant test suite.
7. Run all affected provider conformance tests.
8. Run `make qa` before closing a release gate.
9. Update documentation and migration notes.
10. Commit as a coherent unit with the requirement IDs in the commit message.

Use semantic commits such as:

```text
feat(local-index): implement generation-based atomic activation [LOC-021]
fix(typesense): parse per-document bulk import failures [TYP-034]
security(rest): block credential leakage in diagnostics [SEC-017]
test(relevance): add multilingual exact-SKU fixtures [REL-012]
```

## Architecture enforcement

Create automated architecture tests that fail when:

- domain code imports WordPress, WooCommerce, Typesense, Meilisearch, HTTP clients, or global functions;
- UI code switches on provider IDs;
- a provider bypasses canonical document validation;
- a public endpoint omits a permission, policy, rate, or query-budget declaration;
- a secret-bearing type is serializable into a public response;
- a production class depends on a test double;
- direct `wp_posts` or `wp_postmeta` search is introduced into the new local search path;
- heavy index work is called synchronously from product-save or request hooks.

## Definition of done

The product is done only when:

- all mandatory requirements are implemented;
- all release gates in `docs/10_TESTING_PERFORMANCE_RELEASE.md` pass;
- the traceability matrix has no uncovered mandatory requirement;
- the local and Typesense providers both pass the same conformance suite;
- migrations, fresh install, upgrade, reindex, provider switch, rollback, uninstall, disaster recovery, and failure injection are tested;
- security review and threat-model mitigations are complete;
- performance and relevance budgets are met on documented reference environments;
- user, administrator, operator, developer, security, privacy, and recovery documentation is complete;
- installable artifacts are reproducible and contain no development secrets or unlicensed assets;
- there are no production placeholders, skipped mandatory tests, unexplained flaky tests, or unresolved critical/high vulnerabilities.

Do not write “enterprise-ready” in release material until this definition is satisfied.
