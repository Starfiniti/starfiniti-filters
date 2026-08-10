# Incident response

## Triage

Treat restricted-product leakage, unauthorized control access, lost catalog updates, activation of an incomplete generation, credential exposure, or destructive configuration drift as critical. Disable autocomplete presentation if necessary, but preserve normal WooCommerce form submission. Do not delete failed outbox rows, quarantine records, generations, configuration revisions, operation audit rows, or logs during triage.

Capture UTC time, plugin/configuration/generation versions, correlation IDs, readiness output, queue counts, the bounded error code, and the exact operator action. Never place raw queries, product documents, cookies, authorization headers, salts, database credentials, provider keys, customers, or orders in an incident ticket.

## Containment

1. For relevance or index defects, execute an approved rollback through the plan/approve/execute boundary.
2. For provider outage, retain the certified local provider or normal WooCommerce form fallback; never enable an uncertified transport during an incident.
3. For suspected credential exposure, rotate the external constant/environment secret outside WordPress and invalidate the old provider credential.
4. For visibility leakage, disable the enhanced surface, preserve evidence, and verify the canonical document, scope tokens, active configuration, and provider predicate before restoration.
5. For queue failure, stop cleanup, diagnose the terminal error code, repair the cause, then use the explicit reset/re-enqueue operation.

## Recovery and validation

Restore the database atomically when required. Start workers, drain the durable outbox, run reconciliation, build a fresh shadow generation, require exact source/index counts and smoke queries, activate with human approval, and retain the rollback generation. Run `wp starfiniti-search status --deep` and `pnpm qualify:artifact`; verify an exact public SKU and a known restricted product before closing the incident.

Document root cause, affected versions/time window, containment, customer impact, data-handling assessment, corrective controls, regression tests, and owner. Security disclosure and notification decisions require organizational legal/privacy review and are not automated by the plugin.
