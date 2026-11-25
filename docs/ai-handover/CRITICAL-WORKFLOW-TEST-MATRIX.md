# Critical workflow test coverage matrix — Phase 8 (2026-11-12)

**Scope:** Read-only classification of handover snapshot `251461bf`; no changes to Codex notifications or DeepSeek availability work. Filename matches are approximate and overlap, not unique test counts.

## Inventory
About 680 tracked test-related paths. Filename matches: booking 54; attendance 9; post-intro 12; payment 14; Stripe 19; Meet 17; Google 23; notification 39; enrolment 12; portal 19; finance 24; OAuth 6; reschedule 6. Counts do not prove test depth or complete coverage.

## Selected test execution
| Test | Result | Interpretation |
|---|---|---|
| `booking-teacher-delivery-unit.php` | PASS, exit 0 | Executes booking allocation with fake repositories and availability; not database integration |
| `google-integration-test-runtime.php` | PASS, exit 0 | Explicit Google integration contract; not proof of real Google API |
| `booking-provisioning-recovery-runtime.php` | NOT RUN IN REQUIRED ENV, exit 1 | Guard explicitly requires `DZN_BOOKING_PROVISIONING_RUNTIME=runtime`, WP-CLI, local/development WordPress and MariaDB; deliberately exits 1 outside that environment |
| `finance-intro-context-runtime.php` | REFUSED, exit 1 | Printed `Finance introductory Lesson context runtime refused.`; investigate prerequisites before interpreting as failure |
| `booking-slot-conflict-runtime.php` | NOT RUN IN REQUIRED ENV, exit 1 | No output on standalone invocation; investigate its bootstrap/guard prerequisites |

Previously passed focused contracts: LiveKit attendance retirement, LiveKit classroom retirement, canonical booking occupancy, admin/delegated read authority.

## Coverage map and next proofs
| Journey | Existing relevant tests | Still needed |
|---|---|---|
| Booking availability, teacher matching, occupancy | Booking allocation, occupancy, slot conflict, delivery | Concurrent real DB booking/reschedule, DST and 15-minute buffer |
| Teacher accepts → Meet | Provisioning recovery, Google integration, Meet contracts | Staging Google event/co-host/recording and retry; provider disconnect |
| Attendance → teacher fallback | Attendance and post-intro suites | Real participant identity, duration evidence, manual fallback idempotency |
| Intro → payment → enrolment | Post-intro, Stripe, payment, enrolment suites | Verified settlement, instalments, duplicate webhook, activation worker, portal schedule |
| Student/teacher portals | Portal and finance tests | Owner-scoped HTTP UI journey, read failure visible to user, mobile Meet handoff |
| Notifications | Notification suite | Codex-owned work; review final PR and delivery integration only |
| Availability and golden hours | Availability/booking/golden-hour suites | DeepSeek-owned work; review final PR and cross-timezone integration only |
| Admin and security | Admin and limited security-named tests | Role × action × object matrix, CSRF, IDOR, delegated permissions, God Mode isolation |
| Release | PR gate, deploy and smoke scripts | Real staging workflow and read-only production queue/cron health |

## Findings
**TEST-03 (high):** Source contracts and fake-service unit tests are valuable but cannot substitute for WP-CLI/MariaDB transactional runtime proofs and staging provider journeys. Do not count direct exit 1 from guarded runtime scripts as regression failures.

**TEST-04 (high):** Record required runtime flags, WP-CLI bootstrapping, local DB fixtures, cleanup and environment restrictions for each DB runtime test; use an isolated disposable database, never production.

**TEST-05 (medium):** Build a machine-readable inventory of test scripts, type, dependencies, CI inclusion, expected outputs, owner and duration to enable risk-based selective CI.

**TEST-06 (high):** Before final handover, capture latest main SHA and agent PR heads, rerun exact-head gate, and package a new versioned snapshot including these audit reports.

**No application code, production data, merges or deployments modified.**
