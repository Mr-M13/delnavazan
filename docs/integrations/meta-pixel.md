# Meta Pixel (Facebook & Instagram ads)

Browser-side Meta Pixel for the public marketing site. It is **off by default** and appears in
**Settings → Integrations → Meta Pixel** (protected route `dzn-integration-meta-pixel`).

## Scope

This phase prints Meta's standard base snippet and tracks:

| Event | When | Data |
| --- | --- | --- |
| `PageView` | Every eligible public page, by the base snippet | None |
| `Lead` | A successful public introductory booking request (`theme/assets/js/booking.js`) | `content_name=intro_booking_request`, stable `eventID` = booking request reference |

Deliberately **not** implemented:

- Advanced matching or any customer-information parameter (name, email, phone, country, IP, user agent).
- Server-side Conversions API delivery. That needs a business access token, customer-information
  parameters and a separate privacy review; it is a later phase.
- Attribution storage (`fbc`, `fbp`), offline events, value/currency, or historical backfill.

## Where the snippet prints

The plugin prints it from `wp_head` / `wp_body_open` only when **all** of these are true:

1. an operator enabled the integration and stored a valid pixel id (5–20 digits);
2. the request is not admin, AJAX, cron, REST/JSON, feed, preview, Customizer preview or robots;
3. the visitor is not signed in (portal and account pages are never tracked);
4. `home_url()` is a public host — default `delnavazan.com` and `www.delnavazan.com`,
   filterable with `dzn_meta_pixel_public_hosts`;
5. marketing consent is not denied — cookie `dzn_marketing_consent=denied`, or the
   `dzn_meta_pixel_consent_granted` filter returning `false`;
6. the `dzn_meta_pixel_should_render` filter does not return `false`.

The pixel id is public configuration and appears in the page source. There is no secret in this
integration, so nothing is stored in the encrypted vault.

## Enabling it

1. Open **Settings → Integrations → Meta Pixel**.
2. Paste the numeric Pixel/dataset id from Meta Events Manager.
3. Tick *Load the Meta Pixel on the public marketing site* and save.
4. Press **Validate configuration (local only)**. This checks the id format and the enable switch;
   it does **not** contact Meta.
5. Open the public site signed out and use Meta Events Manager → Test Events (or the Pixel Helper)
   to confirm `PageView`.
6. Submit a synthetic introductory booking request and confirm `Lead`. Use synthetic data only.

## Privacy obligations before enabling

The pixel may set Meta marketing cookies such as `_fbp`. The cookie policy draft
(`platform/content/legal/cookie-policy.html`) and the privacy policy must describe that use, and the
opt-out required by the applicable law must be available, **before** the switch is turned on. The
site currently has no consent banner; a consent manager can deny marketing cookies by setting
`dzn_marketing_consent=denied`, and a site plugin can return `false` from the
`dzn_meta_pixel_consent_granted` filter.

The admin screen repeats this warning. Enabling the integration is an operator privacy decision,
not a neutral configuration change.

## Verification and limits

- The base snippet and helper are server-rendered; the browser loads `fbevents.js` from
  `connect.facebook.net` asynchronously.
- A successful request to Meta is not proof of attribution. Meta Events Manager and the Pixel
  Helper are the evidence surfaces.
- The `Lead` event carries a stable `eventID` (the booking reference) so retries from the same
  success state are deduplicated by Meta.
- Nothing in this integration proves ad delivery, campaign optimisation or revenue.

## Operations

- Disable the switch to stop all browser tracking immediately on the next page load. The snippet is
  not cached separately.
- The status card on **Settings → Integrations** shows enabled/configured state and the last local
  validation only; it never calls Meta.
- To remove the integration entirely, delete the stored `meta_pixel` settings row through the
  integration settings option and remove `MetaPixel::register()` from the plugin bootstrap.

## Tests

```bash
php platform/tests/meta-pixel-unit.php
php platform/tests/integration-control-plane-contract.php
```

The unit test proves the render gates, the pixel-id validation, the absence of credential or
advanced-matching fields, and the consent/opt-out behaviour with mocked WordPress functions. It
does not prove provider acceptance, cookie behaviour in a real browser, or attribution.
