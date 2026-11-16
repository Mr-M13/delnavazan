#!/usr/bin/env bash
# Source contract for the audited WhatsApp template-creation workflow.
#
# Static, hermetic and offline: it reads the workflow YAML, the worker script and
# the documents it must stay in sync with, and never contacts GitHub, Telnyx or
# production. It asserts the authority boundaries that make the dispatch safe:
#
#   - workflow_dispatch only, from main, in the protected production environment;
#   - bound to the exact current origin/main SHA and an exact confirm token;
#   - a checkout pinned to that SHA, with no rolling branch ref;
#   - default mode is dry_run (never apply by default);
#   - only the two documented provider secrets, never printed and fail closed;
#   - the manifest is validated offline (provider/category/language/placeholder
#     shape, unique names, missing samples) before any network call;
#   - the worker can list (GET) and create (POST) only; no update or delete verb
#     exists anywhere, and no credential is hardcoded.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$ROOT/.github/workflows/create-whatsapp-templates.yml"
script="$ROOT/deployment/notifications/whatsapp-template-plan.py"
manifest="$ROOT/deployment/notifications/whatsapp-templates.json"
pr_gate="$ROOT/.github/workflows/pr-runtime-gate.yml"
agents="$ROOT/AGENTS.md"

pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ grep -Fq -- "$2" "$1" || fail "$(basename "$1") is missing required boundary: $2"; }
forbid(){ if grep -Fq -- "$2" "$1"; then fail "$(basename "$1") must not contain: $2"; fi; }
forbid_w(){ if grep -Fqw -- "$2" "$1"; then fail "$(basename "$1") must not contain the word: $2"; fi; }
forbid_re(){ if grep -Eqi -- "$2" "$1"; then fail "$(basename "$1") must not contain the pattern: $2"; fi; }

# --------------------------------------------------------------- presence ---
[[ -f "$workflow" ]] || fail 'the WhatsApp template-create workflow is missing'
[[ -f "$script" ]] || fail 'deployment/notifications/whatsapp-template-plan.py is missing'
[[ -f "$manifest" ]] || fail 'deployment/notifications/whatsapp-templates.json is missing'
[[ -f "$pr_gate" ]] || fail '.github/workflows/pr-runtime-gate.yml is missing'
[[ -f "$agents" ]] || fail 'AGENTS.md is missing'
python3 -m py_compile "$script" || fail 'whatsapp-template-plan.py is not valid Python'
pass 'the workflow, the worker script, the manifest and the documents are present and parse'

# ------------------------------------------------------- dispatch surface ---
need "$workflow" 'workflow_dispatch:'
need "$workflow" 'commit_sha:'
need "$workflow" 'confirm:'
need "$workflow" 'mode:'
need "$workflow" 'options: [dry_run, apply]'
need "$workflow" 'default: dry_run'
need "$workflow" 'required: true'
need "$workflow" 'environment: production'
need "$workflow" 'permissions:'
need "$workflow" 'contents: read'
need "$workflow" 'group: delnavazan-whatsapp-templates'
need "$workflow" 'cancel-in-progress: false'
need "$workflow" 'retention-days: 90'
forbid "$workflow" 'default: apply'
forbid "$workflow" '  push:'
forbid "$workflow" '  pull_request:'
forbid "$workflow" '  schedule:'
forbid "$workflow" '  workflow_call:'
forbid "$workflow" 'workflow_run:'
forbid "$workflow" 'continue-on-error'
pass 'dispatch is workflow_dispatch only, defaults to dry_run, in the protected production environment'

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
forbid "$workflow" 'ref: main'
forbid "$workflow" 'ref: ${{ github.ref }}'
forbid "$workflow" 'ref: ${{ inputs.commit_sha }}'
pass 'the workflow refuses anything but the exact current origin/main SHA'

# ----------------------------------------------------------- confirm token ---
need "$workflow" "[[ \"\$CONFIRM\" == 'CREATE_WA_TEMPLATES' ]]"
[[ "$(grep -Fc "CREATE_WA_TEMPLATES" "$workflow")" -ge 2 ]] \
  || fail 'the confirm token must be validated in the gate job and again before creating'
