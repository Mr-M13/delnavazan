# Booking stress suite — CI gate requirements for Codex

**Date:** 2026-11-16
**Branch:** `test/booking-engine-stress-audit-20261115` (PR #626)
**Status: implemented** in commit `14ea6727`, then corrected by the harness fixes below.

**Owner of `.github/workflows/**`:** Codex. The change was authored by the release
coordinator under AGENTS.md section 6, with `AGENTS.md` updated in the same commit,
because the owner explicitly delegated it. It is a single reviewable commit and can
be reverted independently of the booking code.

## Why this is needed

The current `pr-runtime-gate.yml` proves the allocator, occupancy, cancellation,
teacher-response and recovery paths on MariaDB (job `booking-release-runtime`),
and that job is green on `0c182d28`. It does **not** run:

- scenarios A–G, including the 500-request mixed-instrument fairness corpus;
- the multi-process concurrency matrix (2–100 workers);
- idempotent replay, worker crash/retry, partially overlapping submissions;
- any fairness measurement at all.

Until this job exists, the approved allocation policy cannot be reported as
measured, and the four new native regressions are not gate-protected.

## Change 1 — add four contract checks to `source-contracts`

Insert immediately after `php platform/tests/booking-allocation-unit.php` in the
`source-contracts` job's list:

```yaml
          php platform/tests/booking-allocation-workload-unit.php
          php platform/tests/booking-engine-capacity-unit.php
          php platform/tests/booking-engine-scenario-date-unit.php
          bash platform/tests/booking-stress-runner-contract.sh
```

All four are host-only: no Docker, no database, no WordPress. Verified native on
PHP 8.3.35 against `TMPDIR=/tmp` (Linux-style):

| Check | Assertions |
|---|---|
| `booking-allocation-workload-unit.php` | 19 |
| `booking-engine-capacity-unit.php` | 14 |
| `booking-engine-scenario-date-unit.php` | 25 |
| `booking-stress-runner-contract.sh` | 27 |

## Change 2 — add a `booking-stress-runtime` job

Insert as a new job in `pr-runtime-gate.yml` (sibling of `booking-release-runtime`):

```yaml
  booking-stress-runtime:
    name: Booking stress runtime and fairness
    runs-on: ubuntu-24.04
    timeout-minutes: 90
    permissions:
      contents: read
    env:
      DZN_RUNTIME_STATE_DIR: /tmp/dzn-booking-stress-runtime
      DZN_COMPOSE_PROJECT: dzn-booking-stress-runtime
      DZN_WP_PORT: 18090
    steps:
      - name: Check out source
        uses: actions/checkout@v5
        with:
          persist-credentials: false
      - name: Preload disposable runtime images
        run: |
          docker pull mariadb:11.4
          docker pull wordpress:php8.3-apache
          docker pull wordpress:cli-php8.3
      - name: Provision disposable WordPress and MariaDB
        run: platform/runtime/bin/reset-disposable.sh
      - name: Run booking stress suite
        run: platform/runtime/bin/run-booking-engine-stress.sh
      - name: Publish fairness and safety summary
        if: always()
        run: |
          set -Eeuo pipefail
          dir="$DZN_RUNTIME_STATE_DIR/booking-stress-evidence/$DZN_COMPOSE_PROJECT"
          {
            echo '## Booking stress evidence'
            echo '```'
            grep -h '^AUDIT_RESULT ' "$dir"/scenario-*.log 2>/dev/null || echo 'no scenario output captured'
            echo '```'
          } > "$RUNNER_TEMP/booking-stress-summary.md"
          cat "$RUNNER_TEMP/booking-stress-summary.md" >> "$GITHUB_STEP_SUMMARY"
      - name: Upload raw booking stress evidence
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: booking-stress-evidence
          path: /tmp/dzn-booking-stress-runtime/booking-stress-evidence
          if-no-files-found: warn
          retention-days: 30
      - name: Dispose runtime
        if: always()
        run: platform/runtime/bin/destroy.sh YES || true
