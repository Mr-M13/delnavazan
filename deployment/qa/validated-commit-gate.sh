#!/usr/bin/env bash
set -Eeuo pipefail

fail(){ printf 'FAIL %s\n' "$1" >&2; exit 1; }
[[ $# == 1 ]] || fail 'usage: validated-commit-gate.sh <full-commit-sha>'
sha="$1"
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || fail 'commit must be one full 40-character SHA'
: "${GH_TOKEN:?GH_TOKEN is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"

run="$(gh api --paginate "repos/$GITHUB_REPOSITORY/actions/runs?head_sha=$sha&per_page=100" \
  --jq '.workflow_runs[] | select(.name == "PR runtime gate" and .head_sha == "'"$sha"'" and .status == "completed" and .conclusion == "success") | [.id,.run_number,.updated_at] | @tsv' \
  | sort -k3,3r | head -n 1)"
[[ -n "$run" ]] || fail "no successful PR runtime gate run exists for $sha"
read -r run_id run_number run_updated <<<"$run"
printf 'PASS validated commit %s via PR runtime gate run %s (updated %s)\n' "$sha" "$run_number" "$run_updated"
