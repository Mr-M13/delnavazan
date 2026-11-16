#!/usr/bin/env bash
# Source contract for the audited production host-tooling install workflow.
#
# Static, hermetic and offline: it reads the workflow YAML plus the documents it
# must stay in sync with, and never contacts GitHub or production. It asserts the
# authority boundaries that make the dispatch safe:
#
#   - workflow_dispatch only, from main, in the protected production environment;
#   - bound to the exact current origin/main SHA and an exact confirm token;
#   - a checkout pinned to that SHA, with no rolling branch ref;
#   - only the three documented production SSH secrets, and every remote-shell
#     step carries the documented SSH user;
#   - an append-only cron install whose marker makes a re-run idempotent;
#   - it copies and runs the backup tool, and never restores, deploys, migrates,
#     purges, deletes a point or reads a credential.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$ROOT/.github/workflows/install-production-host-tooling.yml"
pr_gate="$ROOT/.github/workflows/pr-runtime-gate.yml"
deploy_workflow="$ROOT/.github/workflows/deploy-production.yml"
purge_workflow="$ROOT/.github/workflows/purge-production-test-data.yml"
backup_sh="$ROOT/deployment/qa/backup-point.sh"
restore_sh="$ROOT/deployment/qa/restore-point.sh"
runbook="$ROOT/docs/BACKUP-ROLLBACK-RUNBOOK.md"
agents="$ROOT/AGENTS.md"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ grep -Fq -- "$2" "$1" || fail "$(basename "$1") is missing required boundary: $2"; }
forbid(){ if grep -Fq -- "$2" "$1"; then fail "$(basename "$1") must not contain: $2"; fi; }
forbid_re(){ if grep -Eqi -- "$2" "$1"; then fail "$(basename "$1") must not contain the pattern: $2"; fi; }

# --------------------------------------------------------------- presence ---
[[ -f "$workflow" ]] || fail 'the host-tooling install workflow is missing'
[[ -f "$pr_gate" ]] || fail '.github/workflows/pr-runtime-gate.yml is missing'
[[ -f "$runbook" ]] || fail 'docs/BACKUP-ROLLBACK-RUNBOOK.md is missing'
[[ -f "$agents" ]] || fail 'AGENTS.md is missing'
bash -n "$backup_sh" || fail 'backup-point.sh is not valid bash'
bash -n "$restore_sh" || fail 'restore-point.sh is not valid bash'
pass 'the workflow, the documents and both host tools are present and parse'

# ------------------------------------------------------- dispatch surface ---
need "$workflow" 'workflow_dispatch:'
need "$workflow" 'commit_sha:'
need "$workflow" 'confirm:'
need "$workflow" 'required: true'
need "$workflow" 'environment: production'
need "$workflow" 'permissions:'
need "$workflow" 'contents: read'
need "$workflow" 'group: delnavazan-production-deploy'
need "$workflow" 'cancel-in-progress: false'
need "$workflow" 'retention-days: 90'
forbid "$workflow" '  push:'
forbid "$workflow" '  pull_request:'
forbid "$workflow" '  schedule:'
forbid "$workflow" '  workflow_call:'
forbid "$workflow" 'workflow_run:'
forbid "$workflow" 'continue-on-error'
pass 'dispatch is workflow_dispatch only, in the protected production environment, serialized with the deploy'

# ---------------------------------------------------------- main-only gate ---
need "$workflow" 'refs/heads/main'
need "$workflow" '^[0-9a-f]{40}$'
need "$workflow" 'git fetch --no-tags origin'
need "$workflow" "'+refs/heads/main:refs/remotes/origin/main'"
need "$workflow" 'origin_main="$(git rev-parse origin/main)"'
need "$workflow" '[[ "$origin_main" == "$COMMIT_SHA" ]]'
need "$workflow" 'must still be current origin/main'
need "$workflow" '[[ "$(git rev-parse HEAD)" == "$COMMIT_SHA" ]]'
need "$workflow" '[[ "$GITHUB_SHA" == "$COMMIT_SHA" ]]'
need "$workflow" 'ref: ${{ github.sha }}'
need "$workflow" 'persist-credentials: false'
need "$workflow" 'needs: verify'
# No rolling ref may be checked out, and no privileged job may check out a ref
# chosen by a dispatch input (the untrusted-checkout rule): the run commit is
# checked out and proven equal to the requested SHA instead.
forbid "$workflow" 'ref: main'
forbid "$workflow" 'ref: ${{ github.ref }}'
forbid "$workflow" 'ref: ${{ inputs.commit_sha }}'
pass 'the workflow refuses anything but the exact current origin/main SHA'

