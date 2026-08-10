# 01 Licensing and Upstream Strategy

## 1. Legal posture

This document is an engineering provenance policy, not a substitute for legal advice. The implementation must use only source and assets whose rights and license compatibility have been verified.

The public free FiboSearch plugin is distributed as open-source software through WordPress.org. The project must pin and inspect the actual archive used. Do not rely only on a website statement or an assumed WordPress convention. Record the exact license headers and bundled license files from the imported source.

Use `GPL-2.0-or-later` as the target plugin license unless a qualified review of the exact upstream source requires a different compatible choice.

## 2. What may be inherited

Subject to file-level license verification, the fork may preserve and modify useful parts of the official free release, including:

- storefront form and autocomplete behavior;
- mobile overlay behavior;
- details-panel behavior;
- blocks, shortcodes, widgets, menu integration, and PHP embedding APIs;
- settings and personalization behavior;
- public extension hooks;
- theme and builder integrations;
- multilingual compatibility;
- WooCommerce search-results-page integration;
- public analytics behavior;
- templates, styles, scripts, and images whose licenses are compatible.

Every retained file must preserve existing copyright notices. Modified files must add a Starfiniti modification notice without erasing upstream authorship.

## 3. What must not be copied by default

Do not copy, decompile, reconstruct, extract, or import:

- FiboSearch Pro source or compiled assets;
- premium-only archives obtained through an account, customer site, cache, backup, or third party;
- private APIs, credentials, licensing data, customer data, or telemetry data;
- upstream trademarks, logos, branded illustrations, screenshots, or marketing copy unless separately licensed;
- premium behavior by reproducing non-public implementation details observed from code;
- proprietary support content beyond factual interoperability needs.

A merchant having a paid copy does not automatically establish clean provenance for a public fork. The local inverted index must be an independent implementation based on this specification, search-engine literature, public platform APIs, and internally created tests.

Comparable functionality is allowed to be designed independently. Names, class structures, algorithms, schemas, and code must be ours.

## 4. Mandatory upstream record

Create `UPSTREAM.md` with:

```yaml
project: FiboSearch - Ajax Search for WooCommerce
source_kind: official-wordpress-plugin-directory
source_url: <official source URL>
version: <exact version>
release_date: <exact date>
svn_revision_or_commit: <value if available>
archive_sha256: <sha256>
license_expression: <verified SPDX expression>
import_date_utc: <timestamp>
imported_by: <name or automation identity>
baseline_tag: <git tag>
```

Also include:

- a list of license and notice files;
- a list of upstream authors and copyright holders found in source;
- bundled third-party dependencies and their licenses;
- original and new file counts;
- the branch and procedure used to import future security fixes;
- a chronological log of upstream merges or cherry-picks.

`UPSTREAM.md` is immutable history. Corrections are appended with explanation rather than rewriting provenance.

## 5. Branch and update model

Use:

```text
upstream-fibosearch     immutable imports of official free releases
main                    Starfiniti product
release/*               stabilization only
security/*              embargoed security work where required
```

For each upstream update:

1. Import the exact archive into `upstream-fibosearch`.
2. Verify checksum and license inventory.
3. Tag it.
4. Diff it against the previous upstream baseline.
5. Classify security, compatibility, UX, and commercial-only changes.
6. Port relevant fixes through reviewed commits into `main`.
7. Run fork regression, provider conformance, security, and packaging tests.
8. Update `UPSTREAM.md`.

Do not blindly merge upstream into `main` after architecture divergence.

## 6. Branding and trademark separation

Before the first Starfiniti build:

- rename plugin title, slug, namespace, text domain, blocks, settings labels, menu labels, option prefixes, REST namespace, handles, CSS classes where safe, and JavaScript globals;
- remove FiboSearch logos, icons, screenshots, support links, account links, purchase links, review prompts, and promotional copy;
- remove “Fibo”, “FiboSearch”, “Ajax Search for WooCommerce” branding from user-facing product identity;
- retain factual attribution only in `NOTICE`, `UPSTREAM.md`, source headers, and a modest “About and licenses” administration page;
- do not imply endorsement, continuation, official compatibility, partnership, or upgrade lineage;
- use a unique icon and plugin-directory artwork created for Starfiniti.

Compatibility aliases for old shortcode names or hooks may exist during migration, but must be documented as deprecated technical aliases, not brand usage.

## 7. Freemius, telemetry, and commercial code removal

The baseline audit must identify and remove:

- Freemius SDK files and bootstrap;
- license checks;
- paid-plan gates;
- account and upgrade screens;
- affiliate parameters;
- outbound commercial notices;
- telemetry not essential to an explicitly configured service;
- remote calls not required for plugin operation;
- premium placeholders;
- support-ticket integrations tied to the upstream vendor.

Removal requires regression tests so that no hidden dependency causes activation errors, notices, cron failures, or broken settings.

Starfiniti telemetry must be absent by default. A future opt-in product-improvement program must be separately specified, explicit, documented, revocable, and data-minimized.

## 8. Copyright and notice policy

Create:

- `LICENSE`, containing the full applicable GPL text;
- `NOTICE`, naming the upstream project and authors and describing substantial modifications;
- `THIRD_PARTY_NOTICES.md`, generated from the dependency inventory;
- SPDX headers in new source files;
- preserved upstream headers in inherited files;
- a machine-readable SBOM for release artifacts.

Recommended new-file header:

```text
SPDX-License-Identifier: GPL-2.0-or-later
Copyright (C) <year> Starfiniti d.o.o.
```

For inherited modified files, retain existing notices and add:

```text
Modified by Starfiniti d.o.o. for Starfiniti Search.
See UPSTREAM.md and NOTICE for provenance.
```

## 9. WordPress.org distribution constraints

When preparing a WordPress.org version:

- all distributed plugin code, data, images, and libraries must use GPL-compatible licensing;
- human-readable source and build instructions must be publicly available;
- no trialware behavior may disable included functionality after a period or quota;
- external service functionality must be substantial, clearly documented, and consensually configured;
- the plugin must not contact external servers before an administrator configures and enables that provider;
- no executable JavaScript or CSS may be loaded remotely unless it is an essential documented part of a service and allowed by directory policy;
- public branding must be distinct and must not present upstream work as original;
- minified assets require maintained source and reproducible build instructions;
- plugin-directory submission must be reviewed against the current guidelines at release time, not only the guidelines that existed when development started.

A separate enterprise distribution may include service integrations, but the plugin code remains under its declared GPL-compatible license.

## 10. Provenance release gate

Requirements:

- `LIC-001 MUST`: exact official free upstream is pinned and checksummed.
- `LIC-002 MUST`: actual file-level license inventory has no unknown or incompatible production artifact.
- `LIC-003 MUST`: FiboSearch Pro code is absent.
- `LIC-004 MUST`: upstream copyright notices are preserved.
- `LIC-005 MUST`: user-facing branding is independent.
- `LIC-006 MUST`: Freemius and upstream commercial endpoints are removed.
- `LIC-007 MUST`: source/build instructions reproduce distributed assets.
- `LIC-008 MUST`: release SBOM and third-party notices match the package.
- `LIC-009 MUST`: a current WordPress.org policy review is documented before submission.
- `LIC-010 MUST`: an automated scan fails CI when prohibited upstream domains, marks, credentials, or unapproved binaries enter the release package.

No feature gate may be called complete until the initial provenance gate passes.
