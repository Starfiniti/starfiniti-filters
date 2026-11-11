# Setup and troubleshooting

## Twelve setup-readiness stages

Review these in order: environment; catalog; visibility; provider; storage/connection; language/variations; fields/identifiers; price/stock/visibility; index plan; build/verification; storefront placement; smoke/activation.

Each stage returns `pass`, `attention`, or `blocked`, bounded secret-free evidence, and a next action. Any blocked stage blocks readiness. Twelve passes produce setup status `ready`, but `release_certified` remains false until separate release gates pass.

## Minimum safe intake

Ask for:

- WordPress, WooCommerce, Starfiniti Search, PHP, active theme, and relevant builder versions;
- the setup status and only the failing/attention rows;
- the exact visible error or symptom;
- expected versus observed result using a non-sensitive public test product/SKU;
- whether the issue affects autocomplete, discovery, administrator operations, or all surfaces;
- recent changes to catalog, theme, builder, relevance, or indexing configuration.

Ask the customer to redact site identifiers, customer/order information, raw queries, credentials, cookies, nonces, internal hostnames, and private product data. Do not ask for database dumps or complete logs.

## Diagnostic routing

| Symptom | Check first | Safe next action |
| --- | --- | --- |
| No products appear | Setup catalog, active generation, document count, public visibility, smoke result | Resolve the first blocked readiness row, then rebuild/verify through controlled operations |
| One product is missing | Product publication/visibility, stock policy, active generation, outbox failures | Correct the WooCommerce source state, allow bounded synchronization, then verify by public SKU/title |
| Restricted product appears | Stop testing the public surface and record the exact public URL/query without private data | Treat as a security defect; do not weaken or bypass visibility filters |
| Autocomplete fails but form search works | Browser-visible error, REST status, theme/container conflict | Preserve the normal form fallback and test the shared renderer in a qualified owned container |
| Discovery filters look stale | Active schema/generation, URL parameters, reconciliation status | Run bounded reconciliation only through the approved interface, then verify counts and Back/reload behavior |
| Build is stuck or failed | Generation state, cursor, lease/attempts, pending/failed queue, structured correlation ID | Use the administrator's documented remediation; never delete queues/tables or edit options directly |
| Theme layout breaks | Theme/builder/version and placement method | Reproduce with the shared block/shortcode in an owned container; call compatibility pending unless evidence exists |
| Relevance change is wrong | Draft JSON, preview result, current active revision | Correct and preview the draft; do not mutate the active configuration outside the controlled operation |

## Escalation output

When the safe checks do not resolve the issue, summarize:

1. versions and surface;
2. expected versus observed behavior;
3. redacted readiness rows and correlation IDs;
4. steps already tried and their results;
5. whether the issue is reproducible with Twenty Twenty-Five and the shared renderer;
6. any security or visibility impact.

Do not diagnose connectivity as health, speculate that a provider is certified, or tell the customer to expose administrative services.
