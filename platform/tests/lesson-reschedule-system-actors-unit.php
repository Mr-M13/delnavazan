<?php
/** Least-privilege fail-closed system actors for reschedule commit and calendar hand-off. */
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    if (!class_exists('WP_User')) { class WP_User { public int $user_status = 0; public array $allcaps = array(); public function __construct(public int $ID = 0) {} } }
    $GLOBALS['actor_options'] = array();
    $GLOBALS['actor_users'] = array();
    $GLOBALS['actor_caps'] = array();
    $GLOBALS['current_user'] = 1;
    function get_option(string $key, $default = false) { return $GLOBALS['actor_options'][$key] ?? $default; }
    function get_user_by(string $field, int $id) { return $GLOBALS['actor_users'][$id] ?? false; }
    function user_can($user, string $cap): bool { return !empty($GLOBALS['actor_caps'][(int)($user->ID ?? 0)][$cap]); }
    function get_current_user_id(): int { return $GLOBALS['current_user']; }
    function wp_set_current_user(int $id): void { $GLOBALS['current_user'] = $id; }
    if (!class_exists('Delnavazan\\Platform\\Core\\Application\\ProviderIntegrationService')) {
        eval('namespace Delnavazan\\Platform\\Core\\Application; final class ProviderIntegrationService { public const MANAGE_CAPABILITY="dzn_manage_provider_integrations"; }');
    }
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonRescheduleSystemActor.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/LessonRescheduleCalendarActor.php';

    use Delnavazan\Platform\Core\Application\LessonRescheduleSystemActor;
    use Delnavazan\Platform\Integrations\LessonRescheduleCalendarActor;

    $fail = static function (string $name, bool $ok): void { if (!$ok) { throw new RuntimeException('Lesson reschedule system actors unit failed: ' . $name); } };
    $throws = static function (callable $work, string $code) use ($fail): void {
        try { $work(); } catch (Throwable $e) { $fail('expected ' . $code . ' got ' . $e->getMessage(), $e->getMessage() === $code); return; }
        $fail('expected refusal ' . $code, false);
    };
    $user = static function (int $id, array $caps, int $status = 0): WP_User { $u = new WP_User($id); $u->user_status = $status; $GLOBALS['actor_caps'][$id] = array_fill_keys($caps, true); return $u; };

    $GLOBALS['actor_options'][LessonRescheduleSystemActor::USER_OPTION] = 0;
    $throws(static fn() => LessonRescheduleSystemActor::run(static fn() => null), 'reschedule_system_actor_unconfigured');
    $GLOBALS['actor_options'][LessonRescheduleSystemActor::USER_OPTION] = 71;
    $GLOBALS['actor_users'][71] = $user(71, array());
    $throws(static fn() => LessonRescheduleSystemActor::run(static fn() => null), 'reschedule_system_actor_invalid');
    $GLOBALS['actor_users'][71] = $user(71, array('dzn_manage_canonical_lesson_schedules', 'manage_options'));
    $throws(static fn() => LessonRescheduleSystemActor::run(static fn() => null), 'reschedule_system_actor_invalid');
    $GLOBALS['actor_users'][71] = $user(71, array('dzn_manage_canonical_lesson_schedules', 'dzn_manage_provider_integrations'));
    $throws(static fn() => LessonRescheduleSystemActor::run(static fn() => null), 'reschedule_system_actor_invalid');
    $GLOBALS['actor_users'][71] = $user(71, array('dzn_manage_canonical_lesson_schedules'));
    $ran = 0; LessonRescheduleSystemActor::run(static function () use (&$ran): void { $ran++; });
    $fail('a least-privilege scheduling actor runs and restores the session', $ran === 1 && $GLOBALS['current_user'] === 1);

    $GLOBALS['actor_options'][LessonRescheduleCalendarActor::USER_OPTION] = 0;
    $throws(static fn() => LessonRescheduleCalendarActor::run(static fn() => null), 'reschedule_calendar_actor_unconfigured');
    $GLOBALS['actor_options'][LessonRescheduleCalendarActor::USER_OPTION] = 72;
    $GLOBALS['actor_users'][72] = $user(72, array());
    $throws(static fn() => LessonRescheduleCalendarActor::run(static fn() => null), 'reschedule_calendar_actor_invalid');
    $GLOBALS['actor_users'][72] = $user(72, array('dzn_manage_provider_integrations', 'manage_options'));
    $throws(static fn() => LessonRescheduleCalendarActor::run(static fn() => null), 'reschedule_calendar_actor_invalid');
    $GLOBALS['actor_users'][72] = $user(72, array('dzn_manage_provider_integrations', 'dzn_manage_canonical_lesson_schedules'));
    $throws(static fn() => LessonRescheduleCalendarActor::run(static fn() => null), 'reschedule_calendar_actor_invalid');
    $GLOBALS['actor_users'][72] = $user(72, array('dzn_manage_provider_integrations'));
    $ran = 0; LessonRescheduleCalendarActor::run(static function () use (&$ran): void { $ran++; });
    $fail('a least-privilege calendar actor runs', $ran === 1);

    echo "Lesson reschedule system actors unit passed\n";
}
