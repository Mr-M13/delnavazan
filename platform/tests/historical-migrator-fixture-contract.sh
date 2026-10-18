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
assert source.count("! str_ends_with( $name, 'derivation_digest' ) && str_contains( $name, $forbidden )") == 1

spec_match = re.search(
    r"\$spec = array\(\n(?=\t\t\t'finance_policy_roots')(.*?)\n\t\t\);\n\t\t\$handleTables",
    source,
    re.DOTALL,
)
assert spec_match
spec = {}
for line in spec_match.group(1).splitlines():
    values = re.findall(r"'([^']+)'", line)
    if values:
        spec[values[0]] = values[1:]
assert len(spec) == 21

create_columns = {}
create_nullability = {}
create_definitions = {}
for table, definitions in re.findall(
    r'"CREATE TABLE \{\$p\}(finance_[a-z_]+) \((.*?)\) ENGINE=InnoDB',
    source,
):
    column_definitions = definitions.split(',PRIMARY KEY(', 1)[0].split(',')
    create_columns[table] = []
    for definition in column_definitions:
        name, remainder = definition.split(' ', 1)
        create_columns[table].append(name)
        create_nullability[f"{table}.{name}"] = 'NOT NULL' not in remainder
        create_definitions[f"{table}.{name}"] = remainder

assert set(create_columns) == set(spec)
for table, declared in spec.items():
    assert create_columns[table] == ['id', *declared], table

append_only = set(re.findall(
    r"'([^']+)'",
    re.search(r"\$appendOnly = array\((.*?)\);", source).group(1),
))
mutable_match = re.search(
    r"\$mutable = array\(\n(.*?)\n\t\t\);\n\t\t\$digestOptional",
    source,
    re.DOTALL,
)
assert mutable_match
mutable = {}
for line in mutable_match.group(1).splitlines():
    values = re.findall(r"'([^']+)'", line)
    if values:
        mutable[values[0]] = values[1:]
assert set(mutable) == set(spec)
mutable_column_names = re.findall(
    r"'([^']+)'",
    re.search(
        r"foreach \( array\((.*?)\) as \$mutableColumn \)", source
    ).group(1),
)
mutable_conflicts = {
    f"{table}.{column}"
    for table, columns in spec.items()
    for column in mutable_column_names
    if column in columns and column not in mutable[table]
}
assert mutable_conflicts == {
    'finance_teacher_rate_events.effective_until',
    'finance_payability_commands.result_evaluation_id',
    'finance_statement_events.superseded_by_statement_id',
    'finance_reconciliation_runs.state',
}
assert all(name.split('.', 1)[0] in append_only for name in mutable_conflicts)
assert all(
    'updated_at' not in spec[table] and 'updated_by' not in spec[table]
    for table in append_only
)

forbidden = re.findall(
    r"'([^']+)'",
    re.search(r"\$forbiddenNames = array\((.*?)\);", source).group(1),
)
forbidden_conflicts = {
    f"{table}.{column}"
    for table, columns in spec.items()
    for column in columns
    if any(term in column for term in forbidden)
}
assert forbidden_conflicts == {
    'finance_lesson_snapshots.derivation_digest',
    'finance_snapshot_corrections.derivation_digest',
    'finance_payability_evaluations.derivation_digest',
    'finance_payability_overrides.prior_derivation_digest',
    'finance_statements.derivation_digest',
    'finance_statement_lines.derivation_digest',
}
assert all(name.endswith('derivation_digest') for name in forbidden_conflicts)

digest_optional = set(re.findall(
    r"'([^']+)'",
    re.search(r"\$digestOptional = array\((.*?)\);", source).group(1),
))
digest_columns = {
    name: nullable
    for name, nullable in create_nullability.items()
    if create_definitions[name].startswith('char(64)')
}
assert digest_optional == {
    name for name, nullable in digest_columns.items() if nullable
}

append_only_ban = re.compile(
    r'^\s*if \( in_array\( \$table, \$appendOnly, true \) && .*'
    r"Phase 2A\.2-U evidence must be append-only: .*\n",
    re.MULTILINE,
)
weakened, replacements = append_only_ban.subn('', source)
assert replacements == 1
Path(sys.argv[2] + '.weakened-append-only').write_text(weakened)

for suffix, needle in {
    'forbidden-name': "\t\t\t\tforeach ( $forbiddenNames as $forbidden ) if ( ! str_ends_with( $name, 'derivation_digest' ) && str_contains( $name, $forbidden ) ) throw new \\RuntimeException('Migration verification failed: a forbidden Phase 2A.2-U column name: '.$table.'.'.$name);\n",
    'digest-nullability': "\t\t$digestOptional = array('finance_policies.evidence_reference_digest','finance_teacher_rates.evidence_reference_digest','finance_teacher_rate_events.evidence_reference_digest','finance_snapshot_corrections.evidence_reference_digest','finance_payability_evaluations.evidence_reference_digest','finance_payability_overrides.evidence_reference_digest','finance_statement_events.evidence_reference_digest','finance_reconciliation_findings.expected_digest','finance_reconciliation_findings.observed_digest');\n",
}.items():
    assert source.count(needle) == 1, suffix
    Path(sys.argv[2] + '.weakened-' + suffix).write_text(
        source.replace(needle, '', 1)
    )
PY

  local weakened
  for weakened in append-only forbidden-name digest-nullability; do
    if python3 "$adjuster" "$schema" "$migrator.weakened-$weakened" >/dev/null 2>&1; then
      echo "FAIL: Schema $schema fixture adjustment accepted a weakened $weakened verifier" >&2
      exit 1
    fi
  done
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
