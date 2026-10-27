# Delnavazan presentation contract inventory

Status: Package 0 deliverable (baseline and contract inventory). No behaviour change.
Baseline: `Mr-M13/delnavazan` `origin/main` at `c952fe0de043244edeb78a4e71ec831bdc5fbdb8`.
Companion decisions: the 27 October 2026 presentation architecture specification and the two supplied audit/plan documents in `docs/`.

This document records **what the pinned source actually implements**, not what production has
deployed. It is the reconciliation artefact that later packages update. Source presence proves
structure; it does not prove a deployed commit, an entitlement, or an end-to-end action.

## 1. Evidence and limits

| Evidence | Available in this package | Not available |
| --- | --- | --- |
| Canonical routes and screen allowlists | `theme/inc/routes.php` | Production routing cache state |
| Platform reads | `platform/src/Portals/PortalAuthenticatedReadService.php`, `platform/src/Core/Application/Checkout/StudentCommercialCheckoutReadService.php` | Authenticated production principals |
| Actions | `platform/src/Portals/*Controller.php`, `platform/src/Integrations/*JoinController.php` | Live action execution, Stripe test-mode run |
| Public acquisition | `theme/patterns/homepage-editorial.php`, `theme/assets/js/navigation.js`, `theme/inc/{routes,pricing,booking}.php` | Deployed asset versions, measured transfer |
| Presentation | theme templates, `assets/css/*.css`, `assets/js/*.js` | Real-browser screenshots at the required viewports |
| Headers and caching | `theme/inc/crawl-guard.php`, `nocache_headers()` calls | Production response headers |

The audit's 16.8 MB transfer estimate and its "all body text is 12.8px" claim are **not**
reproduced here and remain unmeasured.

Two proven user-facing correctness issues gate later work: the **Acquisition blocker** (the
`/booking/` redirect drops the chosen instrument) and the **Truthfulness blocker** (absence is
labelled as reported from action eligibility alone). Neither is repaired by this package.

## 2. Route and response matrix (verified)

`dzn_theme_route()` normalises `/enrol/` to the internal `booking` renderer and accepts exactly:

`booking`, `login`, `dashboard`, `student-portal`, `teacher-portal`, `teacher-invitation`,
`student-account-claim`, `admin-operations`.

| Entry | Internal route | Screen keys accepted today | Shell | Notes |
| --- | --- | --- | --- | --- |
| `/` and content pages | WordPress | n/a | Public editorial | Unchanged |
| `/enrol/` | `booking` | n/a | Public + booking | Canonical enrolment URL |
| `/booking/` | `booking` | n/a | — | `301` to `/enrol/`; **query is dropped today** (P0-01) |
| `/login/` | `login` | n/a | Auth | Branded login/reset; no tab bar |
| `/dashboard/` | `dashboard` | n/a | Auth | Role resolution; 0/1/many destinations |
| `/student-portal/` | `student-portal` | `home`, `account`, `schedule`, `payments` | Student portal | Unknown `portal-view` becomes `home` |
| `/teacher-portal/` | `teacher-portal` | `home`, `finance`, `settings`, `onboarding` | Teacher portal | Incomplete teacher forced to `onboarding`; unknown `teacher-view` becomes `home` |
| `/teacher-invitation/` | `teacher-invitation` | n/a | Auth | Token claim |
| `/student-account-claim/` | `student-account-claim` | n/a | Auth | Token claim |
| `/admin-operations/` | `admin-operations` | existing operations | Operations | Out of redesign scope |

There is **no** `/app/`, `/app/student/`, or `/app/teacher/` route. Package 9 may revisit that
only as a measured decision.

Protected routes call `nocache_headers()`. The response contract still needs explicit
`no-store`/private cache controls and `X-Robots-Tag: noindex, nofollow` on every protected and
auth response. `crawl-guard.php` marks **non-production** responses
`noindex`; the virtual-route renderer does not yet emit an explicit `X-Robots-Tag` contract for
production. That gap is tracked, not assumed fixed.

## 3. Screen keys and navigation maps

### Student

