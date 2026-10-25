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
        try {
            $postIntro = (new PostIntroStudentReadService())->forStudent((int) $principal['id']);
        } catch (\Throwable $error) {
            // A new workflow surface must never take the whole Student Portal down.
            do_action('dzn_post_intro_student_read_failed', get_class($error));
            $postIntro = array();
        }
        return array(
            'principal' => (new PortalInternalReadSurface())->principal($principal),
            // The Theme must not substitute the site timezone (or the WordPress
            // display name) for this student's canonical profile facts.
            'profile' => $this->studentProfile((int) $principal['id']),
            'lessons' => $lessons,
            'enrolments' => $enrolments,
            'booking_requests' => $bookingRequests,
            'booking_classes' => $bookingClasses,
            'post_intro' => $postIntro,
        );
    }

    /** A deliberately small, self-owned profile projection for portal presentation. */
    private function studentProfile(int $studentId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name,email,phone,whatsapp_phone,country_code,city,timezone,locale,calendar_preference
             FROM {$p}students
             WHERE id=%d AND status='active' AND archived_at IS NULL",
            $studentId
        ), ARRAY_A);
        if (!is_array($row) || $wpdb->last_error !== '') throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        return array(
            'full_name' => (string) ($row['display_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'mobile' => (string) ($row['phone'] ?? ''),
            'whatsapp_phone' => (string) ($row['whatsapp_phone'] ?? ''),
            'country_code' => (string) ($row['country_code'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'timezone' => (string) ($row['timezone'] ?? ''),
            'locale' => (string) ($row['locale'] ?? ''),
            'calendar_preference' => (string) ($row['calendar_preference'] ?? ''),
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
        $schedules = $wpdb->prefix . 'dzn_canonical_lesson_schedule_versions';
        $legacySchedules = $wpdb->prefix . 'dzn_lesson_schedule_versions';
        // A canonical Lesson becomes portal-visible when its occurrence is scheduled. An
        // authorised but unscheduled Lesson has no date/time to present and must not make the
        // whole portal read fail closed; the canonical schedule read still validates every row
        // that is selected.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT l.id FROM {$table} l INNER JOIN {$schedules} v ON v.lesson_id=l.id AND v.applicable_slot=1 WHERE l.{$column}=%d AND l.record_model='canonical_term_lesson_v1' ORDER BY v.starts_at_utc ASC,l.id ASC LIMIT %d",
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
        // Older staff-entered classes are a separate, read-only compatibility projection. They
        // must have the exact current legacy schedule pointer and never acquire canonical portal
        // capabilities (join/absence) merely by becoming visible.
        $legacyIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT l.id FROM {$table} l INNER JOIN {$legacySchedules} v ON v.id=l.current_schedule_version_id AND v.lesson_id=l.id AND v.superseded_at IS NULL WHERE l.{$column}=%d AND l.record_model='legacy_phase1' AND l.status IN ('scheduled','completed') AND l.archived_at IS NULL ORDER BY v.starts_at_utc ASC,l.id ASC LIMIT %d",
                $principalId,
                self::LIMIT
            )
        );
        foreach ((array) $legacyIds as $id) $rows[] = $this->legacyLessonFor($kind, (int) $id, $principalId);
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['starts_at_utc'], (string) $b['starts_at_utc']));
        return $rows;
    }

    /** A legacy class is visible only to its exact canonical portal principal and is read-only. */
    private function legacyLessonFor(string $kind, int $lessonId, int $principalId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $column = $kind === 'teacher' ? 'teacher_id' : 'student_id';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id,l.uid,l.reference_code,l.lesson_type,l.status,l.student_id,l.teacher_id,v.id schedule_id,v.starts_at_utc,v.ends_at_utc,v.schedule_timezone,c.reference_code course_reference,s.reference_code student_reference FROM {$p}lessons l INNER JOIN {$p}lesson_schedule_versions v ON v.id=l.current_schedule_version_id AND v.lesson_id=l.id AND v.superseded_at IS NULL LEFT JOIN {$p}courses c ON c.id=l.course_id LEFT JOIN {$p}students s ON s.id=l.student_id WHERE l.id=%d AND l.{$column}=%d AND l.record_model='legacy_phase1' AND l.status IN ('scheduled','completed') AND l.archived_at IS NULL",
            $lessonId,
            $principalId
        ));
        if (!$row || $wpdb->last_error !== '') throw new \InvalidArgumentException('portal_object_not_portal_visible');
        if ((int) $row->id < 1 || (int) $row->schedule_id < 1 || trim((string) $row->starts_at_utc) === '' || trim((string) $row->ends_at_utc) === '' || trim((string) $row->schedule_timezone) === '') throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        return array(
            'version' => 'portal_lesson_v1',
            'lesson_uid' => trim((string) $row->uid) ?: 'legacy-' . (int) $row->id,
            'reference_code' => trim((string) $row->reference_code) ?: 'Class #' . (int) $row->id,
            'lesson_kind' => (string) $row->lesson_type,
            // Do not present a legacy class as canonical-authorised: no absence or public
            // capability action may be created from this compatibility projection.
            'lifecycle_state' => (string) $row->status === 'completed' ? 'completed' : 'scheduled',
            'enrolment_uid' => '',
            'course_reference' => trim((string) $row->course_reference) ?: 'Course',
            'term_reference' => '',
            'starts_at_utc' => (string) $row->starts_at_utc,
            'ends_at_utc' => (string) $row->ends_at_utc,
            'schedule_timezone' => (string) $row->schedule_timezone,
            'schedule_version_uid' => 'legacy-schedule-' . (int) $row->schedule_id,
            'delivery_state_summary' => (string) $row->status === 'completed' ? 'completed' : 'not_recorded',
            'attendance_state_summary' => 'not_recorded',
            'join_available' => false,
            'join_refusal_reason' => 'legacy_class_read_only',
            'absence_available' => false,
            'absence_refusal_reason' => 'legacy_class_read_only',
            'assignment_state' => $kind === 'teacher' ? 'legacy_read_only' : null,
            'student_display_reference' => $kind === 'teacher' ? (trim((string) $row->student_reference) ?: 'Student') : null,
        );
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
