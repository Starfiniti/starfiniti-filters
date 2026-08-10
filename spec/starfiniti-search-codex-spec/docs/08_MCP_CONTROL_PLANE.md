# 08 Search Control API and MCP Server

## 1. Principle

MCP is an operations and agent-integration interface. It is not the search engine, configuration source of truth, storefront query path, queue, schema migrator, or authorization system.

The deterministic application and control APIs must work without an LLM. MCP tools call those APIs. The model may propose or request operations, but deterministic code validates, plans, authorizes, executes, verifies, audits, and rolls back them.

## 2. Components

```text
MCP client
    |
    | MCP over authenticated transport
    v
Search Operations MCP server
    |
    | typed internal client
    v
WordPress Search Control REST API
    |
    v
Application command/query bus
    |
    +--> configuration and operation planner
    +--> local provider
    +--> Typesense provider
    +--> indexing orchestration
    +--> health and diagnostics
```

A future centralized Starfiniti control plane may sit between MCP and store sites. The first implementation may connect to one or more registered WordPress sites, but tenant isolation must exist from the beginning.

## 3. MCP application

Implement `apps/search-ops-mcp` in TypeScript using the current stable official MCP SDK and specification at implementation time. Pin the supported protocol version and test negotiation.

Runtime requirements:

- supported maintained Node.js release, with the exact minimum documented;
- strict TypeScript;
- schema validation;
- structured logging with secret redaction;
- OpenTelemetry-compatible instrumentation where practical;
- health and readiness endpoints separate from MCP transport;
- no direct database access to WordPress;
- no unrestricted shell;
- no arbitrary HTTP proxy;
- no raw Typesense administration key in model context;
- no storefront availability dependency.

## 4. Authentication and authorization

Use enterprise-capable OAuth 2.1 or the current MCP-recommended authorization model for remote HTTP deployments. Local stdio development mode may use local process trust but must be clearly separated from production.

Scopes:

```text
search.read
search.diagnostics.read
search.analytics.read
search.config.read
search.config.write
search.index.plan
search.index.execute
search.index.activate
search.index.rollback
search.secrets.rotate
search.audit.read
```

Rules:

- least privilege;
- tenant and site binding;
- short-lived access tokens;
- audience and issuer validation;
- PKCE where applicable;
- no token forwarding to downstream services;
- protected refresh-token storage;
- revocation;
- operator identity in audit records;
- stronger authorization for activation, rollback, deletion, and secret rotation.

The MCP server maps caller scopes to WordPress control capabilities. It does not trust a tool argument that claims a role.

## 5. Site registration

A site registration stores:

```text
tenant_id
site_id
display_name
environment
control_api_base
credential_reference
allowed_operations
certificate_or_tls_policy
last_verified_at
status
```

Credentials are held in a proper secret store. They are never written to a model-visible resource, tool result, log, analytics event, or configuration export.

Registration requires a handshake that verifies:

- site identity and installation UUID;
- TLS;
- plugin version;
- control-contract version;
- requested scopes;
- current environment;
- nonce or challenge;
- operator authorization.

Production and staging registrations are distinct.

## 6. WordPress control REST API

Use OpenAPI 3.1 and versioned JSON contracts. Suggested protected endpoints:

```text
GET  /wp-json/starfiniti-search/v1/control/status
GET  /wp-json/starfiniti-search/v1/control/capabilities
GET  /wp-json/starfiniti-search/v1/control/configuration
GET  /wp-json/starfiniti-search/v1/control/schema
GET  /wp-json/starfiniti-search/v1/control/operations
GET  /wp-json/starfiniti-search/v1/control/operations/{id}
GET  /wp-json/starfiniti-search/v1/control/diagnostics
POST /wp-json/starfiniti-search/v1/control/search/test
POST /wp-json/starfiniti-search/v1/control/providers/validate
POST /wp-json/starfiniti-search/v1/control/configuration/plan
POST /wp-json/starfiniti-search/v1/control/configuration/apply
POST /wp-json/starfiniti-search/v1/control/index/plan
POST /wp-json/starfiniti-search/v1/control/index/start
POST /wp-json/starfiniti-search/v1/control/index/verify
POST /wp-json/starfiniti-search/v1/control/index/activate
POST /wp-json/starfiniti-search/v1/control/index/rollback
POST /wp-json/starfiniti-search/v1/control/synonyms/plan
POST /wp-json/starfiniti-search/v1/control/curations/plan
POST /wp-json/starfiniti-search/v1/control/secrets/rotation/plan
```

