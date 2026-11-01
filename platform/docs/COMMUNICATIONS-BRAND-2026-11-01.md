# Delnavazan unified communications brand and typography

Date: 2026-11-01 · Applies to the public website, both portals, transactional email, portal
notifications, student receipts, teacher statements and onboarding copy.

## 1. Canonical public identity

The single source of truth is `platform/src/Core/Support/BrandIdentity.php`. Theme templates read it
through `dzn_theme_brand_identity()`; renderers read it directly. The authenticated mail sender is
deliberately not part of this identity: `no-reply@delnavazan.com`, its SMTP credentials, Return-Path
and provider-approved domain configuration remain unchanged in integration settings.

| Item | Canonical value |
|---|---|
| Persian brand name | دلنوازان |
| English brand name | Delnavazan |
| Phone | `+61413413004` (`tel:+61413413004`) |
| WhatsApp | `+61413413004` (`https://wa.me/61413413004`) |
| Public support email | `admin@delnavazan.com` (`mailto:admin@delnavazan.com`) |
| Website | `https://delnavazan.com` |
| Greeting | درود |
| Tagline | موسیقی ایرانی؛ برای ایرانیان دور از وطن |
| Instagram (preserved) | `https://www.instagram.com/insta.delnavazan/` |

The display form of the number is `+61 413 413 004`. Machine-readable values are isolated with
`dir="ltr"` / `unicode-bidi: isolate` in RTL copy.

## 2. Colour tokens

The visual authority is `theme/assets/css/theme.css`. The renderer projection is
`platform/src/Core/Support/CommunicationDesignTokens.php`; the contract test
`platform/tests/communications-brand-contract.php` fails if the two drift.

| Role | Token | Value |
|---|---|---|
| Page / background | `--dzn-color-background` | `#FBF8F2` |
| Content surface | `--dzn-color-surface-elevated` | `#FFFFFF` |
| Secondary surface | `--dzn-color-surface` | `#F2ECE1` |
| Primary text | `--dzn-color-text-primary` | `#252726` |
| Supporting text | `--dzn-color-text-muted` | `#5F625F` |
| Deep brand / headings | `--dzn-color-text-secondary` | `#104A4D` |
| Primary action | `--dzn-color-action-primary` | `#1E7B70` |
| Primary action hover | `--dzn-color-action-primary-hover` | `#104A4D` |
| Secondary action | `--dzn-color-action-secondary` | `#315C82` |
| Border | `--dzn-color-border` | `#DDD6CA` |
| Strong border | `--dzn-color-border-strong` | `#C9C0B0` |
| Pomegranate accent | `--dzn-color-accent` | `#A33E48` |
| Gold accent | `--dzn-color-highlight` | `#D4AB4F` |
| Gold ink | `--dzn-color-highlight-ink` | `#6F4F10` |
| Success | `--dzn-color-success` | `#2F6B4F` |
| Attention / pending | `--dzn-color-warning` | `#945B16` |
| Error | `--dzn-color-error` | `#A33A3A` |
| Information | `--dzn-color-information` | `#315C82` |
| Focus | `--dzn-color-focus` | `#315C82` |

Status surfaces and borders are semantic tokens (`*-surface`, `*-border`) and are always paired with a
text label, never colour alone.

Measured contrast (WCAG 2.1):

| Pair | Ratio |
|---|---|
| Primary text on ivory | 14.18:1 |
| Supporting text on ivory | 5.83:1 |
| Supporting text on white | 6.18:1 |
| White on primary action | 5.09:1 |
| White on deep teal | 9.96:1 |
| Pomegranate on ivory | 5.93:1 |
| Error on error surface | 5.66:1 |
| Warning on warning surface | 5.05:1 |
| Success on success surface | 5.56:1 |
| Information on information surface | 6.25:1 |
| Gold on deep teal | 4.62:1 (large text / decoration) |
| Gold on ivory | 2.03:1 — **not used for text** |

Gold remains an accent for borders, highlights and restrained decoration. It is never used as body
text on ivory and never as the only cue for status.

### Corrections made

- `--dzn-rgb-gold` was `185 142 55` (`#B98E37`), a second gold; it now matches `#D4AB4F`. The one
  remaining literal gold shadow now uses the token.