| Key | URL | Current component set | Gate |
| --- | --- | --- | --- |
| `home` | `/student-portal/` | provisioning, upcoming lesson, term timeline, booking requests, post-intro, announcement, recent history | Existing |
| `schedule` | `/student-portal/?portal-view=schedule` | active/upcoming/ended rows with calendar and authorised actions | Lesson read returns rows |
| `payments` | `/student-portal/?portal-view=payments` | commercial obligation read; existing checkout root | Commercial read returns obligations |
| `account` | `/student-portal/?portal-view=account` | profile, payments, notifications, history | Existing |

Navigation is built once in `dzn_theme_platform_student_navigation()` and is capped at four
items. Schedule is offered only when the lesson read returns rows; payments only when the
owner-bound commercial read returns obligations. A state with neither stays at Home and Account.

### Teacher

| Key | URL | Current component set | Gate |
| --- | --- | --- | --- |
| `home` | `/teacher-portal/` | stats, announcement, attention, post-intro outcomes, classes, calendar, help | Existing |
| `finance` | `/teacher-portal/?teacher-view=finance` | statements | Existing read |
| `settings` | `/teacher-portal/?teacher-view=settings` | profile, instruments, availability, demand, accepting, Google | Existing |
| `onboarding` | `/teacher-portal/?teacher-view=onboarding` | five presentation steps | Server-forced while not ready |

Navigation is built once in `dzn_theme_platform_teacher_model()` and has three items. The `home`
label is currently **حساب کاربری**, which collides with settings (P1-10).

### Confirmed screen mismatch (P0-04)

`theme/page-templates/teacher-portal-account.php` calls
`dzn_theme_render_teacher_portal( 'account', … )`, but `account` is not an allowed teacher screen
key. It is normalised to the home model and home shell. The canonical virtual route itself only
accepts `home|finance|settings|onboarding`, so the defect is in the page-template/preview entry
points, not in the whole route.

## 4. Field mapping

### 4.1 Student read → projection → component

Source: `PortalAuthenticatedReadService::student()`.

| Platform field | Theme projection | Component | Truth rule |
| --- | --- | --- | --- |
| `profile.full_name`, `email`, `mobile`, `whatsapp_phone`, `country_code`, `city`, `timezone`, `locale`, `calendar_preference` | `student`, `profile` | shell, profile | Read-only; no edit authority |
| `lessons[]` (`lesson_uid`, `schedule_version_uid`, `starts_at_utc`, `ends_at_utc`, `schedule_timezone`, `lifecycle_state`, `delivery_state_summary`, `attendance_state_summary`, `join_available`, `absence_available`, `enrolment_uid`, `term_reference`) | `upcoming_lesson`, `term`, `history` | upcoming-lesson, term-timeline, history | Never widen a capability from a date |
| `enrolments[]` | `provisioning`, `term` | shell, term-timeline | `payment_verified` required for payment wording |
| `booking_requests[]` | `booking_requests` | booking-requests | Pending requests are not confirmed classes |
| `booking_classes[]` | `upcoming_lesson` | upcoming-lesson | Only `confirmed` rows |
| `post_intro[]` | `post_intro` | post-intro | Existing |

Derived fields that are currently unsafe:

- `presentation_state` comes from `dzn_theme_platform_lesson_state()`, which has no `in_progress`
  state and previously invented `absence_notified` from `absence_available` plus attendance open
  (P0-05/P0-06). Package 2 removes that inference; no persistent reported claim is made until the
  Platform read model exposes an explicit canonical fact.
- `join_available` is hard-limited to introductory booking classes; canonical term lessons always
  receive `''` and a disabled button. No student canonical join controller exists in the pinned
  source, so this cannot be fixed by the Theme alone (P0-07).
- `timezone_label` is the raw IANA identifier (P1-07).
- `payments.items` is always empty and `checkout_enabled` is absent, so the account payment block
  cannot render the existing commercial checkout read (P0-07/P1-05).

### 4.2 Teacher read → projection → component

