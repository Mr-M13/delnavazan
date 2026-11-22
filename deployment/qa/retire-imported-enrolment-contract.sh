#!/usr/bin/env bash
# Offline source contract for the imported-enrolment retirement tool.
#
# It proves the destructive surface stays narrow without touching a database:
# no row is ever deleted, every archive is preceded by a verified export and a
# fingerprint re-check, and the workflow is manual-only and environment-gated.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
payload="$root/deployment/qa/retire-imported-enrolment.php"
wrapper="$root/deployment/qa/retire-imported-enrolment.sh"
workflow="$root/.github/workflows/retire-imported-enrolment.yml"
agents="$root/AGENTS.md"
pr_gate="$root/.github/workflows/pr-runtime-gate.yml"

for file in "$payload" "$wrapper" "$workflow" "$agents" "$pr_gate"; do
  [[ -f "$file" ]] || { echo "Missing imported-enrolment retirement control: $file" >&2; exit 1; }
done

php -l "$payload" >/dev/null
bash -n "$wrapper"
bash -n "$0"

must_contain() {
  local file="$1" needle="$2" message="$3"
  grep -Fq -- "$needle" "$file" || { echo "Missing $message: $needle" >&2; exit 1; }
}

must_not_contain_regex() {
  local file="$1" pattern="$2" message="$3"
  if grep -Eqi -- "$pattern" "$file"; then
    echo "Forbidden $message in $(basename "$file"): $pattern" >&2
    exit 1
  fi
}

# --- payload -----------------------------------------------------------------
must_contain "$payload" "RETIRE_IMPORTED_ENROLMENT" "confirm token"
must_contain "$payload" "b74053a74d5419f2bb026036e1dd05ca233b7ccef2df5949acd4ef1303041102" "workflow attestation digest"
must_contain "$payload" "START TRANSACTION" "transactional retirement"
must_contain "$payload" "COMMIT" "transaction commit"
must_contain "$payload" "ROLLBACK" "transaction rollback"
must_contain "$payload" "FOR UPDATE" "row locks before archiving"
must_contain "$payload" "enrolment.imported_retirement" "audit event type"
must_contain "$payload" "enrolment.historical_import" "import-origin proof"
must_contain "$payload" "term_payment_recorded" "refusal for recorded money"
must_contain "$payload" "lesson_already_started" "refusal for a live term"
must_contain "$payload" "downstream_reference_exists" "refusal for downstream records"
must_contain "$payload" "canonical_enrolment_exists" "refusal when the paid path already exists"
must_contain "$payload" "hash_file" "backup checksum verification"
must_contain "$payload" "fingerprint_mismatch" "pre-state fingerprint binding"
must_contain "$payload" "lessons_visible_remaining" "post-retirement portal proof"
must_contain "$payload" "teacher_slot_conflicts" "post-retirement availability proof"
must_contain "$payload" "catalogue_unchanged" "configuration preservation proof"
must_contain "$payload" "student_intact" "student preservation proof"
must_contain "$payload" "archived_at' => \$now" "archive timestamp write"
must_contain "$payload" "purge-test-data-tables.php" "shared catalogue classification"

# The tool must archive, never delete.
must_not_contain_regex "$payload" "DELETE[[:space:]]+FROM" "row deletion"
must_not_contain_regex "$payload" "TRUNCATE[[:space:]]+TABLE" "table truncation"
must_not_contain_regex "$payload" "DROP[[:space:]]+TABLE" "table drop"

# Archive order is part of the design: lessons, then Term, then Enrolment.
lessons_at="$(grep -n "'lesson_archive_failed'" "$payload" | head -1 | cut -d: -f1)"
term_at="$(grep -n "'term_archive_failed'" "$payload" | head -1 | cut -d: -f1)"
enrolment_at="$(grep -n "'enrolment_archive_failed'" "$payload" | head -1 | cut -d: -f1)"
[[ -n "$lessons_at" && -n "$term_at" && -n "$enrolment_at" ]] || { echo 'Missing archive failure guards in the payload.' >&2; exit 1; }
(( lessons_at < term_at && term_at < enrolment_at )) || { echo 'Archive order must be lessons, then Term, then Enrolment.' >&2; exit 1; }

# --- wrapper -----------------------------------------------------------------
must_contain "$wrapper" "RETIRE_IMPORTED_ENROLMENT" "wrapper confirm token"
must_contain "$wrapper" "sha256sum" "backup checksum"
must_contain "$wrapper" "db export" "full database export"
must_contain "$wrapper" "gzip -t" "backup gzip integrity"
must_contain "$wrapper" 'main-${sha:0:12}' "deployed build identity binding"
must_contain "$wrapper" "DZN_RETIRE_ATTESTATION" "workflow attestation"
must_contain "$wrapper" "ENR-[A-Z0-9]+" "enrolment reference validation"
must_contain "$wrapper" "CC[A-Z0-9]+" "student reference validation"

# --- workflow ----------------------------------------------------------------
must_contain "$workflow" "workflow_dispatch:" "manual-only trigger"
must_contain "$workflow" "environment: production" "protected production environment"
must_contain "$workflow" "enrolment_reference" "enrolment reference input"
must_contain "$workflow" "student_reference" "student reference input"
must_contain "$workflow" "RETIRE_IMPORTED_ENROLMENT" "confirm token"
must_contain "$workflow" "PURGE_ATTESTATION_TOKEN" "protected attestation secret"
must_contain "$workflow" "retire-imported-enrolment-contract.sh" "source contract gate"
must_contain "$workflow" "delnavazan-production-deploy" "deployment serialization"
must_contain "$workflow" "source_sha" "reviewed source SHA"
must_contain "$workflow" "commit_sha" "deployed build SHA"
must_contain "$workflow" "post_verification.teacher_slot_conflicts" "availability proof assertion"

# --- wiring and documentation ------------------------------------------------
must_contain "$pr_gate" "retire-imported-enrolment-contract.sh" "PR gate wiring"
must_contain "$agents" "retire-imported-enrolment.sh" "AGENTS contract synchronization"

if grep -Eq '^  (push|pull_request|schedule):' "$workflow"; then
  echo 'The retirement workflow must be workflow_dispatch only.' >&2
  exit 1
fi

if grep -Eq 'environment:[[:space:]]*$' "$workflow"; then
  echo 'The retirement workflow must name the production environment inline.' >&2
  exit 1
fi

# No raw personal identifier may be committed in the new tool.
if grep -REn --include='retire-imported-enrolment.php' --include='retire-imported-enrolment.sh' --include='retire-imported-enrolment.yml' \
  '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$root/deployment" "$root/.github/workflows" >/dev/null; then
  echo 'The retirement tool must not contain a raw email address.' >&2
  exit 1
fi

echo "Imported-enrolment retirement contract passed"
