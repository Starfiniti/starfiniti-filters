# Aggregate analytics contract

Starfiniti Search analytics is disabled by default. An immutable configuration revision must opt in and set a retention window from 1 through 365 inclusive UTC dates. The daily cleanup keeps today plus the preceding `retention_days - 1` dates.

## Collection scope and privacy

The built-in report is an unsampled aggregate within its stated scope: public search endpoint requests that reach provider execution while analytics is enabled. Rate-limited requests, validation rejections, and administrator relevance previews are excluded. Aggregate writes are deliberately non-critical and are not retried, so a database write failure can undercount traffic but cannot break search.

The durable dimensions are date, provider ID, immutable configuration revision, index generation, query-length bucket, and result-count bucket. The table stores only counters, latency sums, latency maxima, and latency observation counts. It never stores query text, IP addresses, users, sessions, product IDs, clicks, carts, customers, or orders.

## Metric dictionary

| Metric | Definition | Undefined case |
| --- | --- | --- |
| `searches` | Provider-executed public search attempts durably represented in the aggregate table. | Always an integer. |
| `successful_searches` | `searches - errors`. | Always an integer. |
| `no_result_searches` | Successful searches for which the provider returned zero results. | Always an integer. |
| `no_result_rate` | `no_result_searches / successful_searches`. | `null` when there are no successful searches. |
| `errors` | Provider executions that returned the generic search-unavailable response. | Always an integer. |
| `error_rate` | `errors / searches`. | `null` when there are no searches. |
| `latency_observations` | Searches with finite measured provider execution latency. | Always an integer. |
| `average_latency_ms` | Weighted arithmetic mean: `sum(latency_sum_ms) / sum(latency_observations)`. | `null` when there are no observations. |
| `maximum_latency_ms` | Largest measured provider execution latency. | `null` when there are no observations. |
| `latency_coverage_rate` | `latency_observations / searches`. | `null` when there are no searches. |

Rates are JSON ratios from 0 through 1, not percentages. A report combines latency using observation-weighted sums; it never averages pre-aggregated averages. Every time-series row includes provider ID, configuration revision, and index generation so operational changes remain visible.

## Interfaces and authorization

- WooCommerce > Starfiniti Search shows the 30-day report to callers with `starfiniti_search_view_analytics`.
- `GET /wp-json/starfiniti-search/v1/control/analytics?days=30` accepts 1 through 365 days, requires the same dedicated capability, and returns `Cache-Control: no-store`.
- `wp starfiniti-search status --deep --analytics --analytics-days=30` includes the report for trusted WP-CLI operators.
- General health/status responses omit analytics, even for callers with the health capability.

The response contains its contract version, policy, exact inclusive UTC window, sampling scope and known loss, privacy assertions, metric definitions, totals, contextual daily series, coarse query/result breakdowns, and a list of metrics that cannot be computed safely from the retained data.

## Deliberately unavailable metrics

Latency percentiles are not derived from sums and maxima because that would be mathematically invalid. Top/zero-result query text, click-through, add-to-cart, conversion, clicked rank, and session-exit reports are unavailable because the aggregate-only policy intentionally does not retain the query, identity, product, click, cart, session, or order linkage required to calculate them. Adding those reports requires a separately reviewed consent, minimization, retention, and privacy design; they must not be inferred from the current aggregates.