Source: `PortalAuthenticatedReadService::teacher()` plus `TeacherStatementReadService`,
`TeacherOnboardingService`, `TeacherAvailabilityService`, `TeachingEligibilityService`.

| Platform field | Theme projection | Component | Truth rule |
| --- | --- | --- | --- |
| `teacher.lessons[]`, `booking_classes[]` | `classes`, `calendar`, `statistics` | classes, calendar, home | Active row must survive; no fake note |
| `teacher.assignments[]` | `statistics.active_students` | home | Display only |
| `onboarding.state`, `readiness_state`, `profile`, `availability_rules`, `google_state`, `can_edit`, `can_submit` | `onboarding`, `current_step`, actions | onboarding | Server readiness wins over query step |
| `TeacherStatementReadService::ownStatements()` | `statements`, `finance_state` | finance | Minor units need currency exponent |
| `TeacherAvailabilityRepository::acceptingFor()` | `accepting_state` | account | No mutation in Theme |
| `ProviderIntegrationReadService::connections()` | `google_state` | account, onboarding | Honest unavailable |

Derived fields that are currently unsafe:

- canonical teacher classes with `start <= now < end` fall through both the future and recent
  branches and disappear (P0-05).
- the home navigation label is "حساب کاربری" (P1-10).
- `previous_private_note` is fabricated as "یادداشت خصوصی در این نمای خواندنی ارائه نشده است."
  (P2).
- finance prints `payable_amount_minor` with the literal text "واحد خرد ارز" (P1-08).
- the onboarding step can be overridden by `?onboarding-step=` up to any of 1–5.

## 5. Action map

Every action below already exists in the pinned source. The Theme may render a descriptor only
when the read supplies the exact capability; each endpoint revalidates session, nonce, ownership
and canonical state.

| Action | Endpoint | Authority source | Nonce action | Return |
| --- | --- | --- | --- | --- |
| Student join introductory class | `admin-post.php?action=dzn_student_join_booking_class` | `BookingClassJoinService` | `dzn_student_join_booking_class` | 302 to provider URL |
| Teacher join introductory class | `admin-post.php?action=dzn_teacher_join_booking_class` | `BookingClassJoinService` | `dzn_teacher_join_booking_class` | 302 to provider URL |
| Teacher join canonical class | `admin-post.php?action=dzn_teacher_join_class` | `TeacherClassJoinService` | `dzn_teacher_join_class` | 302 to provider URL |
| Student absence notice | `admin-post.php?action=dzn_student_report_absence` | `CanonicalAttendanceIntakeService` | `dzn_student_report_absence` | `/student-portal/?absence_status=…` |
| Student lesson issue | `admin-post.php?action=dzn_student_report_lesson_issue` | `CanonicalAttendanceIntakeService` | `dzn_student_report_lesson_issue` | `/student-portal/?absence_status=…` |
| Teacher delivery report | `admin-post.php?action=dzn_teacher_report_delivery` | delivery service | `dzn_teacher_report_delivery` | teacher home status |
| Teacher delivery issue | `admin-post.php?action=dzn_teacher_report_delivery_issue` | delivery service | `dzn_teacher_report_delivery_issue` | teacher home status |
| Teacher booking response | `admin-post.php?action=dzn_teacher_booking_response` | `TeacherBookingResponseController` | `dzn_teacher_booking_response` | allowlisted notice |
| Teacher onboarding writes | `admin-post.php?action=dzn_teacher_onboarding_*` | `TeacherOnboardingController` | matching action | onboarding redirect |
| Teacher availability writes | `admin-post.php?action=dzn_teacher_availability_*`, `dzn_teacher_day_off*` | `TeacherAvailabilitySelfServiceController` | matching action | settings redirect |
| Student commercial checkout read | `GET /wp-json/delnavazan-platform/v1/student/commercial-checkout` | `StudentCommercialCheckoutReadService` | `wp_rest` | JSON obligations |
| Student commercial checkout start | `POST /wp-json/delnavazan-platform/v1/student/checkout` | `StudentCheckoutInitiationService` | `wp_rest` | JSON `redirect_url` |
| Student checkout status | `GET /wp-json/delnavazan-platform/v1/student/checkout-status` | `StudentCheckoutReturnReadService` | `wp_rest` | JSON status |

