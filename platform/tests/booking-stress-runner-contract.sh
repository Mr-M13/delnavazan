#!/usr/bin/env bash
# Contract for the booking stress harness input boundary.
#
# Runs on a plain PHP-8.3/CI host with no Docker: it exercises only the pure
# validation library and the runner's DZN_STRESS_VALIDATE_ONLY planning mode.
# It exists because the harness deletes derived paths with `rm -rf`, so the
# refusal path must be provable without a disposable WordPress stack.
#
#   php not required.  bash platform/tests/booking-stress-runner-contract.sh
set -euo pipefail

repo_root="$(cd "$(dirname "$BASH_SOURCE")/../.." && pwd)"
runner="$repo_root/platform/runtime/bin/run-booking-engine-stress.sh"
inputs="$repo_root/platform/runtime/bin/booking-stress-inputs.sh"
scratch="$(mktemp -d "${TMPDIR:-/tmp}/dzn-stress-runner-contract.XXXXXX")"
state="$scratch/state"
cleanup(){ rm -rf "$scratch"; }
trap cleanup EXIT

pass=0
fail=0
ok(){ pass=$((pass + 1)); printf 'ok   %s\n' "$1"; }
bad(){ fail=$((fail + 1)); printf 'FAIL %s\n' "$1"; }

# A tree that must survive every rejected invocation below.
victim="$scratch/victim"
mkdir -p "$victim/keep"
printf 'disposable guard sentinel\n' >"$victim/keep/important.txt"
sentinel="$victim/keep/important.txt"

# plan() runs the runner in validation-only mode and prints "<status> <output>".
plan(){
  local out status
  out="$(env -u DZN_STRESS_ACTIONS -u DZN_STRESS_LEVELS "$@" DZN_RUNTIME_STATE_DIR="$state" DZN_STRESS_VALIDATE_ONLY=1 bash "$runner" 2>&1)" && status=0 || status=$?
  printf '%s\n%s\n' "$status" "$out"
}

expect_rejected(){ # <label> <expanded-value-name> <value>
  local label="$1" name="$2" value="$3" out status
  out="$(plan "$name=$value")"
  status="${out%%$'\n'*}"
  if [ "$status" = "0" ]; then bad "$label: accepted, expected rejection"; return; fi
  case "$out" in
    *"$name"*) ok "$label: rejected with a diagnosable message";;
    *) bad "$label: rejected without naming $name";;
  esac
}

# --- 1. Path-shaped and unsupported selections are refused -------------------
expect_rejected 'traversal action' 'DZN_STRESS_ACTIONS' '../../victim'
expect_rejected 'absolute action' 'DZN_STRESS_ACTIONS' '/etc/passwd'
expect_rejected 'separator action' 'DZN_STRESS_ACTIONS' 'submit/../accept'
expect_rejected 'shell metacharacter action' 'DZN_STRESS_ACTIONS' 'submit;rm -rf /'
expect_rejected 'glob action' 'DZN_STRESS_ACTIONS' 'submit*'
expect_rejected 'unsupported action' 'DZN_STRESS_ACTIONS' 'delete_everything'
expect_rejected 'traversal level' 'DZN_STRESS_LEVELS' '../../victim'
expect_rejected 'leading-dot level' 'DZN_STRESS_LEVELS' '..'
expect_rejected 'signed level' 'DZN_STRESS_LEVELS' '-5'
expect_rejected 'zero level' 'DZN_STRESS_LEVELS' '0'
expect_rejected 'leading-zero level' 'DZN_STRESS_LEVELS' '08'
expect_rejected 'non-numeric level' 'DZN_STRESS_LEVELS' '5;rm -rf /'
expect_rejected 'level above the documented ceiling' 'DZN_STRESS_LEVELS' '1001'

# --- 2. Rejection happens before any filesystem work -------------------------
if [ -f "$sentinel" ] && grep -q sentinel "$sentinel"; then
  ok 'unrelated files survived every rejected selection'
else
  bad 'a rejected selection removed or rewrote an unrelated file'
fi

# The runner must not delete anything directly: every removal goes through the
# disposable-path primitive, which re-validates the physical path.
if grep -Eq '(^|[^[:alnum:]_])rm[[:space:]]+-[A-Za-z]*r' "$runner"; then
  bad 'the runner still invokes rm directly instead of the disposable-path primitive'
