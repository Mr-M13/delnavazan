# Footer and homepage pricing surface restoration (29 October 2026)

## Symptom

- The footer rendered on ivory instead of the dark turquoise surface.
- The homepage pricing finale rendered on ivory; its price copy and secondary
  text were nearly invisible.

## Root cause

Four design tokens in `theme.css` `@layer tokens` had been rewritten into
self-references:

```css
--dzn-color-surface-elevated: var(--dzn-color-on-action);
--dzn-color-text-secondary: var(--dzn-color-text-secondary);
--dzn-color-action-primary-hover: var(--dzn-color-text-secondary);
--dzn-color-on-action: var(--dzn-color-on-action);
--dzn-color-on-dark: var(--dzn-color-on-dark);
```

A custom property that references itself resolves to the guaranteed-invalid
value. `--dzn-color-text-secondary` therefore resolved to transparent, which is
exactly the surface used by both affected sections. The same corruption also
emptied `on-dark`, `on-action` and `surface-elevated`.

No markup or block-style override was involved.

## Active selectors

| Section | Selector | Background |
|---|---|---|
| Footer | `.site-footer` | `var(--dzn-color-text-secondary)` |
| Pricing finale | `.dzn-story__finale` | two radial gradients + `var(--dzn-color-text-secondary)` |
| Price ledger | `.dzn-story__price .dzn-price-ledger` | intentionally transparent so the finale shows through |

The historical `.dzn-pricing` selector is not present on the current homepage.
The price information is rendered by `.dzn-story__price` and
`.dzn-price-ledger` inside `.dzn-story__finale`.

## Fix

Restored the original token values only:

```css
--dzn-color-surface-elevated: #ffffff;
--dzn-color-text-secondary: #104a4d;
--dzn-color-action-primary-hover: #104a4d;
--dzn-color-on-action: #ffffff;
--dzn-color-on-dark: #fffdf8;
```

No selector, layout, portal component or business rule changed. Restoring
`on-dark`/`on-action` also restores readable text, buttons, links and focus
rings on the turquoise surface. Contrast against `#104a4d`:

| Foreground | Ratio |
|---|---:|
| `#ffffff` | 9.96 |
| `#fffdf8` | 9.80 |
| `#d4ab4f` saffron price | 4.62 |

## Regression test

`platform/tests/homepage-footer-pricing-background-contract.php` checks:

- the active footer and finale selectors and their background token;
- the finale’s radial gradients and on-dark text;
- the transparent price ledger;
- the original token values and no self-referential token;
- AA contrast on the dark turquoise surface.

The test is wired into the PR runtime gate.

## Visual evidence

`platform/docs/evidence/css-audit-2026-10-29/footer-pricing-2026-10-29/`
contains before/after comparisons at 320, 390, 768 and 1440 px for both
sections, plus the computed-style JSON captured from production.

Before: both sections `rgba(0, 0, 0, 0)` with body-colour text.
After: both sections `rgb(16, 74, 77)`; footer text white; pricing text
`#fffdf8` with the saffron amount.

## Verification

- Actual homepage checked in production at 320, 390, 768 and 1440 px, not just a
  synthetic template.
- Public homepage, booking and operations pages: no horizontal overflow at
  320–1440 px.
- Teacher/student presentation harness sweep: all pages pass at 320–1440 px with
  no duplicate ids, unnamed controls or missing alt text.
- CSS architecture, public hardening, responsive-shell and the new footer/pricing
  contract pass.
- Existing CSS bytes are unchanged apart from the five token values; no portal
  or booking stylesheet was edited.

## Release

| Item | Value |
|---|---|
| PR | [#425](https://github.com/Mr-M13/delnavazan/pull/425) |
| Merge SHA | `45dbd2d0630795a5586c40234db61e1f4cfa60fd` |
| First deploy containing the fix | `e07b98cdaa37fc6e981dc54991bcaccc9a55e4df`, run `40837789338` |
| Current deployed SHA | `ab5a4ac2a1ff878e6b666e66d2c369a2a5405b90` |
| Current build | `main-ab5a4ac2a1ff`, schema 54 |
| Current deploy run | `40838148666` — smoke passed |

A dispatch at `45dbd2d` was correctly refused because `main` advanced before the
deploy started; the exact-current-main gate was not bypassed. The subsequent
`e07b98c` and `ab5a4ac` deploys contain the fix; `ab5a4ac` is docs-only on top.