- The notification admin screens used `#0E8C8C`, `#B23A48`, `#FBF7EE` and `Tahoma`; these now use the
  canonical palette and Vazirmatn stack.
- `admin.css` strong border drifted to `#C9BEAE`; it is now `#C9C0B0`.
- The editor palette now exposes the previously missing surface, border, gold-ink and status tokens.
- Inverted `clamp()` bounds in the story heading tokens were corrected.
- A `#5D6B6B` muted-text fallback that differed from `#5F625F` was corrected.

## 3. Persian typography

The bundled font is `theme/assets/fonts/Vazirmatn-Variable.woff2` (SIL Open Font Licence, OFL.txt in
the same directory), declared as a variable font with `font-weight: 400 900`.

| Role | Specification |
|---|---|
| Primary family | `Vazirmatn` |
| Screen stack | `Vazirmatn, Tahoma, Arial, sans-serif` |
| Email stack | `Vazirmatn, Tahoma, 'Segoe UI', Arial, sans-serif` |
| Body | 16px, line-height 1.8 |
| Supporting text | 14px minimum (`--dzn-text-small: .875rem`) |
| Page heading | 28–32px desktop (`clamp(1.75rem, 1.2rem + 1.6vw, 2rem)`) |
| Section heading | 20–24px (`clamp(1.25rem, 1.1rem + .5vw, 1.5rem)`) |
| Button label | 15px, weight 750 (`--dzn-text-button`) |
| Financial amount | 20px (`--dzn-text-financial`) |
| Financial total | 24px (`--dzn-text-financial-total`) |

No letter-spacing is applied to Persian text. `font-synthesis-weight: none` prevents browser-generated
bold, so only the real 400–900 variable axis is used.

### Constraints, stated honestly

- Email clients cannot be forced to load a web font. The email stack is a deliberate fallback: the
  message remains readable and correctly structured with Tahoma or Arial.
- WhatsApp and SMS render in the recipient application’s own font and colour. Only wording, structure
  and link destinations are standardised there.
- Receipts and statements are HTML documents with print CSS; the repository has no PDF library. The
  document font is declared from the theme asset and prints as selectable, shaped RTL text in a
  browser. A server-side PDF pipeline is not claimed.
- Long Persian names wrap instead of clipping; tables use `vertical-align: top` and cells can wrap.
  Mixed LTR values (dates, references, emails, URLs, currency codes) are isolated.

## 4. Renderer architecture

- `platform/src/Integrations/Notifications/DelnavazanEmailRenderer.php` is the one email shell:
  600px content (640px for statements), table-based, RTL, inline styles, dark brand action, gold
  detail, canonical contact footer and a plain-text `AltBody`. `renderTrustedHtml()` exists only for
  server-built financial tables whose values are escaped by their own renderer.
- `TeacherStatementEmailRenderer` composes the same shell instead of maintaining a second palette.
- `StudentReceiptRenderer` and the statement document template derive CSS from
  `CommunicationDesignTokens`; they no longer declare private palettes.
- `theme/inc/brand.php` is the theme adapter; the fallback array is contract-tested against
  `BrandIdentity`.
- `TeacherInvitationDeliveryWorker` now sends through the branded shell while preserving the
  one-time-secret body and the dedicated invitation delivery authority.

## 5. Content rules applied

- Active customer-facing copy uses **درود**, not **سلام**. Historical messages, user-authored content
  and issued financial documents are not rewritten. The word **سلامت** is not a greeting and is left
  intact.
- Preferred terminology: هنرجو، مدرس، کلاس آشنایی، جلسهٔ جبرانی، تغییر زمان کلاس، پرتال هنرجو،
  پرتال مدرس، تمدید دوره.
- Proposal copy distinguishes proposed, accepted, confirmed, cancelled and expired. A proposed time is
  never described as confirmed.
- Amounts and currencies are copied from the recorded minor-unit value; templates never recalculate.
  A display preference never relabels AUD as another currency.
- Recipient-local times remain the existing canonical responsibility; the brand change does not alter
  UTC storage, timezone resolution or calendar preference.
