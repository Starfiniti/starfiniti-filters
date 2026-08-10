# 2026-08-09 Playground baseline

## Result

The pinned WordPress localhost boots and the deterministic WooCommerce fixture is reproducible. FiboFilters activates, but cannot build its index on Playground's SQLite compatibility layer. This is a validated environment limitation and an actionable upstream compatibility finding, not a passed golden baseline.

## Controls

- WordPress 7.0.3, PHP 8.3, WooCommerce 10.7.0.
- WordPress Playground CLI 3.1.48 with one reviewed native install script allowlisted.
- Upstream package and extracted-tree hashes passed twice byte-for-byte.
- Search specification `MANIFEST.sha256` passed for every listed file.
- WordPress-internal networking disabled in both blueprints.
- Target upstream is mounted but left inactive until an explicit browser activation step.

## Deterministic catalog assertions before FiboFilters activation

| Assertion | Result |
| --- | --- |
| Fixture page returns HTTP 200 | Pass |
| Visible Unicode and ordinary products render | Pass |
| Private product is absent | Pass |
| Catalog-hidden product is absent | Pass |
| Password-protected product is present in generic WooCommerce product grid | Observed; search-provider exclusion still required |
| Inactive `[fibofilters]` shortcode remains literal | Pass as pre-activation control |

## FiboFilters activation

- Activation completed and redirected to `wp-admin/admin.php?page=fibofilters`.
- Browser console recorded no upstream JavaScript exception; a Chrome transition-abort diagnostic was observed during slow navigation.
- The admin page emitted repeated database errors from `FiboFilters\Indexer\BuildInfo::get_info()`.
- Failing statement: `SELECT SQL_NO_CACHE option_value FROM wp_options WHERE option_name = 'fibofilters_indexer_last_build_status_tmp'`.
- SQLite bridge result: `SQLSTATE[HY000]: General error: 1 no such column: SQL_NO_CACHE`.
- Public `GET /wp-json/fibofilters/v1/filters/descriptors` returned HTTP 200 with `[]`.
- Anonymous `GET /wp-json/fibofilters/v1/index/status` returned HTTP 401, which is the expected authorization boundary.
- Storefront product visibility controls still held after activation.
- FiboFilters rendered its vertical skeleton but did not replace it with interactive filters because the index was unavailable.

## Consequence

`BASE-001` remains open. The real golden suite must run on MySQL/MariaDB; using SQLite would hide or distort the upstream behavior being preserved. The Playground harness remains useful for activation, admin, renderer, and FiboSearch Free baselines that do not require the FiboFilters index.

## FiboSearch Free 1.34.0 anonymous storefront baseline

FiboSearch Free was activated by the reviewable search blueprint and tested in Chrome against the same deterministic catalog.

| Assertion | Result |
| --- | --- |
| Search form has native `search` landmark and labeled `searchbox` | Pass |
| Product-name query `Blue Alpine` returns `Blue Alpine Shirt` | Pass |
| Exact accented query `Črna Kava` returns `Črna Kava 500 g` | Pass |
| Exact SKU query `EXACT-001` | Fail in this SQLite baseline: `No results` |
| Accent-folded query `Crna Kava` | Fail in this baseline: `No results` |
| Password-protected product query | Pass: `No results` |
| Catalog-hidden product query | Pass: `No results` |
| Private product query | Pass: `No results` |
| Out-of-stock product-name query | Pass: `Red Trail Shirt` |
| Unknown query | Pass: explicit `No results` state |
| Mobile 375×812 layout | Pass: input and suggestions visible, 300 px wide, no horizontal overflow |
| Browser console | Pass: no FiboSearch warning/error recorded during query checks |

### Accessibility gap retained only as evidence

The input has native searchbox semantics and a visible label, but the live suggestions expose no `aria-controls`, `aria-expanded`, `aria-autocomplete`, listbox/option roles, or `aria-selected` state. Arrow Down did not select the suggestion and Escape did not close it in Chrome. Starfiniti must not preserve this behavior; the production renderer must implement and test the WAI-ARIA combobox pattern and full keyboard lifecycle.

### Interpretation

The exact-SKU and accent-folding failures are not accepted Starfiniti requirements. They may be SQLite/provider behavior or upstream defaults and must be rerun on MariaDB before root cause is assigned. The security visibility checks are valid anonymous evidence and match the 1.34.0 release intent.

## Evidence still required

- Screenshot and normalized DOM/network fixtures on MariaDB.
- Index build/cancel/rebuild and interrupted-worker captures.
- Filter URL, sorting, pagination, variation, multilingual, Blocks/FSE, and rollback fixtures.
- WooCommerce 10.9 and 11.0 forward-compatibility runs.
- FiboSearch Free keyboard, exact-SKU, password-protected visibility, and thumbnail-escaping checks.
