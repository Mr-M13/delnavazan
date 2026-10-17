#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
adjuster="$root/runtime/bin/adjust-historical-migrator.py"
tmp="$(mktemp -d "${TMPDIR:-/tmp}/dzn-historical-migrator.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

check_fixture() {
  local schema="$1" ref="$2" expected_bigints="$3" expected_extras="$4"
  local migrator="$tmp/schema-$schema-Migrator.php"
  git -C "$root" show "$ref:src/Core/Infrastructure/Migration/Migrator.php" >"$migrator"

  python3 "$adjuster" "$schema" "$migrator"
  cp "$migrator" "$migrator.first-adjustment"
  python3 "$adjuster" "$schema" "$migrator"
  cmp -s "$migrator.first-adjustment" "$migrator" || {
    echo "FAIL: Schema $schema historical fixture adjustment is not idempotent" >&2
    exit 1
  }

  php -l "$migrator" >/dev/null
  python3 - "$migrator" "$expected_bigints" "$expected_extras" <<'PY'
import re
import sys
from pathlib import Path

source = Path(sys.argv[1]).read_text()
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
assert len(bigints) == int(sys.argv[2]), (len(bigints), sys.argv[2])
assert len(extras) == int(sys.argv[3]), (len(extras), sys.argv[3])
assert "array('integration_connections','identity_digest'),array('integration_oauth_authorizations','state_digest')" not in source
assert source.count("array('integration_oauth_authorizations','state_digest')") == 1
PY
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
