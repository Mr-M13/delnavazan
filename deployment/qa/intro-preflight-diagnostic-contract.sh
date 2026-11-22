#!/usr/bin/env bash
# Offline source contract for the read-only intro pre-flight diagnostic.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
payload="$root/deployment/qa/diagnose-intro-preflight.php"
wrapper="$root/deployment/qa/intro-preflight-diagnostic.sh"
workflow="$root/.github/workflows/diagnose-intro-preflight.yml"
agents="$root/AGENTS.md"
pr_gate="$root/.github/workflows/pr-runtime-gate.yml"

for file in "$payload" "$wrapper" "$workflow" "$agents" "$pr_gate"; do
  [[ -f "$file" ]] || { echo "Missing intro diagnostic control: $file" >&2; exit 1; }
done

php -l "$payload" >/dev/null
bash -n "$wrapper"
bash -n "$0"

must_contain() {
  local file="$1" needle="$2" message="$3"
  grep -Fq -- "$needle" "$file" || { echo "Missing $message: $needle" >&2; exit 1; }
}

# --- read-only guarantees -----------------------------------------------------
for forbidden in "->update(" "->insert(" "->delete(" "db export" "\$wpdb->query"; do
  if grep -Fq -- "$forbidden" "$payload"; then
    echo "The diagnostic must never write: found $forbidden" >&2
    exit 1
  fi
done

must_contain "$payload" "'read_only' => true" "read-only declaration"
must_contain "$payload" "assertAvailableForPreview" "the real pre-flight"
must_contain "$payload" "service_preview" "the service preview"
must_contain "$payload" "teacher_occupancy" "occupancy check"
must_contain "$payload" "continuation_hold" "continuation hold check"
must_contain "$payload" "student_occupancy" "student occupancy check"
must_contain "$payload" "recent_requests" "recent request evidence"
must_contain "$payload" "wp_set_current_user" "administrator read context"

# --- wrapper ------------------------------------------------------------------
must_contain "$wrapper" "eval-file" "WP-CLI eval-file"
must_contain "$wrapper" 'main-${sha:0:12}' "deployed build identity binding"
must_contain "$wrapper" "DZN-STU-[A-Z0-9]+" "student reference validation"

# --- workflow -----------------------------------------------------------------
must_contain "$workflow" "workflow_dispatch:" "manual-only trigger"
must_contain "$workflow" "environment: production" "protected production environment"
must_contain "$workflow" "delnavazan-production-deploy" "deployment serialization"
must_contain "$workflow" "intro-preflight-diagnostic-contract.sh" "source contract gate"
must_contain "$workflow" "source_sha" "reviewed source SHA"
must_contain "$workflow" "commit_sha" "deployed build SHA"

if grep -Eq '^  (push|pull_request|schedule):' "$workflow"; then
  echo 'The diagnostic workflow must be workflow_dispatch only.' >&2
  exit 1
fi

if grep -Eq '^[[:space:]]+confirm:' "$workflow" || grep -Eq 'PURGE_ATTESTATION_TOKEN|RETIRE_IMPORTED_ENROLMENT' "$workflow"; then
  echo 'The read-only diagnostic must not carry a confirm token or attestation secret.' >&2
  exit 1
fi

# --- wiring and documentation -------------------------------------------------
must_contain "$pr_gate" "intro-preflight-diagnostic-contract.sh" "PR gate wiring"
must_contain "$agents" "intro-preflight-diagnostic.sh" "AGENTS contract synchronization"

echo "Intro pre-flight diagnostic contract passed"
