<?php
/**
 * Read-only production release diagnostic.
 *
 * Executed through `wp eval` on the production host. It performs SELECT/read operations only and
 * never writes production state. It never prints a secret value; Stripe output is limited to
 * presence/scope booleans.
 */
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$count = static function (string $table, string $where = '1=1') use ($wpdb, $p): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table} WHERE {$where}");
};
$group = static function (string $table, string $column) use ($wpdb, $p): array {
    $rows = $wpdb->get_results("SELECT {$column} label,COUNT(*) total FROM {$p}{$table} GROUP BY {$column} ORDER BY {$column}", ARRAY_A) ?: array();
    $out = array();
    foreach ($rows as $row) $out[(string) $row['label']] = (int) $row['total'];
    return $out;
};
$cron = _get_cron_array();
$hooks = array();
foreach ((array) $cron as $timestamp => $events) {
    foreach ((array) $events as $hook => $unused) {
        if (in_array((string) $hook, array('dzn_post_intro_activation_dispatch', 'dzn_lesson_completion_automation_dispatch'), true)) {
            $hooks[(string) $hook] = gmdate('Y-m-d H:i:s', (int) $timestamp);
        }
    }
}
$stripeClass = 'Delnavazan\\Platform\\Core\\Application\\PaymentExecution\\StripeTestConfigurationService';
$stripe = array();
$publishableTest = false;
$testEnvironmentAllowed = null;
$testActivationAllowed = null;
if (class_exists($stripeClass)) {
    $service = new $stripeClass();
    $stripe = $service->savedState();
    $publishableTest = $service->publishableKey() !== '';
    $testEnvironmentAllowed = $stripeClass::testEnvironmentAllowed();
    $adapter = 'Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeCheckoutAdapter';
    $testActivationAllowed = class_exists($adapter) ? $adapter::testModeActivationAllowed() : null;
}
$accounts = $wpdb->get_results("SELECT mode,state,execution_state,COUNT(*) total FROM {$p}payment_provider_accounts WHERE provider_key='stripe' GROUP BY mode,state,execution_state ORDER BY mode,state,execution_state", ARRAY_A) ?: array();
$policy = $wpdb->get_row("SELECT policy_version,value_type,policy_value,effective_from,status FROM {$p}commercial_policies WHERE policy_key='INSTALMENT_DUE_DATE_POLICY' ORDER BY policy_version DESC,id DESC LIMIT 1", ARRAY_A);
$twoStateRows = $wpdb->get_results("SELECT state label,COUNT(*) total FROM {$p}commercial_offers WHERE plan_kind='two_instalments' GROUP BY state ORDER BY state", ARRAY_A) ?: array();
$twoStates = array();
foreach ($twoStateRows as $row) $twoStates[(string) $row['label']] = (int) $row['total'];
$lessonRows = $wpdb->get_results("SELECT lifecycle_state label,COUNT(*) total FROM {$p}lessons WHERE record_model='canonical_term_lesson_v1' GROUP BY lifecycle_state ORDER BY lifecycle_state", ARRAY_A) ?: array();
$canonicalLessons = array();
foreach ($lessonRows as $row) $canonicalLessons[(string) $row['label']] = (int) $row['total'];
$failedJobs = $wpdb->get_results("SELECT last_error_code label,COUNT(*) total,MIN(created_at) oldest,MAX(updated_at) newest FROM {$p}post_intro_activation_jobs WHERE state='failed' GROUP BY last_error_code ORDER BY total DESC", ARRAY_A) ?: array();
$latestJobs = $wpdb->get_results("SELECT id,state,attempt_count,last_error_code,checkpoint,created_at,updated_at,completed_at FROM {$p}post_intro_activation_jobs ORDER BY updated_at DESC,id DESC LIMIT 5", ARRAY_A) ?: array();
$screenClass = 'Delnavazan\\Platform\\Admin\\Controller\\ScreenController';
$scheduleClass = 'Delnavazan\\Platform\\Core\\Application\\CanonicalLessonScheduleReadService';
$adminLessonScreen = array(
    'screen_controller_loaded' => class_exists($screenClass),
    'schedule_service_loaded' => class_exists($scheduleClass),
    'stale_admin_namespace_absent' => !class_exists('Delnavazan\\Platform\\Admin\\Controller\\CanonicalLessonScheduleReadService'),
    'schedule_methods' => array(),
);
foreach (array('forLesson', 'forLessonCompletion', 'forLessonDelivery') as $method) {
    $adminLessonScreen['schedule_methods'][$method] = class_exists($scheduleClass) && method_exists($scheduleClass, $method);
}
$out = array(
    'schema' => (string) get_option('dzn_platform_schema_version'),
    'build' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : null,
    'platform_version' => defined('DZN_PLATFORM_VERSION') ? (string) DZN_PLATFORM_VERSION : null,
    'wordpress_version' => (string) get_bloginfo('version'),
    'php_version' => PHP_VERSION,
    'plugin_active' => in_array('delnavazan-platform/delnavazan-platform.php', (array) get_option('active_plugins', array()), true),
    'stylesheet' => (string) get_option('stylesheet'),
    'maintenance_mode' => (function (): string {
        if (!function_exists('wp_is_maintenance_mode')) return 'unknown';
        return wp_is_maintenance_mode() ? 'active' : 'inactive';
    })(),
    'admin_lesson_screen' => $adminLessonScreen,
    'completed_052' => in_array('052_payment_plan_choice', (array) get_option('dzn_platform_completed_migrations', array()), true),
    'cron_disabled' => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : false,
    'cron_hooks' => $hooks,
    'activation_jobs' => $group('post_intro_activation_jobs', 'state'),
    'failed_jobs' => $failedJobs,
    'latest_jobs' => $latestJobs,
    'preparation_states' => $group('post_intro_preparations', 'state'),
    'plan_choices' => $count('post_intro_plan_choices'),
    'attendance_cases' => $group('canonical_attendance_cases', 'state'),
    'attendance_conflicts' => $count('canonical_attendance_conflicts'),
    'payability' => array(
        'pending_total' => $count('finance_payability_evaluations', "disposition='pending' AND applicable_slot=1"),
        'presumed_completion_review' => $count('finance_payability_evaluations', "basis_code='presumed_completion_review' AND applicable_slot=1"),
    ),
    'canonical_lessons' => $canonicalLessons,
    'two_instalment_offers' => array(
        'total' => $count('commercial_offers', "plan_kind='two_instalments'"),
        'states' => $twoStates,
    ),
    'settlements' => $count('commercial_obligation_settlements'),
    'instalment_policy' => array(
        'present' => $policy !== null,
        'status' => $policy === null ? null : (string) $policy['status'],
        'policy_version' => $policy === null ? null : (int) $policy['policy_version'],
        'value_type' => $policy === null ? null : (string) $policy['value_type'],
        'configured' => $policy !== null && $policy['policy_value'] !== null,
        'effective_from' => $policy === null ? null : $policy['effective_from'],
    ),
    'stripe' => array(
        'account_id' => (int) ($stripe['account_id'] ?? 0),
        'execution_enabled' => !empty($stripe['execution_enabled']),
        'publishable_test_key' => $publishableTest,
        'secret_present' => !empty($stripe['secret']),
        'webhook_present' => !empty($stripe['webhook']),
        'test_environment_allowed' => $testEnvironmentAllowed,
        'test_activation_allowed' => $testActivationAllowed,
        'accounts' => $accounts,
    ),
);
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
