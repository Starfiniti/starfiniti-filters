# WordPress.org policy review

Review date: 2026-08-09

Artifact reviewed: Starfiniti Search `0.3.0-alpha.1`

## Outcome

The current source tree passes WordPress Plugin Check 2.0.0 with no errors or warnings. The review found no locally actionable WordPress.org policy blocker. This is engineering evidence, not approval by the WordPress.org Plugin Review Team.

## Authoritative sources

- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Common plugin submission issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
- [How the plugin readme works](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [Including a software license](https://developer.wordpress.org/plugins/plugin-basics/including-a-software-license/)
- [Official Plugin Check project](https://github.com/WordPress/plugin-check)

## Review record

| Area | Evidence | Result |
|---|---|---|
| GPL compatibility and license declaration | Root and plugin `LICENSE`; `License: GPL-3.0-only` in `readme.txt`; `package.json` license | Pass |
| Human-readable source | PHP, JavaScript, CSS, schemas, tests, and build scripts are present; no obfuscated runtime artifact | Pass |
| Upstream/proprietary separation | `UPSTREAM.md`, `NOTICE`, root and packaged third-party notices, SBOM, release prohibition scanner | Pass |
| Tracking and external services | Aggregate analytics are local, minimized, retention-controlled, and cannot break search; no unsolicited telemetry | Pass |
| Executable code and dependencies | No remote executable code, bundled vendor runtime, or unauthorized binary in the plugin artifact | Pass |
| Admin behavior | Capability and nonce checks protect writes; notices are scoped; no dashboard hijack, unsolicited credits, or promotional spam | Pass |
| Input/output safety | Public inputs are bounded and validated; admin and storefront output is contextually escaped; operational errors are redacted | Pass |
| WordPress libraries and APIs | WordPress APIs are used where an equivalent exists; narrowly documented direct queries target authoritative plugin index/control state | Pass |
| Readme/version metadata | Plugin header and stable tag are synchronized by the release verifier | Pass |
| Trademark/branding | Starfiniti branding is independent; upstream names are used only as factual compatibility/provenance references | Pass, subject to directory reviewer judgment |

## Reproducible Plugin Check command

Run inside the isolated WordPress test runtime after installing and activating the official Plugin Check plugin:

```powershell
php wp-cli.phar --path=<wordpress-path> plugin check starfiniti-search --require=<plugins-path>/plugin-check/cli.php --format=json
```

Recorded result with WordPress 7.0.2, WooCommerce 10.7.0, PHP 8.3.28, and Plugin Check 2.0.0:

```text
Success: Checks complete. No errors found.
```

## Submission-time actions

The following cannot be completed locally and remain release-owner actions:

1. Confirm the final public plugin slug, display name, and trademark position.
2. Submit the exact qualified ZIP to WordPress.org and answer any reviewer questions.
3. Re-run this review and Plugin Check against the exact release candidate if WordPress, WooCommerce, Plugin Check, or the artifact changes.