# ----------------------------------------------------------- confirm token ---
need "$workflow" "[[ \"\$CONFIRM\" == 'INSTALL_HOST_TOOLING' ]]"
[[ "$(grep -Fc "INSTALL_HOST_TOOLING" "$workflow")" -ge 2 ]] \
  || fail 'the confirm token must be validated in the gate job and again before installing'
pass 'the exact INSTALL_HOST_TOOLING confirm token is required and re-checked'

# ------------------------------------------------- the three documented keys --
need "$workflow" 'PRODUCTION_SSH_USER'
need "$workflow" 'PRODUCTION_SSH_PRIVATE_KEY: ${{ secrets.PRODUCTION_SSH_PRIVATE_KEY }}'
need "$workflow" 'PRODUCTION_KNOWN_HOSTS: ${{ secrets.PRODUCTION_KNOWN_HOSTS }}'
need "$workflow" 'Known-hosts secret must pin cp119.hosting-cloud.net'
need "$workflow" 'cp119.hosting-cloud.net'

# Exactly the three documented secrets: no other secret can be reached from a
# step that installs on, or runs against, the production host.
secrets_used="$(grep -o 'secrets\.[A-Za-z0-9_]*' "$workflow" | sed 's/^secrets\.//' | LC_ALL=C sort -u || true)"
expected_secrets="$(printf '%s\n' PRODUCTION_KNOWN_HOSTS PRODUCTION_SSH_PRIVATE_KEY PRODUCTION_SSH_USER | LC_ALL=C sort -u)"
[[ "$secrets_used" == "$expected_secrets" ]] \
  || fail "the workflow must reference exactly the three documented production SSH secrets; found: $(printf '%s ' $secrets_used)"

# Every step that opens a remote shell or copies to the host must carry the
# documented SSH user. A remote step without it would have to invent another
# identity, which this workflow is not allowed to do.
mkdir -p "$tmp/steps"
awk -v out="$tmp/steps" '
  /^      - name: / { n++; file = sprintf("%s/step-%02d.txt", out, n) }
  n > 0 { print > file }
' "$workflow"
remote_steps=0
for step in "$tmp"/steps/step-*.txt; do
  [[ -f "$step" ]] || continue
  if grep -Eq '^[[:space:]]*(ssh|scp)[[:space:]]' "$step"; then
    remote_steps=$((remote_steps + 1))
    grep -Fq 'PRODUCTION_SSH_USER' "$step" \
      || fail "step $(basename "$step") opens a remote shell without PRODUCTION_SSH_USER"
    grep -Fq 'secrets.PRODUCTION_SSH_USER' "$step" \
      || fail "step $(basename "$step") opens a remote shell without the documented secret source"
  fi
done
(( remote_steps >= 3 )) \
  || fail "expected the directory, tooling, cron and rollback-point steps to run over SSH; found $remote_steps"
pass "every remote-shell step ($remote_steps) uses only the three documented secrets"

# ------------------------------------------------------------- install body --
need "$workflow" 'deployment/qa/backup-point.sh:dzn-backup-point.sh'
need "$workflow" 'deployment/qa/restore-point.sh:dzn-restore-point.sh'
need "$workflow" 'mkdir -p "$HOME/bin"'
need "$workflow" 'chmod 700 "$HOME/bin"'
need "$workflow" 'chmod 700 "$HOME/bin/$name"'
need "$workflow" 'mv -f "$HOME/bin/.$name.new" "$HOME/bin/$name"'
need "$workflow" 'bash -n "$HOME/bin/$name"'
need "$workflow" 'BYTES='
pass 'the reviewed scripts are copied atomically, 0700, and proven complete and valid'

