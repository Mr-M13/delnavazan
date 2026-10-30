# Legal and policy pages — 2026-10-31

**Status:** implementation complete; **publication intentionally blocked** pending owner and legal confirmation.

## What was implemented

- Five canonical Persian RTL policy pages, authored as versioned source documents:
  - `/privacy-policy/` — سیاست حفظ حریم خصوصی
  - `/terms-and-conditions/` — شرایط و ضوابط استفاده
  - `/cancellation-and-refunds/` — سیاست لغو و بازپرداخت
  - `/recording-and-ai-policy/` — سیاست ضبط کلاس‌ها و استفاده از هوش مصنوعی
  - `/cookie-policy/` — سیاست کوکی‌ها
- A footer section titled «قوانین و سیاست‌ها» with all five links, rendered by the theme footer used
  by public pages, booking and other theme-rendered routes.
- A safe pending response for each canonical URL while its WordPress page is unpublished. It returns
  HTTP 200, explains that the final document is under legal review, provides the published contact
  channels, and is marked `noindex, follow`. It contains no unapproved legal terms.
- Legacy pending drafts `terms` and `refund_returns` redirect to the canonical pages. A published
  legacy policy is never redirected to an unpublished replacement.
- Draft-only WordPress provisioning (`wp dzn-legal provision`) and an operator action under
  **Delnavazan → General → Legal and policy pages**. It creates/refreshes drafts, attaches the Policy
  template, records a version and source SHA-256, and never publishes.
- Booking and post-intro payment surfaces link to Privacy, Terms and Cancellation/Refund before the
  relevant commitment.
- Production smoke now checks all five public URLs and the footer policy section.

## Verified platform facts

| Fact | Source |
| --- | --- |
| Free introductory lesson; paid term starts afterwards | homepage, booking flow, Phase Q/R1 |
| 12 lessons per paid Term | `CanonicalTermAuthorityService::SESSION_ALLOCATION` |
| 2 student replacement lessons per Term; unused replacements expire | Phase L/M policy defaults and portal |
| Student no-show consumes the lesson; teacher/academy non-delivery is a separate academy obligation | Phase O |
| Public self-service booking requires an adult self-attestation; under-18 enquiries are directed to support | `theme/inc/booking.php` |
| Booking request data is displayed as retained for 24 months | booking form copy |
| Recording and research export are disabled; no capture provider is connected | recording architecture decision log and public recording page |
| LiveKit is a protected prototype with no recording/egress/analytics | LiveKit prototype note |
| Stripe is the payment provider; card data is not stored by Delnavazan | checkout/Stripe integration |
| Google Calendar/Meet OAuth is optional for teachers and tokens are encrypted | Phase V integration |
| No analytics or advertising tracker is implemented in the repository | Theme/Platform source audit |
| WordPress auth cookies, `dzn_booking_csrf`, `dzn_google_oauth`, pricing localStorage and portal sessionStorage exist | Theme/Platform source audit |

## Missing facts requiring owner confirmation

These placeholders are deliberately present in the drafts and must be completed before publication:

1. Full legal name of the operator and any trading name.
2. ABN/ACN or other business registration number.
3. Registered business address and the Australian state/territory whose law governs.
4. Official privacy/support contact and complaint response period.
5. Retention schedule for account, lesson, attendance, payment, recording, practice-upload and backup
   data (the 24-month booking-request period is stated in the booking UI, but the full schedule is not
   approved).
6. Whether any recording, AI processing or research capability is active in production; the repository
   currently says it is disabled.
7. Whether automatic renewal, automatic charging, two-instalment payment and Stripe live mode are
   actually enabled for the relevant regions.
8. Confirmation that the stated one-week pre-renewal cancellation requirement is the current business
   rule and how it is enforced operationally.
9. Consumer-law review of the refund wording and the mandatory Australian Consumer Law guarantees.
10. GDPR/UK GDPR applicability and the lawful basis for each processing purpose, if applicable.
11. International transfer safeguards for Stripe, Google, Telnyx, Cloudflare and LiveKit.
12. Final approval of the recording/AI disclosure and guardian-consent process for students aged 7–17.

## Publication gate

The five WordPress pages are created as **drafts** only. No legally consequential terms are published
by this change. Publication requires the owner and legal reviewer to:

1. complete every `نیازمند تأیید` placeholder;
2. verify the facts in the table above against production configuration;
3. review the consumer, privacy, recording and jurisdiction-specific wording;
4. publish each page in WordPress; and
5. re-run the production smoke check and confirm the footer links and canonical metadata.

## Acceptance-evidence integration

The booking and post-intro surfaces now link to the policies before the free introduction and before
paid continuation. A separate, unticked legal-terms acceptance record with a versioned evidence table
was **not** added, because there is no approved legal version or operator identity to accept. Adding a
checkbox that records acceptance of an unpublished or placeholder document would create misleading
evidence. The existing privacy acknowledgement and adult self-attestation are unchanged.

When the owner approves the first publishable version, the next change should add:

- an unticked mandatory terms acceptance on the paid continuation/checkout surface;
- a version identifier tied to the published Terms version;
- append-only acceptance evidence bound to the authenticated student/guardian and the exact version;
- tests proving that optional recording/AI consent remains separate and that declining optional
  consent does not block a paid enrolment.

## Tests performed

- `php platform/tests/legal-pages-contract.php`
- PHP syntax checks on changed Theme/Platform files
- `bash deployment/qa/production-deployment-contract.sh` (includes smoke retry/decision units)
- Existing pure Theme/Platform contracts selected for footer/document/booking impact:
  `homepage-footer-pricing-background-contract`, `release-presentation-gate-contract`,
  `booking-route-state-contract`, `post-intro-enrolment-automation-contract`,
  `student-home-account-contract`, `payment-plan-choice-contract`,
  `recording-choices-ui-contract`, `responsive-shell-contract`,
  `presentation-contract-inventory-contract`, `static.php`.
- Disposable WordPress runtime: `platform/tests/legal-pages-provisioner-runtime.php` passed
  (five drafts, idempotent replay, legacy consolidation, published-page protection, dry run).
- Runtime frontend proof on the disposable WordPress instance:
  - all five unpublished URLs returned HTTP 200 with the pending notice, noindex and the footer
    policy section;
  - `/terms/` and `/refund_returns/` returned 301 to the canonical pending URLs;
  - after publishing one policy page, the canonical page replaced the pending response and rendered
    the Policy template content and footer.

## Rollback

Revert this branch. The pages are drafts; a code-only rollback leaves any operator-created drafts in
WordPress. Delete those drafts manually only after exporting their content. Published policies are never
overwritten or deleted by the provisioner.