else
  ok 'runner performs no direct rm; deletion goes through dzn_remove_disposable_path'
fi

# --- 3. The disposable-path guard itself refuses an outside path -------------
# shellcheck source=../runtime/bin/common.sh
. "$inputs"
(
  DZN_RUNTIME_STATE_DIR="$state"
  . "$repo_root/platform/runtime/bin/common.sh"
  dzn_remove_disposable_path "$victim"
) >/dev/null 2>&1 && bad 'the deletion primitive accepted a path outside the state directory' \
  || ok 'the deletion primitive refuses a path outside the state directory'
[ -f "$sentinel" ] && ok 'the refused deletion left the sentinel intact' || bad 'the refused deletion still removed the target'

# A derived gate path for a *valid* pair must stay inside the disposable tree.
(
  DZN_RUNTIME_STATE_DIR="$state"
  . "$repo_root/platform/runtime/bin/common.sh"
  action='submit'; level='1000'
  dzn_stress_action_supported "$action" || exit 1
  dzn_stress_level_supported "$level" || exit 1
  dzn_assert_disposable_path "$DZN_WP_DIR/booking-stress-gate/$action-$level"
) >/dev/null 2>&1 && ok 'a validated action/level derives a disposable gate path' \
  || bad 'a validated action/level produced a non-disposable gate path'

# --- 4. Empty selections disable the loops; defaults need unset -------------
out="$(plan DZN_STRESS_ACTIONS= DZN_STRESS_LEVELS=)"
status="${out%%$'\n'*}"
body="${out#*$'\n'}"
if [ "$status" = "0" ] && printf '%s' "$body" | grep -q '^plan concurrency_jobs=0$'; then
  ok 'an explicitly empty selection disables the concurrency loops'
else
  bad "an explicitly empty selection still planned jobs: $body"
fi
if printf '%s' "$body" | grep -q '^plan job='; then
  bad 'an explicitly empty selection still planned concrete jobs'
else
  ok 'an explicitly empty selection plans no concrete jobs'
fi
printf '%s' "$body" | grep -q '^plan scenarios=enabled$' \
  && ok 'an explicitly empty selection still runs the scenarios' \
  || bad 'an explicitly empty selection dropped the scenarios'

out="$(plan DZN_STRESS_ACTIONS='' DZN_STRESS_LEVELS='5')"
printf '%s' "$out" | grep -q '^plan concurrency_jobs=0$' \
  && ok 'an empty action list disables jobs even when levels are supplied' \
  || bad 'an empty action list was replaced by the default actions'

out="$(plan DZN_STRESS_ACTIONS='accept' DZN_STRESS_LEVELS='')"
printf '%s' "$out" | grep -q '^plan concurrency_jobs=0$' \
  && ok 'an empty level list disables jobs even when actions are supplied' \
  || bad 'an empty level list was replaced by the default levels'

# Unset is different from empty: the documented defaults apply.
out="$(plan)"
printf '%s' "$out" | grep -q '^plan levels=2 5 10 25 50 100$' \
  && ok 'unset DZN_STRESS_LEVELS falls back to the documented default levels' \
  || bad "unset DZN_STRESS_LEVELS did not use the documented defaults: $out"
printf '%s' "$out" | grep -q '^plan actions=submit accept$' \
  && ok 'unset DZN_STRESS_ACTIONS falls back to the documented default actions' \
  || bad "unset DZN_STRESS_ACTIONS did not use the documented defaults: $out"
printf '%s' "$out" | grep -q '^plan concurrency_jobs=12$' \
  && ok 'the default matrix is 6 levels x 2 actions = 12 jobs' \
  || bad "the default matrix is not 12 jobs: $out"

# An explicit single selection must plan exactly that job.
out="$(plan DZN_STRESS_ACTIONS='submit' DZN_STRESS_LEVELS='1000')"
printf '%s' "$out" | grep -q '^plan job=submit-1000$' \
  && ok 'an explicit selection plans exactly the requested job' \
  || bad "an explicit selection did not plan the requested job: $out"

printf '\n%s passed, %s failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
