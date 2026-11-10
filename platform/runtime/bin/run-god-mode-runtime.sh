#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for God Mode migration, isolation and cleanup.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
dzn_wp eval '
    $required = "074_god_mode_super_tester";
    $ledger = (array) get_option("dzn_platform_completed_migrations", array());
    if (!in_array($required, $ledger, true)) { throw new RuntimeException("God Mode migration is missing from the ledger"); }
    if ((string) get_option("dzn_platform_schema_version") !== (string) DZN_PLATFORM_SCHEMA_VERSION) { throw new RuntimeException("Package schema option mismatch"); }
    global $wpdb; $p = $wpdb->prefix . "dzn_";
    foreach (array("god_mode_sessions","god_mode_session_events","god_mode_scenario_runs","god_mode_audit_events","god_mode_notification_tests","god_mode_cleanup_receipts","god_mode_participant_mappings") as $table) {
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $p . $table)) !== $p . $table) { throw new RuntimeException("Missing table " . $table); }
    }
    foreach (array("lessons","enrolments","booking_requests") as $table) {
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s", $p . $table, "activity_type"));
        if ($count !== 1) { throw new RuntimeException("Missing activity_type on " . $table); }
    }
    $meet = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s", $p . "meet_classrooms", "test_session_id"));
    if ($meet !== 1) { throw new RuntimeException("Missing meet test_session_id"); }
    echo "god_mode_schema_ok\n";
'
# The disposable admin is the runtime operator. Give it a temporary local-only
# authority row so the runtime smoke can exercise the real service boundaries.
dzn_wp eval '
    $user = get_userdata(1);
    if (!$user) { throw new RuntimeException("Runtime admin missing"); }
    $digest = Delnavazan\Platform\Core\Application\GodMode\GodModeAuthority::emailDigest((string) $user->user_email);
    update_option("dzn_god_mode_super_tester_user_id", 1, false);
    update_option("dzn_god_mode_super_tester_email_digest", $digest, false);
    if (!$user->has_cap("dzn_access_god_mode")) { $user->add_cap("dzn_access_god_mode"); }
    global $wpdb; $table = $wpdb->prefix . "dzn_god_mode_authorisations";
    $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE wordpress_user_id=%d", 1));
    if ($row) {
        $wpdb->update($table, array("state" => "active", "email_digest" => $digest, "updated_at" => gmdate("Y-m-d H:i:s")), array("id" => (int) $row->id));
    } else {
        $wpdb->insert($table, array("uid" => Delnavazan\Platform\Core\Support\Identifier::uid(), "wordpress_user_id" => 1, "email_digest" => $digest, "state" => "active", "capability_version" => "god_mode_v1", "granted_at" => gmdate("Y-m-d H:i:s"), "granted_by" => 1, "created_at" => gmdate("Y-m-d H:i:s"), "updated_at" => gmdate("Y-m-d H:i:s")));
    }
'
dzn_wp eval '
    global $wpdb; $p = $wpdb->prefix . "dzn_"; $now = gmdate("Y-m-d H:i:s");
    $uid = Delnavazan\Platform\Core\Support\Identifier::uid();
    $wpdb->insert($p . "instruments", array("uid" => $uid, "reference_code" => "GM-RUNTIME-INS", "slug" => "gm-runtime-ins", "name_fa" => "Runtime Instrument", "name_en" => "Runtime Instrument", "status" => "active", "created_at" => $now, "updated_at" => $now));
    $instrument = (int) $wpdb->insert_id;
    $wpdb->insert($p . "courses", array("uid" => Delnavazan\Platform\Core\Support\Identifier::uid(), "reference_code" => "GM-RUNTIME-COURSE", "instrument_id" => $instrument, "name_fa" => "Runtime Course", "name_en" => "Runtime Course", "course_type" => "standard", "status" => "active", "default_duration_minutes" => 30, "default_buffer_minutes" => 0, "created_at" => $now, "updated_at" => $now));
    $wpdb->insert($p . "teachers", array("uid" => Delnavazan\Platform\Core\Support\Identifier::uid(), "reference_code" => "GM-RUNTIME-TEACHER", "status" => "active", "persian_name" => "Runtime Teacher", "english_name" => "Runtime Teacher", "display_name" => "Runtime Teacher", "email" => "runtime-teacher@example.invalid", "timezone" => "UTC", "locale" => "en", "calendar_preference" => "gregorian", "created_at" => $now, "updated_at" => $now));
    if (!$instrument || !$wpdb->insert_id) { throw new RuntimeException("Runtime teacher seed failed"); }
    echo "god_mode_seed_ok\n";
'
dzn_wp_env DZN_GOD_MODE_RUNTIME_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/god-mode-runtime.php" --user=1
echo 'God Mode runtime: PASS'
