# Prototype compatibility

Classify each prototype as:

- `compatible_now`: implementable by the current shared renderer and qualified contracts;
- `planned`: allowed by the product specification but not implemented or qualified;
- `incompatible`: violates canonical contracts, accessibility, visibility, security, or provider neutrality.

## Required surfaces and states

- Autocomplete: idle, focused empty, debouncing, loading, success, no results, degraded, error, closed.
- Discovery: loading, success, no results, error.
- Mobile overlay: open and closed at 360 px or narrower, plus a desktop viewport of at least 1024 px.
- Setup: not started, in progress, blocked, ready.
- Operations: healthy, degraded, building, verification required, stale, misconfigured, unavailable.
- Relevance: draft, preview, approved, active, retired.
- Analytics: disabled, loading, ready, empty, error.

## Storefront invariants

- Preserve normal WooCommerce search submission without JavaScript.
- Use one shared renderer across blocks, shortcodes, navigation, widgets, PHP, and headless initialization.
- Use canonical typed data bindings, safe text rendering, unique IDs, multiple instances, IME input, abortable requests, and late-response guards.
- Keep visibility/customer scope server-authoritative and non-removable. Never place provider administration keys or logic in the browser.
- Target WCAG 2.2 AA, localized status text, RTL, reduced motion, keyboard/touch parity, and focus containment only in the intentional mobile modal.

## Current component boundary

Current: product-title autocomplete links; stock/category filters; relevance/price/title sorting; bounded 12-card pages; canonical product links; text-only details; failed-image recovery; mobile overlay.

Planned/unqualified: multiple suggestion groups, search history, quick cart actions, variable-product selectors, provider-specific storefront controls, and broad theme certification.

For every component record its canonical source, render type, state, interaction, fallback, accessibility semantics, visibility boundary, and current/planned status. Validate the companion manifest and treat errors as design blockers. Warnings are explicit implementation or qualification work.