pass 'the exact CREATE_WA_TEMPLATES confirm token is required and re-checked'

# ------------------------------------------- exactly the two provider secrets --
need "$workflow" 'TELNYX_API_KEY: ${{ secrets.TELNYX_API_KEY }}'
need "$workflow" 'TELNYX_WABA_ID: ${{ secrets.TELNYX_WABA_ID }}'
need "$workflow" '::add-mask::'
need "$workflow" 'must be configured in the production environment'
secrets_used="$(grep -o 'secrets\.[A-Za-z0-9_]*' "$workflow" | sed 's/^secrets\.//' | LC_ALL=C sort -u || true)"
expected_secrets="$(printf '%s\n' TELNYX_API_KEY TELNYX_WABA_ID | LC_ALL=C sort -u)"
[[ "$secrets_used" == "$expected_secrets" ]] \
  || fail "the workflow must reference exactly the two documented Telnyx secrets; found: $(printf '%s ' $secrets_used)"
need "$script" 'os.environ'
pass 'only TELNYX_API_KEY and TELNYX_WABA_ID are reachable, read from the environment and never printed'

# -------------------------------------------------- manifest validation body --
need "$script" 'REQUIRED_PROVIDER = "telnyx"'
need "$script" 'REQUIRED_CATEGORY = "UTILITY"'
need "$script" 'ALLOWED_LANGUAGES = ("fa", "en")'
need "$script" 'duplicate name'
need "$script" 'named placeholders'
need "$script" 'len(positional) != len(variables)'
need "$script" 'missing_samples'
need "$script" 'filter[waba_id]'
need "$script" 'page[size]'
need "$script" 'total_pages'
need "$script" 'CREATE_PACING_SECONDS = 2'
need "$script" 'time.sleep(CREATE_PACING_SECONDS)'
need "$script" '"type": "BODY"'
need "$script" '"example"'
need "$script" '"body_text"'
need "$script" '"waba_id"'
need "$script" '"language"'
need "$script" '"category"'
need "$script" 'message_templates'
need "$script" '"POST"'
pass 'the manifest is validated offline and the create shape is the official Telnyx one'

# ----------------------------------------- no update/delete verb, create only --
for target in "$workflow" "$script"; do
  forbid_w "$target" 'DELETE'
  forbid_w "$target" 'PUT'
  forbid_w "$target" 'PATCH'
  forbid "$target" '-X DELETE'
  forbid "$target" '-X PUT'
  forbid "$target" '-X PATCH'
done
forbid_re "$script" "method[[:space:]]*=[[:space:]]*[\"'](DELETE|PUT|PATCH)"
pass 'the workflow and worker can list and create only; no update or delete verb exists'

# ------------------------------------------------- no hardcoded credential ----
for target in "$workflow" "$script"; do
  forbid_re "$target" 'BEGIN (RSA|OPENSSH|EC) PRIVATE KEY'
  forbid_re "$target" 'AKIA[0-9A-Z]{16}'
  forbid_re "$target" '(sk_live_|whsec_|ghp_|github_pat_)'
  forbid_re "$target" 'Bearer[[:space:]]+[A-Za-z0-9]'
  forbid_re "$target" "(password|passwd|client[_-]?secret|api[_-]?key)[[:space:]]*[:=][[:space:]]*[\"']"
done
pass 'no hardcoded credential, private key or literal bearer token is reachable'

# ------------------------------------------------ documentation agreement ---
need "$pr_gate" 'bash -n deployment/notifications/whatsapp-template-create-contract.sh'
need "$pr_gate" 'bash deployment/notifications/whatsapp-template-create-contract.sh'
need "$agents" 'create-whatsapp-templates.yml'
need "$agents" 'whatsapp-template-create-contract.sh'
need "$agents" 'CREATE_WA_TEMPLATES'
pass 'the PR gate and AGENTS.md describe this workflow and its limits'

printf 'PASS WhatsApp template-create workflow contract\n'
