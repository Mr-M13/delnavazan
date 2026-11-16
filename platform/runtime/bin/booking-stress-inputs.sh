#!/usr/bin/env bash
# Input validation for the intro-booking stress harness.
#
# This library is deliberately free of side effects: no Docker call, no
# filesystem write and no deletion. It is sourced both by
# run-booking-engine-stress.sh before any derived path is built or removed, and
# by platform/tests/booking-stress-runner-contract.sh, which proves that
# malformed input is refused without touching unrelated files.
#
# Source after common.sh; it uses no helper from it, so it is also usable on its
# own in a plain shell.
set -euo pipefail

# Concurrency actions the harness implements. Anything else is a rejected value,
# never a directory name: `$action` is interpolated into a gate path.
dzn_stress_supported_actions(){
  printf '%s\n' submit accept submit_partial process idempotent submit_crash
}

dzn_stress_action_supported(){
  local candidate="${1:?action is required}" supported
  for supported in $(dzn_stress_supported_actions); do
    [ "$candidate" = "$supported" ] && return 0
  done
  return 1
}

# A level becomes a process count and a path component, so it must be a plain
# positive integer: no sign, no separator, no `..`, no whitespace, no leading
# zero that would make `08` ambiguous in shell arithmetic.
dzn_stress_level_supported(){
  local candidate="${1:?level is required}"
  case "$candidate" in
    ''|*[!0-9]*) return 1;;
  esac
  # Reject leading zeroes so the value reads identically as decimal in every context.
  case "$candidate" in
    0*|'') return 1;;
  esac
  [ "${#candidate}" -le 6 ] || return 1
  [ "$candidate" -gt 0 ] || return 1
  return 0
}

dzn_stress_max_level(){
  local capped="${DZN_STRESS_MAX_LEVEL:-1000}"
  dzn_stress_level_supported "$capped" || { echo 'booking-stress: DZN_STRESS_MAX_LEVEL must be a positive integer without leading zeroes.' >&2; return 1; }
  # The 1000-request level is the documented ceiling; allow an explicit raise but
  # never silently accept an unbounded process burst.
  [ "$capped" -le 100000 ] || { echo 'booking-stress: DZN_STRESS_MAX_LEVEL above 100000 is refused.' >&2; return 1; }
  printf '%s\n' "$capped"
}

# True when a caller supplied the variable at all, even as an empty string.
# `${!name+x}` is available in bash 3.2 (macOS) and must not be replaced by the
# `:-` / `-` expansions: those cannot tell "" apart from unset.
dzn_stress_is_set(){
  local name="${1:?variable name is required}"
  [ -n "${!name+x}" ]
}

# Resolve a selection to an ordered list of values.
#
# Contract: the default applies only when the variable is unset. An explicitly
# empty value is an empty selection, which disables that loop entirely.
dzn_stress_selection(){
  local name="${1:?variable name is required}" fallback="$2" value
  if dzn_stress_is_set "$name"; then value="${!name}"; else value="$fallback"; fi
  printf '%s' "$value"
}

# Validate an already-expanded action list. Prints nothing; fails on the first
# unsupported token so the caller stops before building any path.
dzn_stress_require_actions(){
  local action
  for action in "$@"; do
    dzn_stress_action_supported "$action" || { echo "booking-stress: unsupported DZN_STRESS_ACTIONS value: $action" >&2; echo "       supported: $(dzn_stress_supported_actions | tr '\n' ' ')" >&2; return 1; }
  done
  return 0
}

dzn_stress_require_levels(){
  local level max
  max="$(dzn_stress_max_level)" || return 1
  for level in "$@"; do
    dzn_stress_level_supported "$level" || { echo "booking-stress: unsupported DZN_STRESS_LEVELS value: $level (expected a positive integer)" >&2; return 1; }
    [ "$level" -le "$max" ] || { echo "booking-stress: DZN_STRESS_LEVELS value $level exceeds DZN_STRESS_MAX_LEVEL ($max)" >&2; return 1; }
  done
  return 0
}
