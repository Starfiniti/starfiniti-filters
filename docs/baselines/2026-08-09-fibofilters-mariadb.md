# FiboFilters 1.12.1 real-MariaDB baseline

## Result

The proprietary upstream installs and boots on the pinned PHP 8.3.28, WordPress 7.0.2, WooCommerce 10.7.0, and MariaDB 11.4.10 localhost. Its full administrative setup and meaningful filter-index capture cannot proceed without a vendor license key. The license control was not bypassed.

## Reproducible evidence

- Site: `http://127.0.0.1:8089` with isolated database `starfiniti_filters_baseline`.
- Plugin status: active, version 1.12.1.
- Package SHA-256: `3E8FEFBFE1C1FBA3126E691F67F2D1C5437E39AC33D5AB6DE44BD912456355D2`.
- Admin menu and setup page load without a PHP or browser-console error.
- Setup page requires a Freemius license key before configuration.
- The deterministic catalog contains seven product/product-variation posts.
- Upstream created `wp_fibofilters_storage`; it contains `filters` and `indexer_last_build_logs_tmp` rows.
- Upstream status options report version 1.12.1, storage schema 1, build status `completed`, and zero indexed products.
- Anonymous `GET /wp-json/fibofilters/v1/filters/descriptors` returns HTTP 200 with `[]`.
- Anonymous `GET /wp-json/fibofilters/v1/index/status` returns HTTP 401.
- The `[fibofilters]` shortcode emits no interactive filter controls before licensed configuration; the WooCommerce product grid still renders its visible catalog.

## Boundary

This baseline proves runtime compatibility, storage creation, authorization behavior, and the precise license-gated boundary. It does not prove filter semantics, URL parity, pagination/sorting integration, reindex interruption, or rollback behavior. Those captures require a legitimately licensed upstream test installation. Starfiniti production tests remain independent and do not depend on the proprietary runtime.
