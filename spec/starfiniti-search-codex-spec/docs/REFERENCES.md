# References Used to Form the Specification

These are research inputs, not a substitute for inspecting the exact source and current documentation during implementation. Codex must re-check current stable versions, licenses, breaking changes, and platform support before release.

## FiboSearch

- WordPress.org plugin page, source links, current free features, changelog, runtime requirements, and open-source statement:  
  https://wordpress.org/plugins/ajax-search-for-woocommerce/
- FiboSearch Free versus Pro comparison, including the statement that the free version uses WooCommerce linear search and Pro uses a custom inverted index:  
  https://fibosearch.com/should-i-go-pro-fibosearch-free-vs-pro/
- FiboSearch 2.0 architecture and reliability discussion:  
  https://fibosearch.com/fibosearch-2-0-is-on-the-way/
- FiboSearch indexing documentation for public behavior and lifecycle research only, not source copying:  
  https://fibosearch.com/documentation/developers/indexing/
- FiboSearch changelog:  
  https://fibosearch.com/changelog/

## WordPress licensing and plugin policy

- WordPress GPL license statement:  
  https://wordpress.org/about/license/
- Detailed Plugin Directory Guidelines:  
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Plugin Developer FAQ:  
  https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- Taking over or forking an existing plugin, attribution and identity guidance:  
  https://developer.wordpress.org/plugins/wordpress-org/take-over-an-existing-plugin/
- WordPress security, sanitization, escaping, REST, privacy, and database documentation under the Plugin Handbook:  
  https://developer.wordpress.org/plugins/

## WooCommerce

- WooCommerce extension development and best practices:  
  https://developer.woocommerce.com/
- CRUD and data-store principles:  
  https://developer.woocommerce.com/docs/best-practices/data-management/crud-objects
- Action Scheduler:  
  https://actionscheduler.org/
- WooCommerce Quality Insights Toolkit:  
  https://qit.woo.com/docs/
- WooCommerce testing guidance:  
  https://developer.woocommerce.com/docs/extensions/core-concepts/testing

## Typesense

- Documentation home and current API version:  
  https://typesense.org/docs/
- Search API:  
  https://typesense.org/docs/30.2/api/search.html
- Documents and bulk import:  
  https://typesense.org/docs/30.2/api/documents.html
- API keys and scoped keys:  
  https://typesense.org/docs/30.2/api/api-keys.html
- Collection aliases:  
  https://typesense.org/docs/30.2/api/collection-alias.html
- High availability guide:  
  https://typesense.org/docs/guide/high-availability.html
- Official API clients:  
  https://typesense.org/docs/guide/install-typesense.html#api-clients
- Typesense Cloud management API, if managed provisioning is later implemented:  
  https://cloud.typesense.org/docs/

## Meilisearch

- Documentation home:  
  https://www.meilisearch.com/docs/
- Index settings, including searchable, filterable, sortable attributes, ranking, typo tolerance, synonyms, and stop words:  
  https://www.meilisearch.com/docs/reference/api/settings
- API keys and security:  
  https://www.meilisearch.com/docs/learn/security/master_api_keys
- Tenant tokens:  
  https://www.meilisearch.com/docs/learn/security/tenant_tokens
- Index swap:  
  https://www.meilisearch.com/docs/reference/api/indexes/swap_indexes

## Model Context Protocol

- Current specification root:  
  https://modelcontextprotocol.io/specification/
- Server tools:  
  https://modelcontextprotocol.io/specification/2026-07-28/server/tools
- Server resources:  
  https://modelcontextprotocol.io/specification/2026-07-28/server/resources
- Authorization:  
  https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization
- TypeScript SDK:  
  https://github.com/modelcontextprotocol/typescript-sdk
- Security best practices:  
  https://modelcontextprotocol.io/docs/tutorials/security/security_best_practices

## Standards and engineering references

- WAI-ARIA Authoring Practices, combobox pattern:  
  https://www.w3.org/WAI/ARIA/apg/patterns/combobox/
- WCAG 2.2:  
  https://www.w3.org/TR/WCAG22/
- OpenAPI 3.1:  
  https://spec.openapis.org/oas/v3.1.0
- JSON Schema:  
  https://json-schema.org/
- SPDX license identifiers:  
  https://spdx.org/licenses/
- OWASP SSRF Prevention Cheat Sheet:  
  https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
- OWASP API Security Top 10:  
  https://owasp.org/www-project-api-security/
- OAuth 2.1 work and current security best current practices should be verified at implementation time.

## Research rule

For every external API and platform:

1. use current official primary documentation;
2. pin exact supported versions;
3. record the date and version reviewed;
4. add an integration test for any behavior relied upon;
5. do not use a blog post or remembered behavior as the production contract;
6. re-run the review before each major release.
