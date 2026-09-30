<?php
namespace Delnavazan\Platform\Admin\Diagnostic;

final class NonceLifecycleDiagnostic {
    private const PREFIX = '[Delnavazan Platform Phase 1F lifecycle] ';

    public static function register(): void {
        if (!self::enabled()) return;
        self::logStage('plugin_bootstrap');
        foreach ([
            'plugins_loaded' => 'plugins_loaded',
            'admin_init' => 'admin_init_early',
            'admin_menu' => 'admin_menu_early',
            'current_screen' => 'current_screen',
            'admin_head' => 'admin_head',
        ] as $hook => $stage) {
            add_action($hook, static function () use ($stage): void {
                self::logStage($stage);
            }, PHP_INT_MIN);
        }
    }

    public static function watchLoadHook(string|false $hook): void {
        if (!self::enabled() || !$hook) return;
        add_action('load-' . $hook, static function (): void {
            self::logStage('load_page');
        }, PHP_INT_MIN);
    }

    public static function logStage(string $stage): void {
        if (!self::enabled() || !self::isDelnavazanPost()) return;
        $action = self::key($_POST['dzn_action'] ?? '');
        $noncePresent = array_key_exists('_wpnonce', $_POST);
        $nonceScalar = $noncePresent && is_string($_POST['_wpnonce']) && $_POST['_wpnonce'] !== '';
        $verification = $nonceScalar && function_exists('wp_verify_nonce')
            ? (int) wp_verify_nonce($_POST['_wpnonce'], 'dzn_platform_' . $action)
            : 0;
        $page = self::key($_GET['page'] ?? '');
        $capability = self::capabilityForPage($page);
        $diagnostic = [
            'stage' => self::key($stage),
            'action' => $action,
            'nonce_present' => $noncePresent,
            'nonce_scalar' => $nonceScalar,
            'nonce_verify' => $verification,
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'capability_allowed' => $capability !== '' && function_exists('current_user_can') && current_user_can($capability),
            'page' => $page,
            'method' => self::key($_SERVER['REQUEST_METHOD'] ?? ''),
            'referer_field_present' => array_key_exists('_wp_http_referer', $_POST),
            'core_action_present' => array_key_exists('action', $_POST),
            'core_action2_present' => array_key_exists('action2', $_POST),
        ];
        $json = function_exists('wp_json_encode') ? wp_json_encode($diagnostic) : json_encode($diagnostic);
        error_log(self::PREFIX . (string) $json);
    }

    private static function enabled(): bool {
        return defined('DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS')
            && DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS === true;
    }

    private static function isDelnavazanPost(): bool {
        return self::key($_SERVER['REQUEST_METHOD'] ?? '') === 'post'
            && str_starts_with(self::key($_GET['page'] ?? ''), 'dzn-');
    }

    private static function key(mixed $value): string {
        if (!is_string($value)) return '';
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
    }

    private static function capabilityForPage(string $page): string {
        return [
            'dzn-teacher' => 'dzn_manage_teachers',
            'dzn-student' => 'dzn_manage_students',
            'dzn-instrument' => 'dzn_manage_courses',
            'dzn-course' => 'dzn_manage_courses',
            'dzn-enrolment' => 'dzn_manage_enrolments',
            'dzn-term' => 'dzn_manage_terms',
            'dzn-lesson' => 'dzn_manage_lessons',
            'dzn-exception' => 'dzn_manage_exceptions',
        ][$page] ?? '';
    }
}
