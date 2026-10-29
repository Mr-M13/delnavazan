#!/usr/bin/env bash
set -Eeuo pipefail
here="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
decision="$here/production-smoke-decision.sh"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
expect(){ local expected="$1" primary="$2" confirmation="$3" label="$4" rc=0; "$decision" "$primary" "$confirmation" || rc=$?; [[ "$rc" == "$expected" ]] || fail "$label (expected exit $expected, got $rc)"; }
expect 1 success skipped 'successful primary never activates maintenance'
expect 1 failure success 'a successful confirmation keeps production live'
expect 0 failure failure 'confirmed failure activates maintenance'
expect 0 failure skipped 'missing confirmation fails closed'
expect 0 cancelled skipped 'cancelled primary fails closed'
expect 1 success failure 'successful primary wins over a noisy confirmation'
echo 'production smoke maintenance decision unit: PASS'
