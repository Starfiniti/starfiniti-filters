# Paste this into Codex

You are implementing **Starfiniti Search for WooCommerce** in this repository.

The repository contains a complete specification pack. Start by reading `CODEX_START_HERE.md`, then read every file in the order listed in `README.md`. Treat those documents as binding product and engineering requirements.

The objective is not an MVP. The objective is a production-grade GPL-compatible WooCommerce search platform based on a controlled fork of the official free FiboSearch codebase, with:

- independent Starfiniti branding and preserved legal attribution;
- no FiboSearch Pro source;
- an original local inverted-index search engine;
- a first-class Typesense provider;
- one canonical catalog, request, response, relevance, analytics, and operations model;
- accessible autocomplete and full results-page integration;
- reliable full and incremental indexing, reconciliation, migration, activation, and rollback;
- security, privacy, observability, diagnostics, and disaster recovery;
- a secure separate Search Operations MCP server;
- a provider SDK and conformance suite that allows Meilisearch later.

Follow the gate sequence in `docs/11_IMPLEMENTATION_PLAN.md`. Do not skip gates, silently reduce scope, leave production placeholders, or claim completion based only on compilation or a happy path.

Begin with Gate 0 now:

1. pin and checksum the exact current official free FiboSearch source;
2. verify all licenses and produce `UPSTREAM.md`, `NOTICE`, `THIRD_PARTY_NOTICES.md`, and the SBOM;
3. create the upstream branch and immutable tag;
4. build a reproducible WordPress/WooCommerce baseline;
5. inventory every inherited feature, endpoint, option, table, hook, asset, integration, external call, Freemius component, and security boundary;
6. capture regression tests and E2E baseline behavior;
7. create `docs/AUDIT_BASELINE.md`, `docs/IMPLEMENTATION_STATUS.md`, and `docs/TRACEABILITY_MATRIX.md`;
8. record ADR-0001;
9. run the Gate 0 checks and fix failures before proceeding.

Work autonomously. For routine ambiguity, record an ADR and proceed with the safest supportable choice. Ask only when a genuinely unavailable secret, repository, legal source, or irreversible public decision makes implementation impossible.

Keep `requirements.csv` and `project-status.json` current. Every mandatory requirement needs implementation, tests, documentation, and evidence. Use requirement IDs in commits.

Do not return only a proposed plan. Inspect the repository, create the required project controls, and execute the first gate.
