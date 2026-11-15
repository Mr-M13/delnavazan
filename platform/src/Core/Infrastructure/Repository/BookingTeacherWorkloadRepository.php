<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Per-Teacher workload components across every intro-course instrument.
 *
 * Read-only. One query per lifecycle source, grouped by Teacher, so a whole
 * candidate set costs a constant number of indexed aggregates instead of one
 * round trip per Teacher.
 *
 * Sources, and the identity each one counts (#2 of the allocation policy):
 *
 *  - pending: a live introductory offer. `state='pending' AND active_slot=1` is
 *    the authoritative "still awaiting a Teacher response" row; the unique
 *    `request_active` key allows at most one per request, so a request is never
 *    counted twice here.
 *  - accepted: an introductory booking that a Teacher accepted, read from
 *    `booking_request_confirmations`. A confirmation supersedes its offer
 *    (`state` moves off `pending`), so the same booking cannot appear in both
 *    terms. Manual/administrator assignments create the same confirmation and
 *    are therefore counted exactly once here, not again from the delivery row.
 *  - confirmed: a canonical enrolled lesson that is still expected to be taught
 *    (`authorised` / `scheduled`, or the legacy `status` equivalent).
 *  - completed: a canonical lesson delivered within the rolling window.
 *
 * Introductory canonical lessons are excluded on purpose: the intro pipeline is
 * already counted from the offer/confirmation rows, and counting the projected
 * lesson as well would double the same booking.
 *
 * Cancelled and archived rows are excluded in every term. The window is applied
 * to `starts_at_utc` for bookings and to `completed_at` for delivered lessons, so
 * future work is never aged out and stale history is not carried forever.
 */
final class BookingTeacherWorkloadRepository {
    private string $p;

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /**
     * @param list<int> $teacherIds
     * @return array<int,array{pending:int,accepted:int,confirmed:int,completed:int}>
     */
    public function components(array $teacherIds, string $now, int $windowDays = 30): array {
        global $wpdb;
        $ids = array();
        foreach ($teacherIds as $id) {
            $id = (int) $id;
            if ($id > 0) $ids[$id] = $id;
        }
        $ids = array_values($ids);
        $empty = array('pending' => 0, 'accepted' => 0, 'confirmed' => 0, 'completed' => 0);
        $result = array();
        foreach ($ids as $id) $result[$id] = $empty;
        if (!$ids) return $result;

        $windowDays = max(1, min(365, $windowDays));
        $windowStart = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->modify('-' . $windowDays . ' days')->format('Y-m-d H:i:s');
        $in = implode(',', array_fill(0, count($ids), '%d'));

        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT teacher_id,COUNT(*) n FROM {$this->p}booking_allocation_offers
             WHERE state='pending' AND active_slot=1 AND teacher_id IN ($in)
             GROUP BY teacher_id",
            $ids
        ), ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('booking_workload_pending_unavailable');
        foreach ((array) $pending as $row) $result[(int) $row['teacher_id']]['pending'] = (int) $row['n'];

        $accepted = $wpdb->get_results($wpdb->prepare(
            "SELECT teacher_id,COUNT(*) n FROM {$this->p}booking_request_confirmations
             WHERE teacher_id IN ($in)
               AND state NOT IN ('failed','cancelled')
               AND starts_at_utc>=%s
             GROUP BY teacher_id",
            array_merge($ids, array($windowStart))
        ), ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('booking_workload_accepted_unavailable');
        foreach ((array) $accepted as $row) $result[(int) $row['teacher_id']]['accepted'] = (int) $row['n'];

        // COALESCE(lifecycle_state,status) is the single effective state used by
        // the lesson read services, so the workload read agrees with the rest of
        // the platform instead of inventing a second state vocabulary.
        $lessons = $wpdb->get_results($wpdb->prepare(
            "SELECT teacher_id,
                    SUM(CASE WHEN COALESCE(lifecycle_state,status) IN ('authorised','scheduled') THEN 1 ELSE 0 END) confirmed,
                    SUM(CASE WHEN COALESCE(lifecycle_state,status)='completed' THEN 1 ELSE 0 END) completed
             FROM {$this->p}lessons
             WHERE teacher_id IN ($in)
               AND archived_at IS NULL
               AND lesson_type<>'introductory'
               AND ( COALESCE(lifecycle_state,status) IN ('authorised','scheduled')
                     OR ( COALESCE(lifecycle_state,status)='completed' AND completed_at>=%s ) )
             GROUP BY teacher_id",
            array_merge($ids, array($windowStart))
        ), ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('booking_workload_lessons_unavailable');
        foreach ((array) $lessons as $row) {
            $id = (int) $row['teacher_id'];
            $result[$id]['confirmed'] = (int) $row['confirmed'];
            $result[$id]['completed'] = (int) $row['completed'];
        }

        return $result;
    }
}
