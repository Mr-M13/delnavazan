#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
adjuster="$root/runtime/bin/adjust-historical-migrator.py"
tmp="$(mktemp -d "${TMPDIR:-/tmp}/dzn-historical-migrator.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

check_fixture() {
  local schema="$1" ref="$2" expected_bigints="$3" expected_extras="$4"
  local migrator="$tmp/schema-$schema-Migrator.php"
  local original="$migrator.original"
  local weakened="$migrator.weakened"
  git -C "$root" show "$ref:src/Core/Infrastructure/Migration/Migrator.php" >"$migrator"
  cp "$migrator" "$original"

  python3 "$adjuster" "$schema" "$migrator"
  cp "$migrator" "$migrator.first-adjustment"
  python3 "$adjuster" "$schema" "$migrator"
  cmp -s "$migrator.first-adjustment" "$migrator" || {
    echo "FAIL: Schema $schema historical fixture adjustment is not idempotent" >&2
    exit 1
  }

  php -l "$migrator" >/dev/null
  python3 - "$original" "$migrator" "$expected_bigints" "$expected_extras" <<'PY'
import re
import sys
from pathlib import Path

original = Path(sys.argv[1]).read_text()
source = Path(sys.argv[2]).read_text()
bigints = re.findall(
    r"preg_match\('/\^bigint\(\?:\\\\\(\\\\d\+\\\\\)\)\? unsigned\$/',"
    r" strtolower\(trim\(\(string\) \$[A-Za-z_][A-Za-z0-9_]*->Type\)\)\) !== 1",
    source,
)
extras = re.findall(
    r"strtoupper\(\s*\(string\)\s*\$[A-Za-z_][A-Za-z0-9_]*->Extra\s*\)"
    r"\s*!==\s*'AUTO_INCREMENT'",
    source,
)
assert len(bigints) == int(sys.argv[3]), (len(bigints), sys.argv[3])
assert len(extras) == int(sys.argv[4]), (len(extras), sys.argv[4])
assert "array('integration_connections','identity_digest'),array('integration_oauth_authorizations','state_digest')" not in source
assert source.count("array('integration_oauth_authorizations','state_digest')") == 1

create_table = re.compile(r'^\s*"CREATE TABLE .*$', re.MULTILINE)
assert create_table.findall(source) == create_table.findall(original)
assert "'finance_teacher_rate_events' => array('rate_id','event_sequence','event_type','from_status','to_status','effective_until'" in source
assert "'finance_teacher_rate_events'" in re.search(
    r"\$appendOnly = array\(([^;]+)\);", source
).group(1)
assert source.count("if ( ! in_array( $table, $appendOnly, true ) ) {") == 1
assert source.count("Migration verification failed: an undeclared mutable column:") == 1
assert source.count("Migration verification failed: Phase 2A.2-U evidence must be append-only:") == 1

append_only_ban = re.compile(
    r'^\s*if \( in_array\( \$table, \$appendOnly, true \) && .*'
    r"Phase 2A\.2-U evidence must be append-only: .*\n",
    re.MULTILINE,
)
weakened, replacements = append_only_ban.subn('', source)
assert replacements == 1
Path(sys.argv[2] + '.weakened').write_text(weakened)
PY

  if python3 "$adjuster" "$schema" "$weakened" >/dev/null 2>&1; then
    echo "FAIL: Schema $schema fixture adjustment accepted a weakened append-only verifier" >&2
    exit 1
  fi
}

check_fixture 30 86d57606cabcddba15d076edfe14fb4e7257e60f 9 6
check_fixture 31 2ab0c71f5cc53ca8aa4241db0b2f7d100de65997 10 7
php -r '
$pattern="/^bigint(?:\\(\\d+\\))? unsigned$/";
foreach(array("bigint unsigned","bigint(20) unsigned","BIGINT(20) UNSIGNED") as $type)if(preg_match($pattern,strtolower(trim($type)))!==1)exit(1);
foreach(array("int(20) unsigned","bigint(20) signed","bigint(20) unsigned zerofill") as $type)if(preg_match($pattern,strtolower(trim($type)))===1)exit(1);
if(strtoupper("auto_increment")!=="AUTO_INCREMENT")exit(1);
' || { echo 'FAIL: historical identity compatibility predicate was weakened' >&2; exit 1; }
echo 'Historical Migrator fixture contract: PASS'
