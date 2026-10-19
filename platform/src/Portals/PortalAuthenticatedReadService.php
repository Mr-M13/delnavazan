<?php
namespace Delnavazan\Platform\Portals;

/**
 * Authenticated, read-only composition surface for first-party portal UIs.
 *
 * It resolves the current WordPress session to a canonical principal, discovers
 * only candidate IDs owned by that principal, and re-proves every returned
 * object through PortalAccessPolicy. It exposes no mutation authority.
 */
final class PortalAuthenticatedReadService {
    private const LIMIT = 80;

    public function student(): array {
        $principal = (new PortalPrincipalResolver())->resolve('student');
        $lessons = $this->lessonsFor('student', (int) $principal['id'], $principal);
        $enrolments = $this->studentEnrolments((int) $principal['id'], $principal);
        $bookingRequests = $this->studentBookingRequests((int) $principal['id']);
        $bookingClasses = (new StudentUpcomingBookingReadService())->forStudent((int) $principal['id']);
        return array(
            'principal' => (new PortalInternalReadSurface())->principal($principal),
            'lessons' => $lessons,
            'enrolments' => $enrolments,
            'booking_requests' => $bookingRequests,
            'booking_classes' => $bookingClasses,
        );
    }

    /** Pending introductory requests are scoped by both the canonical Student and its resolution event. */
    private function studentBookingRequests(int $studentId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $requests = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id,r.uid,r.reference_code,r.lifecycle_status,r.resolution_state,r.created_at,i.name_fa instrument_name_fa,i.name_en instrument_name_en,c.name_fa course_name_fa,c.name_en course_name_en
             FROM {$p}booking_requests r
             INNER JOIN {$p}booking_request_identity_resolution_events e ON e.id=r.current_identity_resolution_id AND e.booking_request_id=r.id AND e.student_id=r.student_id AND e.outcome='resolved'
             INNER JOIN {$p}instruments i ON i.id=r.requested_instrument_id
             LEFT JOIN {$p}courses c ON c.id=r.selected_intro_course_id
             WHERE r.student_id=%d AND r.lifecycle_status='submitted' AND r.privacy_erased_at IS NULL
             ORDER BY r.id DESC LIMIT %d",
            $studentId,
            self::LIMIT
        ) );
        $result = array();
        foreach ( $requests as $request ) {
            $times = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT local_date,local_start_time,timezone FROM {$p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number ASC LIMIT 3",
                (int) $request->id
            ), ARRAY_A );
            $result[] = array(
                'version' => 'student_booking_request_v1',
                'uid' => (string) $request->uid,
                'reference' => (string) $request->reference_code,
                'lifecycle_status' => (string) $request->lifecycle_status,
                'resolution_state' => (string) $request->resolution_state,
                'instrument' => (string) ( $request->instrument_name_fa ?: $request->instrument_name_en ),
                'course' => (string) ( $request->course_name_fa ?: $request->course_name_en ),
                'requested_times' => array_map( static fn( array $time ): array => array( 'local_date' => (string) $time['local_date'], 'local_start_time' => substr( (string) $time['local_start_time'], 0, 5 ), 'timezone' => (string) $time['timezone'] ), $times ),
                'created_at' => (string) $request->created_at,
            );
        }
        return $result;
    }

    public function teacher(): array {
        $principal = (new PortalPrincipalResolver())->resolve('teacher');
        if ( ! ( new \Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository() )->hasActiveTeacherAuthority( (int) get_current_user_id(), (int) $principal['id'] ) ) throw new \InvalidArgumentException( 'teacher_onboarding_required' );
        $lessons = $this->lessonsFor('teacher', (int) $principal['id'], $principal);
        $assignments = $this->teacherAssignments((int) $principal['id'], $principal);
        $bookingClasses = (new TeacherUpcomingBookingReadService())->forTeacher((int) $principal['id']);
        return array(
            'principal' => (new PortalInternalReadSurface())->principal($principal),
            'lessons' => $lessons,
            'assignments' => $assignments,
            'booking_classes' => $bookingClasses,
        );
    }

    private function lessonsFor(string $kind, int $principalId, array $principal): array {
        global $wpdb;
        $column = $kind === 'teacher' ? 'teacher_id' : 'student_id';
        $table = $wpdb->prefix . 'dzn_lessons';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE {$column}=%d AND record_model='canonical_term_lesson_v1' ORDER BY id DESC LIMIT %d",
                $principalId,
                self::LIMIT
            )
        );
        $rows = array();
        foreach ((array) $ids as $id) {
            $rows[] = PortalAccessPolicy::assertObject(
                $kind === 'teacher' ? 'teacher_portal' : 'student_portal',
                'lesson',
                (int) $id,
                $principal
            );
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['starts_at_utc'], (string) $b['starts_at_utc']));
        return $rows;
    }

    private function studentEnrolments(int $studentId, array $principal): array {
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_enrolments';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE student_id=%d AND archived_at IS NULL ORDER BY id DESC LIMIT %d",
                $studentId,
                self::LIMIT
            )
        );
        $rows = array();
        foreach ((array) $ids as $id) {
            $rows[] = PortalAccessPolicy::assertObject('student_portal', 'enrolment', (int) $id, $principal);
        }
        return $rows;
    }

    private function teacherAssignments(int $teacherId, array $principal): array {
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_teacher_assignments';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT enrolment_id FROM {$table} WHERE teacher_id=%d AND applicable_slot=1 ORDER BY id DESC LIMIT %d",
                $teacherId,
                self::LIMIT
            )
        );
        $rows = array();
        foreach ((array) $ids as $enrolmentId) {
            $rows[] = PortalAccessPolicy::assertObject('teacher_portal', 'assignment', (int) $enrolmentId, $principal);
        }
        return $rows;
    }
}
