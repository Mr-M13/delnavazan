# Phase 2A.2-E deterministic concurrency harness

This harness is restricted to a disposable local/development WordPress install and
synthetic `phase-2a2e.invalid` records. It uses two independent WP-CLI processes,
one InnoDB database, and post-lock WordPress test hooks. It never uses a delay as
proof of overlap: the controller must observe the holder's `.locked` file before
launching the contender, then observes the contender's `.started` file before
creating `release`.

The runner invokes `phase-2a2e-concurrency-fixture.php` automatically on a
clean database. It derives an array with
`synthetic_domain => 'phase-2a2e.invalid'` and one entry each for `a`, `b`, `c1`,
`c2`, `d1`, `d2`, and `x` through the committed ready-teacher, public-request,
coordination, eligibility, Assent, and Proposal application paths.
Every entry needs `family_uid`, `option_uid`,
`version_number`, `prospective_subject_ref`, and `request_id`; C entries also need
`option_id`, `expected_version`, and `replacement_fingerprint`. `a1`/`a2`, etc.
may supply different `channel` values. `x1` and `x2` must point at distinct
Booking Requests and have different canonical acceptance payloads.
They are dedicated X fixtures: no earlier race mutates either Proposal Version.

For each mode, run the repository controller with exported values:

```sh
export DZN_PHASE_2A2E_RUNTIME_TEST=isolated
export DZN_PHASE_2A2E_WP_CLI=/absolute/path/to/wp
export DZN_PHASE_2A2E_WP_PATH=/absolute/path/to/disposable-wordpress
export DZN_PHASE_2A2E_WP_USER=123 # disposable administrator/capability holder
export DZN_PHASE_2A2E_GATE_DIR=/absolute/path/to/empty/shared-gate
export DZN_PHASE_2A2E_MODE=a # then b, c1, c2, d1, d2, x
tests/phase-2a2e-concurrency-runner.sh
```

The runner asserts worker results and final DB state, returns non-zero on any
invariant breach, clears gate artifacts, and removes its per-run state. After all
modes, run `DZN_PHASE_2A2E_PURGE_FIXTURE=1 wp eval-file
tests/phase-2a2e-concurrency-cleanup.php` to remove the synthetic fixture option.