**Not present in the pinned source:** a student canonical-term join endpoint. Do not add one from
the Theme. Until Platform supplies and reviews it, the student regular-class card must not present
a primary Join action.

## 6. Capability and truth gaps carried into later packages

| Gap | Package | Required resolution |
| --- | --- | --- |
| `/booking/` drops instrument query | 1 | Preserve allowlisted `instrument`/`instrument_name` on a temporary redirect |
| Pricing region hidden and copy implies a base price | 1 | Visible manual selector; Platform price; honest approximation copy |
| In-progress lesson disappears | 2 | Derive from start/end instants; keep active row first |
| Absence label inferred from action eligibility | 2 | Removed: neutral status and post-submit confirmation only; a persistent reported claim waits for an explicit Platform read field |
| Teacher account template uses `account` | 2 | Use `settings` in page template and preview |
| Student join/payment dead CTAs | 2, 5 | Join stays unavailable for canonical term lessons (no Platform endpoint); payments now use the existing read |
| `payments.items` was always empty and `checkout_enabled` was absent | 5 | Resolved: the account and dedicated payments screen now read `StudentCommercialCheckoutReadService`; the tab is gated on returned obligations |
| Footer groups all in grid column 2 | 3 | Resolved: identity, actions, navigation and legal occupy distinct grid areas |
| Public JS loaded on protected routes | 3 | Split carousel/pricing from header navigation; conditional enqueue |
| IANA timezone shown as a label | 2, 6, 7 | Human label plus exact identifier |
| Teacher finance minor units | 6 | Format with currency exponent |
| Onboarding query can jump steps | 7 | Clamp to server first-incomplete step |
| 12-marker term CSS assumption | 8 | Adapt to returned total |
| No explicit protected `X-Robots-Tag` | 9 | Response contract and header test |

## 7. Fixture catalogue

`platform/tests/presentation-state-fixtures.php` is the shared, source-level fixture set. It is
deliberately plain PHP (no WordPress runtime) so every later package can assert against the same
cases.

Required cases:

- account states: `signed_out`, `student_ok`, `student_no_data`, `student_not_linked`, `student_error`,
  `teacher_ready`, `teacher_onboarding_pending`, `teacher_onboarding_returned`, `teacher_no_data`;
- lesson temporal boundaries: `start-1s`, `start`, `mid`, `end-1s`, `end`, `end+1s`, missing end;
- absence: eligible-unreported and not eligible, both neutral. A persistent
  "reported" state is deferred until the Platform read model exposes an explicit
  canonical fact; the post-submit query confirmation is the only current signal.
- payment: required, processing, paid, unavailable;
- finance: available, empty, unavailable;
- onboarding: first incomplete step and a query attempt to skip ahead.

The fixture file records the **expected** temporal state for Package 2 to implement. Package 0
does not change the renderer.

## 8. Launch gates (unchanged)

1. Correctness before expansion: acquisition query, visible price choice, absence label, active
   class continuity, teacher screen/vocabulary.
2. Before advertising a mobile action: prove that role/screen read **and** action work with real
   authorised test principals. Student canonical join and commercial checkout are not proven
   here.
3. Before public mobile rollout: navigation, RTL/zoom, slow-network, no-cache, reduced-motion and
   narrow/landscape visual gates, plus a measured image payload. WCAG 2.2 AA is the release
   target; colour is never the only status signal.

## 9. Open product decisions

| Decision | Default carried forward |
| --- | --- |
| Region detection and privacy copy | Visible manual selector; optional approximate detection with plain explanation |
| Student/booking calendar preference | Keep current booking calendar unless a stored preference is authoritative |
| Student regular-class join | Hide until a reviewed Platform endpoint exists |
| Payments tab | Show only when the authorised commercial read returns obligations and return states pass |
| Notification counts, profile edit, teacher note, deadlines | Hide or explain; never infer |
| Installable PWA / separate `/app/` | Defer; canonical responsive shells first |
