---
name: starfiniti-prototype-design
description: Design, annotate, or review Figma, FigJam, image, HTML, or written UX prototypes for Starfiniti Search for WooCommerce. Use when creating or evaluating autocomplete, product discovery, faceting, mobile search, setup, operations, relevance, analytics, theme integration, or other UI that must map to the Starfiniti plugin's canonical contracts, accessibility behavior, provider-neutral boundary, current implementation, and planned specification.
---

# Starfiniti Prototype Design

Use the repository specification and executable storefront contracts as authority. Produce visually useful designs without inventing a runtime contract the plugin cannot safely implement.

## Start every task

1. Confirm the working copy is the GitHub-backed clone and inspect `git status`.
2. Read `AGENTS.md`, then the focused source files listed in [references/compatibility-contract.md](references/compatibility-contract.md).
3. Classify the requested design as:
   - `compatible_now`: supported by the current qualified implementation;
   - `planned`: permitted by the binding specification but requires implementation and qualification;
   - `incompatible`: violates a security, accessibility, canonical-contract, visibility, or provider-neutral invariant.
4. Do not describe a prototype as production-ready, certified, or released. State the current evidence boundary.

## Design workflow

### 1. Define scope

Select only the surfaces in scope:

- `autocomplete`
- `discovery`
- `mobile-overlay`
- `admin-setup`
- `admin-operations`
- `admin-relevance`
- `admin-analytics`

State whether the design targets the current implementation or a planned extension. Never silently present a planned element as currently available.

### 2. Map components to plugin contracts

For every visible or interactive element, identify:

- canonical request field or response projection;
- semantic HTML/ARIA role;
- state and transition;
- keyboard, touch, and pointer behavior;
- no-JavaScript or failure fallback;
- current/planned status;
- server-side visibility or authorization boundary.

Use text segments and typed projection values. Never design around provider HTML, raw provider parameters, browser-held admin keys, removable visibility filters, or storefront branching on `local`, `typesense`, or any future provider ID.

### 3. Prototype behavior, not only happy screens

Create variants for every required state in the selected surface. Include keyboard paths, focus destination, live-region message, error recovery, mobile close behavior, URL/Back behavior where applicable, and multiple-instance behavior. Use the exact state and interaction vocabulary in the compatibility contract.

For Figma component naming, prefer:

```text
SFS/Search/Autocomplete
SFS/Search/MobileOverlay
SFS/Discovery/Controls
SFS/Discovery/ProductCard
SFS/Discovery/Pagination
SFS/Admin/Setup
SFS/Admin/Operations
SFS/Admin/Relevance
SFS/Admin/Analytics
```

Use variants such as `state=loading`, `state=no-results`, `mode=inline`, and `mode=mobile-overlay`. Add annotations `SFS-CURRENT`, `SFS-PLANNED`, or `SFS-PROHIBITED` when implementation status is material.

### 4. Create a compatibility manifest

Copy [assets/prototype-manifest.example.json](assets/prototype-manifest.example.json) beside the prototype notes and adapt it to the selected surfaces. Keep the manifest free of credentials, private catalog content, customer data, and raw live queries.

Validate it with:

```powershell
node .agents/skills/starfiniti-prototype-design/scripts/validate-prototype.mjs path/to/prototype-compatibility.json
```

Treat validator errors as design blockers. Treat warnings as explicit qualification or implementation work, not implicit approval.

### 5. Report the design result

Return these sections:

1. `Verdict`: compatible now, planned, or incompatible.
2. `Plugin mapping`: component/state to canonical contract and render surface.
3. `Blocking changes`: violations that must be redesigned.
4. `Implementation work`: planned capabilities, tests, and qualification needed.
5. `Prototype coverage`: viewports, states, keyboard/touch paths, fallbacks, and theme evidence.
6. `Manifest`: validation result and path.

## Non-negotiable decisions

- Preserve normal WooCommerce product-search submission without JavaScript.
- Use the shared renderer across shortcode, blocks, navigation, widget, PHP, and headless initialization.
- Treat catalog/provider text as untrusted data and render it as text, never executable HTML.
- Keep storefront behavior provider-neutral. Provider comparison belongs only in protected administrator workflows.
- Keep prices as integer minor units until localized presentation formatting.
- Make visibility and customer scope server-authoritative and non-removable.
- Make previous requests abortable and prevent late responses from replacing newer results.
- Support IME input, localized status messages, RTL, reduced motion, unique IDs, and multiple instances.
- Target WCAG 2.2 AA. Do not rely on hover; trap focus only in the intentional mobile modal.
- Use immutable plan/approve/execute flows for high-impact administrator changes.
- Show degraded, stale, unavailable, and verification-required states honestly; endpoint reachability alone is never “healthy.”

## Current boundary shortcuts

The current storefront supports product-title autocomplete links; stock/category discovery filters; relevance, price, and title sorting; 12-card bounded pages; text-only product cards; details disclosure; canonical product links; and a full-viewport overlay at 42 rem and below.

Treat suggestion groups, search history, quick add-to-cart, variable-product selection, rich provider-specific controls, and broad theme certification as planned unless current repository evidence proves otherwise. Read the full boundary before making a verdict.
