<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Bridges canonical lifecycle/schedule changes to LiveKit room revocation.
 *
 * The canonical transaction remains the only authority. This listener only schedules an
 * out-of-band room deletion, so a provider outage can never reverse or delay a committed
 * cancellation or reschedule. The five-minute participant token remains the hard upper bound.
 */
final class LiveKitRoomRevocationListener {
    public const LESSON_HOOK = 'dzn_livekit_revoke_lesson_rooms';

    public static function register(): void {
        add_action('dzn_phase_2a2m_after_lesson_transition', array(__CLASS__, 'lessonTransition'), 10, 2);
        add_action('dzn_phase_2a2n_after_command_insert', array(__CLASS__, 'scheduleCommand'), 10, 2);
        add_action(self::LESSON_HOOK, array(__CLASS__, 'revokeLesson'), 10, 1);
    }

    public static function lessonTransition(string $operation, int $lessonId): void {
        if (in_array($operation, array('complete', 'cancel'), true) && $lessonId > 0) self::queueLesson($lessonId);
    }

    public static function scheduleCommand(string $operation, int $lessonId): void {
        if (in_array($operation, array('schedule_revise', 'schedule_release'), true) && $lessonId > 0) self::queueLesson($lessonId);
    }

    public static function queueLesson(int $lessonId): void {
        if ($lessonId < 1 || !function_exists('wp_schedule_single_event')) return;
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::LESSON_HOOK, array($lessonId))) return;
        wp_schedule_single_event(time() + 1, self::LESSON_HOOK, array($lessonId));
    }

    public static function revokeLesson(int $lessonId): void {
        try {
            (new LiveKitRoomRevoker())->revokeResource('lesson', $lessonId);
        } catch (\Throwable $error) {
            if (function_exists('do_action')) do_action('dzn_livekit_room_revocation_failed', 'lesson', $lessonId, get_class($error));
        }
    }
}