Every mutating call requires:

- operation type;
- idempotency key;
- expected configuration or active-index revision;
- dry-run flag;
- reason;
- caller identity;
- approval token when required.

Responses return operation plans and safe state, never credentials.

## 7. Resources

MCP resources are read-only context surfaces.

Suggested URIs:

```text
search://tenants/{tenant}/sites/{site}/status
search://tenants/{tenant}/sites/{site}/capabilities
search://tenants/{tenant}/sites/{site}/configuration
search://tenants/{tenant}/sites/{site}/schema
search://tenants/{tenant}/sites/{site}/index-status
search://tenants/{tenant}/sites/{site}/sync-errors
search://tenants/{tenant}/sites/{site}/relevance-tests
search://tenants/{tenant}/sites/{site}/analytics-summary
search://tenants/{tenant}/sites/{site}/audit
search://tenants/{tenant}/sites/{site}/operations/{operation}
```

Resource output:

- is size-bounded and paginated;
- carries freshness timestamp and contract version;
- redacts secrets and sensitive raw queries;
- respects caller scopes and site permissions;
- distinguishes observed state from desired state;
- provides links or IDs for follow-up tools, not arbitrary URLs.

## 8. Read-only tools

Implement narrowly defined tools such as:

```text
search_list_sites
search_get_status
search_get_capabilities
search_get_configuration
search_validate_configuration
search_test_query
search_compare_providers
search_inspect_document
search_inspect_schema
search_get_operation
search_list_sync_failures
search_export_diagnostics
search_get_analytics_summary
search_run_relevance_suite
```

`search_test_query` accepts a safe canonical request and returns redacted normalized results. It must not allow an LLM to bypass visibility scopes.

`search_compare_providers` runs only against providers already authorized for the selected site. It returns overlap, rank differences, latency, warnings, and quality-test impact. It does not automatically switch providers.

## 9. Planning tools

Planning is read-like but may perform safe probes.

```text
search_plan_configuration_change
search_plan_provider_migration
search_plan_full_reindex
search_plan_index_activation
search_plan_rollback
search_plan_synonym_change
search_plan_curation_change
search_plan_secret_rotation
search_plan_reconciliation
```

Every plan returns:

```json
{
  "operation_id": "uuid",
  "plan_hash": "sha256",
  "dry_run": true,
  "current_state": {},
  "desired_state": {},
  "steps": [],
  "risks": [],
  "preconditions": [],
  "estimated_impact": {},
  "verification": [],
  "rollback": {},
  "required_scope": "search.index.activate",
  "approval_required": true,
  "expires_at": ""
}
```

A plan is immutable. Execution references its ID and hash.

## 10. Execution tools

Mutating tools are separate and explicit:

```text
search_start_reindex
search_pause_operation
search_resume_operation
search_cancel_candidate_build
search_verify_candidate
search_activate_candidate
search_rollback_active_index
search_apply_configuration_revision
search_apply_synonym_revision
search_apply_curation_revision
search_retry_sync_failure
search_start_reconciliation
search_rotate_secret
```

Prohibited:

```text
execute_arbitrary_typesense_request
execute_wordpress_rest
run_sql
run_php
run_shell
set_option
delete_collection_by_name
```

The execution tool cannot change the plan. If actual state changed, preconditions fail and a new plan is required.

## 11. Human approval

High-impact operations require human approval:

- switching active provider;
- activating a new index;
- rolling back;
- deleting a retained index or collection;
- changing visibility or scope policy;
- changing a search key exposed to browsers;
- rotating write or provisioning credentials;
- enabling private-network endpoints;
- purging analytics or audit data;
- complete uninstall cleanup.

Approval may be represented by a short-lived signed token bound to:

