# ADR 0002: License and rebrand policy

- Status: Accepted with `LIC-002` follow-up
- Date: 2026-08-09

## Decision

License original Starfiniti distribution code under GPL-3.0-only for compatibility with the FiboFilters package's declared GPLv3 license and FiboSearch Free's GPLv2-or-later license. Preserve copyright and third-party notices. Remove upstream trademarks, logos, Freemius initialization, remote licensing, telemetry identifiers, and marketplace update endpoints from production code.

Names such as FiboFilters and FiboSearch may appear only in attribution, compatibility detection, migration UI, audit fixtures, and documentation that makes non-affiliation clear.

## Release guard

This decision does not close the library inventory. `LIC-002` blocks release until every bundled dependency has exact license evidence and the generated package contains the complete GPLv3 text and all required notices.