# ------------------------------------------------------ cron is idempotent ---
need "$workflow" "readonly CRON_MARKER='dzn-backup-point.sh'"
need "$workflow" '7 3 * * *'
need "$workflow" 'crontab -l 2>/dev/null'
need "$workflow" '| crontab -'
need "$workflow" 'grep -Fq -- "$CRON_MARKER"'
need "$workflow" 'CRON=installed'
need "$workflow" 'CRON=existing-left-untouched'
need "$workflow" "[[ \"\$count\" == '1' ]]"
need "$workflow" 'CRON_LINES=%s'
forbid "$workflow" 'crontab -r'
forbid "$workflow" 'crontab -i'
pass 'the host cron line is append-only, marker-guarded and proven single after install'

# ---------------------------------------------- first point, off-site off ---
need "$workflow" 'DZN_BACKUP_REMOTE= bash "$HOME/bin/dzn-backup-point.sh"'
need "$workflow" 'ROLLBACK_POINT=ok'
need "$workflow" 'OFFSITE=skipped'
need "$workflow" 'BUILD=main-[0-9a-f]{12}'
need "$workflow" 'SCHEMA=[0-9]+'
forbid_re "$workflow" 'DZN_BACKUP_REMOTE=[^[:space:]]'
forbid "$workflow" 'DZN_BACKUP_ROOT='
forbid "$workflow" 'rclone copy'
forbid "$workflow" 'rclone config'
forbid "$workflow" 'rclone.conf'
pass 'the first point is taken with off-site copy deliberately disabled and verified'

# ------------------------------------------- restore is operator-only, only --
# The restore tool may be *copied* for the operator; it must never be run, and
# no CI path may open a maintenance window or import a database.
forbid "$workflow" '--confirm RESTORE'
forbid "$workflow" 'RESTORE=ok'
forbid "$workflow" 'dzn-restore-point.sh --'
forbid "$workflow" 'restore-point.sh --point'
forbid "$workflow" 'restore-point.sh --dry-run'
forbid "$workflow" 'bash ~/bin/dzn-restore-point.sh'
forbid "$workflow" 'bash "$HOME/bin/dzn-restore-point.sh"'
forbid "$workflow" 'maintenance-mode'
forbid "$workflow" 'db import'
forbid "$workflow" 'wp --path='
need "$workflow" 'never restores'
pass 'the workflow cannot restore, open a maintenance window or import a database'

# ----------------------------------------- no deploy, no delete, no secrets --
forbid "$workflow" 'production-deploy.sh'
forbid "$workflow" 'deploy-production.yml'
forbid "$workflow" 'purge-test-data.sh'
forbid "$workflow" 'PURGE_ATTESTATION'
forbid "$workflow" 'public_html'
forbid "$workflow" 'rm -rf'
forbid "$workflow" 'curl '
forbid "$workflow" 'wget '
forbid "$workflow" 'crontab -r'
forbid_re "$workflow" 'BEGIN (RSA|OPENSSH|EC) PRIVATE KEY'
forbid_re "$workflow" 'AKIA[0-9A-Z]{16}'
forbid_re "$workflow" '(password|passwd|api[_-]?key|client[_-]?secret)[[:space:]]*[:=][[:space:]]*[^[:space:]]'
forbid_re "$workflow" '(sk_live_|whsec_|ghp_|github_pat_)'
forbid "$workflow" 'wp-config'
pass 'no deploy, delete, purge, web-tree write, download or hardcoded credential is reachable'

# ------------------------------------------------ documentation agreement ---
need "$pr_gate" 'bash -n deployment/qa/install-host-tooling-contract.sh'
need "$pr_gate" 'bash deployment/qa/install-host-tooling-contract.sh'
need "$runbook" 'install-production-host-tooling.yml'
need "$runbook" 'INSTALL_HOST_TOOLING'
need "$runbook" 'commit_sha'
need "$runbook" 'operator-only'
need "$runbook" 'DZN_BACKUP_REMOTE'
need "$agents" 'install-production-host-tooling.yml'
need "$agents" 'install-host-tooling-contract.sh'
need "$agents" 'INSTALL_HOST_TOOLING'
pass 'the PR gate, the runbook and AGENTS.md describe this workflow and its limits'

# The install path must never be duplicated into the release or purge paths.
if grep -Fq 'install-production-host-tooling' "$deploy_workflow"; then
  fail 'the deploy workflow must not install host tooling'
fi
if grep -Fq 'install-production-host-tooling' "$purge_workflow"; then
  fail 'the purge workflow must not install host tooling'
fi
pass 'the install path is confined to its own workflow'

printf 'PASS production host-tooling install workflow contract\n'
