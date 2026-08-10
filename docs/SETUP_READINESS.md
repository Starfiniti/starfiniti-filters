# Setup readiness contract

Starfiniti Search exposes one evidence-backed setup assessment through WooCommerce > Starfiniti Search, deep health output, and `wp starfiniti-search status --deep`. It is a readiness assessment, not a release certificate; `release_certified` is always `false` until the separate release gates pass.

The ordered contract has twelve stages:

1. PHP, WordPress, WooCommerce, and UTF-8 database compatibility.
2. Eligible products/variations and active/public/restricted document counts.
3. Restricted-catalog detection and server-enforced visibility policy.
4. Active provider selection and real-service certification state.
5. Current index storage plus a conservative catalog estimate.
6. Configured locales and explicit variation strategy.
7. Active schema/analyzer and public searchable documents.
8. Price, stock, visibility, transport, and detected pricing-plugin risk.
9. Immutable configuration revision, write targets, and bounded batch plan.
10. Active generation state, exact source/index count, failed outbox, and quarantine count.
11. Published shared-renderer placement plus a qualified detected integration.
12. A non-mutating exact SKU/title query against a real public indexed document.

Each stage returns `pass`, `attention`, or `blocked`, bounded secret-free evidence, and a concrete next action. Any blocked stage makes setup `blocked`; otherwise any attention stage makes it `attention`. Only twelve passing stages produce `ready`. Count mismatches, uncertified providers, unsafe direct-browser dynamic pricing, missing policy, failed work, quarantine, or smoke-query failure cannot be waived by the UI.

The assessment intentionally does not activate a generation, modify configuration, install a theme, or claim compatibility. Those mutations continue through the immutable plan/approval/execution boundary.