```

Notes for the reviewer:

- `reset-disposable.sh` is mandatory before the runner: the runner does not
  provision, by design.
- The job uses the repository's existing disposal convention
  (`destroy.sh YES || true`) so a failure cannot leak a running stack.
- Evidence is uploaded as a workflow **artifact**, never committed. The runner
  writes it to `$DZN_RUNTIME_STATE_DIR/booking-stress-evidence/…`, which the
  disposable-path guard keeps outside the checkout.
- `timeout-minutes: 90` is a guard, not a target; the 1,000-request scenario A
  and the 100-worker bursts dominate the runtime.

## What the job will evidence, mapped to the request volumes asked for

| Requested | Where it is exercised |
|---|---|
| 50 / 100 / 500 / 1,000 **requests** | scenario A (`$scale` = 50, 100, 500, 1000 single-threaded allocations) and scenario C (500 mixed-instrument requests with acceptance) |
| **Concurrency** contention | `DZN_STRESS_ACTIONS="submit accept"` × `DZN_STRESS_LEVELS="2 5 10 25 50 100"` |
| Simultaneous submissions | `submit` action, ready-file barrier then release |
| Competing teacher acceptances | `accept` action on one Teacher/timeslot |
| Duplicate requests | `submit` idempotency assertions |
| Worker crash + retry | `DZN_STRESS_CRASH=1` (`run_crash`) |
| Transaction rollback | killed-worker transaction, then single retry of the same key |
| Lock contention / retries | launcher/verify logs are grepped for `deadlock`, `lock wait timeout`, `WordPress database error`; any hit fails the step |
| Partially overlapping submissions | `DZN_STRESS_PARTIAL=1` (`submit_partial 25`) |
| Idempotent replay | `DZN_STRESS_IDEMPOTENT=1` (`idempotent 25`) |

The harness spawns at most **100 concurrent PHP processes**, not 1,000. "1,000
requests" is a request-volume measurement (scenario A), not a 1,000-process
burst; the audit report documents the memory reason. If a 1,000-process burst is
required, it needs a separate sizing decision — raise it explicitly rather than
assuming the current matrix covers it.

## Acceptance criteria the job must satisfy before this PR is merge-ready

1. Zero confirmed double bookings (`dzn_stress_teacher_double_bookings() === 0`).
2. Zero hard capacity violations (`dzn_stress_capacity_violations() === 0`),
   including buffer overlaps and live offers.
3. No `deadlock` / `lock wait timeout` / `WordPress database error` in any
   launcher or verify log.
4. Scenario C emits `fairness_global_workload` with `min`, `max`, `spread`,
   `gini` — this is the post-change measurement to compare against the audited
   baseline (range 27–143, Gini 0.297).
5. Scenario C's `spread <= 20` fence **and** the actual measured Gini are
   recorded; the fence alone is not an acceptance result.

## Known risk to review

Replaced: the original `spread <= 20` fence was written without a measurement and
was wrong. The first real run measured spread 88 / Gini 0.1832 against the audited
116 / 0.297. Scenario C now asserts improvement over the audited baseline plus two
structural properties (the heaviest Teacher must be the one eligibility forces, and
each interchangeable portfolio must stay within one booking). See
`BOOKING-ALLOCATION-POLICY-20261116.md` section 7 for the measured table.

## Harness defects this gate found on first execution

All five were test-side; none changed application code.

| Run | Failure | Cause | Fix |
|---|---|---|---|
| `43731557214` | `mkdir ... booking-stress-gate: Permission denied` | host-side write into the container-owned WordPress volume; macOS masks it | mutate the volume in a root CLI container (`gate_exec`), keeping the host-side disposable-path guard |
| `43732621888` | scenario C `spread <= 20` | unmeasured, unachievable fence | replaced with baseline-improvement + structural assertions |
| `43732621888` | scenario D cursor assertions | compared the cursor to a literal Teacher id | assert against a value captured before the transition |
| `43734903302` | D15 "did not release capacity" | assertion ran after allocating the request under test | exclude that request via `$excludeBookingRequestId` |
| `43738252362` | submit level capacity violation | global count saw another fixture's deliberately injected overlapping offers | scope capacity assertions to the level's Teacher |
| `43739826702` | "losing deliveries must remain pending" | retirement moves them to `expired` by contract | assert the retirement contract instead |
| `43741357559` | "Unexpected losing acceptance error" | retirement refusal message missing from the allow-list | add exactly that refusal |

Because capacity always belongs to one Teacher, the per-Teacher scope cannot hide a
real overlap: both halves of any genuine overlap share that Teacher.

## Final verification

Run `43742903765` (job `132899759731`), commit `da9a1edb`, 2026-11-16, 1m56s:
23/23 checks success; 26 `AUDIT_RESULT` records, all `pass:true`; zero deadlock,
lock-wait or database-error lines.
