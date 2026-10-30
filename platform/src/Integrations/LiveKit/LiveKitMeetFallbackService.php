<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Read-only availability check for the existing Google Meet fallback.
 *
 * It never returns or decrypts the Meet URL. The fallback itself is still opened by the existing
 * authenticated TeacherClassJoinService, StudentClassJoinService, or BookingClassJoinService, so
 * the vaulted reference and its ownership checks remain the only source of the join link.
 */
final class LiveKitMeetFallbackService {
    public function lessonAvailable(int $lessonId, int $scheduleVersionId): bool {
        if ($lessonId < 1 || $scheduleVersionId < 1) return false;
        global $wpdb;
        if (!isset($wpdb->prefix)) return false;
        $p = $wpdb->prefix . 'dzn_';
        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings m
             INNER JOIN {$p}provider_projection_secrets s ON s.mapping_kind='calendar_event' AND s.mapping_id=m.id AND s.state='active' AND s.active_slot=1
             WHERE m.lesson_id=%d AND m.schedule_version_id=%d AND m.provider_code='google_calendar'
               AND m.projection_state='verified' AND m.active_slot=1",
            $lessonId,
            $scheduleVersionId
        ));
        return $count === 1;
    }

    public function bookingAvailable(?int $secretId, string $secretState): bool {
        return $secretId !== null && $secretId > 0 && $secretState === 'active';
    }
}
