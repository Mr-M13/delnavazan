#!/usr/bin/env bash
# Source contract for the protected one-shot production purge workflow.
# The workflow is the only sanctioned execution path for the destructive mode.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$ROOT/.github/workflows/purge-production-test-data.yml"
wrapper="$ROOT/deployment/qa/purge-test-data.sh"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ grep -Fq -- "$2" "$1" || fail "$1 missing required boundary: $2"; }
forbid(){ if grep -Fq -- "$2" "$1"; then fail "$1 must not contain: $2"; fi; }

[[ -f "$workflow" ]] || fail 'purge workflow is missing'
bash -n "$wrapper" || fail 'purge wrapper is not valid bash'

need "$workflow" 'workflow_dispatch:'
need "$workflow" 'environment: production'
need "$workflow" 'refs/heads/main'
need "$workflow" 'PURGE_ALL_TEST_DATA'
need "$workflow" '20261123'
need "$workflow" 'group: delnavazan-production-deploy'
need "$workflow" 'git merge-base --is-ancestor'
need "$workflow" 'source_sha'
need "$workflow" 'ref: ${{ inputs.source_sha }}'
need "$workflow" 'PURGE_ATTESTATION_TOKEN'
need "$workflow" 'DZN_PURGE_ATTESTATION'
need "$workflow" 'dzn_go_live_purge_receipt'
need "$workflow" 'Refuse a repeat purge'
need "$workflow" 'purge-production-test-data-contract.sh'
need "$workflow" 'purge-test-data-contract.sh'
need "$workflow" 'deployment/qa/purge-test-data.sh report'
need "$workflow" 'deployment/qa/purge-test-data.sh execute'
need "$workflow" 'Pre-state changed'
need "$workflow" 'purge_rows_remaining'
need "$workflow" 'admin_manage_options'
need "$workflow" 'catalogue_unchanged'
need "$workflow" 'PRODUCTION_SSH_PRIVATE_KEY'
need "$workflow" 'PRODUCTION_KNOWN_HOSTS'
need "$workflow" 'PRODUCTION_SSH_USER'
need "$workflow" 'permissions:'
need "$workflow" 'contents: read'
need "$workflow" 'retention-days: 90'

# One-shot only: no push, pull_request, schedule or workflow_call trigger, and
# no second job that could bypass the confirmation/fingerprint gates.
forbid "$workflow" '  push:'
forbid "$workflow" '  pull_request:'
forbid "$workflow" '  schedule:'
forbid "$workflow" '  workflow_call:'

# The destructive mode must never be reachable from a read-only or deploy path.
if grep -Fq 'purge-test-data.sh execute' "$ROOT/.github/workflows/deploy-production.yml"; then
  fail 'deploy workflow must not execute the purge'
fi

echo 'PASS protected production purge workflow contract'
