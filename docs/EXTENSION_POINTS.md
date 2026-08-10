# Supported extension points

Starfiniti Search exposes a deliberately small extension surface. Internal classes, database tables, generated collection names, and provider-native payloads are not compatibility APIs.

## Search provider port

Contract version: `1.0`

Providers implement `Starfiniti\Search\Domain\Provider\SearchProvider`:

- `id()` returns a stable lowercase provider identifier.
- `capabilities()` returns the versioned provider-capabilities contract and explicit native, emulated, degraded, unavailable, or prohibited states.
- `search()` accepts the canonical bounded search request and returns the canonical search response. Provider-native query strings and HTML never cross this boundary.

The local and Typesense adapters are independent implementations. Storefront code consumes only the canonical REST/render contracts and must never branch on a concrete provider class or provider ID.

Any new provider must add provider-specific tests, pass the common JSON Schema and visibility contracts, document exact certified versions/environments, and pass activation/rollback qualification before it can be declared production-capable.

## Storefront integration registry

Contract version: `1.0`

Theme and builder compatibility is declared in `plugin/starfiniti-search/config/integrations.json` and exposed by `IntegrationRegistry`. Each entry records detection, owned assets, DOM strategy, limitations, evidence, verification date, and certification state. Integrations place the shared renderer through block, shortcode, widget, PHP, or headless APIs; they do not rewrite unrelated theme forms.

## Control API

Contract version: `1.0`

Automation and any future MCP service use the deterministic Starfiniti control REST resources documented in `docs/CONTROL_API.md`. High-impact changes require immutable plans, matching hashes, preconditions, explicit approval identity, idempotency keys, and safe audit records. Generic HTTP, SQL, shell, and raw-provider tools are prohibited.

## Compatibility policy

Changing a contract requires a new version and migration/compatibility tests. Removing or reinterpreting a published field within version `1.0` is prohibited. The architecture, JSON Schema, storefront, control, provider-specific, and installed-artifact tests are the executable compatibility gate.
