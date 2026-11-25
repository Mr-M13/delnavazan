# Frontend/backend integration audit — Phase 2A (2026-11-12)

**Evidence scope:** direct read-only source inspection of handover snapshot `251461bf`, not an end-to-end runtime test. This document is a first targeted trace, **not** an exhaustive route inventory.

## Confirmed endpoint-to-frontend matches
| Frontend | API route | Backend | Result |
|---|---|---|---|
| `theme/assets/js/intro-checkout.js:26` | POST `student/intro-checkout` | `platform/src/Portals/StudentCheckoutController.php:25` | Route and method match |
| `intro-checkout.js:50` | GET `student/intro-checkout-status` | `StudentCheckoutController.php:30` | Route and method match |
| `theme/assets/js/checkout.js:199` | GET `student/checkout-status` | `StudentCheckoutController.php:15` | Route and method match |
| `checkout.js:228` | POST `student/checkout` | `StudentCheckoutController.php:10` | Route and method match |
| `checkout.js:300,393` | GET `student/commercial-checkout` | `StudentCheckoutController.php:20` | Route and method match |
| `checkout.js:336` | `commercial/promotion/apply` | `platform/src/Public/CommercialPromotionRestController.php:28` | Route exists; inspect request method/payload contract |
| `theme/assets/js/booking.js:286` | POST `booking-availability/day` | `platform/src/Public/BookingDayAvailabilityRestController.php:8` | Route and method match |
| `booking.js:401` | `booking-availability/preview` | `platform/src/Public/BookingAvailabilityPreviewRestController.php:9` | Route exists, POST expected |
| `booking.js:668` | `booking-requests` | `platform/src/Public/BookingRequestRestController.php:16` | Route exists; inspect request method/payload contract |

All routes use `delnavazan-platform/v1` namespace.

## Confirmed design issues / improvement opportunities
**UX-01 (low/medium): inconsistent checkout error feedback.** `theme/assets/js/checkout.js:57-67` parses error JSON and attaches `error.payload` for downstream handling, whereas `theme/assets/js/intro-checkout.js:13-20` discards error JSON and always throws generic `checkout_unavailable`. The latter's catch at line 43 shows a generic message. This can hide actionable server states and makes the two payment flows harder to troubleshoot. Recommendation: one shared, typed checkout response/error adapter, with an explicit allowlist of safe customer-facing states, preserving backend authority. Add contract tests for 400/403/404/409/429/503, pending, retry and expired states.

**UX-02 (medium, design): checkout status is split between two JS entrypoints.** `checkout.js` and `intro-checkout.js` separately implement API fetch, nonce, redirects, status and error presentation. The backend has both `checkout-status` (which tries standard then intro reader) and `intro-checkout-status` (intro only) in `StudentCheckoutController.php:42-100`. This is not proven incorrect but increases drift risk. Recommendation: document distinct business flows, then consolidate shared transport and status interpretation without merging canonical payment authorities.

## Security assessment correction
**SEC-01 (needs deeper review, not a confirmed vulnerability):** `RecordingServiceRestController.php:17-29` exposes signed service endpoints with public WordPress permission callbacks, but `pull`, `health`, `callback` and `deletionAuthorisation` invoke signature validation or signed control-plane methods. `RecordingSignature.php:39-70` enforces keyed HMAC, audience, expiry and payload digest. Do **not** report these endpoints as unauthenticated merely because the REST permission callback returns true. Next inspect per-operation nonce replay controls, rate limiting, service identity binding, secret rotation and callback idempotency.

## Remaining priority
- Trace response JSON keys and frontend state transitions for booking, checkout, promotions, attendance, Google Meet and teacher rescheduling.
- Compare form fields and Jalali/UTC conversions end-to-end.
- Trace async job results into portal/admin refresh and notification recovery.
- Test mobile RTL states, loading/retry accessibility, duplicate clicks and stale data.
- Cross-check actual deployed main rather than assuming this snapshot matches current production.

No source changes or deployment were made.
