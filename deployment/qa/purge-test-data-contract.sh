#!/usr/bin/env bash
# Source contract for the guarded go-live purge.
# Verifies the guard rails and proves the table classification covers every
# table the schema source declares.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
sh="$ROOT/deployment/qa/purge-test-data.sh"
php_file="$ROOT/deployment/qa/purge-test-data.php"
plan_file="$ROOT/deployment/qa/purge-test-data-tables.php"
migrator="$ROOT/platform/src/Core/Infrastructure/Migration/Migrator.php"
livekit="$ROOT/platform/src/Integrations/LiveKit/LiveKitAttendanceSchema.php"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ grep -Fq -- "$2" "$1" || fail "$1 missing required boundary: $2"; }
forbid(){ if grep -Fq -- "$2" "$1"; then fail "$1 must not contain: $2"; fi; }

bash -n "$sh" || fail 'wrapper is not valid bash'
php -l "$php_file" >/dev/null || fail 'payload is not valid PHP'
php -l "$plan_file" >/dev/null || fail 'classification is not valid PHP'

need "$sh" 'PURGE_ALL_TEST_DATA'
need "$sh" 'main-${sha:0:12}'
need "$sh" 'domains/delnavazan.com/public_html'
need "$sh" 'db export'
need "$sh" 'gzip -t'
need "$sh" 'sha256sum'
need "$sh" 'backups/go-live-purge'
need "$sh" 'prestate-fingerprint'

need "$php_file" 'PURGE_ALL_TEST_DATA'
need "$php_file" 'hamed.moqadasi@yahoo.com'
need "$php_file" 'production_environment_required'
need "$php_file" 'hash_equals'
need "$php_file" 'hash_file'
need "$php_file" 'SET FOREIGN_KEY_CHECKS=0'
need "$php_file" 'SET FOREIGN_KEY_CHECKS=1'
need "$php_file" 'START TRANSACTION'
need "$php_file" 'ROLLBACK'
need "$php_file" 'COMMIT'
need "$php_file" 'wp_delete_user'
need "$php_file" 'dzn_research_name'
need "$php_file" 'manage_options'
need "$php_file" 'unclassified_platform_tables'
need "$php_file" 'prestate_fingerprint_mismatch'
need "$php_file" 'preserve_table_changed'
need "$php_file" 'receipt_digest'
need "$php_file" "'deletions_performed'"

forbid "$php_file" 'DROP TABLE'
forbid "$php_file" 'TRUNCATE'
forbid "$php_file" 'wp db reset'
forbid "$php_file" '--force'