```text
tenant
site
operation_id
plan_hash
action
approver
expiration
```

The model cannot mint or modify approval. A tool result states `approval_required` and the user-facing client handles confirmation.

Low-risk operations such as deep health checks or test-query comparisons may execute without separate approval when scope permits.

## 12. Dry-run and idempotency

Every mutating operation supports dry-run.

Idempotency keys are stored with operation results. Repeating the same request:

- returns the existing operation;
- does not create duplicate collections, jobs, synonyms, or keys;
- detects a changed payload under the same key and rejects it;
- remains safe after network timeout.

## 13. Deterministic operation sequence

The MCP server may not rely on a model to call tools in a magical order. Each tool validates prerequisites. Where a workflow must be ordered, expose operation state and next legal actions.

Example:

```text
plan -> start candidate -> wait/inspect -> verify -> request approval -> activate
```

Calling `activate` before successful verification returns a typed precondition failure.

Long-running operations return an operation ID. Status is polled or delivered through supported progress notifications. No tool invocation remains open indefinitely.

## 14. Search catalog tools for commerce agents

A separate read-only profile may expose:

```text
catalog_search_products
catalog_get_product
catalog_get_variations
catalog_list_facets
catalog_compare_products
catalog_get_related_products
catalog_check_availability
```

These tools call `SearchGateway` and WooCommerce hydration with the caller's authorized scope. They work with local or Typesense without exposing provider details.

Rules:

- no write operations;
- no hidden or restricted products;
- current price and availability revalidated;
- bounded result counts;
- clear currency and locale;
- no invented product claims;
- traceable result IDs and URLs;
- optional separation into another MCP server or scope set.

## 15. Prompt-injection and untrusted data

Product titles, descriptions, metadata, and diagnostics are untrusted data. They may contain text instructing an AI to ignore policy.

MCP responses must:

- structure catalog data as data, not instructions;
- mark untrusted content;
- cap text length;
- omit HTML and scripts;
- never let product text select tools or scopes;
- keep authorization and operation policy outside model-controllable fields;
- avoid placing secrets near model context;
- require deterministic validation regardless of model output.

## 16. Audit

Every MCP interaction records:

```text
timestamp
tenant
site
caller identity
client identity
tool
scope
arguments hash
safe argument summary
operation ID
result code
approval identity
correlation ID
duration
```

Do not store access tokens, secrets, full sensitive queries, or unrestricted product documents.

Audit reads are paginated and protected. Audit retention is configurable but destructive audit purge requires strong approval and a separate record where policy allows.

## 17. MCP testing

Mandatory:

- protocol negotiation;
- schema validation;
- OAuth happy and failure paths;
- tenant isolation;
- site isolation;
- scope enforcement per tool and resource;
- token audience, issuer, expiration, and revocation;
- approval binding;
- idempotency;
- changed-state precondition failure;
- secret-redaction property tests;
- prompt-injection fixtures in product data;
- malformed downstream response;
- WordPress unavailable;
- Typesense unavailable;
- long-running operation status;
- cancellation;
- rate limiting;
- replay attempts;
- no arbitrary URL or tool injection;
- compatibility against the pinned MCP SDK and protocol version.

## 18. MCP requirements

- `MCP-001 MUST`: MCP is outside the storefront query dependency chain.
- `MCP-002 MUST`: tools call deterministic versioned control APIs.
- `MCP-003 MUST`: remote production auth uses the current secure MCP authorization model with least privilege.
- `MCP-004 MUST`: resources and tools enforce tenant, site, and scope isolation.
- `MCP-005 MUST`: no generic HTTP, SQL, shell, or raw provider tool exists.
- `MCP-006 MUST`: all high-impact writes use immutable plans, dry-run, preconditions, and human approval.
- `MCP-007 MUST`: operations are idempotent and resumable through operation IDs.
- `MCP-008 MUST`: secrets never enter model-visible content.
- `MCP-009 MUST`: catalog data is treated as untrusted data against prompt injection.
- `MCP-010 MUST`: MCP audit records identify caller, tool, operation, approval, and result safely.
