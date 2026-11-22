#!/usr/bin/env bash
# Offline source contract for the targeted test-account deletion tool.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
payload="$root/deployment/qa/delete-test-student.php"
wrapper="$root/deployment/qa/delete-test-student.sh"
workflow="$root/.github/workflows/delete-test-student.yml"
agents="$root/AGENTS.md"
pr_gate="$root/.github/workflows/pr-runtime-gate.yml"

for file in "$payload" "$wrapper" "$workflow" "$agents" "$pr_gate"; do
  [[ -f "$file" ]] || { echo "Missing targeted-deletion control: $file" >&2; exit 1; }
done

php -l "$payload" >/dev/null
bash -n "$wrapper"
bash -n "$0"
bash -n "$root/deployment/qa/purge-test-data.sh"

must_contain() {
  local file="$1" needle="$2" message="$3"
  grep -Fq -- "$needle" "$file" || { echo "Missing $message: $needle" >&2; exit 1; }
}

must_contain "$payload" "DELETE_TEST_STUDENT" "confirm token"
must_contain "$payload" "a6b159ec09a2ad752655da929d8f996d28862aed6851ccc250108ead42e21f7d" "retained administrator hash"
must_contain "$payload" "b74053a74d5419f2bb026036e1dd05ca233b7ccef2df5949acd4ef1303041102" "workflow attestation digest"
must_contain "$payload" "wp_delete_user" "WordPress user deletion"
must_contain "$payload" "SET FOREIGN_KEY_CHECKS=0" "foreign-key-safe transaction"
must_contain "$payload" "START TRANSACTION" "transactional deletion"
must_contain "$payload" "COMMIT" "transaction commit"
must_contain "$payload" "ROLLBACK" "transaction rollback"
must_contain "$payload" "hash_file" "backup checksum verification"
must_contain "$payload" "target_rows_remaining" "post-deletion verification"
must_contain "$payload" "admin_manage_options" "administrator preservation proof"
must_contain "$payload" "catalogue_unchanged" "configuration preservation proof"
must_contain "$payload" "purge-test-data-tables.php" "shared table classification"

must_contain "$wrapper" "DELETE_TEST_STUDENT" "wrapper confirm token"
must_contain "$wrapper" "sha256sum" "backup checksum"
must_contain "$wrapper" "db export" "full database export"
must_contain "$wrapper" "mariadb-dump" "hardened-host export fallback"
must_contain "$wrapper" "MYSQL_PWD" "credential-safe dump invocation"
must_contain "$wrapper" "gzip -t" "backup gzip integrity"
must_contain "$wrapper" "main-\${sha:0:12}" "deployed build identity binding"
must_contain "$wrapper" "DZN_DELETE_ATTESTATION" "workflow attestation"

must_contain "$workflow" "workflow_dispatch:" "manual-only trigger"
must_contain "$workflow" "environment: production" "protected production environment"
must_contain "$workflow" "target_email_sha256" "email-hash input"
must_contain "$workflow" "DELETE_TEST_STUDENT" "confirm token"
must_contain "$workflow" "PURGE_ATTESTATION_TOKEN" "protected attestation secret"
must_contain "$workflow" "delete-test-student-contract.sh" "source contract gate"
must_contain "$workflow" "delnavazan-production-deploy" "deployment serialization"
must_contain "$workflow" "source_sha" "reviewed source SHA"
must_contain "$workflow" "commit_sha" "deployed build SHA"

must_contain "$pr_gate" "delete-test-student-contract.sh" "PR gate wiring"
must_contain "$agents" "delete-test-student.sh" "AGENTS contract synchronization"

# No raw email address may be committed in the new destructive tool.
if grep -REn --include='delete-test-student.php' --include='delete-test-student.sh' --include='delete-test-student.yml' \
  '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$root/deployment" "$root/.github/workflows" >/dev/null; then
  echo 'The targeted deletion tool must not contain a raw email address.' >&2
  exit 1
fi

# The workflow must be manual-only and must have exactly one environment gate.
if grep -Eq '^  (push|pull_request|schedule):' "$workflow"; then
  echo 'The targeted deletion workflow must be workflow_dispatch only.' >&2
  exit 1
fi

echo "Targeted test-student deletion contract passed"
