<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Capability-gated operational projection for the consolidated Enrolment workspace.
 *
 * This class is deliberately read-only. It composes the existing canonical and legacy
 * booking, coordination, Enrolment, Term, Lesson, attendance and post-intro records for
 * staff decisions; every write remains with the owning authority service.
 */
final class EnrolmentWorkspaceReadService {
    private const ACTIVE_BOOKING_LIMIT = 250;
    private const HISTORY_BOOKING_LIMIT = 500;
    private const CLASS_LIMIT = 600;
    private string $p;
    /** @var array<int,int> */
    private array $importCompletedCache = array();

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function bookingCases(array $filters, bool $history = false): array {
        $this->requireAny(array('dzn_view_booking_requests', 'dzn_manage_booking_request_coordination'));
        $rows = $this->bookingBaseRows($history);
        $search = trim((string) ($filters['q'] ?? ''));
        $stage = sanitize_key((string) ($filters['stage'] ?? ''));
        $result = array();
        foreach ($rows as $row) {
            $summary = $this->composeBookingRow($row, $history);
            if ($search !== '' && !$this->bookingMatches($summary, $search)) continue;
            if ($stage !== '' && (string) ($summary['stage_key'] ?? '') !== $stage) continue;
            $result[] = $summary;
        }
        usort($result, static function (array $a, array $b) use ($history): int {
            if ($history) return strcmp((string) ($b['sort_at'] ?? ''), (string) ($a['sort_at'] ?? ''));
            return array((int) $a['urgency_rank'], (string) ($a['deadline_at'] ?? '9999'), (string) ($a['created_at'] ?? ''))
                <=> array((int) $b['urgency_rank'], (string) ($b['deadline_at'] ?? '9999'), (string) ($b['created_at'] ?? ''));
        });
        $perPage = 20;
        $total = count($result);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($filters['page'] ?? 1)));
        return array(
            'rows' => array_slice($result, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        );
    }

    /** @return array<string,mixed>|null */
    public function bookingCase(int $requestId): ?array {
        $this->requireAny(array('dzn_view_booking_requests', 'dzn_manage_booking_request_coordination'));
        if ($requestId < 1) return null;
        $row = $this->bookingRow($requestId);
        if (!$row) return null;
        $history = !$this->isActiveBooking($row);
        $case = $this->composeBookingRow($row, $history);
        $case['timeline'] = $this->bookingTimeline($requestId);
        $case['identity_events'] = $this->identityEvents($requestId);
        $case['coordination'] = $this->coordinationCase($requestId);
        $case['confirmed'] = $this->confirmedBooking($requestId);
        $case['outcome'] = $this->introOutcome($requestId);
        $case['post_intro'] = $this->postIntro($requestId);
        $case['notifications'] = $this->bookingNotifications($requestId);
        $case['prior_bookings'] = (int) ($row->student_id ?? 0) > 0 ? $this->priorBookings((int) $row->student_id, $requestId) : array();
        return $case;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,counts:array<string,int>} */
    public function classes(array $filters, bool $history = false): array {
        $this->requireAny(array('dzn_manage_lessons', 'dzn_manage_canonical_lessons', 'dzn_manage_enrolments'));
        $rows = array_merge($this->canonicalClassRows(), $this->legacyClassRows());
        $this->primeImportCounts(array_values(array_unique(array_map(static fn(object $row): int => (int) ($row->enrolment_id ?? 0), $rows))));
        $search = trim((string) ($filters['q'] ?? ''));
        $teacherId = (int) ($filters['teacher_id'] ?? 0);
        $studentId = (int) ($filters['student_id'] ?? 0);
        $courseId = (int) ($filters['course_id'] ?? 0);
        $termId = (int) ($filters['term_id'] ?? 0);
        $date = trim((string) ($filters['date'] ?? ''));
        $action = sanitize_key((string) ($filters['action'] ?? ''));
        $today = $this->academyToday();
        $out = array();
        $counts = array('today' => 0, 'upcoming' => 0, 'history' => 0, 'action' => 0);
        foreach ($rows as $row) {
            $summary = $this->composeClassRow($row);
            $isHistory = $summary['bucket'] === 'history';
            if ($isHistory !== $history) continue;
            if ($search !== '' && !$this->classMatches($summary, $search)) continue;
            if ($teacherId > 0 && (int) $summary['teacher_id'] !== $teacherId) continue;
            if ($studentId > 0 && (int) $summary['student_id'] !== $studentId) continue;
            if ($courseId > 0 && (int) $summary['course_id'] !== $courseId) continue;
            if ($termId > 0 && (int) ($summary['term_id'] ?? 0) !== $termId) continue;
            if ($date !== '' && (string) ($summary['local_date'] ?? '') !== $date && (string) ($summary['local_date_short'] ?? '') !== $date) continue;
            if ($action === 'needs_action' && empty($summary['action_needed'])) continue;
            if ($history) $counts['history']++;
            elseif ($summary['bucket'] === 'today') $counts['today']++;
            else $counts['upcoming']++;
            if (!empty($summary['action_needed'])) $counts['action']++;
            $out[] = $summary;
        }
        usort($out, static function (array $a, array $b) use ($history): int {
            $left = (string) ($a['starts_at_utc'] ?: '9999-12-31 23:59:59');
            $right = (string) ($b['starts_at_utc'] ?: '9999-12-31 23:59:59');
            return $history ? strcmp($right, $left) : strcmp($left, $right);
        });
        $perPage = 25;
        $total = count($out);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($filters['page'] ?? 1)));
        return array(
            'rows' => array_slice($out, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'counts' => $counts,
        );
    }

    /** @return array<string,mixed>|null */
    public function classDetail(int $lessonId): ?array {
        $this->requireAny(array('dzn_manage_lessons', 'dzn_manage_canonical_lessons', 'dzn_manage_enrolments'));
        if ($lessonId < 1) return null;
        $row = $this->classRow($lessonId);
        if (!$row) return null;
        $class = $this->composeClassRow($row);
        $class['schedule_versions'] = $this->scheduleVersions($lessonId, (string) $class['record_model']);
        $class['attendance_evidence'] = $this->attendanceDetail($lessonId, (int) ($class['schedule_version_id'] ?? 0));
        $class['delivery'] = $this->deliveryDetail($lessonId);
        $class['notifications'] = (new NotificationDeliveryStatusReadService())->forLesson($lessonId);
        $class['term'] = $this->termDetail((int) ($class['term_id'] ?? 0));
        $class['assignment_history'] = $this->assignmentHistory((int) ($class['enrolment_id'] ?? 0));
        $class['import_evidence'] = $this->importEvidence((int) ($class['enrolment_id'] ?? 0));
        return $class;
    }

    /** @return array<int,object> */
    public function studentOptions(string $search = '', int $limit = 100): array {
        $this->requireAny(array('dzn_manage_students', 'dzn_manage_enrolments', 'dzn_manage_lessons', 'dzn_manage_canonical_lessons'));
        global $wpdb;
        $limit = max(1, min(200, $limit));
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,reference_code,display_name,email,timezone,status FROM {$this->p}students
             WHERE archived_at IS NULL AND status <> 'archived'
               AND (%s='' OR display_name LIKE %s OR email LIKE %s OR reference_code LIKE %s)
             ORDER BY display_name ASC LIMIT %d",
            trim($search), $like, $like, $like, $limit
        )) ?: array();
    }

    /** @return array<int,object> */
    public function teacherOptions(string $search = '', int $limit = 100): array {
        $this->requireAny(array('dzn_manage_teachers', 'dzn_manage_enrolments', 'dzn_manage_lessons', 'dzn_manage_canonical_lessons', 'dzn_manage_canonical_lesson_schedules'));
        global $wpdb;
        $limit = max(1, min(200, $limit));
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.reference_code,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) display_name,t.email,t.timezone
             FROM {$this->p}teachers t
             WHERE t.archived_at IS NULL AND t.status='active'
               AND (%s='' OR t.display_name LIKE %s OR t.english_name LIKE %s OR t.persian_name LIKE %s OR t.email LIKE %s)
             ORDER BY display_name ASC LIMIT %d",
            trim($search), $like, $like, $like, $like, $limit
        )) ?: array();
    }

    /** @return array<int,object> */
    public function courseOptions(string $search = '', int $limit = 250): array {
        $this->requireAny(array('dzn_manage_courses', 'dzn_manage_enrolments', 'dzn_manage_lessons', 'dzn_manage_canonical_lessons'));
        global $wpdb;
        $limit = max(1, min(300, $limit));
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.id,c.reference_code,COALESCE(NULLIF(c.name_en,''),c.name_fa) name,c.course_type,c.instrument_id,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name
             FROM {$this->p}courses c LEFT JOIN {$this->p}instruments i ON i.id=c.instrument_id
             WHERE c.archived_at IS NULL AND c.status='active'
               AND (%s='' OR c.name_en LIKE %s OR c.name_fa LIKE %s OR i.name_en LIKE %s OR i.name_fa LIKE %s)
             ORDER BY instrument_name ASC,c.course_type ASC,name ASC LIMIT %d",
            trim($search), $like, $like, $like, $like, $limit
        )) ?: array();
    }

    /** @return array<int,object> */
    public function teacherOptionsForCourse(int $courseId, string $search = '', int $limit = 100): array {
        $this->requireAny(array('dzn_manage_teachers', 'dzn_manage_enrolments'));
        if ($courseId < 1) return array();
        global $wpdb;
        $limit = max(1, min(200, $limit));
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.reference_code,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) display_name,
                    t.email,t.timezone,COALESCE(NULLIF(p.timezone,''),t.timezone) availability_timezone
             FROM {$this->p}teachers t
             INNER JOIN {$this->p}teacher_course_eligibility e ON e.teacher_id=t.id AND e.course_id=%d AND e.status='active'
             LEFT JOIN {$this->p}teacher_availability_profiles p ON p.teacher_id=t.id AND p.status='active'
             WHERE t.archived_at IS NULL AND t.status='active'
               AND (%s='' OR t.display_name LIKE %s OR t.english_name LIKE %s OR t.persian_name LIKE %s OR t.email LIKE %s)
             ORDER BY display_name ASC LIMIT %d",
            $courseId, trim($search), $like, $like, $like, $like, $limit
        )) ?: array();
    }

    /** @return array<int,object> */
    public function termOptions(int $limit = 120): array {
        $this->requireAny(array('dzn_manage_terms', 'dzn_manage_enrolments', 'dzn_manage_lessons', 'dzn_manage_canonical_lessons'));
        global $wpdb;
        $limit = max(1, min(200, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.reference_code,t.sequence_number,t.lifecycle_state,
                    COALESCE(NULLIF(s.display_name,''),'Student') student_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$this->p}terms t
             LEFT JOIN {$this->p}enrolments e ON e.id=t.enrolment_id
             LEFT JOIN {$this->p}students s ON s.id=e.student_id
             LEFT JOIN {$this->p}courses c ON c.id=e.course_id
             WHERE t.archived_at IS NULL
             ORDER BY t.id DESC LIMIT %d",
            $limit
        )) ?: array();
    }

    /** @return array<int,object> */
    public function termOptionsForEnrolment(int $enrolmentId): array {
        $this->requireAny(array('dzn_manage_terms', 'dzn_manage_enrolments'));
        if ($enrolmentId < 1) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,reference_code,sequence_number,lifecycle_state,status,lesson_allocation,replacement_allowance
             FROM {$this->p}terms WHERE enrolment_id=%d AND archived_at IS NULL ORDER BY sequence_number DESC",
            $enrolmentId
        )) ?: array();
    }

    /**
     * Trustworthy conversion reporting. Every outcome names its reason and the period.
     *
     * @return array{period:array<string,string>,denominator:int,converted:int,awaiting:int,did_not_convert:int,excluded:int,rows:array<int,array<string,mixed>>}
     */
    public function conversionReport(string $fromDate, string $toDate): array {
        $this->requireAny(array('dzn_view_booking_requests', 'dzn_manage_booking_request_coordination'));
        $from = $this->utcBoundary($fromDate, false);
        $to = $this->utcBoundary($toDate, true);
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id,r.created_at,r.student_id,r.lifecycle_status,r.resolution_state,
                    o.outcome outcome_code,o.recorded_at outcome_at,
                    p.state preparation_state,p.enrolment_id,p.offer_id,p.student_action_at,
                    e.id converted_enrolment_id,
                    s.full_name student_name,s.timezone contact_timezone,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    conf.starts_at_utc intro_at,conf.schedule_timezone intro_timezone,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}booking_requests r
             LEFT JOIN {$this->p}booking_request_intro_outcomes o ON o.booking_request_id=r.id
             LEFT JOIN {$this->p}post_intro_preparations p ON p.booking_request_id=r.id
             LEFT JOIN {$this->p}enrolments e ON e.id=p.enrolment_id
             LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
             LEFT JOIN {$this->p}instruments i ON i.id=r.requested_instrument_id
             LEFT JOIN {$this->p}courses c ON c.id=r.selected_intro_course_id
             LEFT JOIN {$this->p}booking_request_confirmations conf ON conf.id=(SELECT MAX(cf.id) FROM {$this->p}booking_request_confirmations cf WHERE cf.booking_request_id=r.id)
             LEFT JOIN {$this->p}teachers t ON t.id=conf.teacher_id
             WHERE r.created_at>=%s AND r.created_at<%s AND r.privacy_erased_at IS NULL
             ORDER BY r.created_at DESC LIMIT 2000",
            $from, $to
        )) ?: array();
        $summary = array('converted' => 0, 'awaiting' => 0, 'did_not_convert' => 0, 'excluded' => 0);
        $out = array();
        foreach ($rows as $row) {
            $classification = $this->conversionClassification($row);
            $summary[$classification['bucket']]++;
            $elapsed = max(0, time() - (int) strtotime((string) $row->created_at . ' UTC'));
            $timezone = $this->safeTimezone((string) ($row->intro_timezone ?: $row->contact_timezone ?: 'UTC'));
            $out[] = array(
                'request_id' => (int) $row->id,
                'created_at' => (string) $row->created_at,
                'student' => (string) ($row->student_name ?: 'Learner'),
                'course' => (string) ($row->course_name ?: '—'),
                'instrument' => (string) ($row->instrument_name ?: '—'),
                'teacher' => (string) ($row->teacher_name ?: 'Not selected'),
                'date' => $row->intro_at !== null && $row->intro_at !== ''
                    ? $this->localLabel((string) $row->intro_at, $timezone)
                    : $this->localLabel((string) $row->created_at, $this->safeTimezone((string) ($row->contact_timezone ?: 'UTC'))) . ' (requested)',
                'elapsed_seconds' => $elapsed,
                'elapsed' => $this->ageLabel($elapsed),
                'outcome' => $classification['label'],
                'reason' => $classification['reason'],
                'bucket' => $classification['bucket'],
            );
        }
        return array(
            'period' => array('from' => substr($from, 0, 10), 'to' => substr($to, 0, 10)),
            'denominator' => count($rows),
            'converted' => $summary['converted'],
            'awaiting' => $summary['awaiting'],
            'did_not_convert' => $summary['did_not_convert'],
            'excluded' => $summary['excluded'],
            'rows' => array_slice($out, 0, 40),
        );
    }

    /**
     * Active introductory requests that still need review or follow-up.
     *
     * The queue is based on the latest booking confirmation, never on a synthetic
     * lesson. A request is removed from this active queue when the payment link
     * has been sent and fourteen days pass without a recorded decision, payment
     * or activation. The underlying case is never deleted; it remains in History.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,auto_removed:int,counts:array<string,int>}
     */
    public function reviewCases(): array {
        $this->requireAny(array('dzn_view_booking_requests', 'dzn_manage_booking_request_coordination'));
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT r.id,r.reference_code,r.created_at,r.lifecycle_status,r.resolution_state,
                    s.full_name student_name,s.email student_email,s.mobile student_mobile,s.country,s.timezone contact_timezone,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    conf.id confirmation_id,conf.uid confirmation_uid,conf.state confirmation_state,
                    conf.starts_at_utc,conf.ends_at_utc,conf.schedule_timezone,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,
                    o.outcome outcome_code,o.recorded_at outcome_at,
                    p.id preparation_id,p.state preparation_state,p.enrolment_id,p.updated_at preparation_updated_at
             FROM {$this->p}booking_requests r
             INNER JOIN {$this->p}booking_request_confirmations conf ON conf.id=(SELECT MAX(cf.id) FROM {$this->p}booking_request_confirmations cf WHERE cf.booking_request_id=r.id)
             LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
             LEFT JOIN {$this->p}instruments i ON i.id=r.requested_instrument_id
             LEFT JOIN {$this->p}courses c ON c.id=r.selected_intro_course_id
             LEFT JOIN {$this->p}teachers t ON t.id=conf.teacher_id
             LEFT JOIN {$this->p}booking_request_intro_outcomes o ON o.booking_request_id=r.id
             LEFT JOIN {$this->p}post_intro_preparations p ON p.booking_request_id=r.id
             WHERE r.privacy_erased_at IS NULL
               AND conf.state IN ('confirmed','awaiting_payment')
               AND conf.starts_at_utc>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)
             ORDER BY conf.starts_at_utc ASC,r.id ASC LIMIT 500"
        ) ?: array();
        if (!$rows) return array('rows' => array(), 'total' => 0, 'auto_removed' => 0, 'counts' => array('upcoming' => 0, 'outcome_due' => 0, 'payment_open' => 0, 'follow_up_due' => 0));
        $ids = array_map(static fn(object $row): int => (int) $row->id, $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT d.booking_request_id,n.notification_kind,
                    MAX(n.handed_off_at) handed_off_at,MAX(n.created_at) created_at,MAX(n.updated_at) updated_at,COUNT(*) total
             FROM {$this->p}booking_request_student_notifications n
             INNER JOIN {$this->p}booking_request_teacher_deliveries d ON d.id=n.delivery_id
             WHERE d.booking_request_id IN ({$placeholders})
               AND n.notification_kind IN ('post_intro_invitation','post_intro_reminder')
             GROUP BY d.booking_request_id,n.notification_kind",
            ...$ids
        )) ?: array();
        $byRequest = array();
        foreach ($notifications as $notification) {
            $requestId = (int) $notification->booking_request_id;
            $kind = (string) $notification->notification_kind;
            $byRequest[$requestId][$kind] = array(
                'at' => (string) (($notification->handed_off_at !== null && $notification->handed_off_at !== '') ? $notification->handed_off_at : ($notification->created_at ?: $notification->updated_at)),
                'count' => (int) $notification->total,
            );
        }
        $now = time();
        $out = array();
        $autoRemoved = 0;
        $counts = array('upcoming' => 0, 'outcome_due' => 0, 'payment_open' => 0, 'follow_up_due' => 0);
        foreach ($rows as $row) {
            $requestId = (int) $row->id;
            $start = strtotime((string) $row->starts_at_utc . ' UTC');
            $end = strtotime((string) $row->ends_at_utc . ' UTC');
            $prepState = (string) ($row->preparation_state ?? '');
            $paymentLink = $byRequest[$requestId]['post_intro_invitation'] ?? null;
            $reminder = $byRequest[$requestId]['post_intro_reminder'] ?? null;
            $paymentLinkAt = $paymentLink['at'] ?? '';
            if ($paymentLinkAt === '' && in_array($prepState, array('checkout_pending', 'payment_received'), true)) $paymentLinkAt = (string) ($row->preparation_updated_at ?? '');
            $paymentLinkSent = $paymentLinkAt !== '';
            $paymentSettled = in_array($prepState, array('payment_received', 'activated'), true) || (int) ($row->enrolment_id ?? 0) > 0;
            $noInterestAfterLink = $paymentLinkSent && !$paymentSettled
                && $prepState !== 'activated'
                && $paymentLinkAt !== ''
                && strtotime($paymentLinkAt . ' UTC') + 14 * 86400 < $now;
            if ($noInterestAfterLink) { $autoRemoved++; continue; }
            $attended = match ((string) ($row->outcome_code ?? '')) {
                'delivered' => 'Yes',
                'student_no_show', 'no_show' => 'No',
                '' => $end !== false && $end < $now ? 'Not recorded' : 'Not yet',
                default => ucfirst(str_replace('_', ' ', (string) $row->outcome_code)),
            };
            $followUpCompleted = (string) ($row->outcome_at ?? '') !== ''
                || in_array($prepState, array('activated', 'expired'), true)
                || ($reminder !== null && (int) $reminder['count'] > 0);
            $status = match (true) {
                $prepState === 'activated' => 'Enrolment activated',
                $prepState === 'payment_received' => 'Payment received — activating',
                $prepState === 'checkout_pending' => 'Payment link open',
                (string) ($row->outcome_at ?? '') !== '' => 'Outcome recorded',
                $start !== false && $start > $now => 'Upcoming intro',
                default => 'Outcome due',
            };
            if ($start !== false && $start > $now) $counts['upcoming']++;
            elseif ((string) ($row->outcome_at ?? '') === '') $counts['outcome_due']++;
            if ($prepState === 'checkout_pending' || ($paymentLinkSent && !$paymentSettled)) $counts['payment_open']++;
            if (!$followUpCompleted && $end !== false && $end < $now) $counts['follow_up_due']++;
            $out[] = array(
                'id' => $requestId,
                'reference' => (string) ($row->reference_code ?: 'Request ' . $requestId),
                'date' => $this->localLabel((string) $row->starts_at_utc, $this->safeTimezone((string) ($row->schedule_timezone ?: $row->contact_timezone ?: 'UTC'))),
                'starts_at_utc' => (string) $row->starts_at_utc,
                'instrument' => (string) ($row->instrument_name ?: '—'),
                'course' => (string) ($row->course_name ?: '—'),
                'teacher' => (string) ($row->teacher_name ?: 'Not selected'),
                'student' => (string) ($row->student_name ?: 'Learner'),
                'status' => $status,
                'country' => (string) ($row->country ?: '—'),
                'attended' => $attended,
                'payment_link_sent' => $paymentLinkSent ? 'Yes · ' . $this->localLabel($paymentLinkAt, $this->safeTimezone((string) ($row->schedule_timezone ?: $row->contact_timezone ?: 'UTC'))) : 'No',
                'reminder' => $reminder !== null ? (int) $reminder['count'] . ' · ' . $this->localLabel((string) $reminder['at'], $this->safeTimezone((string) ($row->schedule_timezone ?: $row->contact_timezone ?: 'UTC'))) : 'None',
                'follow_up_completed' => $followUpCompleted ? 'Yes' : 'No',
                'sort_at' => (string) $row->starts_at_utc,
                'outcome' => (string) ($row->outcome_code ?? ''),
                'preparation_state' => $prepState,
            );
        }
        return array(
            'rows' => $out,
            'total' => count($out),
            'auto_removed' => $autoRemoved,
            'counts' => $counts,
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function bookingBaseRows(bool $history): array {
        global $wpdb;
        $limit = $history ? self::HISTORY_BOOKING_LIMIT : self::ACTIVE_BOOKING_LIMIT;
        $active = "r.lifecycle_status='submitted' AND r.privacy_erased_at IS NULL";
        $where = $history
            ? "(r.lifecycle_status<>'submitted' OR r.privacy_erased_at IS NOT NULL OR EXISTS (SELECT 1 FROM {$this->p}booking_request_intro_outcomes ho WHERE ho.booking_request_id=r.id AND ho.outcome<>'delivered') OR EXISTS (SELECT 1 FROM {$this->p}post_intro_preparations hp WHERE hp.booking_request_id=r.id AND hp.state IN ('activated','expired','exception')))"
            : "({$active}) OR EXISTS (SELECT 1 FROM {$this->p}post_intro_preparations ap WHERE ap.booking_request_id=r.id AND ap.state NOT IN ('activated','expired','exception'))";
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,s.full_name,s.email,s.mobile,s.country,s.city,s.timezone contact_timezone,s.communication_language,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$this->p}booking_requests r
             LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
             LEFT JOIN {$this->p}instruments i ON i.id=r.requested_instrument_id
             LEFT JOIN {$this->p}courses c ON c.id=r.selected_intro_course_id
             WHERE {$where}
             ORDER BY r.created_at DESC LIMIT %d",
            $limit
        )) ?: array();
    }

    private function bookingRow(int $requestId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,s.full_name,s.email,s.mobile,s.country,s.city,s.timezone contact_timezone,s.communication_language,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$this->p}booking_requests r
             LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
             LEFT JOIN {$this->p}instruments i ON i.id=r.requested_instrument_id
             LEFT JOIN {$this->p}courses c ON c.id=r.selected_intro_course_id
             WHERE r.id=%d",
            $requestId
        ));
    }

    /** @return array<string,mixed> */
    private function composeBookingRow(object $row, bool $history): array {
        $requestId = (int) $row->id;
        $times = $this->requestedTimes($requestId);
        $held = $this->heldSlot($requestId);
        $coordination = $this->coordinationSummary($requestId);
        $confirmation = $this->confirmedBooking($requestId);
        $outcome = $this->introOutcome($requestId);
        $postIntro = $this->postIntro($requestId);
        $stage = $this->bookingStage($row, $times, $coordination, $confirmation, $outcome, $postIntro);
        $deadline = $this->bookingDeadline($row, $times, $postIntro, $held);
        $age = max(0, time() - strtotime((string) $row->created_at . ' UTC'));
        $urgency = $this->bookingUrgency($row, $stage, $times, $deadline, $outcome, $postIntro);
        $notifications = $this->bookingNotifications($requestId);
        $teacher = $coordination['teacher_name'] !== '' ? $coordination['teacher_name'] : (string) ($confirmation['teacher_name'] ?? '');
        $teacherResponse = $coordination['teacher_response'] !== '' ? $coordination['teacher_response'] : (string) ($confirmation['state_label'] ?? '');
        $conversion = $this->conversionClassification((object) array(
            'id' => $requestId,
            'created_at' => $row->created_at,
            'student_id' => $row->student_id,
            'lifecycle_status' => $row->lifecycle_status,
            'resolution_state' => $row->resolution_state,
            'outcome_code' => $outcome['outcome'] ?? null,
            'outcome_at' => $outcome['recorded_at'] ?? null,
            'preparation_state' => $postIntro['state'] ?? null,
            'enrolment_id' => $postIntro['enrolment_id'] ?? null,
            'offer_id' => $postIntro['offer_id'] ?? null,
            'student_action_at' => $postIntro['student_action_at'] ?? null,
            'converted_enrolment_id' => $postIntro['enrolment_id'] ?? null,
        ));
        $completion = $this->completionState($row, $outcome, $postIntro, $conversion);
        return array(
            'id' => $requestId,
            'reference' => (string) ($row->reference_code ?: 'Request ' . $requestId),
            'created_at' => (string) $row->created_at,
            'sort_at' => (string) (($outcome['recorded_at'] ?? null) ?: ($postIntro['updated_at'] ?? null) ?: $row->created_at),
            'age_seconds' => $age,
            'age_label' => $this->ageLabel($age),
            'deadline_at' => $deadline['utc'],
            'deadline_label' => $deadline['label'],
            'deadline_note' => $deadline['note'],
            'learner_name' => (string) ($row->full_name ?: 'Learner'),
            'email' => (string) ($row->email ?? ''),
            'phone' => (string) ($row->mobile ?? ''),
            'country' => (string) ($row->country ?? ''),
            'city' => (string) ($row->city ?? ''),
            'timezone' => (string) ($row->contact_timezone ?: 'UTC'),
            'instrument' => (string) ($row->instrument_name ?: 'Instrument'),
            'course' => (string) ($row->course_name ?: 'Introductory course'),
            'requested_times' => $times,
            'held_slot' => $held,
            'stage_key' => $stage['key'],
            'stage_label' => $stage['label'],
            'next_action' => $stage['next_action'],
            'teacher_id' => (int) $coordination['teacher_id'],
            'teacher_name' => $teacher !== '' ? $teacher : 'Teacher not selected',
            'teacher_response' => $teacherResponse !== '' ? $teacherResponse : 'No response recorded',
            'match_attempts' => max((int) $coordination['candidate_count'], $confirmation !== null ? 1 : 0),
            'availability_state' => $confirmation !== null ? 'Confirmed; provider projection state is shown below' : $coordination['availability_state'],
            'identity_state' => $this->identityState($row),
            'prior_booking_count' => (int) ($row->student_id ?? 0) > 0 ? $this->priorBookingCount((int) $row->student_id, $requestId) : null,
            'notification_summary' => $notifications['summary'],
            'notification_rows' => $notifications['rows'],
            'urgency_rank' => $urgency['rank'],
            'urgency_label' => $urgency['label'],
            'confirmation' => $confirmation,
            'outcome' => $outcome,
            'post_intro' => $postIntro,
            'coordination' => $coordination,
            'conversion' => $conversion,
            'completion' => $completion,
            'history' => $history,
            'is_active' => !$history,
        );
    }

    /** @return array{utc:?string,label:string,note:string} */
    private function bookingDeadline(object $row, array $times, array $postIntro, ?array $held): array {
        $now = gmdate('Y-m-d H:i:s');
        if ($held && ($held['expires_at'] ?? '') !== '' && ($held['capacity_effective'] ?? false)) {
            $zone = $this->safeTimezone((string) ($held['schedule_timezone'] ?? 'UTC'));
            return array(
                'utc' => (string) $held['expires_at'],
                'label' => $this->localLabel((string) $held['expires_at'], $zone),
                'note' => 'Earlier of the first regular slot and six days after the introductory lesson ends. Reservation ' . (string) $held['state'] . '.',
            );
        }
        if ($postIntro && in_array((string) ($postIntro['state'] ?? ''), array('prepared', 'checkout_pending', 'payment_received'), true)) {
            $expiry = (string) ($postIntro['reservation_expires_at'] ?? '');
            if ($expiry !== '') {
                return array(
                    'utc' => $expiry,
                    'label' => $this->localLabel($expiry, $this->safeTimezone((string) ($postIntro['schedule_timezone'] ?? $row->contact_timezone ?? 'UTC'))),
                    'note' => 'Follow-up deadline for the continuation decision and verified payment.',
                );
            }
        }
        if ($times) {
            $first = $times[0];
            $expired = (string) ($first['starts_at_utc'] ?? '') < $now;
            return array(
                'utc' => (string) ($first['starts_at_utc'] ?? ''),
                'label' => $this->localLabel((string) ($first['starts_at_utc'] ?? ''), $this->safeTimezone((string) ($first['timezone'] ?? $row->contact_timezone ?? 'UTC'))),
                'note' => $expired ? 'The earliest requested time has passed; confirm a new slot or close the request.' : 'Before the earliest requested introductory time.',
            );
        }
        return array('utc' => null, 'label' => 'No requested time recorded', 'note' => 'Review the request before contacting the learner.');
    }

    /** @return array{key:string,label:string,next_action:string} */
    private function bookingStage(object $row, array $times, array $coordination, ?array $confirmation, ?array $outcome, array $postIntro): array {
        $identity = $this->identityState($row);
        if ((string) $row->privacy_erased_at !== '' && $row->privacy_erased_at !== null) {
            return array('key' => 'archived', 'label' => 'Privacy-erased', 'next_action' => 'Archived history only');
        }
        if ($outcome !== null && (string) $outcome['outcome'] !== 'delivered') {
            return array('key' => 'closed', 'label' => 'Intro not delivered', 'next_action' => 'Review the outcome and close or rebook');
        }
        if ($postIntro && (string) ($postIntro['state'] ?? '') === 'activated') {
            return array('key' => 'converted', 'label' => 'Converted', 'next_action' => 'Open the enrolled class');
        }
        if ($postIntro && (string) ($postIntro['state'] ?? '') === 'expired') {
            return array('key' => 'expired', 'label' => 'Continuation expired', 'next_action' => 'Review whether a new arrangement is wanted');
        }
        if ($postIntro && in_array((string) ($postIntro['state'] ?? ''), array('prepared', 'checkout_pending', 'payment_received'), true)) {
            return array('key' => 'post_intro', 'label' => 'Decision & payment', 'next_action' => 'Confirm payment evidence or send a permitted reminder');
        }
        if ($outcome !== null) {
            return array('key' => 'post_intro', 'label' => 'Preparing continuation', 'next_action' => 'Review the post-intro exception');
        }
        if ($confirmation !== null && (string) ($confirmation['state'] ?? '') === 'cancelled') {
            return array('key' => 'closed', 'label' => 'Intro cancelled', 'next_action' => 'No further action; retraction and notice are recorded');
        }
        if ($confirmation !== null && (string) ($confirmation['state'] ?? '') === 'confirmed') {
            $ended = (string) ($confirmation['ends_at_utc'] ?? '') < gmdate('Y-m-d H:i:s');
            return array('key' => $ended ? 'intro_outcome' : 'intro_scheduled', 'label' => $ended ? 'Outcome due' : 'Trial scheduled', 'next_action' => $ended ? 'Record what happened' : 'Prepare for the introductory lesson');
        }
        if ((string) ($coordination['state'] ?? '') !== '' && !empty($coordination['candidate_count'])) {
            if (!empty($coordination['has_recorded_assent'])) {
                return array('key' => 'teacher_agreed', 'label' => 'Teacher agreed', 'next_action' => 'Confirm the trial booking');
            }
            return array('key' => 'teacher_match', 'label' => 'Teacher matching', 'next_action' => 'Record the Teacher’s agreement');
        }
        if ($identity['key'] !== 'verified') {
            return array('key' => 'identity', 'label' => 'Identity needed', 'next_action' => 'Resolve identity or record an identity blocker');
        }
        return array('key' => 'new', 'label' => 'New request', 'next_action' => 'Assess coverage and propose a Teacher');
    }

    /** @return array{rank:int,label:string} */
    private function bookingUrgency(object $row, array $stage, array $times, array $deadline, ?array $outcome, array $postIntro): array {
        $now = time();
        if (in_array($stage['key'], array('intro_outcome', 'post_intro'), true)) {
            $deadlineTs = $deadline['utc'] ? strtotime($deadline['utc'] . ' UTC') : null;
            if ($deadlineTs !== null && $deadlineTs - $now < 172800) return array('rank' => 1, 'label' => 'Deadline within 48 hours');
            return array('rank' => 2, 'label' => 'Action needed');
        }
        if ($stage['key'] === 'intro_scheduled') {
            $start = strtotime((string) ($times[0]['starts_at_utc'] ?? '') . ' UTC');
            if ($start && $start - $now < 86400) return array('rank' => 1, 'label' => 'Trial within 24 hours');
            return array('rank' => 3, 'label' => 'Trial scheduled');
        }
        $age = $now - strtotime((string) $row->created_at . ' UTC');
        if ($stage['key'] === 'new' && $age > 172800) return array('rank' => 1, 'label' => 'Unanswered for over 2 days');
        if ($stage['key'] === 'identity') return array('rank' => 2, 'label' => 'Identity blocker');
        $firstStart = strtotime((string) ($times[0]['starts_at_utc'] ?? '') . ' UTC');
        if ($firstStart && $firstStart - $now < 172800) return array('rank' => 1, 'label' => 'Requested time within 48 hours');
        return array('rank' => 4, 'label' => 'In progress');
    }

    /** @return array<int,array<string,mixed>> */
    private function requestedTimes(int $requestId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,sequence_number,local_date,local_start_time,timezone,starts_at_utc,instructional_ends_at_utc,occupied_ends_at_utc,instructional_duration_minutes,buffer_minutes
             FROM {$this->p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number ASC",
            $requestId
        )) ?: array();
        return array_map(function (object $row): array {
            $zone = $this->safeTimezone((string) $row->timezone);
            return array(
                'id' => (int) ($row->id ?? 0),
                'sequence' => (int) $row->sequence_number,
                'local_date' => (string) $row->local_date,
                'local_time' => substr((string) $row->local_start_time, 0, 5),
                'timezone' => $zone,
                'starts_at_utc' => (string) $row->starts_at_utc,
                'ends_at_utc' => (string) $row->instructional_ends_at_utc,
                'occupied_ends_at_utc' => (string) $row->occupied_ends_at_utc,
                'duration_minutes' => (int) $row->instructional_duration_minutes,
                'buffer_minutes' => (int) $row->buffer_minutes,
                'label' => $this->localLabel((string) $row->starts_at_utc, $zone),
            );
        }, $rows);
    }

    private function identityState(object $row): array {
        $resolved = (int) ($row->student_id ?? 0) > 0;
        $resolution = (string) ($row->resolution_state ?? '');
        if ($resolved) return array('key' => 'verified', 'label' => 'Verified Student #' . (int) $row->student_id);
        if ($resolution === 'ambiguous') return array('key' => 'ambiguous', 'label' => 'Ambiguous identity');
        if ($resolution === 'rejected') return array('key' => 'rejected', 'label' => 'Identity rejected');
        return array('key' => 'unresolved', 'label' => 'Unresolved');
    }

    /** @return array<string,mixed> */
    private function coordinationSummary(int $requestId): array {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}coordination_cases WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
            $requestId
        ));
        if (!$case) return array('case_id' => 0, 'state' => '', 'candidate_count' => 0, 'teacher_id' => 0, 'teacher_name' => '', 'teacher_response' => '', 'availability_state' => 'Not assessed', 'has_recorded_assent' => false);
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}coordination_case_candidates c LEFT JOIN {$this->p}teachers t ON t.id=c.teacher_id
             WHERE c.coordination_case_id=%d ORDER BY c.id DESC",
            (int) $case->id
        )) ?: array();
        $latest = $candidates[0] ?? null;
        $assent = $latest ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}teacher_availability_assents WHERE candidate_id=%d AND state='recorded' ORDER BY id DESC LIMIT 1",
            (int) $latest->id
        )) : null;
        $proposal = $latest ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}proposal_options WHERE candidate_id=%d ORDER BY id DESC LIMIT 1",
            (int) $latest->id
        )) : null;
        $response = $latest ? $this->teacherResponseLabel((string) $latest->status, $assent ? 'Assent recorded' : '', $proposal ? 'issued' : '') : '';
        return array(
            'case_id' => (int) $case->id,
            'reference' => (string) ($case->reference_code ?? ''),
            'state' => (string) $case->state,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'teacher_id' => $latest ? (int) $latest->teacher_id : 0,
            'teacher_name' => $latest ? (string) $latest->teacher_name : '',
            'teacher_response' => $response,
            'availability_state' => $assent ? 'Teacher agreement recorded' : ($latest ? 'Agreement not recorded' : 'No Teacher proposed'),
            'has_recorded_assent' => (bool) $assent,
            'assent' => $assent,
            'proposal' => $proposal,
        );
    }

    private function teacherResponseLabel(string $candidateStatus, string $assent, string $proposal): string {
        if ($proposal !== '') return 'Proposal ' . str_replace('_', ' ', $proposal);
        if ($assent !== '') return $assent;
        return match ($candidateStatus) {
            'under_discussion' => 'Under discussion',
            'not_available' => 'Teacher unavailable',
            'not_suitable' => 'Not suitable',
            'withdrawn_from_consideration' => 'Withdrawn',
            'closed' => 'Closed',
            default => $candidateStatus === '' ? 'No response recorded' : ucfirst(str_replace('_', ' ', $candidateStatus)),
        };
    }

    /** @return array<string,mixed>|null */
    private function confirmedBooking(int $requestId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,
                    COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name
             FROM {$this->p}booking_request_confirmations c
             LEFT JOIN {$this->p}teachers t ON t.id=c.teacher_id
             LEFT JOIN {$this->p}booking_requests r ON r.id=c.booking_request_id
             LEFT JOIN {$this->p}courses course ON course.id=r.selected_intro_course_id
             WHERE c.booking_request_id=%d ORDER BY c.id DESC LIMIT 1",
            $requestId
        ));
        if (!$row) return null;
        return array(
            'confirmation_id' => (int) $row->id,
            'confirmation_uid' => (string) $row->uid,
            'version' => (int) $row->version,
            'booking_request_id' => (int) $row->booking_request_id,
            'requested_time_id' => (int) $row->requested_time_id,
            'state' => (string) $row->state,
            'state_label' => $this->confirmationStateLabel((string) $row->state),
            'teacher_id' => (int) $row->teacher_id,
            'teacher_name' => (string) $row->teacher_name,
            'course_name' => (string) $row->course_name,
            'starts_at_utc' => (string) $row->starts_at_utc,
            'ends_at_utc' => (string) $row->ends_at_utc,
            'schedule_timezone' => (string) $row->schedule_timezone,
            'local_date' => (string) $row->local_wall_date,
            'local_time' => substr((string) $row->local_wall_time, 0, 5),
            'label' => $this->localLabel((string) $row->starts_at_utc, $this->safeTimezone((string) $row->schedule_timezone)),
            'provider_code' => (string) $row->provider_code,
            'confirmed_at' => $row->confirmed_at,
            'failure_reason_code' => $row->failure_reason_code,
            'attempt_count' => (int) $row->attempt_count,
        );
    }

    private function confirmationStateLabel(string $state): string {
        return match ($state) {
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'pending_projection' => 'Calendar projection pending',
            'awaiting_conference' => 'Meet space pending',
            'awaiting_payment' => 'Introductory payment pending',
            'retryable' => 'Projection retryable',
            'failed' => 'Confirmation failed',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    /** @return array<string,mixed>|null */
    private function introOutcome(int $requestId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}booking_request_intro_outcomes WHERE booking_request_id=%d",
            $requestId
        ));
        if (!$row) return null;
        return array(
            'outcome' => (string) $row->outcome,
            'label' => match ((string) $row->outcome) {
                'delivered' => 'Delivered',
                'student_no_show' => 'Student no-show',
                'teacher_cancelled' => 'Teacher cancelled',
                'student_cancelled' => 'Student cancelled',
                default => ucfirst(str_replace('_', ' ', (string) $row->outcome)),
            },
            'note' => (string) ($row->note ?? ''),
            'recorded_at' => (string) $row->recorded_at,
            'source' => (string) $row->source,
            'recorded_by' => (int) $row->recorded_by,
        );
    }

    /** @return array<string,mixed> */
    private function postIntro(int $requestId): array {
        global $wpdb;
        $prep = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}post_intro_preparations WHERE booking_request_id=%d",
            $requestId
        ));
        if (!$prep) return array('state' => '', 'state_label' => 'No post-intro preparation', 'reservation_expires_at' => null);
        $reservation = null;
        $slot = null;
        if ((int) ($prep->continuation_case_id ?? 0) > 0) {
            $reservation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->p}canonical_continuation_reservations WHERE continuation_case_id=%d",
                (int) $prep->continuation_case_id
            ));
        }
        if ((int) ($prep->slot_authority_id ?? 0) > 0) {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->p}canonical_continuation_slot_authorities WHERE id=%d",
                (int) $prep->slot_authority_id
            ));
        }
        $checkout = null;
        if ((int) ($prep->offer_id ?? 0) > 0) {
            $obligation = $wpdb->get_row($wpdb->prepare(
                "SELECT o.id FROM {$this->p}commercial_offer_obligations o WHERE o.offer_id=%d ORDER BY o.obligation_sequence LIMIT 1",
                (int) $prep->offer_id
            ));
            if ($obligation) {
                $checkout = $wpdb->get_row($wpdb->prepare(
                    "SELECT uid,state,provider_key,expires_at,closed_at,close_reason FROM {$this->p}intro_checkout_sessions WHERE intro_obligation_id=(SELECT id FROM {$this->p}intro_payment_obligations WHERE booking_request_id=%d LIMIT 1) AND active_slot=1 LIMIT 1",
                    $requestId
                )) ?: $wpdb->get_row($wpdb->prepare(
                    "SELECT uid,state,provider_key,expires_at,closed_at,close_reason FROM {$this->p}checkout_sessions WHERE obligation_id=%d ORDER BY id DESC LIMIT 1",
                    (int) $obligation->id
                ));
            }
        }
        $purchase = null;
        $offer = null;
        if ((int) ($prep->offer_id ?? 0) > 0) {
            $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_offers WHERE id=%d", (int) $prep->offer_id));
            $purchase = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_purchases WHERE offer_id=%d", (int) $prep->offer_id));
        }
        $enrolment = (int) ($prep->enrolment_id ?? 0) > 0 ? $wpdb->get_row($wpdb->prepare("SELECT id,reference_code,lifecycle_state,status FROM {$this->p}enrolments WHERE id=%d", (int) $prep->enrolment_id)) : null;
        return array(
            'preparation_id' => (int) $prep->id,
            'preparation_uid' => (string) $prep->uid,
            'state' => (string) $prep->state,
            'state_label' => match ((string) $prep->state) {
                'prepared' => 'Ready for student decision',
                'checkout_pending' => 'Payment link open',
                'payment_received' => 'Payment received',
                'activated' => 'Enrolment activated',
                'expired' => 'Continuation expired',
                'exception' => 'Exception — review',
                default => ucfirst(str_replace('_', ' ', (string) $prep->state)),
            },
            'exception_code' => (string) ($prep->exception_code ?? ''),
            'exception_detail' => (string) ($prep->exception_detail ?? ''),
            'reservation_expires_at' => $reservation ? (string) $reservation->expires_at : null,
            'reservation_state' => $reservation ? (string) $reservation->state : '',
            'reservation_capacity_effective' => $reservation && CanonicalContinuationRule::capacityEffective((string) $reservation->state, (string) $reservation->expires_at, gmdate('Y-m-d H:i:s')),
            'schedule_timezone' => (string) ($reservation->schedule_timezone ?? $slot->schedule_timezone ?? ''),
            'local_date' => (string) ($reservation->local_wall_date ?? $slot->local_wall_date ?? ''),
            'local_time' => substr((string) ($reservation->local_wall_time ?? $slot->local_wall_time ?? ''), 0, 5),
            'offer_id' => (int) ($prep->offer_id ?? 0),
            'offer_state' => $offer ? (string) $offer->state : '',
            'purchase_id' => $purchase ? (int) $purchase->id : 0,
            'purchase_state' => $purchase ? (string) $purchase->state : '',
            'checkout_state' => $checkout ? (string) $checkout->state : '',
            'checkout_expires_at' => $checkout ? (string) ($checkout->expires_at ?? '') : '',
            'checkout_provider' => $checkout ? (string) ($checkout->provider_key ?? '') : '',
            'payment_verified' => $purchase !== null,
            'enrolment_id' => $enrolment ? (int) $enrolment->id : 0,
            'enrolment_reference' => $enrolment ? (string) ($enrolment->reference_code ?? '') : '',
            'enrolment_state' => $enrolment ? (string) ($enrolment->lifecycle_state ?? '') : '',
            'updated_at' => (string) $prep->updated_at,
            'student_action_at' => $prep->student_action_at,
            'teacher_notified_at' => $prep->teacher_notified_at,
            'slot_authority_id' => (int) ($prep->slot_authority_id ?? 0),
            'intro_lesson_id' => (int) ($prep->intro_lesson_id ?? 0),
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function bookingTimeline(int $requestId): array {
        $events = array();
        $case = $this->coordinationCase($requestId);
        if ($case) {
            foreach ($case['candidates'] as $candidate) {
                $events[] = array('at' => (string) ($candidate->created_at ?? ''), 'label' => 'Teacher candidate: ' . (string) ($candidate->teacher_name ?? 'Teacher'), 'detail' => (string) $candidate->status);
            }
        }
        $events[] = array('at' => (string) ($this->bookingRow($requestId)->created_at ?? ''), 'label' => 'Request received', 'detail' => 'Public booking intake');
        foreach ($this->identityEvents($requestId) as $event) {
            $events[] = array('at' => (string) ($event->verified_at ?? ''), 'label' => 'Identity ' . (string) $event->outcome, 'detail' => (string) $event->verification_basis);
        }
        $confirmation = $this->confirmedBooking($requestId);
        if ($confirmation) $events[] = array('at' => (string) ($confirmation['confirmed_at'] ?? ''), 'label' => 'Introductory booking ' . $confirmation['state_label'], 'detail' => $confirmation['label']);
        $outcome = $this->introOutcome($requestId);
        if ($outcome) $events[] = array('at' => (string) $outcome['recorded_at'], 'label' => 'Intro outcome: ' . $outcome['label'], 'detail' => $outcome['note']);
        $prep = $this->postIntro($requestId);
        if (($prep['state'] ?? '') !== '') $events[] = array('at' => (string) ($prep['updated_at'] ?? ''), 'label' => 'Post-intro: ' . $prep['state_label'], 'detail' => (string) ($prep['exception_detail'] ?? ''));
        if (!empty($prep['enrolment_id'])) $events[] = array('at' => (string) ($prep['updated_at'] ?? ''), 'label' => 'Enrolment activated', 'detail' => (string) ($prep['enrolment_reference'] ?? ''));
        foreach ($this->bookingNotifications($requestId)['rows'] as $notification) {
            $events[] = array('at' => (string) ($notification['at'] ?? ''), 'label' => 'Notification: ' . $notification['label'], 'detail' => $notification['detail']);
        }
        usort($events, static fn(array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));
        return $events;
    }

    /** @return array<int,object> */
    private function identityEvents(int $requestId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}booking_request_identity_resolution_events WHERE booking_request_id=%d ORDER BY resolution_sequence ASC",
            $requestId
        )) ?: array();
    }

    /** @return array<string,mixed>|null */
    private function coordinationCase(int $requestId): ?array {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}coordination_cases WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
            $requestId
        ));
        if (!$case) return null;
        $summary = $this->coordinationSummary($requestId);
        $summary['case'] = $case;
        $summary['assents'] = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}teacher_availability_assents a LEFT JOIN {$this->p}teachers t ON t.id=a.teacher_id
             WHERE a.coordination_case_id=%d ORDER BY a.id DESC LIMIT 20",
            (int) $case->id
        )) ?: array();
        $summary['proposals'] = $wpdb->get_results($wpdb->prepare(
            "SELECT v.*,o.id option_id,o.teacher_id
             FROM {$this->p}proposal_versions v
             INNER JOIN {$this->p}proposal_options o ON o.id=v.proposal_option_id
             WHERE o.proposal_family_id IN (SELECT id FROM {$this->p}proposal_families WHERE coordination_case_id=%d)
             ORDER BY v.id DESC LIMIT 20",
            (int) $case->id
        )) ?: array();
        return $summary;
    }

    /** @return array<int,array<string,mixed>> */
    private function bookingNotifications(int $requestId): array {
        $status = (new NotificationDeliveryStatusReadService())->forBookingRequest($requestId);
        $rows = array();
        foreach ((array) $status['rows'] as $row) {
            $category = (string) ($row['category'] ?? 'unknown');
            $rows[] = array(
                'label' => $this->notificationLabel($category),
                'category' => $category,
                'at' => (string) ($row['updated_at'] ?? ''),
                'detail' => $this->notificationDetail($category, $row),
            );
        }
        $post = $this->postIntroNotifications($requestId);
        foreach ($post as $row) {
            $category = $this->notificationCategory((string) $row->state, $row->handed_off_at);
            $status['summary'][$category] = (int) ($status['summary'][$category] ?? 0) + 1;
            $rows[] = array(
                'label' => $this->notificationLabel($category) . ' · ' . $this->postIntroNotificationKind((string) $row->notification_kind),
                'category' => $category,
                'at' => (string) $row->updated_at,
                'detail' => $this->notificationDetail($category, array('state' => (string) $row->state, 'attempt_count' => (int) $row->attempt_count, 'last_error_code' => $row->last_error_code)),
            );
        }
        return array('summary' => $status['summary'] + array('unknown' => 0), 'rows' => $rows);
    }

    /** @return array<int,object> */
    private function postIntroNotifications(int $requestId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT n.* FROM {$this->p}booking_request_student_notifications n
             INNER JOIN {$this->p}booking_request_teacher_deliveries d ON d.id=n.delivery_id
             WHERE d.booking_request_id=%d AND n.notification_kind IN ('post_intro_invitation','post_intro_reminder')
             ORDER BY n.id DESC LIMIT 20",
            $requestId
        )) ?: array();
    }

    private function notificationCategory(string $state, ?string $handedOffAt): string {
        return match ($state) {
            'queued', 'retryable', 'sending' => 'queued',
            'handed_off' => 'handed_off',
            'delivered' => 'delivered',
            'failed' => 'failed',
            default => 'unknown',
        };
    }

    private function notificationLabel(string $category): string {
        return match ($category) {
            'queued' => 'Queued',
            'handed_off', 'sent' => 'Sent to transport (not receipt)',
            'delivery_confirmed', 'delivered' => 'Delivered — confirmed by provider',
            'failed' => 'Failed',
            'portal_recorded' => 'Portal notification recorded',
            'unknown' => 'Unknown',
            default => 'Recorded',
        };
    }

    private function notificationDetail(string $category, array $row): string {
        if ($category === 'failed') return 'Failure: ' . (string) ($row['last_error_code'] ?? 'transport or recipient unavailable');
        if ($category === 'sent' || $category === 'handed_off') return 'The mail transport accepted the hand-off; recipient receipt is not confirmed.';
        if ($category === 'queued') return 'Waiting for the notification worker.';
        return 'Evidence source: ' . (string) ($row['state'] ?? $category);
    }

    private function postIntroNotificationKind(string $kind): string {
        return $kind === 'post_intro_reminder' ? 'Payment reminder' : 'Enrolment / payment invitation';
    }

    /** @return array<int,array<string,mixed>> */
    private function priorBookings(int $studentId, int $excludeRequestId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,reference_code,lifecycle_status,resolution_state,created_at FROM {$this->p}booking_requests
             WHERE student_id=%d AND id<>%d AND privacy_erased_at IS NULL ORDER BY created_at DESC LIMIT 10",
            $studentId, $excludeRequestId
        )) ?: array();
        return array_map(static fn(object $row): array => array(
            'id' => (int) $row->id,
            'reference' => (string) ($row->reference_code ?: 'Request ' . (int) $row->id),
            'state' => (string) $row->lifecycle_status . ' / ' . (string) $row->resolution_state,
            'created_at' => (string) $row->created_at,
        ), $rows);
    }

    private function priorBookingCount(int $studentId, int $excludeRequestId): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}booking_requests WHERE student_id=%d AND id<>%d AND privacy_erased_at IS NULL",
            $studentId, $excludeRequestId
        ));
    }

    private function heldSlot(int $requestId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT res.* FROM {$this->p}canonical_continuation_reservations res
             INNER JOIN {$this->p}canonical_continuation_cases c ON c.id=res.continuation_case_id
             INNER JOIN {$this->p}post_intro_preparations p ON p.continuation_case_id=c.id
             WHERE p.booking_request_id=%d ORDER BY res.id DESC LIMIT 1",
            $requestId
        ));
        if (!$row) return null;
        return array(
            'reservation_id' => (int) $row->id,
            'state' => (string) $row->state,
            'expires_at' => (string) $row->expires_at,
            'schedule_timezone' => (string) $row->schedule_timezone,
            'capacity_effective' => CanonicalContinuationRule::capacityEffective((string) $row->state, (string) $row->expires_at, gmdate('Y-m-d H:i:s')),
        );
    }

    /** @return array<string,mixed> */
    private function conversionClassification(object $row): array {
        $state = (string) ($row->preparation_state ?? '');
        $outcome = (string) ($row->outcome_code ?? '');
        $converted = (int) ($row->converted_enrolment_id ?? 0) > 0 || (int) ($row->enrolment_id ?? 0) > 0;
        if ($outcome !== '' && $outcome !== 'delivered') {
            return array('bucket' => 'excluded', 'label' => 'Excluded — ' . str_replace('_', ' ', $outcome), 'reason' => 'The introductory lesson was not delivered.');
        }
        if ($converted) {
            return array('bucket' => 'converted', 'label' => 'Converted', 'reason' => 'Canonical enrolment exists and the continuation was activated.');
        }
        if (in_array($outcome, array('', 'delivered'), true) && $state === '') {
            if ((string) $row->lifecycle_status !== 'submitted') {
                return array('bucket' => 'did_not_convert', 'label' => 'Did not convert', 'reason' => 'Request closed without a delivered introductory lesson and verified enrolment.');
            }
            return array('bucket' => 'awaiting', 'label' => 'Awaiting delivery', 'reason' => 'Trial not delivered yet; counted in the open decision period.');
        }
        if (in_array($state, array('prepared', 'checkout_pending', 'payment_received', 'pending', 'preparing'), true)) {
            return array('bucket' => 'awaiting', 'label' => 'Awaiting decision or payment', 'reason' => 'The continuation hold or decision period is still open.');
        }
        if ($state === 'expired') {
            return array('bucket' => 'did_not_convert', 'label' => 'Did not convert', 'reason' => 'The continuation expired without verified payment or enrolment.');
        }
        if ($state === 'exception') {
            return array('bucket' => 'excluded', 'label' => 'Excluded — unresolved evidence', 'reason' => 'Post-intro preparation failed and requires review.');
        }
        return array('bucket' => 'awaiting', 'label' => 'Awaiting decision', 'reason' => 'Outcome recorded; continuation is not yet resolved.');
    }

    /** @return array{label:string,key:string} */
    private function completionState(object $row, ?array $outcome, array $postIntro, array $conversion): array {
        if (($conversion['bucket'] ?? '') === 'converted') return array('key' => 'converted', 'label' => 'Converted to enrolled classes');
        if (($postIntro['state'] ?? '') === 'expired') return array('key' => 'expired', 'label' => 'Continuation expired');
        if (($outcome['outcome'] ?? '') !== '' && ($outcome['outcome'] ?? '') !== 'delivered') return array('key' => 'closed', 'label' => 'Trial finished — ' . ($outcome['label'] ?? 'not delivered'));
        if ((string) $row->lifecycle_status !== 'submitted') return array('key' => 'closed', 'label' => 'Request closed');
        return array('key' => 'open', 'label' => 'Active case');
    }

    /** @return array<int,array<string,mixed>> */
    private function canonicalClassRows(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.uid,l.reference_code,l.lesson_type,l.lifecycle_state,l.status,l.sequence_number,l.canonical_sequence,
                    l.student_id,l.teacher_id,l.course_id,l.enrolment_id,l.term_id,l.teacher_assignment_id,l.record_model,
                    s.display_name student_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    e.reference_code enrolment_reference,e.lifecycle_state enrolment_state,
                    term.reference_code term_reference,term.lifecycle_state term_state,term.sequence_number term_sequence,
                    term.lesson_allocation,term.replacement_allowance,
                    ta.state assignment_state,
                    sv.id schedule_version_id,sv.starts_at_utc,sv.ends_at_utc,sv.schedule_timezone,sv.local_wall_date,sv.local_wall_time,sv.duration_minutes,sv.buffer_minutes,
                    o.outcome_code,o.delivery_state,o.attendance_state,o.remedy_class,
                    ac.id attendance_case_id,ac.state attendance_case_state,ac.case_version attendance_case_version
             FROM {$this->p}lessons l
             LEFT JOIN {$this->p}students s ON s.id=l.student_id
             LEFT JOIN {$this->p}teachers t ON t.id=l.teacher_id
             LEFT JOIN {$this->p}courses c ON c.id=l.course_id
             LEFT JOIN {$this->p}enrolments e ON e.id=l.enrolment_id
             LEFT JOIN {$this->p}terms term ON term.id=l.term_id
             LEFT JOIN {$this->p}teacher_assignments ta ON ta.id=l.teacher_assignment_id
             LEFT JOIN {$this->p}canonical_lesson_schedule_versions sv ON sv.lesson_id=l.id AND sv.applicable_slot=1
             LEFT JOIN {$this->p}canonical_lesson_delivery_outcomes o ON o.lesson_id=l.id AND o.applicable_slot=1
             LEFT JOIN {$this->p}canonical_attendance_cases ac ON ac.lesson_id=l.id AND ac.schedule_version_id=sv.id
             WHERE l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL
             ORDER BY COALESCE(sv.starts_at_utc,'9999-12-31 23:59:59') ASC,l.id DESC LIMIT %d",
            self::CLASS_LIMIT
        )) ?: array();
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function legacyClassRows(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.uid,l.reference_code,l.lesson_type,l.status,l.student_id,l.teacher_id,l.course_id,l.enrolment_id,l.term_id,
                    l.record_model,l.current_schedule_version_id,
                    s.display_name student_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    e.reference_code enrolment_reference,e.lifecycle_state enrolment_state,
                    term.reference_code term_reference,term.lifecycle_state term_state,term.sequence_number term_sequence,
                    term.lesson_allocation,term.replacement_allowance,
                    sv.id schedule_version_id,sv.starts_at_utc,sv.ends_at_utc,sv.schedule_timezone,sv.local_wall_date,sv.local_wall_time,
                    NULL duration_minutes,NULL buffer_minutes,
                    NULL assignment_state,NULL outcome_code,NULL delivery_state,NULL attendance_state,NULL remedy_class,
                    NULL attendance_case_id,NULL attendance_case_state,NULL attendance_case_version
             FROM {$this->p}lessons l
             LEFT JOIN {$this->p}students s ON s.id=l.student_id
             LEFT JOIN {$this->p}teachers t ON t.id=l.teacher_id
             LEFT JOIN {$this->p}courses c ON c.id=l.course_id
             LEFT JOIN {$this->p}enrolments e ON e.id=l.enrolment_id
             LEFT JOIN {$this->p}terms term ON term.id=l.term_id
             LEFT JOIN {$this->p}lesson_schedule_versions sv ON sv.id=l.current_schedule_version_id
             WHERE l.record_model='legacy_phase1' AND l.archived_at IS NULL
             ORDER BY COALESCE(sv.starts_at_utc,'9999-12-31 23:59:59') ASC,l.id DESC LIMIT %d",
            self::CLASS_LIMIT
        )) ?: array();
        return $rows;
    }

    private function classRow(int $lessonId): ?object {
        foreach (array_merge($this->canonicalClassRows(), $this->legacyClassRows()) as $row) {
            if ((int) $row->id === $lessonId) return $row;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function composeClassRow(object $row): array {
        $schedule = (string) $row->starts_at_utc;
        $zone = $this->safeTimezone((string) ($row->schedule_timezone ?: 'UTC'));
        $localDate = (string) ($row->local_wall_date ?? '');
        $today = $this->academyToday();
        $state = (string) ($row->lifecycle_state ?? $row->status ?? '');
        $isFinished = in_array($state, array('completed', 'cancelled', 'archived'), true);
        $isPast = $schedule !== '' && $schedule < gmdate('Y-m-d H:i:s');
        $bucket = ($isFinished || ($isPast && $localDate !== $today)) ? 'history' : ($localDate === $today ? 'today' : 'upcoming');
        $attendance = $this->attendanceState($row);
        $action = $this->classActionNeeded($row, $bucket, $attendance);
        return array(
            'id' => (int) $row->id,
            'reference' => (string) ($row->reference_code ?: 'Class ' . (int) $row->id),
            'record_model' => (string) $row->record_model,
            'lesson_type' => (string) $row->lesson_type,
            'state' => $state,
            'state_label' => $this->classStateLabel($state, (string) $row->record_model),
            'student_id' => (int) $row->student_id,
            'student_name' => (string) ($row->student_name ?: 'Student'),
            'teacher_id' => (int) $row->teacher_id,
            'teacher_name' => (string) ($row->teacher_name ?: 'Teacher'),
            'course_id' => (int) $row->course_id,
            'course_name' => (string) ($row->course_name ?: 'Course'),
            'enrolment_id' => (int) ($row->enrolment_id ?? 0),
            'enrolment_reference' => (string) ($row->enrolment_reference ?? ''),
            'enrolment_state' => (string) ($row->enrolment_state ?? ''),
            'term_id' => (int) ($row->term_id ?? 0),
            'term_reference' => (string) ($row->term_reference ?? ''),
            'term_state' => (string) ($row->term_state ?? ''),
            'term_sequence' => (int) ($row->term_sequence ?? 0),
            'lesson_allocation' => (int) ($row->lesson_allocation ?? 0),
            'replacement_allowance' => (int) ($row->replacement_allowance ?? 0),
            'assignment_id' => (int) ($row->teacher_assignment_id ?? 0),
            'assignment_state' => (string) ($row->assignment_state ?? ''),
            'schedule_version_id' => (int) ($row->schedule_version_id ?? 0),
            'starts_at_utc' => $schedule,
            'ends_at_utc' => (string) ($row->ends_at_utc ?? ''),
            'local_date' => $localDate,
            'local_date_short' => $localDate,
            'local_time' => substr((string) ($row->local_wall_time ?? ''), 0, 5),
            'timezone' => $zone,
            'local_label' => $schedule !== '' ? $this->localLabel($schedule, $zone) : 'Schedule not set',
            'duration_minutes' => (int) ($row->duration_minutes ?? 0),
            'buffer_minutes' => (int) ($row->buffer_minutes ?? 0),
            'bucket' => $bucket,
            'attendance' => $attendance,
            'action_needed' => $action,
            'term_progress' => $this->termProgress($row),
            'remaining_classes' => $this->remainingClasses($row),
            'replacement_used' => $this->replacementUsed((int) ($row->term_id ?? 0)),
            'is_replacement' => (string) $row->lesson_type === 'replacement',
        );
    }

    private function classActionNeeded(object $row, string $bucket, array $attendance): string {
        $state = (string) ($row->lifecycle_state ?? $row->status ?? '');
        if ($bucket === 'history') return '';
        if (in_array($state, array('cancelled', 'archived'), true)) return '';
        if ((string) ($row->schedule_version_id ?? '') === '' || (int) ($row->schedule_version_id ?? 0) < 1) return 'Schedule the class';
        if ((string) ($row->record_model ?? '') === 'canonical_term_lesson_v1' && (string) ($row->assignment_state ?? '') !== 'assigned') return 'Teacher Assignment needs review';
        if (in_array((string) ($row->attendance_case_state ?? ''), array('review_required', 'ready_for_review'), true)) return 'Attendance review';
        if ((string) ($row->attendance_case_state ?? '') === 'open' && (string) ($row->ends_at_utc ?? '') < gmdate('Y-m-d H:i:s')) return 'Attendance decision';
        if (($attendance['key'] ?? '') === 'missing' && (string) ($row->ends_at_utc ?? '') < gmdate('Y-m-d H:i:s')) return 'Attendance decision';
        return '';
    }

    /** @return array{key:string,label:string,detail:string} */
    private function attendanceState(object $row): array {
        $caseState = (string) ($row->attendance_case_state ?? '');
        $outcomeState = (string) ($row->attendance_state ?? '');
        if ($caseState === 'review_required') return array('key' => 'review', 'label' => 'Review required', 'detail' => 'Evidence conflict or missing identity needs a decision.');
        if ($outcomeState !== '') return array('key' => $outcomeState, 'label' => ucfirst(str_replace('_', ' ', $outcomeState)), 'detail' => 'From the canonical delivery outcome.');
        if ($caseState !== '') return array('key' => $caseState, 'label' => ucfirst(str_replace('_', ' ', $caseState)), 'detail' => 'Canonical attendance case.');
        if ((string) ($row->ends_at_utc ?? '') < gmdate('Y-m-d H:i:s')) return array('key' => 'missing', 'label' => 'No evidence', 'detail' => 'A clicked link or administrator presence is not attendance evidence.');
        return array('key' => 'pending', 'label' => 'Pending', 'detail' => 'Attendance is decided after the class ends.');
    }

    private function termProgress(object $row): string {
        $allocation = (int) ($row->lesson_allocation ?? 0);
        if ($allocation < 1) return 'Term not recorded';
        $completed = $this->completedStandardCount((int) ($row->term_id ?? 0));
        $completed = max($completed, $this->importCompletedCount((int) ($row->enrolment_id ?? 0)));
        return $completed . ' of ' . $allocation . ' complete';
    }

    private function remainingClasses(object $row): int {
        $allocation = (int) ($row->lesson_allocation ?? 0);
        if ($allocation < 1) return 0;
        $completed = max($this->completedStandardCount((int) ($row->term_id ?? 0)), $this->importCompletedCount((int) ($row->enrolment_id ?? 0)));
        return max(0, $allocation - $completed);
    }

    /** @param array<int,int> $enrolmentIds */
    private function primeImportCounts(array $enrolmentIds): void {
        $ids = array_values(array_filter(array_unique($enrolmentIds), fn(int $id): bool => $id > 0 && !array_key_exists($id, $this->importCompletedCache)));
        if (!$ids) return;
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT aggregate_id,safe_detail FROM {$this->p}platform_audit_events
             WHERE aggregate_type='enrolment' AND event_type='enrolment.historical_import' AND aggregate_id IN ({$placeholders})
             ORDER BY id ASC",
            ...$ids
        )) ?: array();
        foreach ($rows as $row) {
            $detail = json_decode((string) $row->safe_detail, true);
            if (is_array($detail)) $this->importCompletedCache[(int) $row->aggregate_id] = (int) ($detail['completed_classes'] ?? 0);
        }
        foreach ($ids as $id) if (!array_key_exists($id, $this->importCompletedCache)) $this->importCompletedCache[$id] = 0;
    }

    private function importCompletedCount(int $enrolmentId): int {
        if ($enrolmentId < 1) return 0;
        if (array_key_exists($enrolmentId, $this->importCompletedCache)) return $this->importCompletedCache[$enrolmentId];
        $evidence = $this->importEvidence($enrolmentId);
        return $this->importCompletedCache[$enrolmentId] = (int) ($evidence['completed_classes'] ?? 0);
    }

    private function completedStandardCount(int $termId): int {
        if ($termId < 1) return 0;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}lessons WHERE term_id=%d AND lesson_type='standard' AND lifecycle_state='completed' AND archived_at IS NULL",
            $termId
        ));
    }

    private function replacementUsed(int $termId): int {
        if ($termId < 1) return 0;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}lessons WHERE term_id=%d AND lesson_type='replacement' AND lifecycle_state<>'cancelled' AND archived_at IS NULL",
            $termId
        ));
    }

    private function classStateLabel(string $state, string $model): string {
        if ($model === 'legacy_phase1') {
            return match ($state) {
                'draft' => 'Draft',
                'scheduled' => 'Scheduled',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                'archived' => 'Archived',
                default => ucfirst(str_replace('_', ' ', $state)),
            };
        }
        return match ($state) {
            'authorised' => 'Scheduled',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'archived' => 'Archived',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function scheduleVersions(int $lessonId, string $model): array {
        global $wpdb;
        $table = $model === 'legacy_phase1' ? 'lesson_schedule_versions' : 'canonical_lesson_schedule_versions';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}{$table} WHERE lesson_id=%d ORDER BY version_number DESC LIMIT 20",
            $lessonId
        )) ?: array();
        return array_map(function (object $row): array {
            return array(
                'version' => (int) $row->version_number,
                'local_date' => (string) $row->local_wall_date,
                'local_time' => substr((string) $row->local_wall_time, 0, 5),
                'timezone' => (string) $row->schedule_timezone,
                'starts_at_utc' => (string) $row->starts_at_utc,
                'ends_at_utc' => (string) $row->ends_at_utc,
                'reason' => (string) ($row->reason_code ?? $row->reason ?? ''),
                'active' => $row->superseded_at === null,
            );
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function attendanceDetail(int $lessonId, int $scheduleVersionId): array {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}canonical_attendance_cases WHERE lesson_id=%d AND schedule_version_id=%d LIMIT 1",
            $lessonId, $scheduleVersionId
        ));
        if (!$case) return array('case' => null, 'evidence' => array(), 'decisions' => array(), 'anomalies' => array());
        $evidence = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}canonical_attendance_evidence WHERE case_id=%d ORDER BY id ASC LIMIT 50",
            (int) $case->id
        )) ?: array();
        $decisions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}canonical_attendance_decisions WHERE case_id=%d ORDER BY decision_sequence DESC LIMIT 20",
            (int) $case->id
        )) ?: array();
        $anomalies = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}canonical_attendance_case_anomalies WHERE case_id=%d ORDER BY id DESC LIMIT 20",
            (int) $case->id
        )) ?: array();
        return array(
            'case' => $case,
            'evidence' => array_map(static fn(object $e): array => array(
                'evidence_kind' => (string) $e->evidence_kind,
                'source_kind' => (string) $e->source_kind,
                'participant_role' => (string) ($e->participant_role ?? ''),
                'identity_state' => (string) ($e->participant_identity_state ?? ''),
                'verification_state' => (string) ($e->verification_state ?? ''),
                'join_at_utc' => $e->join_at_utc,
                'leave_at_utc' => $e->leave_at_utc,
                'observed_at' => (string) $e->observed_at,
                'reason_code' => (string) $e->reason_code,
                'attribution' => (string) ($e->attribution ?? ''),
                'missing' => (string) ($e->verification_state ?? '') === 'unverified' || $e->join_at_utc === null,
            ), $evidence),
            'decisions' => $decisions,
            'anomalies' => $anomalies,
        );
    }

    /** @return array<string,mixed> */
    private function deliveryDetail(int $lessonId): array {
        try {
            $history = (new CanonicalLessonDeliveryReadService())->forLesson($lessonId);
            return array(
                'effective' => $history['effective'] ?? null,
                'outcomes' => $history['outcomes'] ?? array(),
            );
        } catch (\Throwable) {
            return array('effective' => null, 'outcomes' => array());
        }
    }

    /** @return array<string,mixed>|null */
    private function termDetail(int $termId): ?array {
        if ($termId < 1) return null;
        global $wpdb;
        $term = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}terms WHERE id=%d", $termId));
        if (!$term) return null;
        $summaries = $wpdb->get_results($wpdb->prepare(
            "SELECT lesson_type,lifecycle_state,COUNT(*) total FROM {$this->p}lessons WHERE term_id=%d AND archived_at IS NULL GROUP BY lesson_type,lifecycle_state",
            $termId
        )) ?: array();
        return array('term' => $term, 'summary' => $summaries);
    }

    /** @return array<string,mixed>|null */
    public function importEvidence(int $enrolmentId): ?array {
        if ($enrolmentId < 1) return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT safe_detail,occurred_at FROM {$this->p}platform_audit_events WHERE aggregate_type='enrolment' AND aggregate_id=%d AND event_type='enrolment.historical_import' ORDER BY id DESC LIMIT 1",
            $enrolmentId
        ));
        if (!$row) return null;
        $detail = json_decode((string) $row->safe_detail, true);
        return is_array($detail) ? $detail + array('recorded_at' => (string) $row->occurred_at) : null;
    }

    /** @return array<int,object> */
    private function assignmentHistory(int $enrolmentId): array {
        if ($enrolmentId < 1) return array();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}teacher_assignments a LEFT JOIN {$this->p}teachers t ON t.id=a.teacher_id
             WHERE a.enrolment_id=%d ORDER BY a.assignment_sequence ASC",
            $enrolmentId
        )) ?: array();
    }

    private function bookingMatches(array $case, string $search): bool {
        $haystack = implode(' ', array(
            (string) $case['reference'], (string) $case['learner_name'], (string) $case['email'], (string) $case['phone'],
            (string) $case['instrument'], (string) $case['course'], (string) $case['teacher_name'], (string) $case['stage_label'],
        ));
        return stripos($haystack, $search) !== false;
    }

    private function classMatches(array $class, string $search): bool {
        $haystack = implode(' ', array(
            (string) $class['reference'], (string) $class['student_name'], (string) $class['teacher_name'],
            (string) $class['course_name'], (string) $class['local_label'], (string) $class['term_reference'],
        ));
        return stripos($haystack, $search) !== false;
    }

    private function isActiveBooking(object $row): bool {
        if ((string) ($row->privacy_erased_at ?? '') !== '' && $row->privacy_erased_at !== null) return false;
        if ((string) $row->lifecycle_status !== 'submitted') return false;
        return true;
    }

    private function safeTimezone(string $timezone): string {
        $timezone = trim($timezone);
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    private function localLabel(string $utc, string $timezone): string {
        if ($utc === '') return 'Not scheduled';
        try {
            $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            $local = $date->setTimezone(new \DateTimeZone($this->safeTimezone($timezone)));
            return $local->format('D j M Y, H:i') . ' ' . $this->safeTimezone($timezone);
        } catch (\Throwable) {
            return $utc . ' UTC';
        }
    }

    private function academyToday(): string {
        if (function_exists('wp_date')) return (string) wp_date('Y-m-d');
        return gmdate('Y-m-d');
    }

    private function utcBoundary(string $date, bool $exclusiveEnd): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) $date = gmdate('Y-m-d');
        try {
            $zone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $local = new \DateTimeImmutable($date . ' 00:00:00', $zone);
            if ($exclusiveEnd) $local = $local->modify('+1 day');
            return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $date . ' 00:00:00';
        }
    }

    private function ageLabel(int $seconds): string {
        if ($seconds < 3600) return max(1, (int) floor($seconds / 60)) . ' min';
        if ($seconds < 86400) return (int) floor($seconds / 3600) . ' h';
        return (int) floor($seconds / 86400) . ' days';
    }

    private function requireAny(array $capabilities): void {
        foreach ($capabilities as $capability) if (current_user_can($capability)) return;
        throw new \RuntimeException('Unauthorized');
    }
}