coverage_php="$(mktemp)"
trap 'rm -f "$coverage_php"' EXIT
cat > "$coverage_php" <<'PHP'
<?php
$root = $argv[1];
$planFile = $argv[2];
$sources = array_slice($argv, 3);
$plan = require $planFile;
if (!is_array($plan) || !isset($plan['preserve'], $plan['purge'])) {
    fwrite(STDERR, "FAIL  classification file must return preserve and purge arrays\n");
    exit(1);
}
$preserve = $plan['preserve'];
$purge = $plan['purge'];
foreach (array('preserve', 'purge') as $key) {
    if (count($plan[$key]) !== count(array_unique($plan[$key]))) {
        fwrite(STDERR, "FAIL  duplicate entry in $key classification\n");
        exit(1);
    }
}
$overlap = array_intersect($preserve, $purge);
if ($overlap) {
    fwrite(STDERR, 'FAIL  table in both preserve and purge: ' . implode(',', $overlap) . "\n");
    exit(1);
}
$tables = array();
foreach ($sources as $source) {
    $sql = (string) file_get_contents($source);
    $declared = preg_match_all('/CREATE TABLE/i', $sql);
    $parsed = preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?\{(?:\$p\}([a-z0-9_]+)|\$table\})/i', $sql, $m, PREG_OFFSET_CAPTURE);
    if ($declared !== $parsed) {
        fwrite(STDERR, "FAIL  unparsed CREATE TABLE declaration in $source ($declared declared, $parsed parsed)\n");
        exit(1);
    }
    // Resolve `$table = $p . 'name'` declarations by nearest preceding assignment.
    preg_match_all('/\$table\s*=\s*\$p\s*\.\s*\'([a-z0-9_]+)\'/', $sql, $assignments, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as $index => $match) {
        $name = $m[1][$index][0];
        if ('' !== $name) {
            $tables[] = $name;
            continue;
        }
        $offset = $match[1];
        $resolved = '';
        foreach ($assignments[0] as $a => $assignment) {
            if ($assignment[1] < $offset) {
                $resolved = $assignments[1][$a][0];
            }
        }
        if ('' === $resolved) {
            fwrite(STDERR, "FAIL  cannot resolve variable CREATE TABLE in $source\n");
            exit(1);
        }
        $tables[] = $resolved;
    }
}
$tables = array_values(array_unique($tables));
if (!$tables) {
    fwrite(STDERR, "FAIL  no schema tables parsed\n");
    exit(1);
}
$known = array_merge($preserve, $purge);
$unclassified = array_values(array_diff($tables, $known));
if ($unclassified) {
    fwrite(STDERR, 'FAIL  schema tables missing from classification: ' . implode(',', $unclassified) . "\n");
    exit(1);
}
$missing = array_values(array_diff($known, $tables));
if ($missing) {
    fwrite(STDERR, 'FAIL  classification names tables the schema does not declare: ' . implode(',', $missing) . "\n");
    exit(1);
}
$mustPurge = array('students', 'teachers', 'booking_requests', 'enrolments', 'lessons', 'terms', 'commercial_purchases', 'commercial_offers', 'recordings', 'platform_outbox', 'god_mode_sessions', 'livekit_attendance_rooms');
foreach ($mustPurge as $table) {
    if (!in_array($table, $purge, true)) {
        fwrite(STDERR, "FAIL  user-activity table must be purged: $table\n");
        exit(1);
    }
}
$mustPreserve = array('courses', 'instruments', 'commercial_products', 'commercial_prices', 'notification_templates', 'finance_policies', 'payment_provider_accounts', 'meet_workspace_connections');
foreach ($mustPreserve as $table) {
    if (!in_array($table, $preserve, true)) {
        fwrite(STDERR, "FAIL  configuration table must be preserved: $table\n");
        exit(1);
    }
}
printf("PASS classification covers %d schema tables (%d preserve, %d purge)\n", count($tables), count($preserve), count($purge));
PHP
php "$coverage_php" "$ROOT" "$plan_file" "$migrator" "$livekit" || fail 'classification coverage check failed'

unit="$ROOT/deployment/qa/purge-test-data-unit.php"
php -l "$unit" >/dev/null || fail 'unit harness is not valid PHP'

report_json="$(php "$unit" report)" || fail 'stubbed report run failed'
fingerprint="$(printf '%s' "$report_json" | php -r '$j=json_decode(stream_get_contents(STDIN),true); if(!is_array($j)||empty($j["ok"])||empty($j["fingerprint"])){fwrite(STDERR,"report failed\n");exit(1);} echo $j["fingerprint"];')" \
  || fail 'stubbed report returned no fingerprint'
[[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]] || fail 'stubbed report returned an invalid fingerprint'

backup="$(mktemp)"
head -c 2000000 /dev/zero > "$backup"
backup_sha="$(php -r 'echo hash_file("sha256",$argv[1]);' "$backup")"

execute_json="$(php "$unit" execute "$fingerprint" "$backup" "$backup_sha")" || fail 'stubbed execute run failed'
printf '%s' "$execute_json" | grep -Fq '"deletions_performed":true' || fail 'stubbed execute run did not report deletions_performed=true'

expect_refusal(){
  local label="$1" needle="$2"; shift 2
  local out
  if out="$(php "$unit" "$@" 2>&1)"; then
    fail "stubbed $label run should have refused"
  fi
  printf '%s' "$out" | grep -Fq "$needle" || fail "stubbed $label run missing refusal: $needle"
}
expect_refusal 'bad-confirm' 'confirm_token_invalid' bad-confirm "$fingerprint" "$backup" "$backup_sha"
expect_refusal 'bad-fingerprint' 'prestate_fingerprint_mismatch' bad-fingerprint "$backup" "$backup_sha"
expect_refusal 'bad-backup' 'backup_file_unavailable' bad-backup "$fingerprint"
rm -f "$backup"

echo 'PASS guarded go-live purge source contract'
