#!/usr/bin/env bash
# In-place upgrade rehearsal.  A database built from one immutable Schema-N revision is upgraded by the
# candidate tree under test, so the additive slices this package merges are proved on one *existing*
# database rather than on a fresh install.  The shared checkout is never checked out or linked: the base
# revision and the candidate are both disposable worktrees inside the state directory.
#
# usage: schema-upgrade-rehearsal.sh <base-schema> <base-ref> <declared-added-table>...
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
base_schema="${1:?usage: schema-upgrade-rehearsal.sh <base-schema> <base-ref> <declared-added-table>...}"; shift
base_ref="${1:?usage: schema-upgrade-rehearsal.sh <base-schema> <base-ref> <declared-added-table>...}"; shift
[ "$#" -gt 0 ] || { echo 'error: at least one declared added table is required.' >&2; exit 2; }
case "$base_schema" in
  30) base_worktree="$DZN_BASE30_WORKTREE";;
  31) base_worktree="$DZN_BASE31_WORKTREE";;
  *) echo "error: unknown base schema: $base_schema" >&2; exit 2;;
esac
before="$DZN_RUNTIME_STATE_DIR/rehearsal-before.json"; after="$DZN_RUNTIME_STATE_DIR/rehearsal-after.json"
dzn_require_cached_images; dzn_candidate; dzn_prepare_worktree "$base_worktree" "$base_ref"
dzn_assert_runtime_paths
dzn_compose down --volumes --remove-orphans || true
dzn_remove_disposable_path "$DZN_DB_DIR"; dzn_remove_disposable_path "$DZN_WP_DIR"; mkdir -p "$DZN_DB_DIR" "$DZN_WP_DIR"
dzn_compose up --pull never -d --wait db
dzn_compose up --pull never -d wordpress
DZN_PLUGIN_SOURCE="$base_worktree"; export DZN_PLUGIN_SOURCE
dzn_assert_runtime_paths
for _ in $(seq 1 30); do [ -f "$DZN_WP_DIR/wp-load.php" ] && break; sleep 1; done
[ -f "$DZN_WP_DIR/wp-load.php" ] || { echo 'error: WordPress core was not supplied by the cached image.' >&2; exit 1; }
dzn_wp core install --url="$DZN_WP_URL" --title="Schema $base_schema rehearsal" --admin_user=admin --admin_password=local-only-not-a-secret --admin_email=admin@example.invalid --skip-email
plugin="$DZN_WP_DIR/wp-content/plugins/delnavazan-platform"; mkdir -p "$(dirname "$plugin")"; ln -s "$base_worktree" "$plugin"
dzn_wp plugin activate delnavazan-platform
dzn_wp eval "if ((string)get_option(\"dzn_platform_schema_version\") !== \"$base_schema\") throw new RuntimeException(\"the immutable base did not install Schema $base_schema\"); update_option(\"dzn_runtime_rehearsal_preserved_option\",\"preserve-me\",false);"
# One snapshot shape for both sides.  The `platform_outbox` identity and rows are projected onto the
# columns the base already had, because the additive Schema-032 slice only adds nullable columns there;
# every other table is compared whole, with only Migrator's two ledger options excluded from `options`.
snapshot='global $wpdb;$added=array("notification_id","workflow_key","workflow_version","intent_key","audience","scheduled_for","expires_at","deferral_count","priority","lease_token_digest","failure_reason_code");$tables=$wpdb->get_col("SHOW TABLES");sort($tables,SORT_STRING);$out=array();foreach($tables as $t){$rows=$wpdb->get_results("SELECT * FROM ".$t,ARRAY_A);if(substr($t,-8)==="options")$rows=array_values(array_filter($rows,static fn($r)=>!in_array($r["option_name"],array("dzn_platform_schema_version","dzn_platform_completed_migrations"),true)));if(substr($t,-15)==="platform_outbox"){$rows=array_map(static function($r)use($added){foreach($added as $column)unset($r[$column]);return $r;},$rows);$columns=$wpdb->get_results($wpdb->prepare("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY COLUMN_NAME",$t),ARRAY_A);$identity=array_values(array_filter($columns,static fn($column)=>!in_array($column["COLUMN_NAME"],$added,true)));}else{$create=$wpdb->get_row("SHOW CREATE TABLE ".$t,ARRAY_N);$identity=(string)$create[1];}$out[$t]=array("schema"=>hash("sha256",serialize($identity)),"data"=>hash("sha256",serialize($rows)));}echo wp_json_encode($out);'
dzn_wp eval "$snapshot" >"$before"
rm -f "$plugin"; ln -s "$DZN_PLUGIN_WORKTREE" "$plugin"
DZN_PLUGIN_SOURCE="$DZN_PLUGIN_WORKTREE"; export DZN_PLUGIN_SOURCE
dzn_assert_runtime_paths
dzn_wp eval '\Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();'
dzn_wp eval "$snapshot" >"$after"
expected=''; for table in "$@"; do expected="$expected'$table',"; done; expected="array(${expected%,})"
compare="$(cat <<'CODE'
$before=json_decode(file_get_contents("/state/rehearsal-before.json"),true);$after=json_decode(file_get_contents("/state/rehearsal-after.json"),true);if(!is_array($before)||!is_array($after))throw new RuntimeException("invalid snapshot");$added=array_values(array_diff(array_keys($after),array_keys($before)));sort($added);$expected=EXPECTED_ARRAY;sort($expected);if($added!==$expected)throw new RuntimeException("added tables were not exactly the declared set: ".json_encode($added));$lost=array_values(array_diff(array_keys($before),array_keys($after)));if($lost!==array())throw new RuntimeException("a pre-existing table disappeared: ".json_encode($lost));foreach($before as $table=>$shape)if($after[$table]!==$shape)throw new RuntimeException("a pre-existing table schema or data changed: ".$table);echo "schema_rehearsal_preservation_ok\n";
CODE
)"
compare="${compare/EXPECTED_ARRAY/$expected}"
dzn_php -r "$compare"
"$(dirname "$BASH_SOURCE")/verify-schema33.sh"
echo "Schema $base_schema ($base_ref) -> 33 rehearsal: PASS"
