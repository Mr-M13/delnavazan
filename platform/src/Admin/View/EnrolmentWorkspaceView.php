<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\EnrolmentWorkspaceController;
use Delnavazan\Platform\Admin\Controller\Menu;
use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;
use Delnavazan\Platform\Core\Application\EnrolmentWorkspaceReadService;
use Delnavazan\Platform\Core\Application\HistoricalEnrolmentImportService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStatusReadService;
use Delnavazan\Platform\Core\Application\PostIntroPreparationService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilitySummary;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;

/** Accessible, responsive presentation for the consolidated Enrolment workspace. */
final class EnrolmentWorkspaceView {
    public static function newBookings(EnrolmentWorkspaceReadService $read, array $query): void {
        $filters = self::bookingFilters($query);
        $active = $read->bookingCases($filters, false);
        $historyFilters = $filters;
        $historyFilters['q'] = sanitize_text_field((string) ($query['history_q'] ?? ''));
        $historyFilters['page'] = max(1, absint($query['history_page'] ?? 1));
        $history = $read->bookingCases($historyFilters, true);
        $report = $read->conversionReport(
            sanitize_text_field((string) ($query['report_from'] ?? gmdate('Y-m-d', strtotime('-30 days')))),
            sanitize_text_field((string) ($query['report_to'] ?? gmdate('Y-m-d')))
        );
        echo '<div class="wrap dzn-enrolment dzn-enrolment--trials">';
        self::tabs('new');
        self::notices();
        echo '<header class="dzn-enrolment__page-head"><div><h1>Intro</h1>';
        echo '<p>Incoming requests, Teacher matching and the introductory lesson in one place. Completed, cancelled and expired trials are kept in History and are never mixed into the action queue.</p></div>';
        echo '<div class="dzn-enrolment__hero-actions">';
        if (current_user_can('dzn_manage_booking_request_coordination')) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-booking-request-coordination')) . '">Coordination cases</a>';
        if (current_user_can('dzn_manage_booking_request_coordination') && current_user_can('dzn_manage_teacher_availability_assent')) echo '<a class="button button-primary" href="#dzn-create-intro">Create introductory lesson</a>';
        echo '</div></header>';
        self::reportPanel($report);
        if (current_user_can('dzn_manage_booking_request_coordination') && current_user_can('dzn_manage_teacher_availability_assent')) self::createIntroPanel($read);
        self::bookingFiltersForm($filters, false);
        echo '<section class="dzn-enrolment__section" aria-labelledby="dzn-action-needed"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Urgency ordered</p><h2 id="dzn-action-needed">Action needed</h2></div><span class="dzn-count-chip">' . esc_html(number_format_i18n((int) $active['total'])) . ' active ' . esc_html($active['total'] === 1 ? 'case' : 'cases') . '</span></div>';
        if (!$active['rows']) {
            echo '<div class="dzn-empty-state"><h3>No active booking or trial cases</h3><p>New requests appear here immediately. Completed cases remain searchable in History.</p></div>';
        } else {
            self::bookingTable($active['rows'], false);
            self::pagination($filters, $active, false, 'new');
        }
        echo '</section>';
        echo '<details class="dzn-history dzn-enrolment__section" id="dzn-booking-history"' . (!empty($query['history_q']) ? ' open' : '') . '><summary><span><strong>History</strong><br><small>Completed, cancelled, expired and archived cases — separate from the action queue.</small></span><span class="dzn-count-chip">' . esc_html(number_format_i18n((int) $history['total'])) . '</span></summary><div class="dzn-history__body">';
        echo '<form method="get" class="dzn-enrolment__filter-form"><input type="hidden" name="page" value="' . esc_attr(EnrolmentWorkspaceController::PAGE) . '"><input type="hidden" name="view" value="new">';
        echo '<label class="dzn-enrolment__search"><span>Search history</span><input type="search" name="history_q" value="' . esc_attr($historyFilters['q']) . '" placeholder="Learner, reference, contact, instrument or Teacher"></label><button class="button">Search history</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&view=new')) . '">Reset</a></form>';
        if (!$history['rows']) echo '<p>No closed booking cases match this search.</p>';
        else { self::bookingTable($history['rows'], true); self::pagination($historyFilters, $history, true, 'new'); }
        echo '</div></details></div>';
    }

    public static function review(EnrolmentWorkspaceReadService $read, array $query): void {
        $review = $read->reviewCases();
        echo '<div class="wrap dzn-enrolment dzn-enrolment--review">';
        self::tabs('review');
        self::notices();
        echo '<header class="dzn-enrolment__page-head"><div><h1>Review</h1>';
        echo '<p>Every confirmed introductory request that still needs attention. The table shows the delivery, payment-link, reminder and follow-up facts that already exist in the platform; it never invents attendance or payment evidence.</p></div></header>';
        echo '<div class="dzn-mini-stats dzn-review-stats"><span><strong>' . esc_html((string) $review['counts']['upcoming']) . '</strong> upcoming</span><span><strong>' . esc_html((string) $review['counts']['outcome_due']) . '</strong> outcome due</span><span><strong>' . esc_html((string) $review['counts']['payment_open']) . '</strong> payment link open</span><span><strong>' . esc_html((string) $review['counts']['follow_up_due']) . '</strong> follow-up due</span></div>';
        echo '<p class="description">' . ((int) $review['auto_removed'] > 0 ? esc_html(number_format_i18n((int) $review['auto_removed'])) . ' request(s) were removed from this active queue because the payment link was sent more than 14 days ago and no decision or payment was recorded. ' : '') . 'A request is removed from this active queue 14 days after the payment link is sent when no decision or payment was recorded. The underlying case remains available in History.</p>';
        echo '<section class="dzn-enrolment__section" aria-labelledby="dzn-review-queue"><div class="dzn-enrolment__section-head"><div><h2 id="dzn-review-queue">Active introductory requests</h2><p>The queue is based on the latest booking confirmation. Cancelled and auto-expired requests are not shown.</p></div><span class="dzn-count-chip">' . esc_html(number_format_i18n((int) $review['total'])) . ' active</span></div>';
        if (!$review['rows']) {
            echo '<div class="dzn-empty-state"><h3>No introductory requests need review</h3><p>New confirmed introductory bookings appear here immediately.</p></div>';
        } else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped dzn-review-table"><thead><tr><th>Date</th><th>Instrument</th><th>Teacher</th><th>Student</th><th>Status</th><th>Country</th><th>Attended</th><th>Payment link sent</th><th>Reminder</th><th>Follow-up completed</th></tr></thead><tbody>';
            foreach ($review['rows'] as $row) {
                $url = admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&request_id=' . (int) $row['id']);
                echo '<tr>';
                echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html((string) $row['date']) . '</strong></a><br><small>' . esc_html((string) $row['reference']) . '</small></td>';
                echo '<td>' . esc_html((string) $row['instrument']) . '<br><small>' . esc_html((string) $row['course']) . '</small></td>';
                echo '<td>' . esc_html((string) $row['teacher']) . '</td>';
                echo '<td><a href="' . esc_url($url) . '">' . esc_html((string) $row['student']) . '</a></td>';
                echo '<td><span class="dzn-badge dzn-badge--teal">' . esc_html((string) $row['status']) . '</span></td>';
                echo '<td>' . esc_html((string) $row['country']) . '</td>';
                echo '<td>' . esc_html((string) $row['attended']) . '</td>';
                echo '<td>' . esc_html((string) $row['payment_link_sent']) . '</td>';
                echo '<td>' . esc_html((string) $row['reminder']) . '</td>';
                echo '<td><span class="dzn-badge ' . ($row['follow_up_completed'] === 'Yes' ? 'dzn-badge--teal' : 'dzn-badge--soft') . '">' . esc_html((string) $row['follow_up_completed']) . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section></div>';
    }

    public static function bookingCase(array $case, ?array $classroom): void {
        echo '<div class="wrap dzn-enrolment dzn-enrolment--case">';
        self::notices();
        echo '<p class="dzn-back"><a href="' . esc_url(admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&view=new')) . '">← Back to Intro</a>';
        if (current_user_can('dzn_manage_booking_request_coordination')) echo ' · <a href="' . esc_url(admin_url('admin.php?page=dzn-booking-request-coordination')) . '">Coordination cases</a>';
        echo '</p>';
        echo '<header class="dzn-enrolment__case-head"><div><p class="dzn-eyebrow">' . esc_html((string) $case['reference']) . ' · ' . esc_html($case['identity_state']['label']) . '</p><h1>' . esc_html((string) $case['learner_name']) . '</h1><p>' . esc_html((string) $case['instrument']) . ' · ' . esc_html((string) $case['course']) . ' · ' . esc_html((string) $case['timezone']) . '</p></div><div class="dzn-enrolment__case-state"><span class="dzn-badge dzn-badge--teal">' . esc_html((string) $case['stage_label']) . '</span><span class="dzn-chip">' . esc_html((string) $case['urgency_label']) . '</span></div></header>';
        echo '<div class="dzn-case-grid">';
        self::caseFacts($case);
        self::caseDecision($case, $classroom);
        echo '</div>';
        echo '<div class="dzn-case-columns"><div>';
        self::caseActions($case);
        self::caseTimeline($case);
        echo '</div><aside>';
        self::caseNotifications($case);
        self::caseHistory($case);
        self::caseDiagnostics($case);
        echo '</aside></div></div>';
    }

    public static function enrolledClasses(EnrolmentWorkspaceReadService $read, array $query): void {
        $filters = self::classFilters($query);
        $active = $read->classes($filters, false);
        $historyFilters = $filters;
        $historyFilters['q'] = sanitize_text_field((string) ($query['history_q'] ?? ''));
        $historyFilters['page'] = max(1, absint($query['history_page'] ?? 1));
        $history = $read->classes($historyFilters, true);
        echo '<div class="wrap dzn-enrolment dzn-enrolment--classes">';
        self::tabs('enrolled');
        self::notices();
        echo '<header class="dzn-enrolment__page-head"><div><h1>Enrolled classes</h1>';
        echo '<p>Today and upcoming classes first, with Term progress, replacement allowance, attendance and the next required action. Completed and cancelled classes remain in History.</p></div><div class="dzn-enrolment__hero-actions">';
        if (current_user_can('dzn_manage_enrolments') && current_user_can('dzn_manage_terms') && current_user_can('dzn_manage_lessons')) echo '<a class="button" href="#dzn-import-enrolment">Import existing enrolment</a>';
        echo '</div></header>';
        if (current_user_can('dzn_manage_enrolments') && current_user_can('dzn_manage_terms') && current_user_can('dzn_manage_lessons')) self::importPanel($read, $query);
        self::classFiltersForm($filters, false);
        echo '<section class="dzn-enrolment__section"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Today and upcoming</p><h2>Classes</h2></div><span class="dzn-count-chip">' . esc_html(number_format_i18n((int) $active['total'])) . ' ' . esc_html($active['total'] === 1 ? 'class' : 'classes') . '</span></div>';
        echo '<div class="dzn-mini-stats"><span><strong>' . esc_html((string) $active['counts']['today']) . '</strong> today</span><span><strong>' . esc_html((string) $active['counts']['upcoming']) . '</strong> upcoming</span><span><strong>' . esc_html((string) $active['counts']['action']) . '</strong> need action</span></div>';
        if (!$active['rows']) echo '<div class="dzn-empty-state"><h3>No current classes</h3><p>Classes starting today or later appear here. Imported and completed records stay available in History.</p></div>';
        else { self::classTable($active['rows'], false); self::pagination($filters, $active, false, 'enrolled'); }
        echo '</section>';
        echo '<details class="dzn-history dzn-enrolment__section"' . (!empty($query['history_q']) ? ' open' : '') . '><summary><span><strong>Class history</strong><br><small>Completed and cancelled classes, with their attendance and financial history retained.</small></span><span class="dzn-count-chip">' . esc_html(number_format_i18n((int) $history['total'])) . '</span></summary><div class="dzn-history__body">';
        echo '<form method="get" class="dzn-enrolment__filter-form"><input type="hidden" name="page" value="' . esc_attr(EnrolmentWorkspaceController::PAGE) . '"><input type="hidden" name="view" value="enrolled"><label class="dzn-enrolment__search"><span>Search class history</span><input type="search" name="history_q" value="' . esc_attr($historyFilters['q']) . '" placeholder="Student, Teacher, Course or term"></label><button class="button">Search history</button></form>';
        if (!$history['rows']) echo '<p>No completed or cancelled classes match this search.</p>';
        else { self::classTable($history['rows'], true); self::pagination($historyFilters, $history, true, 'enrolled'); }
        echo '</div></details></div>';
    }

    public static function classDetail(array $class, ?array $classroom): void {
        echo '<div class="wrap dzn-enrolment dzn-enrolment--class">';
        self::notices();
        echo '<p class="dzn-back"><a href="' . esc_url(admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&view=enrolled')) . '">← Back to Enrolled classes</a></p>';
        echo '<header class="dzn-enrolment__case-head"><div><p class="dzn-eyebrow">' . esc_html((string) $class['reference']) . ' · ' . esc_html((string) $class['state_label']) . '</p><h1>' . esc_html((string) $class['student_name']) . '</h1><p>' . esc_html((string) $class['course_name']) . ' · ' . esc_html((string) $class['teacher_name']) . ' · ' . esc_html((string) $class['local_label']) . '</p></div><div class="dzn-enrolment__case-state"><span class="dzn-badge dzn-badge--teal">' . esc_html((string) $class['attendance']['label']) . '</span>' . (!empty($class['action_needed']) ? '<span class="dzn-chip dzn-chip--warn">' . esc_html((string) $class['action_needed']) . '</span>' : '') . '</div></header>';
        echo '<div class="dzn-class-detail-grid"><main>';
        self::classFacts($class);
        self::classClassroom($class, $classroom);
        self::classActions($class);
        self::attendanceEvidence($class);
        self::scheduleHistory($class);
        self::assignmentHistory($class);
        echo '</main><aside>';
        self::classTermPanel($class);
        self::classNotificationPanel($class);
        self::caseDiagnostics($class);
        echo '</aside></div></div>';
    }

    /** @param array<string,mixed> $case */
    private static function caseFacts(array $case): void {
        echo '<section class="dzn-case-card"><h2>Request facts</h2><dl class="dzn-facts">';
        self::fact('Learner', $case['learner_name']);
        self::fact('Contact', trim(implode(' · ', array_filter(array($case['email'], $case['phone'])))));
        self::fact('Country / timezone', trim(implode(' · ', array_filter(array($case['country'], $case['timezone'])))));
        self::fact('Instrument / course', $case['instrument'] . ' · ' . $case['course']);
        self::fact('Request age', $case['age_label']);
        self::fact('Deadline', $case['deadline_label'], $case['deadline_note']);
        self::fact('Stage', $case['stage_label']);
        self::fact('Next action', $case['next_action']);
        self::fact('Teacher', $case['teacher_name']);
        self::fact('Teacher response', $case['teacher_response']);
        self::fact('Match attempts', (string) $case['match_attempts']);
        self::fact('Availability / conflicts', $case['availability_state']);
        self::fact('Identity', $case['identity_state']['label']);
        if ($case['prior_booking_count'] !== null) self::fact('Prior bookings', (string) $case['prior_booking_count']);
        echo '</dl>';
        echo '<h3>Requested times</h3>';
        if (!$case['requested_times']) echo '<p>No requested times recorded.</p>';
        else {
            echo '<ol class="dzn-time-list">';
            foreach ($case['requested_times'] as $time) echo '<li><strong>' . esc_html((string) $time['label']) . '</strong><br><small>' . esc_html((string) $time['duration_minutes']) . ' minutes · ' . esc_html((string) $time['starts_at_utc']) . ' UTC</small></li>';
            echo '</ol>';
        }
        if ($case['held_slot']) {
            echo '<h3>Reserved first regular slot</h3><p><strong>' . esc_html(self::local((string) $case['held_slot']['expires_at'], (string) $case['held_slot']['schedule_timezone'])) . '</strong><br><small>' . esc_html($case['held_slot']['capacity_effective'] ? 'Reservation currently capacity-effective.' : 'Reservation is not currently capacity-effective.') . '</small></p>';
        }
        if ($case['confirmed']) {
            echo '<h3>Proposed / confirmed trial</h3><p><strong>' . esc_html((string) $case['confirmed']['label']) . '</strong><br><small>' . esc_html((string) $case['confirmed']['state_label']) . ' · ' . esc_html((string) $case['confirmed']['teacher_name']) . '</small></p>';
            $confirmation = $case['confirmed'];
            if ((string) ($confirmation['state'] ?? '') !== 'confirmed') {
                echo '<dl class="dzn-facts">';
                self::fact('Provisioning failure', (string) ($confirmation['failure_reason_code'] ?: 'None recorded'));
                self::fact('Provisioning attempts', (string) (int) ($confirmation['attempt_count'] ?? 0));
                self::fact('Last attempt', (string) ($confirmation['last_attempt_at'] ?: 'Not recorded'));
                self::fact('Next automatic attempt', (string) ($confirmation['next_attempt_at'] ?: 'None'));
                self::fact('Organizer connection', (string) ($confirmation['organizer_state'] ?? 'unknown') . (!empty($confirmation['organizer_scope_match']) ? ' · scopes exact' : ' · scopes need review'));
                self::fact('Recovery eligible', !empty($confirmation['recovery_eligible']) ? 'Yes — no new Teacher acceptance required' : 'No — review the case state or organizer connection');
                $classroom = $confirmation['classroom'] ?? null;
                self::fact('Classroom record', $classroom ? ((string) $classroom['classroom_state'] . ' · co-host ' . (string) $classroom['cohost_state']) : 'Not created');
                if ($classroom && !empty($classroom['failure_reason_code'])) self::fact('Classroom failure', (string) $classroom['failure_reason_code']);
                echo '</dl>';
            }
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $case */
    private static function caseDecision(array $case, ?array $classroom): void {
        echo '<section class="dzn-case-card dzn-case-card--decision"><h2>Decision state</h2>';
        echo '<div class="dzn-stage-strip"><span class="' . ($case['identity_state']['key'] === 'verified' ? 'is-done' : 'is-blocked') . '">Identity</span><span class="' . ($case['teacher_id'] > 0 ? 'is-done' : '') . '">Teacher</span><span class="' . ($case['confirmed'] ? 'is-done' : '') . '">Trial</span><span class="' . ($case['outcome'] ? 'is-done' : '') . '">Outcome</span><span class="' . (($case['post_intro']['state'] ?? '') === 'activated' ? 'is-done' : '') . '">Enrolment</span></div>';
        echo '<p class="dzn-decision-next"><strong>Next:</strong> ' . esc_html((string) $case['next_action']) . '</p>';
        if (!empty($case['confirmed'])) {
            echo '<p class="dzn-inline-note"><strong>Trial delivery evidence:</strong> ' . esc_html((string) ($case['outcome']['label'] ?? 'No outcome recorded')) . '. Administrator presence or a clicked link is never attendance evidence.</p>';
        }
        if (!empty($case['post_intro']['state'])) {
            echo '<h3>After the introductory lesson</h3><dl class="dzn-facts">';
            self::fact('Recorded outcome', (string) ($case['outcome']['label'] ?? 'Not recorded'));
            self::fact('Attendance / delivery evidence', (string) ($case['outcome']['label'] ?? 'No outcome evidence yet'), 'Administrator presence or a clicked link is never attendance evidence.');
            self::fact('Student decision', (string) ($case['post_intro']['state_label'] ?? '—'));
            self::fact('First regular slot', trim((string) (($case['post_intro']['local_date'] ?? '') . ' ' . ($case['post_intro']['local_time'] ?? '') . ' ' . ($case['post_intro']['schedule_timezone'] ?? ''))));
            self::fact('Reservation expiry', $case['deadline_label'], $case['deadline_note']);
            self::fact('Payment link', self::paymentLinkLabel($case['post_intro']));
            self::fact('Confirmed payment evidence', !empty($case['post_intro']['payment_verified']) ? 'Verified payment evidence recorded' : 'No verified payment');
            $reminders = array_values(array_filter((array) $case['notification_rows'], static fn(array $row): bool => str_contains((string) ($row['label'] ?? ''), 'Payment reminder')));
            self::fact('Reminders', $reminders ? (string) count($reminders) . ' recorded · last ' . (string) $reminders[0]['at'] : 'No reminder recorded');
            self::fact('Activation', (string) ($case['post_intro']['enrolment_state'] ?: 'Not activated'));
            echo '</dl>';
            $countdown = self::countdown((string) ($case['post_intro']['reservation_expires_at'] ?? ''));
            if ($countdown !== '') echo '<p class="dzn-countdown">' . esc_html($countdown) . '</p>';
            if (($case['post_intro']['state'] ?? '') !== 'activated' && current_user_can('dzn_manage_post_intro_automation')) {
                if (($case['post_intro']['state'] ?? '') === 'exception') {
                    echo '<div class="notice notice-warning inline"><p><strong>Post-intro exception.</strong> ' . esc_html((string) ($case['post_intro']['exception_detail'] ?: 'Review the authoritative preparation record before retrying.')) . '</p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-post-intro')) . '">Open protected Post-intro diagnostics</a></p></div>';
                } else {
                    echo '<div class="dzn-action-row">';
                    self::postForm('resend_payment_link', array('request_id' => (int) $case['id']), 'Resend payment link', 'secondary', 'I understand that a queued message is not recipient delivery.');
                    self::postForm('send_reminder', array('request_id' => (int) $case['id']), 'Send reminder', 'secondary', 'I confirm the continuation is still open and no verified payment exists.');
                    echo '</div><p class="description">These actions are refused unless the existing notification policy permits them. They are idempotent and never create a charge.</p>';
                }
            }
        }
        $confirmation = $case['confirmed'] ?? null;
        if ($confirmation && (string) ($confirmation['state'] ?? '') === 'failed') {
            echo '<div class="notice notice-warning inline"><p><strong>Teacher agreement is already recorded.</strong> Google classroom creation failed. Retrying reuses the accepted booking and never asks the Teacher to accept again.</p>';
            if (!empty($confirmation['recovery_eligible']) && current_user_can('dzn_manage_booking_request_coordination')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-inline-form">';
                wp_nonce_field(EnrolmentWorkspaceController::ACTION);
                echo '<input type="hidden" name="action" value="' . esc_attr(EnrolmentWorkspaceController::ACTION) . '">';
                echo '<input type="hidden" name="operation" value="retry_booking_projection">';
                echo '<input type="hidden" name="request_id" value="' . esc_attr((string) (int) $case['id']) . '">';
                echo '<input type="hidden" name="confirmation_id" value="' . esc_attr((string) (int) ($confirmation['confirmation_id'] ?? 0)) . '">';
                echo '<input type="hidden" name="expected_confirmation_version" value="' . esc_attr((string) (int) ($confirmation['version'] ?? 0)) . '">';
                echo '<input type="hidden" name="idempotency_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
                echo '<button class="button button-primary" type="submit">تلاش مجدد برای ساخت کلاس</button></form>';
            } else {
                echo '<p class="description">' . esc_html(!empty($confirmation['recovery_eligible']) ? 'Classroom retry requires booking coordination permission.' : 'This booking is not currently eligible for retry. Check the organizer connection, the class time and the current confirmation state.') . '</p>';
            }
            echo '</div>';
        }
        if ($classroom) self::classroomActions('booking', $classroom);
        echo '</section>';
    }

    /** @param array<string,mixed> $case */
    private static function caseActions(array $case): void {
        echo '<section class="dzn-enrolment__section dzn-case-actions"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Contextual actions</p><h2>Resolve the case</h2></div></div>';
        if ($case['identity_state']['key'] !== 'verified' && current_user_can('dzn_resolve_booking_request_student_identity')) self::identityActions($case);
        // Once a confirmation exists, the Teacher's acceptance is durable. Do not offer coordination
        // or "record agreement" controls that imply the Teacher has not answered.
        if (!$case['confirmed'] && current_user_can('dzn_manage_booking_request_coordination')) self::coordinationActions($case);
        if ($case['identity_state']['key'] === 'verified' && current_user_can('dzn_manage_booking_request_coordination') && current_user_can('dzn_manage_teacher_availability_assent') && !$case['confirmed']) self::manualMatchForm($case);
        if ($case['confirmed'] && empty($case['outcome'])) self::introLifecycleForms($case);
        if ($case['confirmed'] && empty($case['outcome']) && current_user_can('dzn_manage_post_intro_automation')) self::outcomeForm($case);
        if ($case['confirmed'] && empty($case['outcome']) && !current_user_can('dzn_manage_post_intro_automation')) echo '<p>Outcome recording requires post-intro automation access.</p>';
        if ($case['confirmed'] && !empty($case['outcome'])) echo '<p class="dzn-inline-note">The introductory outcome is recorded as <strong>' . esc_html((string) $case['outcome']['label']) . '</strong>. The post-intro decision is shown above.</p>';
        if (!$case['confirmed'] && !in_array($case['stage_key'], array('identity', 'provisioning_pending', 'provisioning_failed', 'intro_payment_pending'), true)) echo '<p class="description">A normal confirmed match requires an active central Google organizer and provider projection. No control on this page invents either.</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $case */
    private static function identityActions(array $case): void {
        $read = new EnrolmentWorkspaceReadService();
        $students = $read->studentOptions('', 150);
        echo '<details class="dzn-action-panel"><summary><strong>Resolve identity</strong><br><small>Human-reviewed only. Contact details never create a Student automatically.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('identity_resolve', array('request_id' => (int) $case['id']));
        echo '<p><label>Existing verified Student<select name="student_id"><option value="">Choose an existing Student</option>';
        foreach ($students as $student) echo '<option value="' . esc_attr((string) $student->id) . '">' . esc_html((string) $student->reference_code . ' · ' . $student->display_name . ' · ' . $student->email) . '</option>';
        echo '</select></label></p>';
        echo '<fieldset><legend>Or create a reviewed Student</legend><p class="description">Leave the existing Student choice empty. Use only after verifying the person.</p>';
        self::input('display_name', 'Display name');
        self::input('email', 'Email', 'email');
        self::input('phone', 'Phone');
        self::input('country_code', 'Country code', 'text', (string) $case['country']);
        self::input('city', 'City', 'text', (string) $case['city']);
        self::input('timezone', 'Timezone', 'text', (string) $case['timezone']);
        echo '</fieldset><button class="button button-primary">Resolve identity</button></form>';
        self::formStart('identity_blocker', array('request_id' => (int) $case['id']));
        echo '<p><label>Or record a reviewed blocker<select name="outcome"><option value="ambiguous">Ambiguous identity</option><option value="rejected">Identity rejected</option><option value="unresolved">Keep unresolved</option></select></label></p><button class="button">Record blocker</button></form>';
        echo '</div></details>';
    }

    /** @param array<string,mixed> $case */
    private static function coordinationActions(array $case): void {
        $coordination = $case['coordination'];
        echo '<details class="dzn-action-panel"><summary><strong>Teacher matching &amp; coordination</strong><br><small>Propose a Teacher and record attributable agreement. The coordination command centre carries identity, authority, Proposal, acceptance, conversion and assignment work in one place.</small></summary><div class="dzn-action-panel__body">';
        if (empty($coordination['case_id'])) {
            self::formStart('open_coordination', array('request_id' => (int) $case['id']));
            echo '<p><label>Reason<select name="reason"><option value="admin_opened">Administrator opened</option><option value="manual_review">Manual review</option></select></label></p><button class="button button-primary">Open coordination</button></form>';
        } else {
            echo '<p><strong>Coordination case:</strong> ' . esc_html((string) ('#' . $coordination['case_id'] . ' · ' . $coordination['state'])) . '</p>';
            $read = new EnrolmentWorkspaceReadService();
            $teachers = $read->teacherOptions('', 150);
            self::formStart('add_candidate', array('request_id' => (int) $case['id'], 'case_id' => (int) $coordination['case_id']));
            echo '<p><label>Teacher candidate<select name="teacher_id" required><option value="">Choose a Teacher</option>';
            foreach ($teachers as $teacher) echo '<option value="' . esc_attr((string) $teacher->id) . '">' . esc_html((string) $teacher->display_name . ' · ' . $teacher->email) . '</option>';
            echo '</select></label> <label>Source<select name="source"><option value="manual_search">Manual search</option><option value="advisory_match">Advisory match</option><option value="student_or_guardian_preference">Student preference</option><option value="approved_exception">Approved exception</option></select></label> <button class="button">Add candidate</button></form>';
            if (!empty($coordination['candidates'])) {
                $candidate = $coordination['candidates'][0];
                $assent = $coordination['assent'] ?? null;
                echo '<h3>Record Teacher agreement</h3>';
                self::formStart('record_assent', array('request_id' => (int) $case['id'], 'case_id' => (int) $coordination['case_id']));
                echo '<input type="hidden" name="candidate_id" value="' . esc_attr((string) $candidate->id) . '"><input type="hidden" name="candidate_version" value="' . esc_attr((string) $candidate->version) . '"><input type="hidden" name="teacher_id" value="' . esc_attr((string) $candidate->teacher_id) . '">';
                echo '<p><label>Course<select name="course_id">';
                foreach ((new EnrolmentWorkspaceReadService())->courseOptions('', 200) as $course) echo '<option value="' . esc_attr((string) $course->id) . '">' . esc_html((string) $course->instrument_name . ' · ' . $course->name) . '</option>';
                echo '</select></label> <label>Frequency per week<input type="number" name="frequency_per_week" value="1" min="1" max="14" required></label> <label>Duration minutes<input type="number" name="expected_duration_minutes" value="30" min="5" max="480" required></label></p>';
                self::input('commencement_window_start', 'Commencement window start UTC', 'text', (string) ($case['requested_times'][0]['starts_at_utc'] ?? ''));
                self::input('commencement_window_end', 'Commencement window end UTC', 'text', gmdate('Y-m-d H:i:s', strtotime((string) ($case['requested_times'][0]['starts_at_utc'] ?? 'now') . ' UTC +7 days')));
                self::input('assent_timezone', 'Agreement timezone', 'text', (string) $case['timezone']);
                self::input('valid_until', 'Agreement valid until UTC', 'text', gmdate('Y-m-d H:i:s', strtotime('+30 days')));
                self::input('review_by', 'Review by UTC', 'text', gmdate('Y-m-d H:i:s', strtotime('+14 days')));
                self::input('attribution_basis', 'Attribution basis', 'text', 'direct_teacher_statement');
                echo '<p><label>Evidence channel<select name="evidence_channel"><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="in_person">In person</option><option value="video_call">Video call</option><option value="other_verified">Other verified</option></select></label></p>';
                self::input('evidence_at', 'Evidence UTC', 'text', gmdate('Y-m-d H:i:s'));
                echo '<button class="button button-primary">Record agreement</button></form>';
                if ($assent) {
                    $fingerprint = self::assentFingerprint((int) $assent->snapshot_id);
                    if ($fingerprint !== '') {
                        echo '<h3>Propose this Teacher</h3>';
                        self::formStart('issue_proposal', array('request_id' => (int) $case['id']));
                        echo '<input type="hidden" name="candidate_id" value="' . esc_attr((string) $candidate->id) . '"><input type="hidden" name="arrangement_fingerprint" value="' . esc_attr($fingerprint) . '"><button class="button">Issue Proposal from recorded agreement</button></form>';
                    }
                }
            }
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-booking-request-coordination&case_id=' . (int) $coordination['case_id'])) . '">Open coordination command centre</a></p>';
        }
        echo '</div></details>';
    }

    /** @param array<string,mixed> $case */
    private static function manualMatchForm(array $case): void {
        $read = new EnrolmentWorkspaceReadService();
        $teachers = $read->courseOptions() ? $read->teacherOptions('', 150) : array();
        echo '<details class="dzn-action-panel dzn-action-panel--accent" open><summary><strong>Manual Teacher match</strong><br><small>Use when staff spoke to a Teacher who agrees despite ordinary availability settings. Real collisions, invalid times and identity blockers are still refused.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('manual_match', array('request_id' => (int) $case['id']));
        echo '<p><label>Chosen requested time<select name="requested_time_id" required>';
        foreach ($case['requested_times'] as $time) echo '<option value="' . esc_attr((string) ($time['id'] ?? 0)) . '">' . esc_html((string) $time['label'] . ' · ' . $time['timezone']) . '</option>';
        echo '</select></label></p>';
        echo '<p class="description">The chosen value is the authoritative requested-time record. Sequence order is shown in its label.</p>';
        echo '<p><label>Teacher<select name="teacher_id" required><option value="">Choose the Teacher who agreed</option>';
        foreach ($teachers as $teacher) echo '<option value="' . esc_attr((string) $teacher->id) . '">' . esc_html((string) $teacher->display_name . ' · ' . $teacher->email) . '</option>';
        echo '</select></label></p>';
        echo '<p><label>Agreement evidence channel<select name="agreement_channel"><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="in_person">In person</option><option value="video_call">Video call</option><option value="other_verified">Other verified</option></select></label></p>';
        self::input('agreement_reference', 'Agreement reference', 'text', '');
        self::input('agreement_at', 'Agreement UTC', 'text', gmdate('Y-m-d H:i:s'));
        echo '<p><label>Availability preference override<select name="override_reason_code"><option value="">Not needed — within preference</option><option value="teacher_agreed_outside_preference">Teacher agreed outside ordinary availability</option><option value="mutual_reschedule">Mutual reschedule</option><option value="operational_exception">Operational exception</option></select></label></p>';
        echo '<p><label>Staff reason<textarea name="staff_reason" rows="2" required placeholder="What was agreed, with whom, and why this slot is appropriate"></textarea></label></p>';
        echo '<button type="submit" class="button button-primary">Confirm match with audit</button></form>';
        echo '<p class="description">The chosen sequence must resolve to an existing requested time. If the Teacher has a real class collision, the authority refuses the match and no booking is created.</p>';
        echo '</div></details>';
    }

    /** @param array<string,mixed> $case */
    private static function introLifecycleForms(array $case): void {
        $confirmation = $case['confirmed'] ?? null;
        if (!$confirmation) return;
        $state = (string) ($confirmation['state'] ?? '');
        if (!in_array($state, array('confirmed', 'awaiting_payment'), true)) return;
        $confirmedAt = strtotime((string) ($confirmation['starts_at_utc'] ?? '') . ' UTC');
        $future = $confirmedAt !== false && $confirmedAt > time();
        echo '<details class="dzn-action-panel"><summary><strong>Reschedule introductory lesson</strong><br><small>Admin and Teacher action. The Student is notified and the provider event is updated when its projection authority is available.</small></summary><div class="dzn-action-panel__body">';
        if ($future) {
            self::formStart('intro_reschedule', array('request_id' => (int) $case['id']));
            echo '<input type="hidden" name="expected_confirmation_id" value="' . esc_attr((string) ($confirmation['confirmation_id'] ?? 0)) . '"><input type="hidden" name="expected_confirmation_version" value="' . esc_attr((string) ($confirmation['version'] ?? 0)) . '">';
            self::input('local_date', 'New local date', 'date', (string) ($confirmation['local_date'] ?? ''), true);
            self::timeSelect('local_start_time', 'New local start time', (string) ($confirmation['local_time'] ?? ''));
            self::timezoneSelect('schedule_timezone', 'Local timezone (service area)', (string) ($confirmation['schedule_timezone'] ?? ''));
            echo '<p><label>Reason<select name="reason_code"><option value="student_requested">Student requested</option><option value="teacher_requested">Teacher requested</option><option value="operational_change">Operational change</option></select></label></p>';
            echo '<button class="button button-primary">Reschedule introductory lesson</button></form>';
        } else {
            echo '<p class="dzn-inline-note">This introductory lesson has already started or ended. Its outcome is recorded instead of rescheduling it.</p>';
        }
        echo '</div></details>';
        echo '<details class="dzn-action-panel dzn-action-panel--danger"><summary><strong>Cancel introductory lesson</strong><br><small>Admin, Teacher or Student action. The case and financial history remain; the portals stop showing the cancelled class.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('intro_cancel', array('request_id' => (int) $case['id']));
        echo '<input type="hidden" name="expected_confirmation_id" value="' . esc_attr((string) ($confirmation['confirmation_id'] ?? 0)) . '"><input type="hidden" name="expected_confirmation_version" value="' . esc_attr((string) ($confirmation['version'] ?? 0)) . '">';
        echo '<p><label>Reason<select name="reason_code"><option value="duplicate_request">Duplicate request</option><option value="student_requested">Student requested</option><option value="teacher_requested">Teacher requested</option><option value="operational_change">Operational change</option></select></label></p>';
        echo '<button class="button" onclick="return confirm(\'Cancel this introductory lesson? The case and its history remain.\')">Cancel introductory lesson</button></form>';
        echo '</div></details>';
    }

    /** @param array<string,mixed> $case */
    private static function outcomeForm(array $case): void {
        echo '<details class="dzn-action-panel"><summary><strong>Record introductory outcome</strong><br><small>Only a delivered outcome starts the continuation workflow.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('record_outcome', array('request_id' => (int) $case['id']));
        echo '<p><label>What happened<select name="outcome" required><option value="delivered">Delivered</option><option value="student_no_show">Student no-show</option><option value="student_cancelled">Student cancelled</option><option value="teacher_cancelled">Teacher cancelled</option></select></label></p><p><label>Note<textarea name="note" rows="3"></textarea></label></p><button class="button button-primary">Record outcome</button></form>';
        echo '<p class="description">The introductory lesson must have finished. A no-show or cancellation is retained as an exception and never sends a payment invitation.</p></div></details>';
    }

    /** @param array<string,mixed> $case */
    private static function caseNotifications(array $case): void {
        echo '<section class="dzn-case-card"><h2>Notification evidence</h2><p class="description">A transport hand-off is not recipient delivery. Delivered is shown only with provider evidence.</p>';
        $summary = $case['notification_summary'];
        $labels = array('queued' => 'Queued', 'handed_off' => 'Sent to transport', 'delivery_confirmed' => 'Delivered', 'portal_recorded' => 'Portal recorded', 'failed' => 'Failed', 'unknown' => 'Unknown');
        echo '<div class="dzn-notification-summary">';
        $any = false;
        foreach ($labels as $key => $label) {
            if ((int) ($summary[$key] ?? 0) < 1) continue;
            $any = true;
            echo '<span class="dzn-notification-state dzn-notification-state--' . esc_attr($key) . '"><strong>' . esc_html((string) $summary[$key]) . '</strong> ' . esc_html($label) . '</span>';
        }
        if (!$any) echo '<p>No notification evidence recorded.</p>';
        echo '</div>';
        if (!empty($case['notification_rows'])) {
            echo '<ul class="dzn-timeline dzn-timeline--compact">';
            foreach (array_slice($case['notification_rows'], 0, 8) as $row) {
                echo '<li><span class="dzn-timeline__dot"></span><strong>' . esc_html((string) $row['label']) . '</strong><small>' . esc_html((string) $row['at']) . '</small><p>' . esc_html((string) $row['detail']) . '</p>';
                $receipt = (string) ($row['provider_receipt_state'] ?? '');
                if ($receipt !== '') {
                    echo '<p class="description"><strong>Provider receipt:</strong> ' . esc_html($receipt) . ' · ' . esc_html((string) ($row['provider_channel'] ?? 'mobile')) . ' · ' . esc_html((string) ($row['provider_receipt_at'] ?? '')) . '</p>';
                } elseif ((string) ($row['category'] ?? '') === 'handed_off' && (string) ($row['channel'] ?? '') === 'email') {
                    echo '<p class="description">Email hand-off recorded; no email delivery receipt is available.</p>';
                }
                if ((string) ($row['category'] ?? '') === 'failed') {
                    echo '<p class="description"><strong>Recipient:</strong> ' . esc_html((string) ($row['audience'] ?: 'unknown') . ((string) ($row['recipient'] ?? '') !== '' ? ' · ' . (string) $row['recipient'] : '')) . ' · <strong>Channel:</strong> ' . esc_html((string) ($row['channel'] ?? 'email')) . ' · <strong>Attempts:</strong> ' . esc_html((string) ($row['attempt_count'] ?? 0)) . ' · <strong>Last attempt:</strong> ' . esc_html((string) ($row['last_attempt'] ?? '')) . '</p>';
                    if (!empty($row['recovery_available']) && (int) ($row['notification_id'] ?? 0) > 0 && current_user_can('dzn_operate_notification_dispatch')) {
                        self::formStart('recover_booking_notification', array('request_id' => (int) $case['id'], 'notification_id' => (int) $row['notification_id']));
                        echo '<button class="button button-secondary" onclick="return confirm(\'Requeue this failed booking notification? The booking state will be revalidated first.\')">Recover notification</button></form>';
                    } elseif ((string) ($row['recovery_reason'] ?? '') !== '') {
                        echo '<p class="description">Recovery unavailable: ' . esc_html((string) $row['recovery_reason']) . '</p>';
                    }
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $case */
    private static function caseHistory(array $case): void {
        if (empty($case['prior_bookings'])) return;
        echo '<section class="dzn-case-card"><h2>Prior booking history</h2><p class="description">Shown only for a verified Student identity.</p><ul class="dzn-link-list">';
        foreach ($case['prior_bookings'] as $row) echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&request_id=' . (int) $row['id'])) . '">' . esc_html((string) $row['reference']) . '</a><small>' . esc_html((string) $row['state'] . ' · ' . $row['created_at']) . '</small></li>';
        echo '</ul></section>';
    }

    /** @param array<string,mixed> $case */
    private static function caseDiagnostics(array $case): void {
        $postIntro = $case['post_intro'] ?? array();
        $applicable = (($postIntro['state'] ?? '') === 'exception') || (($postIntro['exception_code'] ?? '') !== '');
        if (!$applicable || !current_user_can('dzn_manage_post_intro_automation')) return;
        echo '<details class="dzn-diagnostics"><summary>Diagnostics</summary><div class="dzn-diagnostics__body"><p><strong>Automation identity / repair</strong></p><p>Only this exception is relevant. The full technical repair surface is protected and is not part of the ordinary workflow. Inspect the canonical preparation and payment evidence before running any repair.</p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-post-intro')) . '">Open protected diagnostics</a></p></div></details>';
    }

    /** @param array<string,mixed> $case */
    private static function caseTimeline(array $case): void {
        echo '<section class="dzn-enrolment__section"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Full record</p><h2>Case timeline</h2></div></div>';
        if (empty($case['timeline'])) echo '<p>No timeline events recorded.</p>';
        else {
            echo '<ol class="dzn-timeline">';
            foreach ($case['timeline'] as $event) echo '<li><span class="dzn-timeline__dot"></span><strong>' . esc_html((string) $event['label']) . '</strong><small>' . esc_html((string) $event['at']) . '</small><p>' . esc_html((string) $event['detail']) . '</p></li>';
            echo '</ol>';
        }
        echo '</section>';
    }

    private static function reportPanel(array $report): void {
        echo '<section class="dzn-report" aria-labelledby="dzn-conversion-title"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Trustworthy conversion</p><h2 id="dzn-conversion-title">Introductory conversion</h2></div><span class="dzn-count-chip">' . esc_html((string) $report['denominator']) . ' denominator</span></div>';
        echo '<p class="description">Reporting period ' . esc_html((string) $report['period']['from']) . ' to ' . esc_html((string) $report['period']['to']) . '. A completed introduction with no payment today remains <strong>awaiting decision or payment</strong>.</p>';
        echo '<div class="dzn-report-grid"><div class="dzn-report-card dzn-report-card--converted"><strong>' . esc_html((string) $report['converted']) . '</strong><span>Converted</span><small>Actual enrolled/activated continuation.</small></div><div class="dzn-report-card dzn-report-card--awaiting"><strong>' . esc_html((string) $report['awaiting']) . '</strong><span>Awaiting decision or payment</span><small>Hold and decision period still open.</small></div><div class="dzn-report-card dzn-report-card--lost"><strong>' . esc_html((string) $report['did_not_convert']) . '</strong><span>Did not convert</span><small>Explicit decline or expired without verified payment.</small></div><div class="dzn-report-card dzn-report-card--excluded"><strong>' . esc_html((string) $report['excluded']) . '</strong><span>Excluded / reviewed separately</span><small>Cancelled/no-show or unresolved evidence.</small></div></div>';
        if (!empty($report['rows'])) {
            echo '<details class="dzn-report__detail" open><summary>Intro conversion overview</summary><div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Student</th><th>Course</th><th>Teacher</th><th>Class date</th><th>Time elapsed</th><th>Outcome</th><th>Reason</th></tr></thead><tbody>';
            foreach ($report['rows'] as $row) {
                echo '<tr><td>' . esc_html((string) $row['student']) . '</td><td>' . esc_html((string) $row['course']) . '</td><td>' . esc_html((string) $row['teacher']) . '</td><td>' . esc_html((string) $row['date']) . '</td><td>' . esc_html((string) $row['elapsed']) . '</td><td>' . esc_html((string) $row['outcome']) . '</td><td>' . esc_html((string) $row['reason']) . '</td></tr>';
            }
            echo '</tbody></table></div></details>';
        }
        echo '</section>';
    }

    private static function createIntroPanel(EnrolmentWorkspaceReadService $read): void {
        $students = $read->studentOptions('', 200);
        $courses = $read->courseOptions('', 250);
        $state = self::introFormState();
        $values = (array) ($state['values'] ?? array());
        echo '<details class="dzn-create-intro dzn-enrolment__section" id="dzn-create-intro"' . (!empty($state['open']) ? ' open' : '') . '><summary><span><strong>Create introductory lesson</strong><br><small>For an existing verified Student. The server checks the slot, Teacher agreement and real collisions when you press Create.</small></span></summary><div class="dzn-create-intro__body">';
        if ((string) ($state['message'] ?? '') !== '') {
            echo '<div class="notice notice-error inline dzn-intro-form-error" role="alert"><p><strong>Introductory lesson was not created.</strong> ' . esc_html((string) $state['message']) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-create-intro__form" data-dzn-intro-create>';
        wp_nonce_field(EnrolmentWorkspaceController::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(EnrolmentWorkspaceController::ACTION) . '"><input type="hidden" name="operation" value="create_intro"><input type="hidden" name="idempotency_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
        self::studentSelect('student_id', $students, absint($values['student_id'] ?? 0));
        self::courseSelect('course_id', $courses, absint($values['course_id'] ?? 0));
        echo '<p><label>Teacher<select name="teacher_id" required><option value="">Choose the Teacher who agreed</option>';
        foreach ($read->teacherOptions('', 200) as $teacher) echo '<option value="' . esc_attr((string) $teacher->id) . '"' . selected(absint($values['teacher_id'] ?? 0), (int) $teacher->id, false) . '>' . esc_html((string) $teacher->display_name . ' · ' . $teacher->email) . '</option>';
        echo '</select></label></p>';
        self::input('local_date', 'Local date', 'date', (string) ($values['local_date'] ?? ''), true);
        self::timeSelect('local_start_time', 'Local start time', (string) ($values['local_start_time'] ?? ''));
        self::timezoneSelect('schedule_timezone', 'Local timezone (service area)', (string) ($values['schedule_timezone'] ?? ''));
        echo '<p><label>Teacher agreement channel<select name="agreement_channel">';
        foreach (array('phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'in_person' => 'In person', 'video_call' => 'Video call', 'other_verified' => 'Other verified') as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected((string) ($values['agreement_channel'] ?? 'phone'), $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label></p>';
        self::input('agreement_reference', 'Agreement reference', 'text', (string) ($values['agreement_reference'] ?? ''), true);
        self::input('agreement_at', 'Agreement UTC', 'text', (string) ($values['agreement_at'] ?? gmdate('Y-m-d H:i:s')), true, 'Use YYYY-MM-DD HH:MM:SS UTC.');
        echo '<p><label>Availability override<select name="override_reason_code"><option value="">Not needed</option>';
        foreach (array('teacher_agreed_outside_preference' => 'Teacher agreed outside ordinary availability', 'mutual_reschedule' => 'Mutual reschedule', 'operational_exception' => 'Operational exception') as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected((string) ($values['override_reason_code'] ?? ''), $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label></p>';
        echo '<p><label>Staff reason<textarea name="staff_reason" rows="2" required>' . esc_html((string) ($values['staff_reason'] ?? '')) . '</textarea></label></p>';
        echo '<button class="button button-primary">Create</button></form>';
        echo '<p class="description">Create performs both the slot check and the save. A real Teacher collision, inactive Student, missing Teacher agreement or invalid local time is refused here and the form stays open with the relevant error. It creates the request, identity evidence and booking confirmation once, then uses the same provider projection and notification state as a normal Teacher match.</p>';
        echo '</div></details>';
    }

    /** @return array{open:bool,message:string,values:array<string,mixed>} */
    private static function introFormState(): array {
        if ((string) ($_GET['dzn_form'] ?? '') !== 'intro') return array('open' => false, 'message' => '', 'values' => array());
        $key = 'dzn_intro_form_' . get_current_user_id();
        $stored = get_transient($key);
        delete_transient($key);
        if (!is_array($stored)) return array('open' => true, 'message' => '', 'values' => array());
        return array('open' => true, 'message' => (string) ($stored['message'] ?? ''), 'values' => is_array($stored['values'] ?? null) ? $stored['values'] : array());
    }

    private static function timeSelect(string $name, string $label, string $value = ''): void {
        echo '<p><label>' . esc_html($label) . '<select name="' . esc_attr($name) . '" required><option value="">Choose a local start time</option>';
        for ($minute = 0; $minute < 24 * 60; $minute += 30) {
            $option = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            echo '<option value="' . esc_attr($option) . '"' . selected($value, $option, false) . '>' . esc_html($option) . '</option>';
        }
        echo '</select></label><br><span class="description">24-hour local time in the selected service-area timezone.</span></p>';
    }

    private static function timezoneSelect(string $name, string $label, string $value = ''): void {
        $areas = class_exists('\\Delnavazan\\Platform\\Core\\Application\\Finance\\GoldenHourRule')
            ? \Delnavazan\Platform\Core\Application\Finance\GoldenHourRule::defaults()
            : array();
        $timezones = array();
        foreach ($areas as $key => $area) {
            $timezone = (string) ($area['timezone'] ?? '');
            if ($timezone === '') continue;
            $timezones[$timezone] = (string) ($area['label'] ?? $key) . ' — ' . $timezone;
        }
        $academy = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
        if ($academy !== '' && !isset($timezones[$academy])) $timezones[$academy] = 'Academy — ' . $academy;
        if (!isset($timezones['UTC'])) $timezones['UTC'] = 'UTC';
        echo '<p><label>' . esc_html($label) . '<select name="' . esc_attr($name) . '" required><option value="">Choose a service-area timezone</option>';
        foreach ($timezones as $timezone => $labelText) echo '<option value="' . esc_attr($timezone) . '"' . selected($value, $timezone, false) . '>' . esc_html($labelText) . '</option>';
        echo '</select></label><br><span class="description">The same handful of service areas used by the academy timetable, so the same civil time is never entered against a mismatched IANA timezone.</span></p>';
    }

    private static function importPanel(EnrolmentWorkspaceReadService $read, array $query): void {
        $students = $read->studentOptions('', 250);
        $courses = $read->courseOptions('', 300);
        $teachers = $read->teacherOptions('', 200);
        $preview = null;
        if (isset($query['preview_import'])) {
            try { $preview = (new HistoricalEnrolmentImportService())->preview($query); }
            catch (\Throwable $exception) { $preview = array('error' => $exception->getMessage()); }
        }
        echo '<details class="dzn-import dzn-enrolment__section" id="dzn-import-enrolment"><summary><span><strong>Import existing enrolment</strong><br><small>A course already running in the old system. It is never disguised as a new introductory lesson.</small></span></summary><div class="dzn-import__body">';
        if (is_array($preview)) self::importPreview($preview);
        echo '<form method="get" class="dzn-enrolment__filter-form"><input type="hidden" name="page" value="' . esc_attr(EnrolmentWorkspaceController::PAGE) . '"><input type="hidden" name="view" value="enrolled"><input type="hidden" name="preview_import" value="1">';
        self::importFields($read, $students, $teachers, $courses, $query);
        echo '<button class="button">Preview Term and future lessons</button></form>';
        echo '<details class="dzn-action-panel"><summary><strong>Commit after previewing the Term and future classes</strong><br><small>Past attendance and payment are never invented.</small></summary><div class="dzn-action-panel__body">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-import__commit">';
        wp_nonce_field(EnrolmentWorkspaceController::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(EnrolmentWorkspaceController::ACTION) . '"><input type="hidden" name="operation" value="import_commit"><input type="hidden" name="idempotency_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
        self::importFields($read, $students, $teachers, $courses, $query);
        echo '<button class="button button-primary" onclick="return confirm(\'Commit this historical import? It will create one Enrolment, one Term and the stated future classes. Past attendance and payments are not invented.\')">Commit import</button></form>';
        echo '<p class="description">Requires evidence or an explicit “historical status unknown” for attendance and payments. Duplicate Student/Course/Teacher Enrolments and real schedule collisions are refused before commit.</p></div></details>';
        echo '</div></details>';
    }

    private static function importFields(EnrolmentWorkspaceReadService $read, array $students, array $teachers, array $courses, array $query): void {
        self::studentSelect('student_id', $students, (int) ($query['student_id'] ?? 0));
        echo '<p><label>Teacher<select name="teacher_id" required><option value="">Choose Teacher</option>';
        foreach ($teachers as $teacher) echo '<option value="' . esc_attr((string) $teacher->id) . '"' . selected((int) ($query['teacher_id'] ?? 0), (int) $teacher->id, false) . '>' . esc_html((string) $teacher->display_name . ' · ' . $teacher->email) . '</option>';
        echo '</select></label></p>';
        self::courseSelect('course_id', $courses, (int) ($query['course_id'] ?? 0));
        self::input('original_start_date', 'Original start date', 'date', (string) ($query['original_start_date'] ?? ''));
        self::input('completed_classes', 'Classes already completed', 'number', (string) ($query['completed_classes'] ?? '0'));
        self::input('remaining_classes', 'Classes remaining', 'number', (string) ($query['remaining_classes'] ?? ''));
        echo '<p><label>Weekly class day<select name="weekday">';
        foreach (array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday') as $value => $label) echo '<option value="' . esc_attr((string) $value) . '"' . selected((int) ($query['weekday'] ?? 0), $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label></p>';
        self::input('next_class_date', 'Next class local date', 'date', (string) ($query['next_class_date'] ?? ''));
        self::input('local_time', 'Weekly local time', 'text', (string) ($query['local_time'] ?? ''));
        self::input('timezone', 'Timezone', 'text', (string) ($query['timezone'] ?? ''));
        self::input('replacement_allowance', 'Replacement allowance', 'number', (string) ($query['replacement_allowance'] ?? '0'));
        echo '<p><label>Recorded payment status<select name="payment_status">';
        foreach (array('unknown' => 'Historical status unknown', 'not_required' => 'Not required', 'pending' => 'Pending', 'paid' => 'Paid (evidence required)', 'failed' => 'Failed', 'refunded' => 'Refunded (evidence required)') as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected((string) ($query['payment_status'] ?? 'unknown'), $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label></p>';
        self::input('payment_evidence_reference', 'Payment evidence reference', 'text', (string) ($query['payment_evidence_reference'] ?? ''));
        echo '<p><label>Past attendance evidence<select name="attendance_evidence_state"><option value="historical_status_unknown"' . selected((string) ($query['attendance_evidence_state'] ?? 'historical_status_unknown'), 'historical_status_unknown', false) . '>Historical status unknown</option><option value="evidence_provided"' . selected((string) ($query['attendance_evidence_state'] ?? ''), 'evidence_provided', false) . '>Evidence provided</option></select></label></p>';
        self::input('attendance_evidence_reference', 'Attendance evidence reference', 'text', (string) ($query['attendance_evidence_reference'] ?? ''));
    }

    private static function importPreview(array $preview): void {
        if (isset($preview['error'])) {
            echo '<div class="notice notice-error inline"><p><strong>Import preview refused.</strong> ' . esc_html(self::importMessage((string) $preview['error'])) . '</p></div>';
            return;
        }
        echo '<div class="dzn-import-preview"><h3>Preview: Term and future lessons</h3><p><strong>Imported Term 1</strong> · ' . esc_html((string) $preview['completed_classes']) . ' already completed · ' . esc_html((string) $preview['remaining_classes']) . ' remaining · ' . esc_html((string) $preview['replacement_allowance']) . ' replacement allowance</p>';
        if (!empty($preview['warnings'])) {
            echo '<ul class="dzn-warning-list">';
            foreach ($preview['warnings'] as $warning) echo '<li>' . esc_html((string) $warning) . '</li>';
            echo '</ul>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Future class</th><th>Local time</th><th>Timezone</th></tr></thead><tbody>';
        foreach (array_slice((array) $preview['slots'], 0, 20) as $index => $slot) echo '<tr><td>' . esc_html((string) ($index + 1)) . '</td><td>' . esc_html((string) $slot['local_date'] . ' ' . substr((string) $slot['local_time'], 0, 5)) . '</td><td>' . esc_html((string) $slot['timezone']) . '</td></tr>';
        if (count((array) $preview['slots']) > 20) echo '<tr><td colspan="3">… ' . esc_html((string) (count((array) $preview['slots']) - 20)) . ' more future classes</td></tr>';
        echo '</tbody></table><p class="description">No past lesson is marked attended and no payment is claimed unless the evidence state above explicitly supports it.</p></div>';
    }

    private static function bookingFiltersForm(array $filters, bool $history): void {
        echo '<form method="get" class="dzn-enrolment__filter-form"><input type="hidden" name="page" value="' . esc_attr(EnrolmentWorkspaceController::PAGE) . '"><input type="hidden" name="view" value="new">';
        echo '<label class="dzn-enrolment__search"><span>Search active cases</span><input type="search" name="q" value="' . esc_attr($filters['q']) . '" placeholder="Learner, contact, reference, instrument or Teacher"></label>';
        echo '<label><span>Stage</span><select name="stage"><option value="">All active stages</option>';
        foreach (array('new' => 'New request', 'identity' => 'Identity needed', 'teacher_match' => 'Teacher matching', 'intro_scheduled' => 'Trial scheduled', 'intro_outcome' => 'Outcome due', 'post_intro' => 'Decision & payment', 'converted' => 'Converted') as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($filters['stage'], $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label><button class="button">Filter</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&view=new')) . '">Reset</a></form>';
    }

    private static function classFiltersForm(array $filters, bool $history): void {
        $read = new EnrolmentWorkspaceReadService();
        echo '<form method="get" class="dzn-enrolment__filter-form dzn-enrolment__filter-form--classes"><input type="hidden" name="page" value="' . esc_attr(EnrolmentWorkspaceController::PAGE) . '"><input type="hidden" name="view" value="enrolled">';
        echo '<label class="dzn-enrolment__search"><span>Search classes</span><input type="search" name="q" value="' . esc_attr($filters['q']) . '" placeholder="Student, Teacher, Course or term"></label>';
        echo '<label><span>Teacher</span><select name="teacher_id"><option value="0">All Teachers</option>';
        foreach ($read->teacherOptions('', 200) as $teacher) echo '<option value="' . esc_attr((string) $teacher->id) . '"' . selected($filters['teacher_id'], (int) $teacher->id, false) . '>' . esc_html((string) $teacher->display_name) . '</option>';
        echo '</select></label><label><span>Student</span><select name="student_id"><option value="0">All Students</option>';
        foreach ($read->studentOptions('', 200) as $student) echo '<option value="' . esc_attr((string) $student->id) . '"' . selected($filters['student_id'], (int) $student->id, false) . '>' . esc_html((string) $student->display_name) . '</option>';
        echo '</select></label><label><span>Course</span><select name="course_id"><option value="0">All Courses</option>';
        foreach ($read->courseOptions('', 250) as $course) echo '<option value="' . esc_attr((string) $course->id) . '"' . selected($filters['course_id'], (int) $course->id, false) . '>' . esc_html((string) $course->instrument_name . ' · ' . $course->name) . '</option>';
        echo '</select></label><label><span>Term</span><select name="term_id"><option value="0">All Terms</option>';
        foreach ($read->termOptions(120) as $term) echo '<option value="' . esc_attr((string) $term->id) . '"' . selected($filters['term_id'], (int) $term->id, false) . '>' . esc_html(trim((string) $term->reference_code . ' · ' . $term->student_name . ' · ' . $term->course_name)) . '</option>';
        echo '</select></label><label><span>Date</span><input type="date" name="date" value="' . esc_attr($filters['date']) . '"></label>';
        echo '<label><span>Action needed</span><select name="action"><option value="">All classes</option><option value="needs_action"' . selected($filters['action'], 'needs_action', false) . '>Needs action</option></select></label>';
        echo '<button class="button">Filter</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&view=enrolled')) . '">Reset</a></form>';
    }

    private static function bookingTable(array $rows, bool $history): void {
        echo '<div class="dzn-table-scroll"><table class="widefat striped dzn-booking-table"><thead><tr><th>Urgency / stage</th><th>Learner &amp; contact</th><th>Country / timezone</th><th>Instrument / course</th><th>Requested &amp; proposed</th><th>Teacher response</th><th>Match attempts / availability</th><th>Age &amp; deadline</th><th>Notifications</th><th>Next action</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&request_id=' . (int) $row['id']);
            echo '<tr>';
            echo '<td><span class="dzn-badge ' . ($history ? 'dzn-badge--soft' : 'dzn-badge--teal') . '">' . esc_html((string) $row['stage_label']) . '</span><br><small>' . esc_html((string) $row['urgency_label']) . '</small></td>';
            echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html((string) $row['learner_name']) . '</strong></a><br><small>' . esc_html(trim(implode(' · ', array_filter(array((string) $row['email'], (string) $row['phone']))))) . '</small></td>';
            echo '<td>' . esc_html(trim(implode(' · ', array_filter(array((string) $row['country'], (string) $row['city']))))) . '<br><small>' . esc_html((string) $row['timezone']) . '</small></td>';
            echo '<td>' . esc_html((string) $row['instrument']) . '<br><small>' . esc_html((string) $row['course']) . '</small></td>';
            echo '<td>' . self::timeSummary($row) . '</td>';
            echo '<td>' . esc_html((string) $row['teacher_name']) . '<br><small>' . esc_html((string) $row['teacher_response']) . '</small></td>';
            echo '<td>' . esc_html((string) $row['match_attempts']) . ' attempt' . ((int) $row['match_attempts'] === 1 ? '' : 's') . '<br><small>' . esc_html((string) $row['availability_state']) . '</small></td>';
            echo '<td>' . esc_html((string) $row['age_label']) . '<br><small>' . esc_html((string) $row['deadline_label']) . '</small></td>';
            echo '<td>' . self::notificationSummary($row['notification_summary']) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($url) . '">' . esc_html($history ? 'Review history' : (string) $row['next_action']) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function classTable(array $rows, bool $history): void {
        echo '<div class="dzn-table-scroll"><table class="widefat striped dzn-class-table"><thead><tr><th>Class</th><th>Local time</th><th>Student</th><th>Teacher</th><th>Instrument</th><th>Term progress</th><th>Remaining</th><th>Replacement</th><th>Schedule</th><th>Attendance</th><th>Next action</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&view=enrolled&class_id=' . (int) $row['id']);
            echo '<tr>';
            echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html((string) $row['state_label']) . '</strong></a><br><small>' . esc_html((string) $row['reference']) . '</small></td>';
            echo '<td>' . esc_html((string) $row['local_label']) . '<br><small>' . esc_html((string) $row['timezone']) . '</small></td>';
            echo '<td>' . esc_html((string) $row['student_name']) . '</td><td>' . esc_html((string) $row['teacher_name']) . '</td><td>' . esc_html((string) $row['course_name']) . '</td>';
            echo '<td>' . esc_html((string) $row['term_progress']) . '<br><small>' . esc_html((string) $row['term_reference']) . '</small></td>';
            echo '<td>' . esc_html((string) $row['remaining_classes']) . '</td><td>' . esc_html((string) $row['replacement_used']) . ' used / ' . esc_html((string) $row['replacement_allowance']) . ' allowed</td>';
            echo '<td><span class="dzn-badge ' . ((string) $row['state'] === 'authorised' ? 'dzn-badge--teal' : 'dzn-badge--soft') . '">' . esc_html((string) $row['state_label']) . '</span></td>';
            echo '<td><span class="dzn-attendance dzn-attendance--' . esc_attr((string) $row['attendance']['key']) . '">' . esc_html((string) $row['attendance']['label']) . '</span><br><small>' . esc_html((string) $row['attendance']['detail']) . '</small></td>';
            echo '<td>' . (!empty($row['action_needed']) ? '<strong>' . esc_html((string) $row['action_needed']) . '</strong><br>' : '') . '<a class="button button-small" href="' . esc_url($url) . '">Open class</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function classFacts(array $class): void {
        echo '<section class="dzn-enrolment__section"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Class context</p><h2>Class facts</h2></div></div><dl class="dzn-facts dzn-facts--columns">';
        self::fact('Local time', (string) $class['local_label']);
        self::fact('Timezone', (string) $class['timezone']);
        self::fact('Student', (string) $class['student_name']);
        self::fact('Teacher', (string) $class['teacher_name']);
        self::fact('Instrument / course', (string) $class['course_name']);
        self::fact('Term', (string) ($class['term_reference'] ?: 'Not recorded'));
        self::fact('Term progress', (string) $class['term_progress']);
        self::fact('Remaining classes', (string) $class['remaining_classes']);
        self::fact('Replacement allowance', (string) $class['replacement_used'] . ' used / ' . $class['replacement_allowance'] . ' allowed');
        self::fact('Schedule state', (string) $class['state_label']);
        self::fact('Attendance', (string) $class['attendance']['label'], (string) $class['attendance']['detail']);
        self::fact('Next action', (string) ($class['action_needed'] ?: 'No immediate action'));
        echo '</dl></section>';
    }

    private static function classClassroom(array $class, ?array $classroom): void {
        echo '<section class="dzn-enrolment__section"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Teaching room</p><h2>Classroom and links</h2></div></div>';
        if (!$classroom) {
            echo '<p class="dzn-inline-note">No administrator classroom projection is available for this legacy or unprovisioned class. No link was invented.</p>';
            if (current_user_can('dzn_manage_lessons')) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-lesson&id=' . (int) $class['id'])) . '">Open protected class record</a></p>';
            return;
        }
        self::classroomActions((string) $class['record_model'] === 'canonical_term_lesson_v1' ? 'lesson' : 'unavailable', $classroom);
        echo '</section>';
    }

    private static function classroomActions(string $kind, array $classroom): void {
        if ($kind === 'unavailable') return;
        echo '<div class="dzn-classroom"><p><strong>Google Meet:</strong> ' . esc_html(!empty($classroom['meet_available']) ? 'provisioned' : ucfirst(str_replace('_', ' ', (string) ($classroom['state'] ?? 'unavailable')))) . ' · <strong>Co-host:</strong> ' . esc_html((string) ($classroom['cohost_state'] ?? 'pending')) . '</p>';
        echo '<div class="dzn-classroom__actions">';
        if (!empty($classroom['meet_available'])) {
            echo '<form method="post" target="_blank" rel="noopener noreferrer" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-inline-form">';
            wp_nonce_field('dzn_admin_meet_class');
            echo '<input type="hidden" name="action" value="dzn_admin_meet_class"><input type="hidden" name="kind" value="' . esc_attr((string) $kind) . '">';
            if ($kind === 'booking') echo '<input type="hidden" name="confirmation_uid" value="' . esc_attr((string) ($classroom['confirmation_uid'] ?? '')) . '">';
            else echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) ($classroom['lesson_id'] ?? 0)) . '">';
            echo '<button class="button button-primary" type="submit">Open administrator classroom</button></form>';
        }
        if (!empty($classroom['student_entry_url'])) echo '<button type="button" class="button" data-dzn-copy="' . esc_attr((string) $classroom['student_entry_url']) . '">Copy student portal link</button>';
        if (!empty($classroom['teacher_entry_url'])) echo '<button type="button" class="button" data-dzn-copy="' . esc_attr((string) $classroom['teacher_entry_url']) . '">Copy teacher portal link</button>';
        if (!empty($classroom['meet_available'])) {
            echo '<form method="post" target="_blank" rel="noopener noreferrer" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-inline-form">';
            wp_nonce_field('dzn_admin_classroom_meet');
            echo '<input type="hidden" name="action" value="dzn_admin_classroom_meet"><input type="hidden" name="meet_kind" value="' . esc_attr((string) $kind) . '">';
            if ($kind === 'booking') echo '<input type="hidden" name="confirmation_uid" value="' . esc_attr((string) ($classroom['confirmation_uid'] ?? '')) . '">';
            else echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) ($classroom['lesson_id'] ?? 0)) . '">';
            echo '<button class="button" type="submit">Open backup Google Meet</button></form>';
            echo '<button type="button" class="button" data-dzn-meet-copy data-kind="' . esc_attr((string) $kind) . '"' . ($kind === 'booking' ? ' data-confirmation-uid="' . esc_attr((string) ($classroom['confirmation_uid'] ?? '')) . '"' : ' data-lesson-id="' . esc_attr((string) ($classroom['lesson_id'] ?? 0)) . '"') . ' data-endpoint="' . esc_url(rest_url('delnavazan-platform/v1/admin/classroom/meet-link')) . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">Copy Meet link</button>';
        }
        echo '</div><p class="description">Copied participant links carry no administrator ticket. They retain normal sign-in, identity and joining-window rules. Administrator presence is never attendance evidence.</p></div>';
    }

    /** Shared class action component for introductory and enrolled classes. */
    private static function classActions(array $class): void {
        $canonical = (string) $class['record_model'] === 'canonical_term_lesson_v1';
        echo '<section class="dzn-enrolment__section dzn-class-actions" id="dzn-class-actions"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Shared class action component</p><h2>Manage this class</h2></div></div>';
        if ($canonical) {
            if ($class['state'] === 'authorised' && current_user_can('dzn_manage_canonical_lesson_schedules')) self::rescheduleForm($class);
            if (current_user_can('dzn_manage_teacher_assignments') && $class['enrolment_id'] > 0 && $class['assignment_id'] > 0) self::teacherChangeForm($class);
            if (current_user_can('dzn_manage_canonical_lessons') && $class['state'] === 'authorised') self::cancelForm($class);
            if (current_user_can('dzn_manage_canonical_lessons') && $class['state'] === 'authorised') self::completeForm($class);
            if (current_user_can('dzn_manage_canonical_lesson_delivery')) self::deliveryForm($class);
        } else {
            if (current_user_can('dzn_manage_lessons')) self::legacyRescheduleForm($class);
            echo '<p class="dzn-inline-note">This is a historical/legacy class. Completion, cancellation and attendance remain on its protected <a href="' . esc_url(admin_url('admin.php?page=dzn-lesson&id=' . (int) $class['id'])) . '">class record</a>.</p>';
        }
        echo '<p class="description">Cancel/archive preserves audit and financial history. Teacher changes require recorded agreement and do not silently move future classes.</p></section>';
    }

    private static function rescheduleForm(array $class): void {
        echo '<details class="dzn-action-panel"><summary><strong>Change date or time</strong><br><small>Conflicts and capacity are checked by the canonical schedule authority.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('class_reschedule', array('class_id' => (int) $class['id'], 'record_model' => (string) $class['record_model']));
        echo '<input type="hidden" name="expected_assignment_id" value="' . esc_attr((string) $class['assignment_id']) . '"><input type="hidden" name="expected_schedule_version_id" value="' . esc_attr((string) $class['schedule_version_id']) . '">';
        self::input('schedule_timezone', 'Timezone', 'text', (string) ($class['timezone'] ?: 'UTC'));
        self::input('local_wall_date', 'Local date', 'date', (string) $class['local_date']);
        self::input('local_wall_time', 'Local start time', 'text', (string) $class['local_time']);
        self::input('reason_code', 'Reason code', 'text', 'operator_reschedule');
        self::input('evidence_reference', 'Evidence reference', 'text', '');
        echo '<button class="button button-primary">Review and save new time</button></form><p class="description">If the Teacher has another class in the occupied interval, the change is refused.</p></div></details>';
    }

    private static function legacyRescheduleForm(array $class): void {
        echo '<details class="dzn-action-panel"><summary><strong>Change date or time</strong><br><small>Uses the existing legacy schedule authority and Teacher occupancy lock.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('class_reschedule', array('class_id' => (int) $class['id'], 'record_model' => (string) $class['record_model']));
        self::input('schedule_timezone', 'Timezone', 'text', (string) ($class['timezone'] ?: 'UTC'));
        self::input('local_wall_date', 'Local date', 'date', (string) $class['local_date']);
        self::input('local_wall_time', 'Local start time', 'text', (string) $class['local_time']);
        self::input('reason_code', 'Reason', 'text', 'operator_reschedule');
        echo '<button class="button button-primary">Save new time</button></form></div></details>';
    }

    private static function teacherChangeForm(array $class): void {
        $read = new EnrolmentWorkspaceReadService();
        echo '<details class="dzn-action-panel"><summary><strong>Change Teacher</strong><br><small>Requires the new Teacher’s agreement and preserves the assignment history.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('class_change_teacher', array('class_id' => (int) $class['id']));
        echo '<input type="hidden" name="enrolment_id" value="' . esc_attr((string) $class['enrolment_id']) . '"><input type="hidden" name="expected_assignment_id" value="' . esc_attr((string) $class['assignment_id']) . '">';
        echo '<p><label>New Teacher<select name="new_teacher_id" required><option value="">Choose a Teacher</option>';
        foreach ($read->teacherOptions('', 200) as $teacher) if ((int) $teacher->id !== (int) $class['teacher_id']) echo '<option value="' . esc_attr((string) $teacher->id) . '">' . esc_html((string) $teacher->display_name . ' · ' . $teacher->email) . '</option>';
        echo '</select></label></p><p><label>Agreement channel<select name="evidence_channel"><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="in_person">In person</option><option value="video_call">Video call</option><option value="other_verified">Other verified</option></select></label></p>';
        self::input('evidence_reference', 'Agreement reference', 'text', '');
        self::input('evidence_at', 'Agreement UTC', 'text', gmdate('Y-m-d H:i:s'));
        echo '<button class="button button-primary">Replace Teacher assignment</button></form><p class="description">The authority refuses this while active future schedules exist. Cancel or reschedule those classes first.</p></div></details>';
    }

    private static function cancelForm(array $class): void {
        echo '<details class="dzn-action-panel dzn-action-panel--danger"><summary><strong>Cancel class</strong><br><small>Uses the canonical lifecycle. Audit, attendance evidence and financial history remain.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('class_cancel', array('class_id' => (int) $class['id']));
        if ((int) ($class['schedule_version_id'] ?? 0) > 0) echo '<input type="hidden" name="expected_schedule_version_id" value="' . esc_attr((string) $class['schedule_version_id']) . '">';
        echo '<p><label>Reason<select name="reason_code"><option value="attested_non_delivery">Attested non-delivery — replacement eligible</option><option value="academy_unavailable">Academy unavailable</option><option value="student_cancelled">Student cancelled</option><option value="teacher_cancelled">Teacher cancelled</option></select></label></p>';
        self::input('evidence_reference', 'Evidence reference', 'text', '');
        echo '<button class="button" onclick="return confirm(\'Cancel this class? The record and its financial history remain.\')">Cancel class</button></form></div></details>';
    }

    private static function completeForm(array $class): void {
        if ((string) ($class['ends_at_utc'] ?? '') > gmdate('Y-m-d H:i:s')) return;
        echo '<details class="dzn-action-panel"><summary><strong>Mark class completed</strong><br><small>Only after the scheduled class ends; finance remains a separate authority.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('class_complete', array('class_id' => (int) $class['id']));
        self::input('evidence_reference', 'Completion evidence reference', 'text', '');
        echo '<button class="button button-primary">Mark completed</button></form></div></details>';
    }

    private static function deliveryForm(array $class): void {
        echo '<details class="dzn-action-panel"><summary><strong>Attendance outcome</strong><br><small>Reviewed evidence only. A clicked link or administrator presence is not proof.</small></summary><div class="dzn-action-panel__body">';
        self::formStart('class_delivery', array('class_id' => (int) $class['id']));
        echo '<input type="hidden" name="lesson_state" value="' . esc_attr((string) ($class['state'] ?: 'authorised')) . '">';
        echo '<p><label>Decision<select name="outcome_code"><option value="delivered">Attended / delivered</option><option value="student_no_show">Student did not attend</option><option value="interruption">Interrupted</option><option value="review_required">Refer for review</option></select></label></p>';
        self::input('reason_code', 'Reason code', 'text', 'administrator_attendance_review');
        self::input('evidence_reference', 'Evidence reference', 'text', '');
        echo '<button class="button button-primary">Record attendance decision</button></form><p class="description">Replacement and Teacher-pay consequences follow the existing canonical rules.</p></div></details>';
    }

    private static function attendanceEvidence(array $class): void {
        echo '<section class="dzn-enrolment__section"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Evidence</p><h2>Attendance evidence</h2></div></div>';
        $attendance = $class['attendance_evidence'] ?? array('case' => null, 'evidence' => array(), 'decisions' => array(), 'anomalies' => array());
        if (empty($attendance['case'])) {
            echo '<div class="dzn-empty-state dzn-empty-state--compact"><p><strong>No canonical attendance case.</strong> ' . esc_html((string) $class['attendance']['detail']) . '</p></div>';
            if (current_user_can('dzn_manage_canonical_attendance_review')) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-attendance-review')) . '">Open protected attendance review</a></p>';
            return;
        }
        $case = $attendance['case'];
        echo '<dl class="dzn-facts"><dt>Case state</dt><dd><strong>' . esc_html((string) $case->state) . '</strong> · version ' . esc_html((string) $case->case_version) . '</dd><dt>Occurrence</dt><dd>' . esc_html((string) $case->occurrence_start_utc . ' – ' . $case->occurrence_end_utc) . '</dd><dt>Review window</dt><dd>' . esc_html((string) $case->window_end_utc) . '</dd></dl>';
        if ($attendance['evidence']) {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Evidence</th><th>Participant</th><th>Identity</th><th>Verification</th><th>Join / leave</th><th>Missing / disputed</th></tr></thead><tbody>';
            foreach ($attendance['evidence'] as $evidence) {
                echo '<tr><td>' . esc_html((string) $evidence['evidence_kind']) . '<br><small>' . esc_html((string) $evidence['source_kind']) . '</small></td><td>' . esc_html((string) $evidence['participant_role']) . '</td><td>' . esc_html((string) $evidence['identity_state']) . '</td><td>' . esc_html((string) $evidence['verification_state']) . '</td><td>' . esc_html((string) ($evidence['join_at_utc'] ?: '—') . ' / ' . ($evidence['leave_at_utc'] ?: '—')) . '</td><td>' . (($evidence['missing'] ?? false) ? '<strong>Missing or unverified</strong>' : esc_html((string) $evidence['reason_code'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p>No participant evidence exists. Missing evidence is not attendance.</p>';
        }
        if ($attendance['anomalies']) {
            echo '<p class="dzn-warning-list">Anomalies: ';
            echo esc_html(implode(', ', array_map(static fn(object $row): string => (string) $row->code, $attendance['anomalies'])));
            echo '</p>';
        }
        if (current_user_can('dzn_manage_canonical_attendance_review')) {
            echo '<details class="dzn-action-panel"><summary><strong>Make reviewed attendance decision</strong></summary><div class="dzn-action-panel__body">';
            self::formStart('class_attendance', array('class_id' => (int) $class['id'], 'case_id' => (int) $case->id));
            echo '<input type="hidden" name="expected_case_version" value="' . esc_attr((string) $case->case_version) . '">';
            echo '<p><label>Decision<select name="adjudication"><option value="settle_delivered">Confirm attended and settle delivered</option><option value="record_no_change">Record absence / no canonical change</option><option value="review_required">Refer for review</option><option value="teacher_non_delivery">Teacher/academy non-delivery</option><option value="reassess">Reassess evidence</option></select></label></p>';
            echo '<button class="button button-primary" onclick="return confirm(\'Apply this attendance decision against the reviewed case version?\')">Apply reviewed decision</button></form><p class="description">Duplicate or conflicting settlement is refused by the canonical idempotency and case-version checks.</p></div></details>';
        }
        echo '</section>';
    }

    private static function scheduleHistory(array $class): void {
        echo '<section class="dzn-enrolment__section"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">History</p><h2>Schedule versions</h2></div></div>';
        if (empty($class['schedule_versions'])) echo '<p>No schedule versions recorded.</p>';
        else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Version</th><th>Local time</th><th>Timezone</th><th>UTC</th><th>Reason</th><th>Current</th></tr></thead><tbody>';
            foreach ($class['schedule_versions'] as $version) echo '<tr><td>' . esc_html((string) $version['version']) . '</td><td>' . esc_html((string) $version['local_date'] . ' ' . $version['local_time']) . '</td><td>' . esc_html((string) $version['timezone']) . '</td><td>' . esc_html((string) $version['starts_at_utc']) . '</td><td>' . esc_html((string) $version['reason']) . '</td><td>' . (!empty($version['active']) ? 'Yes' : 'No') . '</td></tr>';
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }

    private static function assignmentHistory(array $class): void {
        if (empty($class['assignment_history'])) return;
        echo '<section class="dzn-enrolment__section"><div class="dzn-enrolment__section-head"><div><p class="dzn-eyebrow">Teacher history</p><h2>Assignment history</h2></div></div><div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Sequence</th><th>Teacher</th><th>State</th><th>Assigned</th><th>Ended</th></tr></thead><tbody>';
        foreach ($class['assignment_history'] as $row) echo '<tr><td>' . esc_html((string) $row->assignment_sequence) . '</td><td>' . esc_html((string) ($row->teacher_name ?: 'Teacher')) . '</td><td>' . esc_html((string) $row->state) . '</td><td>' . esc_html((string) $row->assigned_at) . '</td><td>' . esc_html((string) ($row->terminated_at ?: '—')) . '</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function classTermPanel(array $class): void {
        echo '<section class="dzn-case-card"><h2>Term &amp; entitlement</h2><dl class="dzn-facts">';
        self::fact('Term', (string) ($class['term_reference'] ?: 'Not recorded'));
        self::fact('Progress', (string) $class['term_progress']);
        self::fact('Remaining classes', (string) $class['remaining_classes']);
        self::fact('Replacement allowance', (string) $class['replacement_allowance']);
        self::fact('Replacement lessons used', (string) $class['replacement_used']);
        self::fact('Enrolment', (string) ($class['enrolment_reference'] ?: 'Not recorded'), (string) $class['enrolment_state']);
        if (!empty($class['import_evidence'])) {
            $import = $class['import_evidence'];
            self::fact('Imported completed classes', (string) (int) ($import['completed_classes'] ?? 0), 'Historical status: ' . str_replace('_', ' ', (string) ($import['attendance_evidence_state'] ?? 'unknown')));
            self::fact('Imported payment status', (string) ($import['payment_status'] ?? 'unknown'), !empty($import['payment_claimed_paid']) ? 'Paid was explicitly evidenced at import.' : 'No paid claim was invented.');
        }
        echo '</dl>';
        if (current_user_can('dzn_manage_canonical_terms') && $class['term_id'] > 0) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-term&id=' . (int) $class['term_id'])) . '">Open protected Term</a></p>';
        echo '</section>';
    }

    private static function classNotificationPanel(array $class): void {
        $summary = $class['notifications']['summary'] ?? array();
        echo '<section class="dzn-case-card"><h2>Notifications</h2>';
        $labels = array('queued' => 'Queued', 'handed_off' => 'Sent to transport', 'delivery_confirmed' => 'Delivered', 'portal_recorded' => 'Portal recorded', 'failed' => 'Failed', 'closed' => 'Closed');
        $any = false;
        foreach ($labels as $key => $label) {
            if ((int) ($summary[$key] ?? 0) < 1) continue;
            $any = true;
            echo '<span class="dzn-notification-state dzn-notification-state--' . esc_attr($key) . '"><strong>' . esc_html((string) $summary[$key]) . '</strong> ' . esc_html($label) . '</span> ';
        }
        if (!$any) echo '<p>No class notification evidence.</p>';
        echo '<p class="description">Transport acceptance is not recipient delivery.</p></section>';
    }

    private static function timeSummary(array $row): string {
        $parts = array();
        foreach (array_slice((array) $row['requested_times'], 0, 2) as $time) $parts[] = '<span>' . esc_html((string) $time['label']) . '</span>';
        if (!empty($row['confirmed'])) $parts[] = '<strong>Confirmed: ' . esc_html((string) $row['confirmed']['label']) . '</strong>';
        return $parts ? implode('<br>', $parts) : 'No times recorded';
    }

    private static function notificationSummary(array $summary): string {
        $out = array();
        foreach (array('queued' => 'queued', 'handed_off' => 'sent', 'delivery_confirmed' => 'delivered', 'failed' => 'failed', 'unknown' => 'unknown') as $key => $label) {
            if ((int) ($summary[$key] ?? 0) > 0) $out[] = '<span class="dzn-notification-state dzn-notification-state--' . esc_attr($key) . '">' . esc_html((string) $summary[$key]) . ' ' . esc_html($label) . '</span>';
        }
        return $out ? implode(' ', $out) : '<span class="dzn-muted">No evidence</span>';
    }

    private static function paymentLinkLabel(array $postIntro): string {
        if (!empty($postIntro['payment_verified'])) return 'Paid — verified evidence';
        if (($postIntro['checkout_state'] ?? '') !== '') return ucfirst(str_replace('_', ' ', (string) $postIntro['checkout_state'])) . ' · click status unavailable';
        return 'No payment link recorded · click status unavailable';
    }

    private static function countdown(string $utc): string {
        if ($utc === '') return '';
        $seconds = strtotime($utc . ' UTC') - time();
        if ($seconds <= 0) return 'The authoritative deadline has passed.';
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        return 'Countdown from the authoritative record: ' . ($days > 0 ? $days . 'd ' : '') . $hours . 'h remaining.';
    }

    private static function assentFingerprint(int $snapshotId): string {
        if ($snapshotId < 1) return '';
        try {
            $snapshot = (new PostIntroRepository())->currentAssentSnapshot($snapshotId);
            return $snapshot ? (string) $snapshot->arrangement_fingerprint : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function bookingFilters(array $query): array {
        return array(
            'q' => sanitize_text_field((string) ($query['q'] ?? '')),
            'stage' => sanitize_key((string) ($query['stage'] ?? '')),
            'page' => max(1, absint($query['paged'] ?? 1)),
        );
    }

    private static function classFilters(array $query): array {
        return array(
            'q' => sanitize_text_field((string) ($query['q'] ?? '')),
            'teacher_id' => absint($query['teacher_id'] ?? 0),
            'student_id' => absint($query['student_id'] ?? 0),
            'course_id' => absint($query['course_id'] ?? 0),
            'term_id' => absint($query['term_id'] ?? 0),
            'date' => sanitize_text_field((string) ($query['date'] ?? '')),
            'action' => sanitize_key((string) ($query['action'] ?? '')),
            'page' => max(1, absint($query['paged'] ?? 1)),
        );
    }

    private static function pagination(array $filters, array $result, bool $history, string $view): void {
        if ((int) $result['pages'] <= 1) return;
        $args = array('page' => EnrolmentWorkspaceController::PAGE, 'view' => $view);
        foreach ($filters as $key => $value) {
            if ($key === 'page' || $value === '' || $value === 0) continue;
            $args[$key === 'page' ? 'paged' : ($history ? 'history_' . $key : $key)] = $value;
        }
        $args['paged'] = $result['page'];
        echo '<nav class="tablenav dzn-pagination" aria-label="Pagination"><span>Page ' . esc_html((string) $result['page']) . ' of ' . esc_html((string) $result['pages']) . ' · ' . esc_html(number_format_i18n((int) $result['total'])) . ' records</span> ';
        if ($result['page'] > 1) echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($args, array('paged' => $result['page'] - 1)), admin_url('admin.php'))) . '">Previous</a> ';
        if ($result['page'] < $result['pages']) echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($args, array('paged' => $result['page'] + 1)), admin_url('admin.php'))) . '">Next</a>';
        echo '</nav>';
    }

    private static function tabs(string $active): void {
        $tabs = array();
        if (current_user_can(Menu::ENROLMENT_TRIALS_CAP)) {
            $tabs['new'] = 'Intro';
            $tabs['review'] = 'Review';
        }
        if (current_user_can(Menu::ENROLLED_CLASSES_CAP)) $tabs['enrolled'] = 'Enrolled classes';
        if (!$tabs) return;
        echo '<nav class="nav-tab-wrapper dzn-enrolment__tabs" aria-label="Enrolment sections">';
        foreach ($tabs as $key => $label) {
            $url = admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE . '&view=' . rawurlencode($key));
            echo '<a class="nav-tab' . ($active === $key ? ' nav-tab-active' : '') . '" href="' . esc_url($url) . '"' . ($active === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function notices(): void {
        if (isset($_GET['dzn_form'])) return;
        if (isset($_GET['dzn_workspace_notice'])) {
            $message = sanitize_text_field(wp_unslash((string) $_GET['dzn_workspace_notice']));
            $error = (string) ($_GET['dzn_workspace_error'] ?? '') === '1';
            echo '<div class="notice ' . esc_attr($error ? 'notice-error' : 'notice-success') . ' inline" role="' . esc_attr($error ? 'alert' : 'status') . '"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['dzn_notice'])) echo '<div class="notice ' . esc_attr((string) ($_GET['dzn_error'] ?? '') === '1' ? 'notice-error' : 'notice-info') . ' inline"><p>' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['dzn_notice']))) . '</p></div>';
    }

    private static function formStart(string $operation, array $hidden = array()): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-enrolment-form">';
        wp_nonce_field(EnrolmentWorkspaceController::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(EnrolmentWorkspaceController::ACTION) . '"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="idempotency_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
        foreach ($hidden as $name => $value) echo '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '">';
    }

    private static function postForm(string $operation, array $hidden, string $label, string $class = 'secondary', string $confirm = ''): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-inline-form">';
        wp_nonce_field(EnrolmentWorkspaceController::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(EnrolmentWorkspaceController::ACTION) . '"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="idempotency_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
        foreach ($hidden as $name => $value) echo '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '">';
        echo '<button class="button ' . esc_attr($class) . '"' . ($confirm !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '') . '>' . esc_html($label) . '</button></form>';
    }

    private static function input(string $name, string $label, string $type = 'text', string $value = '', bool $required = false, string $description = ''): void {
        echo '<p><label>' . esc_html($label) . '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '></label>';
        if ($description !== '') echo '<br><span class="description">' . esc_html($description) . '</span>';
        echo '</p>';
    }

    private static function studentSelect(string $name, array $students, int $selectedId = 0): void {
        echo '<p><label>Student<select name="' . esc_attr($name) . '" required><option value="">Choose a verified Student</option>';
        foreach ($students as $student) echo '<option value="' . esc_attr((string) $student->id) . '"' . selected($selectedId, (int) $student->id, false) . '>' . esc_html((string) $student->reference_code . ' · ' . $student->display_name . ' · ' . $student->email) . '</option>';
        echo '</select></label></p>';
    }

    private static function courseSelect(string $name, array $courses, int $selectedId = 0): void {
        echo '<p><label>Course<select name="' . esc_attr($name) . '" required><option value="">Choose an active Course</option>';
        foreach ($courses as $course) echo '<option value="' . esc_attr((string) $course->id) . '"' . selected($selectedId, (int) $course->id, false) . '>' . esc_html((string) $course->instrument_name . ' · ' . $course->name . ' · ' . $course->course_type) . '</option>';
        echo '</select></label></p>';
    }

    private static function fact(string $label, string $value, string $note = ''): void {
        echo '<dt>' . esc_html($label) . '</dt><dd>' . ($value === '' ? '<span class="dzn-muted">Not recorded</span>' : esc_html($value));
        if ($note !== '') echo '<br><small>' . esc_html($note) . '</small>';
        echo '</dd>';
    }

    private static function local(string $utc, string $timezone): string {
        if ($utc === '') return 'Not recorded';
        try {
            $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $date->setTimezone(new \DateTimeZone(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC'))->format('D j M Y, H:i') . ' ' . $timezone;
        } catch (\Throwable) {
            return $utc . ' UTC';
        }
    }

    private static function importMessage(string $message): string {
        return match ($message) {
            'An active imported or legacy Enrolment already exists for this Student, Course and Teacher' => 'A duplicate Enrolment already exists for this Student, Course and Teacher.',
            'Choose an active Student', 'Choose an active Teacher', 'Choose an active Course', 'The Teacher is not eligible for this Course' => $message,
            default => ucfirst(str_replace('_', ' ', $message)) . '.',
        };
    }
}
