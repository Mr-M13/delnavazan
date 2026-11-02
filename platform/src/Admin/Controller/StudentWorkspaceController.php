<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer;
use Delnavazan\Platform\Core\Application\CoreReadService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStatusReadService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\StudentWorkspaceFilters;

/**
 * Consolidated Students administration workspace.
 *
 * The directory and profile are one page. Operational context is read from
 * canonical owners; every mutation is a focused RecordAction backed by an
 * application service. No raw identity, token or provider secret is rendered.
 */
final class StudentWorkspaceController {
    private const PAGE = 'dzn-student';

    public static function screen(): void {
        if (!current_user_can('dzn_manage_students')) wp_die('Forbidden', 403);
        self::notices();
        $studentId = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($studentId > 0) self::profile($studentId);
        else self::directory(true);
    }

    private static function directory(bool $withCreate = false): void {
        $filters = StudentWorkspaceFilters::normalize($_GET);
        $result = (new CoreReadService())->studentWorkspaceDirectory($filters);
        $canFinance = current_user_can('dzn_view_finance_authority') || current_user_can('dzn_view_payment_execution_authority');
        $canNotify = current_user_can(NotificationRule::READ_CAPABILITY);

        echo '<div class="wrap dzn-students dzn-students-directory">';
        echo '<header class="dzn-students__hero"><div><p class="dzn-eyebrow">Student administration</p><h1>Students</h1>';
        echo '<p>Recent activity comes first. Students with upcoming lessons, completed lessons, active enrolments and non-expired introductory requests are ranked together from canonical timestamps.</p></div>';
        if ($withCreate && current_user_can('dzn_manage_students')) {
            echo '<div class="dzn-students__hero-actions"><a class="button button-primary" href="#dzn-add-student" onclick="var d=document.getElementById(\'dzn-add-student\');if(d){d.open=true;}">Add student</a></div>';
        }
        echo '</header>';

        if ($withCreate) self::addStudentForm();

        echo '<section class="dzn-students__filters" aria-labelledby="dzn-student-filter-title">';
        echo '<h2 id="dzn-student-filter-title" class="screen-reader-text">Search and filter students</h2>';
        echo '<form method="get" class="dzn-students__filter-form"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">';
        echo '<input type="hidden" name="student_sort" value="' . esc_attr($filters['sort']) . '"><input type="hidden" name="student_order" value="' . esc_attr($filters['direction']) . '">';
        echo '<label class="dzn-students__search"><span>Search students</span><input type="search" name="q" value="' . esc_attr($filters['query']) . '" placeholder="Name, email, phone, student ID, guardian, instrument or teacher"></label>';
        echo '<label><span>Show</span><select name="student_status">';
        foreach (StudentWorkspaceFilters::statusLabels() as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($filters['status'], $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label>';
        echo '<button class="button">Search</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">Reset</a></form>';
        echo '<p class="dzn-students__count"><strong>' . esc_html(number_format_i18n((int) $result['total'])) . '</strong> ' . esc_html($result['total'] === 1 ? 'student' : 'students') . ' · page ' . esc_html((string) $result['page']) . ' of ' . esc_html((string) $result['pages']) . '</p>';
        echo '</section>';

        if (!$result['rows']) {
            echo '<div class="dzn-empty-state"><h2>No students match these filters</h2><p>Try a broader search, choose another status, or clear the filters. Archived students remain searchable from the Archived filter.</p></div>';
            self::pagination($filters, $result);
            echo '</div>';
            return;
        }

        echo '<p class="dzn-table-hint">On a small screen, scroll the table sideways to reach every column.</p>';
        echo '<div class="dzn-table-scroll"><table class="widefat striped dzn-students__table"><thead><tr>';
        echo self::directorySortHeader('name', 'Student', $filters);
        echo '<th scope="col">Contact</th>';
        echo self::directorySortHeader('status', 'Status', $filters);
        echo '<th scope="col">Instrument</th><th scope="col">Teacher</th><th scope="col">Term</th><th scope="col">Lessons</th>';
        echo self::directorySortHeader('renewal_date', 'Renewal', $filters);
        echo self::directorySortHeader('payment_due_date', 'Payment', $filters);
        echo '<th scope="col">Absences</th><th scope="col">Notifications</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($result['rows'] as $student) {
            $url = add_query_arg(array('page' => self::PAGE, 'id' => (int) $student->id), admin_url('admin.php'));
            $accountUrl = $url . '#student-account';
            echo '<tr>';
            echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html((string) $student->student_name) . '</strong></a>';
            if ((string) ($student->reference_code ?? '') !== '') echo '<br><small>' . esc_html((string) $student->reference_code) . '</small>';
            if ((string) ($student->email ?? '') !== '') echo '<br><small>' . esc_html((string) $student->email) . '</small>';
            echo '</td>';
            echo '<td>' . esc_html((string) $student->contact_label) . '<br><small>' . esc_html((string) $student->location_label) . '<br>' . esc_html((string) ($student->timezone ?? 'Time zone not recorded')) . '</small></td>';
            echo '<td>' . self::statusBadge((string) $student->directory_status_label) . '</td>';
            echo '<td>' . esc_html((string) $student->instrument_label) . '</td>';
            echo '<td>' . esc_html((string) $student->teacher_label) . '</td>';
            echo '<td>' . esc_html((string) $student->term_label) . '</td>';
            echo '<td>' . esc_html((string) $student->lessons_label) . '</td>';
            echo '<td>' . esc_html((string) $student->renewal_label) . '</td>';
            echo '<td>' . ($canFinance ? esc_html((string) $student->payment_label) : '<span class="dzn-muted">Restricted</span>') . '</td>';
            echo '<td>' . esc_html((string) $student->absence_label) . '</td>';
            echo '<td>' . ($canNotify ? self::deliveryBadge((string) $student->notification_label, (string) $student->notification_state) : '<span class="dzn-muted">Restricted</span>') . '<br><small>Last activity ' . esc_html((string) $student->last_activity_label) . '</small></td>';
            echo '<td class="dzn-students__row-actions"><div class="dzn-row-actions"><a class="button" href="' . esc_url($url) . '">Manage</a> <a class="button-link" href="' . esc_url($accountUrl) . '">Account</a>';
            if ((string) ($student->archived_at ?? '') !== '' || (string) ($student->status ?? '') === 'archived') {
                RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-restore'));
            } else {
                RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-archive'));
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($filters, $result);
        echo '</div>';
    }

    private static function profile(int $studentId): void {
        $lessonPage = isset($_GET['lesson_page']) ? max(1, absint($_GET['lesson_page'])) : 1;
        $profile = (new CoreReadService())->studentWorkspaceProfile($studentId, $lessonPage, 50);
        if (!$profile) {
            echo '<div class="wrap dzn-students"><p><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">← Back to Students</a></p><div class="dzn-empty-state"><h1>Student not found</h1><p>The student record may have been removed or is no longer available.</p></div></div>';
            return;
        }
        $student = $profile['student'];
        $summary = $profile['summary'];
        $name = self::studentName($student);
        $status = StudentWorkspaceFilters::directoryStatus(
            (string) ($student->status ?? ''),
            ($student->archived_at ?? null) !== null,
            self::hasActiveIntro($profile['intro_requests'] ?? array()),
            (bool) (($summary['enrolment'] ?? null) !== null)
        );
        $id = (int) $student->id;

        echo '<div class="wrap dzn-students dzn-students-profile">';
        echo '<p class="dzn-back"><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">← Back to Students</a></p>';
        echo '<header class="dzn-student-header"><div class="dzn-student-header__identity">';
        echo '<p class="dzn-eyebrow">Student</p><h1>' . esc_html($name) . '</h1>';
        echo '<p class="dzn-student-header__meta">' . esc_html((string) ($student->reference_code ?: 'No public ID')) . ' · ' . esc_html((string) ($student->email ?: 'No email')) . ' · ' . esc_html((string) ($student->timezone ?: 'Time zone not recorded')) . '</p>';
        echo '<p>' . self::statusBadge($status);
        if ((string) ($summary['enrolment']->course_name ?? '') !== '') echo ' <span class="dzn-chip">' . esc_html((string) $summary['enrolment']->course_name) . '</span>';
        if ((string) ($summary['enrolment']->teacher_name ?? '') !== '') echo ' <span class="dzn-chip">' . esc_html((string) $summary['enrolment']->teacher_name) . '</span>';
        if (($summary['next_lesson_at'] ?? null) !== null) echo ' <span class="dzn-chip dzn-chip--soft">Next ' . esc_html(self::human((string) $summary['next_lesson_at'])) . '</span>';
        echo '</p></div><div class="dzn-student-header__actions">';
        RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-profile'));
        if ((string) ($student->archived_at ?? '') !== '' || (string) ($student->status ?? '') === 'archived') {
            RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-restore'));
        } else {
            RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-archive'));
        }
        echo '</div></header>';

        echo '<div class="dzn-student-layout"><aside class="dzn-student-rail"><nav aria-label="Student profile sections"><strong>Profile sections</strong><ol>';
        $sections = array(
            'student-details' => 'Student details',
            'student-account' => 'Identity, account & guardian',
            'student-enrolment' => 'Enrolment & introductory lessons',
            'student-courses' => 'Courses, instruments & teachers',
            'student-terms' => 'Terms, progress & renewals',
            'student-lessons' => 'Lessons, attendance & absences',
            'student-finance' => 'Payments, instalments & discounts',
            'student-notifications' => 'Notifications & communication',
            'student-recording' => 'Recording, consent & learning',
            'student-tasks' => 'Tasks & resolutions',
            'student-actions' => 'Administrative actions',
        );
        foreach ($sections as $anchor => $label) echo '<li><a href="#' . esc_attr($anchor) . '">' . esc_html($label) . '</a></li>';
        echo '</ol></nav></aside><main class="dzn-student-main">';

        self::detailsSection($profile);
        self::accountSection($profile);
        self::enrolmentSection($profile);
        self::coursesSection($profile);
        self::termsSection($profile);
        self::lessonsSection($profile);
        self::financeSection($profile);
        self::notificationsSection($profile);
        self::recordingSection($profile);
        self::tasksSection($profile);
        self::actionsSection($profile);

        echo '</main></div></div>';
    }

    private static function detailsSection(array $profile): void {
        $student = $profile['student'];
        self::sectionOpen('student-details', 'Student details', 'The public identity used by the academy. Internal account identity is managed separately below.');
        $rows = array(
            'Student name' => trim((string) ($student->first_name ?? '') . ' ' . (string) ($student->last_name ?? '')),
            'Display / preferred name' => (string) ($student->display_name ?? ''),
            'Email' => (string) ($student->email ?? ''),
            'Phone' => (string) ($student->phone ?? ''),
            'WhatsApp' => (string) ($student->whatsapp_phone ?? ''),
            'Country' => (string) ($student->country_code ?? ''),
            'City' => (string) ($student->city ?? ''),
            'Time zone' => (string) ($student->timezone ?? ''),
            'Preferred language' => (string) ($student->locale ?? ''),
            'Calendar preference' => (string) ($student->calendar_preference ?? ''),
            'Student status' => (string) ($student->status ?? ''),
            'Public student ID' => (string) ($student->reference_code ?? ''),
            'Enrolment date' => self::human((string) ($profile['summary']['enrolment']->started_at ?? '')),
        );
        self::kv($rows);
        if (current_user_can('dzn_manage_students')) {
            echo '<div class="dzn-actions">';
            RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-profile'));
            echo '</div>';
        } else {
            echo '<p class="dzn-muted">Profile editing requires student maintenance access.</p>';
        }
        self::sectionClose();
    }

    private static function accountSection(array $profile): void {
        $student = $profile['student'];
        $account = $profile['account'];
        $identity = $profile['identity'];
        self::sectionOpen('student-account', 'Identity, account & guardian', 'Account linkage, proof of ownership, guardian authority and only the anomalies that actually exist.');
        echo '<div class="dzn-account-grid"><div class="dzn-card"><h3>Account status</h3>';
        self::kv(array(
            'Portal account' => $account['user'] ? self::accountLabel($account['user']) : 'Not linked',
            'Account status' => $account['status'] === 'linked' ? 'Linked and active' : 'No active portal link',
            'Email ownership' => self::emailVerificationLabel((string) $account['email_verification']),
            'Claim invitation' => $account['claim'] ? self::claimLabel($account['claim']) : 'No account-claim invitation',
            'Linked since' => $account['principal'] ? self::human((string) $account['principal']['linked_at']) : '',
            'Age / authority category' => $identity['available'] ? ucfirst((string) ($identity['capacity']['classification'] ?? 'Not reviewed')) : 'Restricted',
        ));
        echo '<div class="dzn-actions">';
        if (current_user_can('dzn_manage_students')) RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-send-password-reset'));
        if (current_user_can('dzn_issue_student_account_claims') && !$account['user']) RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-issue-account-claim'));
        if (current_user_can('dzn_manage_student_acceptance_authority')) {
            if ($account['user']) RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-deactivate-account','student-supersede-account'));
            else RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-link-account'));
            RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-classify-capacity'));
        }
        echo '</div>';
        echo '<p class="dzn-note">Password reset uses the existing WordPress reset workflow. The interface never displays, retrieves or sets an existing password and does not claim delivery until evidence exists.</p>';
        echo '</div>';

        echo '<div class="dzn-card"><h3>Guardian authority</h3>';
        if (!$identity['available']) {
            echo '<p class="dzn-muted">Guardian and identity details require student acceptance-eligibility authority.</p>';
        } elseif (!$profile['guardians']) {
            echo '<p class="dzn-empty-inline">No guardian representative authority is recorded. This is not automatically a problem; check the reviewed age category above.</p>';
        } else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Guardian</th><th>Relationship / authority</th><th>Status</th><th>Effective</th><th>Actions</th></tr></thead><tbody>';
            foreach ($profile['guardians'] as $guardian) {
                echo '<tr><td><strong>' . esc_html((string) $guardian['display_name']) . '</strong><br><small>' . esc_html((string) $guardian['email']) . '</small></td>';
                echo '<td>' . esc_html((string) $guardian['relationship']) . '<br><small>' . esc_html(ucfirst(str_replace('_', ' ', (string) $guardian['authority_scope']))) . '</small></td>';
                echo '<td>' . esc_html($guardian['active'] ? 'Active' : ucfirst((string) $guardian['state'])) . '</td>';
                echo '<td>' . esc_html(self::human((string) $guardian['effective_from'])) . ($guardian['effective_until'] ? '<br><small>until ' . esc_html(self::human((string) $guardian['effective_until'])) . '</small>' : '') . '</td>';
                echo '<td>';
                if ($guardian['active'] && current_user_can('dzn_manage_student_acceptance_authority')) {
                    RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-guardian-supersede','student-guardian-revoke'));
                } elseif (!$guardian['active']) echo '<span class="dzn-muted">Historical</span>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($identity['available'] && current_user_can('dzn_manage_student_acceptance_authority')) {
            echo '<div class="dzn-actions">';
            RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-guardian-grant'));
            echo '</div>';
            echo '<p class="dzn-note">Guardian linkage uses the existing representative-authority service. It never transfers authority between unrelated accounts and never bypasses verified identity evidence.</p>';
        }
        echo '</div></div>';

        if ($identity['available']) {
            echo '<div class="dzn-identity-review"><h3>Identity review</h3>';
            if (!$identity['anomalies']) {
                echo '<p class="dzn-state dzn-state--ok">No identity anomaly is recorded for this student.</p>';
            } else {
                foreach ($identity['anomalies'] as $anomaly) {
                    echo '<article class="dzn-anomaly" id="' . esc_attr('dzn-anomaly-' . sanitize_key((string) $anomaly['code'])) . '"><h4>' . esc_html((string) $anomaly['title']) . '</h4><p>' . esc_html((string) $anomaly['description']) . '</p>';
                    if (!empty($anomaly['evidence'])) self::kv($anomaly['evidence']);
                    if ($anomaly['options']) {
                        echo '<p><strong>Valid choices</strong></p><ul>';
                        foreach ($anomaly['options'] as $option) echo '<li>' . esc_html((string) $option) . '</li>';
                        echo '</ul>';
                    }
                    if (!empty($anomaly['action_keys'])) {
                        echo '<div class="dzn-actions">';
                        RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, (array) $anomaly['action_keys']);
                        echo '</div>';
                    }
                    if (!empty($anomaly['central']) && is_array($anomaly['central'])) {
                        $central = $anomaly['central'];
                        echo '<p><a class="button" href="' . esc_url(self::centralUrl((string) $central['page'], (array) ($central['args'] ?? array()), 'student-account')) . '">' . esc_html((string) $central['label']) . '</a></p>';
                    }
                    if ((string) $anomaly['code'] === 'duplicate_identity_candidate' && $identity['candidates']) {
                        echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Candidate student</th><th>Public ID</th><th>Email</th><th>Phone</th><th>Status</th></tr></thead><tbody>';
                        foreach ($identity['candidates'] as $candidate) {
                            $candidateUrl = add_query_arg(array('page' => self::PAGE, 'id' => (int) $candidate['id']), admin_url('admin.php'));
                            echo '<tr><td><a href="' . esc_url($candidateUrl) . '">' . esc_html((string) $candidate['display_name']) . '</a></td><td>' . esc_html((string) $candidate['reference_code']) . '</td><td>' . esc_html((string) $candidate['email']) . '</td><td>' . esc_html((string) $candidate['phone']) . '</td><td>' . esc_html($candidate['archived'] ? 'Archived' : ucfirst((string) $candidate['status'])) . '</td></tr>';
                        }
                        echo '</tbody></table></div>';
                    }
                    if ((string) $anomaly['code'] === 'booking_identity_candidate' && $identity['pending_booking_matches']) {
                        echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Matching request</th><th>Instrument</th><th>Created</th><th>State</th></tr></thead><tbody>';
                        foreach ($identity['pending_booking_matches'] as $request) {
                            echo '<tr><td>' . esc_html((string) ($request->reference_code ?: 'Introductory request')) . '</td><td>' . esc_html((string) ($request->instrument_name ?? 'Not recorded')) . '</td><td>' . esc_html(self::human((string) $request->created_at)) . '</td><td>' . esc_html(ucfirst(str_replace('_', ' ', (string) $request->resolution_state))) . '</td></tr>';
                        }
                        echo '</tbody></table></div>';
                    }
                    if ((string) $anomaly['code'] === 'booking_identity_unresolved' && $identity['unresolved_booking_requests']) {
                        echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Request</th><th>Instrument</th><th>Created</th><th>State</th></tr></thead><tbody>';
                        foreach ($identity['unresolved_booking_requests'] as $request) {
                            echo '<tr><td>' . esc_html((string) ($request->reference_code ?: 'Introductory request')) . '</td><td>' . esc_html((string) ($request->instrument_name ?? 'Not recorded')) . '</td><td>' . esc_html(self::human((string) $request->created_at)) . '</td><td>' . esc_html(ucfirst(str_replace('_', ' ', (string) $request->resolution_state))) . '</td></tr>';
                        }
                        echo '</tbody></table></div>';
                    }
                    echo '</article>';
                }
            }
            echo '<p class="dzn-note">Anomalies appear only from canonical mismatches or missing evidence. Candidate records are never merged automatically.</p></div>';
        }
        self::sectionClose();
    }

    private static function enrolmentSection(array $profile): void {
        self::sectionOpen('student-enrolment', 'Enrolment & introductory lessons', 'The student’s own introductory requests and enrolments. Central booking and enrolment queues remain under Enrolments.');
        echo '<h3>Introductory lesson requests</h3>';
        if (!$profile['intro_requests']) {
            echo '<p class="dzn-empty-inline">No introductory lesson request is linked to this student.</p>';
        } else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Request</th><th>Instrument / course</th><th>Teacher</th><th>Preferred times</th><th>Status</th><th>Expiry</th><th>Next action</th><th>Open</th></tr></thead><tbody>';
            foreach ($profile['intro_requests'] as $request) {
                echo '<tr><td><strong>' . esc_html((string) ($request->reference_code ?: 'Introductory request')) . '</strong><br><small>' . esc_html(self::human((string) $request->created_at)) . '</small></td>';
                echo '<td>' . esc_html((string) ($request->instrument_name ?? 'Instrument not recorded')) . '<br><small>' . esc_html((string) ($request->course_name ?? '')) . '</small></td>';
                echo '<td>' . esc_html((string) ($request->teacher_name ?? 'Not assigned')) . '</td>';
                echo '<td>' . esc_html((string) ($request->preferred_times ?: 'No preference recorded')) . '</td>';
                echo '<td>' . esc_html((string) $request->status_label) . '</td>';
                echo '<td>' . esc_html((string) ($request->expires_label ?: '—')) . '</td>';
                echo '<td>' . esc_html((string) $request->next_action) . '</td><td>';
                echo '<a class="button" href="' . esc_url(self::centralUrl('dzn-booking-requests', array('request_id' => (int) $request->id), 'student-enrolment')) . '">Open request</a>';
                if ((string) ($request->coordination_case_id ?? '') !== '') echo ' <a class="button" href="' . esc_url(self::centralUrl('dzn-booking-request-coordination', array('case_id' => (int) $request->coordination_case_id), 'student-enrolment')) . '">Coordination</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if (current_user_can(Menu::ENROLMENT_WORKSPACE_CAP)) {
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-enrolment-workspace')) . '">Open the central bookings & enrolments workspace</a></p>';
        }
        echo '<h3>Enrolment journey</h3>';
        if (!$profile['enrolments']) {
            echo '<p class="dzn-empty-inline">No enrolment is recorded yet. An eligible introductory outcome can start the canonical enrolment workflow.</p>';
        } else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Course / instrument</th><th>Teacher</th><th>Status</th><th>Started</th><th>Current term</th><th>Reference</th><th>Open</th></tr></thead><tbody>';
            foreach ($profile['enrolments'] as $enrolment) {
                echo '<tr><td>' . esc_html((string) ($enrolment->course_name ?? 'Course not recorded')) . '<br><small>' . esc_html((string) ($enrolment->instrument_name ?? '')) . '</small></td>';
                echo '<td>' . esc_html((string) ($enrolment->teacher_name ?? 'Not assigned')) . '</td>';
                echo '<td>' . esc_html((string) $enrolment->status_label) . '</td>';
                echo '<td>' . esc_html(self::human((string) ($enrolment->started_at ?? ''))) . '</td>';
                echo '<td>' . esc_html((string) $enrolment->term_label) . '</td>';
                echo '<td>' . esc_html((string) ($enrolment->reference_code ?? '')) . '</td>';
                echo '<td><a class="button" href="' . esc_url(self::centralUrl('dzn-enrolment', array('id' => (int) $enrolment->id), 'student-enrolment')) . '">Open enrolment</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<div class="dzn-actions">';
        if (current_user_can('dzn_manage_canonical_enrolment_lifecycle')) RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, array('student-enrolment-transition'));
        echo '<a class="button" href="' . esc_url(self::centralUrl('dzn-enrolment', array('student_id' => (int) $profile['student']->id), 'student-enrolment')) . '">Start / change enrolment in Enrolments</a>';
        echo '</div><p class="dzn-note">New course or re-enrolment starts in the canonical Enrolments workflow. The central list is filtered to this student and carries a return link.</p>';
        self::sectionClose();
    }

    private static function coursesSection(array $profile): void {
        self::sectionOpen('student-courses', 'Courses, instruments & teachers', 'Current and historical course relationships. Teacher changes use the canonical Teacher Assignment authority.');
        if (!$profile['enrolments']) {
            echo '<p class="dzn-empty-inline">No current or historical course assignment is recorded.</p>';
        } else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Instrument</th><th>Course</th><th>Teacher</th><th>Enrolment status</th><th>Current term</th><th>Started</th></tr></thead><tbody>';
            foreach ($profile['enrolments'] as $enrolment) {
                echo '<tr><td>' . esc_html((string) ($enrolment->instrument_name ?? 'Not recorded')) . '</td><td>' . esc_html((string) ($enrolment->course_name ?? 'Not recorded')) . '</td><td>' . esc_html((string) ($enrolment->teacher_name ?? 'Not assigned')) . '</td><td>' . esc_html((string) $enrolment->status_label) . '</td><td>' . esc_html((string) $enrolment->term_label) . '</td><td>' . esc_html(self::human((string) ($enrolment->started_at ?? ''))) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if (current_user_can('dzn_manage_teacher_assignments')) {
            RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, array('student-reassign-teacher'));
            echo '<p class="dzn-note">A Teacher replacement is refused while an active future schedule exists. Reschedule or release those classes first. Course and instrument changes remain in the canonical Enrolments workspace.</p>';
        }
        self::sectionClose();
    }

    private static function termsSection(array $profile): void {
        $summary = $profile['summary'];
        $term = $summary['term'];
        self::sectionOpen('student-terms', 'Terms, progress & renewals', 'Lesson entitlement and renewal facts are read from canonical Terms and Renewal Cycles.');
        if (!$term) {
            echo '<p class="dzn-empty-inline">No current Term is recorded for this student.</p>';
        } else {
            $progress = $summary['term_progress'];
            echo '<div class="dzn-metric-grid">';
            self::metric('Current term', 'Term ' . (int) $term->sequence_number, ucfirst(str_replace('_', ' ', (string) $term->lifecycle_state)));
            self::metric('Completed', (string) (int) $progress['completed'], 'of ' . (int) $progress['allocation'] . ' entitled lessons');
            self::metric('Remaining', (string) (int) $progress['remaining'], 'not yet completed');
            self::metric('Scheduled', (string) (int) $progress['scheduled'], 'upcoming entitled lessons');
            self::metric('Replacements', (string) (int) $progress['replacement_remaining'], 'of ' . (int) $progress['replacement_allowance'] . ' make-up slots available');
            self::metric('Renewal', $summary['renewal'] ? ucfirst(str_replace('_', ' ', (string) $summary['renewal']['state'])) : 'No cycle', $summary['renewal'] ? self::human((string) ($summary['renewal']['guarantee_deadline_at'] ?: $summary['renewal']['boundary_at'])) : '');
            echo '</div>';
        }
        echo '<h3>Term history</h3>';
        if (!$profile['terms']) {
            echo '<p class="dzn-empty-inline">No Term history.</p>';
        } else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Term</th><th>Course</th><th>State</th><th>Progress</th><th>Replacement allowance</th><th>Payment state</th><th>Open</th></tr></thead><tbody>';
            foreach ($profile['terms'] as $row) {
                echo '<tr><td><strong>Term ' . esc_html((string) $row->sequence_number) . '</strong><br><small>' . esc_html((string) ($row->reference_code ?? '')) . '</small></td><td>' . esc_html((string) ($row->course_name ?? '')) . '</td><td>' . esc_html((string) $row->state_label) . '</td><td>' . esc_html((string) $row->progress_label) . '</td><td>' . esc_html((string) (int) $row->replacement_allowance . ' allowed · ' . (int) $row->replacement_count . ' used') . '</td><td>' . esc_html(ucfirst(str_replace('_', ' ', (string) ($row->payment_state ?? '')))) . '</td><td><a class="button" href="' . esc_url(self::centralUrl('dzn-term', array('id' => (int) $row->id), 'student-terms')) . '">Open term</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<div class="dzn-actions">';
        if (current_user_can('dzn_manage_canonical_terms')) RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, array('student-term-transition'));
        echo '</div>';
        if ($summary['renewal']) {
            echo '<div class="dzn-callout"><h3>Renewal milestone</h3>';
            self::kv(array(
                'Renewal state' => ucfirst(str_replace('_', ' ', (string) $summary['renewal']['state'])),
                'Boundary' => self::human((string) $summary['renewal']['boundary_at']),
                'Guarantee deadline' => self::human((string) ($summary['renewal']['guarantee_deadline_at'] ?? '')),
                'Automatic charge' => self::human((string) ($summary['renewal']['automatic_charge_at'] ?? '')),
                'Next term' => $summary['renewal']['next_term_id'] ? 'Successor term already bound' : 'Not bound',
            ));
            echo '<p class="dzn-note">Renewal reminders are visible in notification history. No renewal Term or financial obligation is created by this workspace.</p>';
            echo '<p><a class="button" href="' . esc_url(self::centralUrl('dzn-enrolment', array('student_id' => (int) $profile['student']->id), 'student-terms')) . '">Review renewal and enrolment in Enrolments</a></p></div>';
        }
        self::sectionClose();
    }

    private static function lessonsSection(array $profile): void {
        $summary = $profile['summary'];
        self::sectionOpen('student-lessons', 'Lessons, attendance & absences', 'Canonical class history and schedule. Cancelled and unattended classes are never counted as completed.');
        $absences = $summary['absences'];
        echo '<div class="dzn-metric-grid">';
        self::metric('Reported absences', (string) (int) $absences['recorded'], (int) $absences['student_reported'] . ' student-reported · ' . (int) $absences['no_show'] . ' recorded no-show');
        self::metric('Make-up eligible', (string) (int) $absences['eligible_makeup'], 'currently allocated');
        self::metric('Used replacements', (string) (int) $absences['used'], 'within the current term allowance');
        self::metric('Remaining', (string) (int) $absences['remaining'], 'replacement entitlement');
        self::metric('Teacher non-delivery', (string) (int) $absences['teacher_non_delivery'], 'academy obligation facts');
        echo '</div>';
        echo '<p class="dzn-note">A reported absence is evidence, not an automatic replacement entitlement. Replacement slots are bounded by the current Term allowance.</p>';

        $lessonSet = $profile['lessons'];
        $groups = array('upcoming'=>'Upcoming lessons','completed'=>'Completed lessons','introductory'=>'Introductory lessons','cancelled'=>'Cancelled or rescheduled','replacement'=>'Replacement lessons','other'=>'Other class records');
        foreach ($groups as $key => $label) {
            $rows = array_values(array_filter($lessonSet['rows'], static fn(object $lesson): bool => (string) $lesson->group === $key));
            if (!$rows) continue;
            $open = in_array($key, array('upcoming','other'), true) ? ' open' : '';
            echo '<details class="dzn-lessons-group"' . $open . '><summary>' . esc_html($label) . ' <span class="dzn-count">' . esc_html(number_format_i18n(count($rows))) . '</span></summary>';
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>When</th><th>Teacher</th><th>Course</th><th>Type</th><th>Attendance</th><th>Term</th><th>Replacement</th><th>Open</th></tr></thead><tbody>';
            foreach ($rows as $lesson) {
                echo '<tr><td><strong>' . esc_html((string) $lesson->when_label) . '</strong><br><small>' . esc_html((string) $lesson->state_label) . '</small></td><td>' . esc_html((string) ($lesson->teacher_name ?? '')) . '</td><td>' . esc_html((string) ($lesson->course_name ?? '')) . '</td><td>' . esc_html((string) $lesson->type_label) . '</td><td>' . esc_html((string) $lesson->attendance_label) . '</td><td>' . esc_html((string) $lesson->term_label) . '</td><td>' . esc_html((string) ($lesson->replacement_label ?: '—')) . '</td><td><a class="button" href="' . esc_url(self::centralUrl('dzn-lesson', array('id' => (int) $lesson->id), 'student-lessons')) . '">Open class</a></td></tr>';
            }
            echo '</tbody></table></div></details>';
        }
        if (!$lessonSet['rows']) echo '<p class="dzn-empty-inline">No class history is recorded.</p>';
        $from = $lessonSet['total'] > 0 ? (($lessonSet['page'] - 1) * $lessonSet['per_page']) + 1 : 0;
        $to = min((int) $lessonSet['total'], (int) $lessonSet['page'] * (int) $lessonSet['per_page']);
        echo '<p class="dzn-note">Showing ' . esc_html((string) $from) . '–' . esc_html((string) $to) . ' of ' . esc_html((string) $lessonSet['total']) . ' class records. Use the page controls to reach older history.</p>';
        if ((int) $lessonSet['pages'] > 1) {
            $base = array('page'=>self::PAGE,'id'=>(int) $profile['student']->id);
            echo '<nav class="tablenav dzn-pagination" aria-label="Lesson history pages"><div class="tablenav-pages">';
            if ((int) $lessonSet['page'] > 1) echo '<a class="button" href="' . esc_url(add_query_arg($base + array('lesson_page'=>(int) $lessonSet['page'] - 1), admin_url('admin.php')) . '#student-lessons') . '">‹ Newer lessons</a> ';
            echo '<span class="displaying-num">Page ' . esc_html((string) $lessonSet['page']) . ' of ' . esc_html((string) $lessonSet['pages']) . '</span>';
            if ((int) $lessonSet['page'] < (int) $lessonSet['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('lesson_page'=>(int) $lessonSet['page'] + 1), admin_url('admin.php')) . '#student-lessons') . '">Older lessons ›</a>';
            echo '</div></nav>';
        }
        echo '<div class="dzn-actions">';
        if (current_user_can('dzn_manage_canonical_lesson_schedules')) RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, array('student-lesson-reschedule'));
        if (current_user_can('dzn_manage_canonical_attendance_review')) RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, array('student-attendance-review'));
        if (current_user_can('dzn_manage_lessons') || current_user_can('dzn_manage_canonical_lesson_schedules')) RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, array('student-reschedule-decision'));
        echo '<a class="button" href="' . esc_url(self::centralUrl('dzn-lesson', array(), 'student-lessons')) . '">Open Classes &amp; schedules</a></div>';
        if ($profile['attendance_cases']) {
            echo '<h3>Attendance cases awaiting review</h3><div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Occurrence</th><th>Course</th><th>State</th><th>Case version</th></tr></thead><tbody>';
            foreach ($profile['attendance_cases'] as $case) echo '<tr><td>' . esc_html(self::human((string) $case->occurrence_start_utc)) . '</td><td>' . esc_html((string) ($case->course_name ?? '')) . '</td><td>' . esc_html(ucfirst(str_replace('_', ' ', (string) $case->state))) . '</td><td>' . esc_html((string) (int) $case->case_version) . '</td></tr>';
            echo '</tbody></table></div>';
        }
        if ($profile['reschedules']) {
            echo '<h3>Pending reschedule proposals</h3><div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Class</th><th>Proposed time</th><th>Teacher</th><th>State</th></tr></thead><tbody>';
            foreach ($profile['reschedules'] as $proposal) echo '<tr><td>' . esc_html((string) ($proposal->course_name ?? 'Class')) . '</td><td>' . esc_html(self::human((string) $proposal->proposed_starts_at_utc)) . ' ' . esc_html((string) $proposal->schedule_timezone) . '</td><td>' . esc_html((string) ($proposal->teacher_name ?? '')) . '</td><td>' . esc_html(ucfirst(str_replace('_', ' ', (string) $proposal->state))) . '</td></tr>';
            echo '</tbody></table></div>';
        }
        self::sectionClose();
    }

    private static function financeSection(array $profile): void {
        $finance = $profile['finance'];
        self::sectionOpen('student-finance', 'Payments, instalments & discounts', 'Confirmed payment evidence and stored obligations only. A checkout attempt is never shown as a completed payment.');
        if (!($finance['available'] ?? false)) {
            echo '<p class="dzn-restricted">' . esc_html((string) ($finance['reason'] ?? 'Finance details are restricted for this account.')) . '</p>';
            self::sectionClose();
            return;
        }
        $s = $finance['summary'];
        echo '<div class="dzn-metric-grid">';
        self::metric('Current term price', self::money((int) $s['current_term_price_minor'], (string) $finance['currency']), (string) ($finance['offer']->course_name ?? 'No current offer'));
        self::metric('Total paid', self::money((int) $s['paid_minor'], (string) $finance['currency']), 'confirmed payment evidence');
        self::metric('Outstanding', self::money((int) $s['outstanding_minor'], (string) $finance['currency']), (string) $s['status']);
        self::metric('Next due', self::human((string) ($s['next_due_at'] ?? '')), (string) ($s['plan_kind'] === 'two_instalments' ? 'Instalment plan' : 'Full payment'));
        self::metric('Refunded', self::money((int) $s['refunded_minor'], (string) $finance['currency']), 'accepted refund evidence');
        self::metric('Payment method', (string) $s['method'], 'provider evidence, never a secret');
        echo '</div>';

        echo '<h3>Instalments &amp; obligations</h3>';
        if (!$finance['obligations']) {
            echo '<p class="dzn-empty-inline">No current obligation is recorded.</p>';
        } else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Instalment</th><th>Sessions</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead><tbody>';
            foreach ($finance['obligations'] as $obligation) {
                $settled = (int) ($obligation->settled_amount ?? 0);
                $overdue = $settled <= 0 && $obligation->due_at !== null && (string) $obligation->due_at < gmdate('Y-m-d H:i:s');
                $label = $settled > 0 ? 'Paid ' . self::human((string) $obligation->settled_at) : ($overdue ? 'Overdue' : 'Due');
                echo '<tr><td>' . esc_html((int) $obligation->obligation_sequence) . '</td><td>' . esc_html((int) $obligation->sessions_from . '–' . (int) $obligation->sessions_to) . '</td><td>' . esc_html(self::money((int) $obligation->amount_minor, (string) $obligation->currency)) . '</td><td>' . esc_html(self::human((string) ($obligation->due_at ?? ''))) . '</td><td>' . esc_html($label) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<h3>Discounts</h3>';
        if (!$finance['discounts']) echo '<p class="dzn-empty-inline">No promotion or discount redemption is recorded.</p>';
        else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Promotion</th><th>Value</th><th>State</th><th>Consumed</th></tr></thead><tbody>';
            foreach ($finance['discounts'] as $discount) {
                $value = (string) $discount->kind === 'percentage' ? ((int) $discount->percentage_bp / 100) . '%' : self::money((int) ($discount->fixed_amount_minor ?? 0), (string) ($discount->promotion_currency ?? ''));
                echo '<tr><td>' . esc_html((string) ($discount->name ?: 'Promotion #' . (int) $discount->id)) . '</td><td>' . esc_html($value) . '</td><td>' . esc_html(ucfirst((string) $discount->state)) . '</td><td>' . esc_html(self::human((string) $discount->consumed_at)) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<h3>Payment history</h3>';
        if (!$finance['payments']) echo '<p class="dzn-empty-inline">No checkout or payment evidence is recorded.</p>';
        else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Evidence</th><th>Amount</th><th>Provider</th><th>Occurred</th></tr></thead><tbody>';
            foreach ($finance['payments'] as $payment) {
                echo '<tr><td>' . esc_html((string) ($payment['label'] ?? ucfirst((string) $payment['kind']))) . '</td><td>' . esc_html($payment['amount_minor'] === null ? '—' : self::money((int) $payment['amount_minor'], (string) $payment['currency'])) . '</td><td>' . esc_html((string) ($payment['provider'] ?: 'Not recorded')) . '</td><td>' . esc_html(self::human((string) ($payment['occurred_at'] ?? ''))) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($finance['intro_payments']) {
            echo '<h3>Introductory payments</h3><div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Reference</th><th>Amount</th><th>State</th><th>Paid</th><th>Failed / refunded</th></tr></thead><tbody>';
            foreach ($finance['intro_payments'] as $row) {
                echo '<tr><td>' . esc_html((string) $row->reference_code) . '</td><td>' . esc_html(self::money((int) $row->amount_minor, (string) $row->currency)) . '</td><td>' . esc_html(ucfirst((string) $row->state)) . '</td><td>' . esc_html(self::human((string) ($row->paid_at ?? ''))) . '</td><td>' . esc_html(self::human((string) ($row->failed_at ?? $row->refunded_at ?? ''))) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<p class="dzn-note">Manual bookkeeping balances are not Student payment records. Only confirmed provider or administrator settlement evidence is counted as paid. Payment-plan changes, settlement and refunds remain with the central Finance authority; this profile does not invent a second payment-plan calculation.</p>';
        if (current_user_can(Menu::FINANCE_WORKSPACE_CAP)) {
            $financeQuery = (string) ($finance['offer']->reference_code ?? $finance['offer']->uid ?? '');
            echo '<p><a class="button" href="' . esc_url(self::centralUrl('dzn-finance', array('tab' => 'payments', 'q' => $financeQuery, 'ob_q' => $financeQuery), 'student-finance')) . '">Open this student in Finance</a></p>';
        }
        self::sectionClose();
    }

    private static function notificationsSection(array $profile): void {
        $notifications = $profile['notifications'];
        self::sectionOpen('student-notifications', 'Notifications & communication', 'Provider and delivery evidence. Queued or handed-off messages are not called delivered.');
        if (!($notifications['available'] ?? false)) {
            echo '<p class="dzn-restricted">' . esc_html((string) ($notifications['reason'] ?? 'Communication details are restricted for this account.')) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<div class="dzn-metric-grid">';
        foreach (($notifications['summary'] ?? array()) as $key => $count) {
            self::metric(ucfirst(str_replace('_', ' ', (string) $key)), (string) (int) $count, 'messages');
        }
        echo '</div>';
        if ($notifications['suppression']) {
            echo '<div class="dzn-callout dzn-callout--warn"><h3>Active communication suppression</h3><p>' . esc_html(ucfirst(str_replace('_', ' ', (string) $notifications['suppression']['purpose']))) . ' · ' . esc_html((string) $notifications['suppression']['reason_code']) . ' · effective ' . esc_html(self::human((string) $notifications['suppression']['effective_from'])) . '</p></div>';
        }
        echo '<h3>Recent notification evidence</h3>';
        if (!$notifications['rows']) echo '<p class="dzn-empty-inline">No canonical notification evidence is recorded.</p>';
        else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Event</th><th>Audience / channel</th><th>Evidence state</th><th>Attempts</th><th>Updated</th></tr></thead><tbody>';
            foreach ($notifications['rows'] as $row) {
                echo '<tr><td>' . esc_html((string) ($row['event_key'] ?? '')) . '</td><td>' . esc_html(trim((string) ($row['audience'] ?? '') . ' · ' . (string) ($row['channel'] ?? 'email'))) . '</td><td>' . self::deliveryBadge(self::deliveryLabel((string) ($row['category'] ?? '')), (string) ($row['category'] ?? '')) . '</td><td>' . esc_html((string) (int) ($row['attempt_count'] ?? 0)) . '</td><td>' . esc_html(self::human((string) ($row['updated_at'] ?? ''))) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($notifications['portal']) {
            echo '<h3>Site inbox</h3><div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Event</th><th>Title</th><th>Read</th><th>Created</th></tr></thead><tbody>';
            foreach ($notifications['portal'] as $row) echo '<tr><td>' . esc_html((string) $row->event_key) . '</td><td>' . esc_html((string) $row->title) . '</td><td>' . esc_html($row->read_at ? self::human((string) $row->read_at) : 'Unread') . '</td><td>' . esc_html(self::human((string) $row->created_at)) . '</td></tr>';
            echo '</tbody></table></div>';
        }
        if ($notifications['channels']) {
            echo '<h3>Messaging channels</h3><div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Channel</th><th>Provider state</th><th>Messages</th></tr></thead><tbody>';
            foreach ($notifications['channels'] as $row) echo '<tr><td>' . esc_html(strtoupper((string) $row->channel)) . '</td><td>' . esc_html(ucfirst((string) $row->state)) . '</td><td>' . esc_html((string) (int) $row->total) . '</td></tr>';
            echo '</tbody></table></div>';
        }
        echo '<p class="dzn-note">' . esc_html((string) $notifications['sms_policy']) . '</p>';
        echo '<p class="dzn-note">This profile does not offer a notification retry button: no per-notification administrator retry authority exists in the current dispatch service. Failed delivery is inspected and retried only through the canonical dispatch/worker policy.</p>';
        if (current_user_can(NotificationRule::READ_CAPABILITY)) echo '<p><a class="button" href="' . esc_url(self::centralUrl('dzn-notifications', array('tab' => 'diagnostics'), 'student-notifications')) . '">Open communication diagnostics</a></p>';
        self::sectionClose();
    }

    private static function recordingSection(array $profile): void {
        $recording = $profile['recording'];
        self::sectionOpen('student-recording', 'Recording, consent & learning content', 'Append-only consent evidence. This workspace does not create a recording, upload content or enable AI processing.');
        echo '<div class="dzn-account-grid">';
        self::consentCard('Class recording', $recording['recording'], $recording['capture_enabled']);
        self::consentCard('AI / research processing', $recording['research'], $recording['research_export_enabled']);
        echo '</div>';
        echo '<p class="dzn-note">Capture is ' . esc_html($recording['capture_enabled'] ? 'enabled by policy' : 'not enabled in this release') . '. Research export is ' . esc_html($recording['research_export_enabled'] ? 'enabled by policy' : 'not enabled in this release') . '. Guardian consent decisions are not available through the current authority.</p>';
        echo '<h3>Consent history</h3>';
        if (!$recording['history']) echo '<p class="dzn-empty-inline">No consent decision is recorded.</p>';
        else {
            echo '<div class="dzn-table-scroll"><table class="widefat striped"><thead><tr><th>Purpose</th><th>Decision</th><th>Decided</th><th>Notice version</th><th>Language</th><th>Guardian actor</th></tr></thead><tbody>';
            foreach ($recording['history'] as $row) {
                echo '<tr><td>' . esc_html(ucfirst(str_replace('_', ' ', (string) $row['purpose']))) . '</td><td>' . esc_html(ucfirst((string) $row['decision'])) . '</td><td>' . esc_html(self::human((string) $row['decided_at'])) . '</td><td>' . esc_html((string) $row['notice_version']) . '</td><td>' . esc_html(strtoupper((string) $row['language'])) . '</td><td>' . esc_html($row['guardian_actor_user_id'] ? 'Guardian representative' : 'Student') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<div class="dzn-callout"><h3>Learning content</h3><p>' . esc_html((string) ($recording['learning_content']['reason'] ?? 'Not available.')) . '</p></div>';
        self::sectionClose();
    }

    private static function tasksSection(array $profile): void {
        self::sectionOpen('student-tasks', 'Tasks & resolutions', 'Unresolved student-specific issues from the canonical task authority and derived blockers. Every action states its consequence.');
        if (!$profile['tasks']) {
            echo '<p class="dzn-state dzn-state--ok">No unresolved student-specific issue is recorded.</p>';
            self::sectionClose();
            return;
        }
        $hasExceptionTask = false;
        foreach ($profile['tasks'] as $task) {
            echo '<article class="dzn-task"><div class="dzn-task__head"><h3>' . esc_html((string) $task['title']) . '</h3><span class="dzn-chip">' . esc_html((string) $task['status']) . '</span></div>';
            echo '<p>' . esc_html((string) $task['description']) . '</p>';
            if ($task['options']) {
                echo '<ul class="dzn-task__options">';
                foreach ($task['options'] as $option) echo '<li>' . esc_html((string) $option) . '</li>';
                echo '</ul>';
            }
            if (($task['source'] ?? '') === 'operational_exception') $hasExceptionTask = true;
            echo '</article>';
        }
        if ($hasExceptionTask && current_user_can('dzn_manage_exceptions')) {
            echo '<div class="dzn-actions dzn-actions--stack">';
            RecordActionRenderer::renderSelected('student', $profile['student'], self::PAGE, array('student-task-acknowledge','student-task-resolve','student-task-dismiss'));
            echo '</div><p class="dzn-note">These transitions use the same Operational Exception authority as the Overview Action Centre.</p>';
        }
        self::sectionClose();
    }

    private static function actionsSection(array $profile): void {
        $student = $profile['student'];
        $archived = (string) ($student->archived_at ?? '') !== '' || (string) ($student->status ?? '') === 'archived';
        $summary = $profile['summary'];
        self::sectionOpen('student-actions', 'Administrative actions', 'Deactivate, archive and reactivate have different meanings. No action cascades deletion of lessons, payments, attendance or consent history.');
        $dependencies = array();
        if (($summary['enrolment'] ?? null) !== null) $dependencies[] = 'Active enrolment: ' . (string) ($summary['enrolment']->course_name ?? 'Course') . ' · ' . ucfirst(str_replace('_', ' ', (string) ($summary['enrolment']->enrolment_state ?? '')));
        if (($summary['next_lesson_at'] ?? null) !== null) $dependencies[] = 'Next scheduled lesson: ' . self::human((string) $summary['next_lesson_at']);
        if (($profile['finance']['available'] ?? false) && (int) ($profile['finance']['summary']['outstanding_minor'] ?? 0) > 0) $dependencies[] = 'Outstanding payment: ' . self::money((int) $profile['finance']['summary']['outstanding_minor'], (string) $profile['finance']['currency']) . ' · ' . (string) $profile['finance']['summary']['status'];
        if (($summary['renewal']['state'] ?? '') !== '') $dependencies[] = 'Renewal cycle: ' . ucfirst(str_replace('_', ' ', (string) $summary['renewal']['state']));
        if ($dependencies) {
            echo '<div class="dzn-callout dzn-callout--warn"><h3>Dependencies to review before deactivating</h3><ul>';
            foreach ($dependencies as $dependency) echo '<li>' . esc_html($dependency) . '</li>';
            echo '</ul></div>';
        }
        echo '<div class="dzn-callout"><h3>Distinct lifecycle meanings</h3><table class="widefat striped"><thead><tr><th>Action</th><th>What it changes</th><th>What it preserves</th><th>Access effect</th></tr></thead><tbody>';
        echo '<tr><td><strong>Deactivate</strong></td><td>Student status becomes inactive.</td><td>Enrolments, lessons, payments, attendance, consent and WordPress user.</td><td>Portal access follows the inactive student state; account links are reviewed separately.</td></tr>';
        echo '<tr><td><strong>Archive</strong></td><td>Student leaves normal directories and becomes archived.</td><td>All protected history and the WordPress user.</td><td>Archiving is refused while operational enrolments depend on the student.</td></tr>';
        echo '<tr><td><strong>Restore</strong></td><td>Archived student returns to the directory as inactive.</td><td>Identity, history and account link evidence.</td><td>Access is not restored automatically.</td></tr>';
        echo '<tr><td><strong>Reactivate</strong></td><td>Inactive student returns to active participation.</td><td>Identity, history and account evidence.</td><td>Portal access still requires an active verified account link.</td></tr>';
        echo '<tr><td><strong>Account access</strong></td><td>Revokes or replaces the Student principal link.</td><td>WordPress user, other roles, owned content and history.</td><td>Only Student access is affected.</td></tr>';
        echo '<tr><td><strong>Re-enrol</strong></td><td>Starts a new canonical enrolment or course change in Enrolments.</td><td>The existing student identity and all history.</td><td>No duplicate student or account is created.</td></tr>';
        echo '</tbody></table></div>';
        echo '<div class="dzn-action-list">';
        echo '<div class="dzn-action-card"><h3>Deactivate</h3><p>Pause current operational participation without deleting history or the WordPress account.</p>';
        if (!$archived) RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-deactivate')); else echo '<p class="dzn-muted">Archived students must be restored first.</p>';
        echo '</div>';
        echo '<div class="dzn-action-card"><h3>Reactivate</h3><p>Return an inactive student to the operational directory. Portal access is not restored automatically.</p>';
        if (!$archived) RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-reactivate')); else echo '<p class="dzn-muted">Use Restore for an archived student.</p>';
        echo '</div>';
        echo '<div class="dzn-action-card"><h3>Archive</h3><p>Remove the student from daily directories while retaining protected history. Archiving is refused while operational enrolments still depend on the record.</p>';
        if (!$archived) RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-archive')); else echo '<p class="dzn-muted">This student is already archived.</p>';
        echo '</div>';
        echo '<div class="dzn-action-card"><h3>Restore</h3><p>Find an archived student and make the profile eligible for a new enrolment. Access remains revoked until explicitly re-established.</p>';
        if ($archived) RecordActionRenderer::renderSelected('student', $student, self::PAGE, array('student-restore')); else echo '<p class="dzn-muted">This student is not archived.</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="dzn-callout"><h3>Re-enrolment and returning students</h3><p>Find the existing student first. Restore or reactivate the same record, review the identity and account, then start a new enrolment through the canonical Enrolments workflow. Never create a second student identity because a previous enrolment ended.</p>';
        if (current_user_can(Menu::ENROLMENT_WORKSPACE_CAP)) echo '<p><a class="button" href="' . esc_url(self::centralUrl('dzn-enrolment', array('student_id' => (int) $student->id), 'student-actions')) . '">Start re-enrolment for this student</a></p>';
        echo '</div>';
        echo '<div class="dzn-callout"><h3>WordPress account handling</h3><p>Student actions never delete a WordPress user. A user may own content, hold guardian authority, carry another role or be referenced by history. Account access is revoked through the Student principal authority only; archival preserves the user and all protected records.</p></div>';
        self::sectionClose();
    }

    private static function addStudentForm(): void {
        echo '<details class="dzn-add-student" id="dzn-add-student"><summary class="button button-primary">Add student</summary>';
        echo '<p>Create a student profile only for a learner already identified by the academy. The form checks for an existing email or phone identity before creating a new record.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">';
        wp_nonce_field('dzn_platform_create_student');
        echo '<input type="hidden" name="dzn_action" value="create_student">';
        echo '<input type="hidden" name="student_form_id" value="' . esc_attr(wp_generate_uuid4()) . '">';
        echo '<div class="dzn-form-grid">';
        self::input('first_name', 'First name');
        self::input('last_name', 'Last name');
        self::input('display_name', 'Display / preferred name', true);
        self::input('email', 'Email', false, 'email');
        self::input('phone', 'Phone');
        self::input('whatsapp_phone', 'WhatsApp');
        self::input('country_code', 'Country code', false, 'text', 'AU');
        self::input('city', 'City');
        self::input('timezone', 'Time zone', false, 'text', 'Australia/Brisbane');
        self::select('locale', 'Preferred language', array('en_AU'=>'English (Australia)','fa_IR'=>'Persian'), 'en_AU');
        self::select('calendar_preference', 'Calendar', array('gregorian'=>'Gregorian','persian'=>'Persian','auto'=>'Automatic'), 'gregorian');
        self::select('status', 'Status', array('active'=>'Active','inactive'=>'Inactive'), 'active');
        echo '</div><p><button class="button button-primary">Create student</button></p></form></details>';
    }

    private static function sectionOpen(string $id, string $title, string $description): void {
        echo '<section id="' . esc_attr($id) . '" class="dzn-student-section"><details open><summary><span>' . esc_html($title) . '</span></summary><div class="dzn-student-section__body"><p class="dzn-section-lede">' . esc_html($description) . '</p>';
    }

    private static function sectionClose(): void {
        echo '</div></details></section>';
    }

    private static function kv(array $rows): void {
        echo '<table class="widefat striped dzn-kv"><tbody>';
        foreach ($rows as $label => $value) {
            $value = trim((string) $value);
            echo '<tr><th scope="row">' . esc_html((string) $label) . '</th><td>' . ($value !== '' ? esc_html($value) : '<span class="dzn-muted">Not recorded</span>') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function metric(string $label, string $value, string $hint = ''): void {
        echo '<div class="dzn-metric"><span class="dzn-metric__label">' . esc_html($label) . '</span><strong class="dzn-metric__value">' . esc_html($value) . '</strong>' . ($hint !== '' ? '<span class="dzn-metric__hint">' . esc_html($hint) . '</span>' : '') . '</div>';
    }

    private static function consentCard(string $label, array $choice, bool $enabled): void {
        echo '<div class="dzn-card"><h3>' . esc_html($label) . '</h3>';
        if ($choice['decision'] === null) {
            echo '<p class="dzn-empty-inline">No decision recorded.</p>';
        } else {
            self::kv(array(
                'Decision' => ucfirst((string) $choice['decision']),
                'Decided' => self::human((string) $choice['decided_at']),
                'Notice version' => (string) $choice['notice_version'],
                'Language' => strtoupper((string) $choice['language']),
                'Processing' => $enabled ? 'Enabled by policy' : 'Not enabled in this release',
            ));
        }
        echo '</div>';
    }

    private static function pagination(array $filters, array $result): void {
        if ((int) $result['pages'] <= 1) return;
        $base = array(
            'page' => self::PAGE,
            'q' => $filters['query'],
            'student_status' => $filters['status'],
            'student_sort' => $filters['sort'],
            'student_order' => $filters['direction'],
        );
        echo '<nav class="tablenav dzn-pagination" aria-label="Student directory pages"><div class="tablenav-pages">';
        if ((int) $result['page'] > 1) echo '<a class="button" href="' . esc_url(add_query_arg($base + array('student_page' => (int) $result['page'] - 1), admin_url('admin.php'))) . '">‹ Previous</a> ';
        echo '<span class="displaying-num">Page ' . esc_html((string) $result['page']) . ' of ' . esc_html((string) $result['pages']) . '</span>';
        if ((int) $result['page'] < (int) $result['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('student_page' => (int) $result['page'] + 1), admin_url('admin.php'))) . '">Next ›</a>';
        echo '</div></nav>';
    }

    private static function statusBadge(string $status): string {
        $tone = match (strtolower($status)) {
            'active' => 'ok',
            'introductory' => 'info',
            'awaiting enrolment' => 'warn',
            'inactive' => 'muted',
            'archived' => 'muted',
            default => 'info',
        };
        return '<span class="dzn-status dzn-status--' . esc_attr($tone) . '">' . esc_html($status) . '</span>';
    }

    private static function deliveryBadge(string $label, string $state): string {
        $tone = $state === 'failed' ? 'bad' : ($state === 'healthy' || $state === 'delivery_confirmed' ? 'ok' : ($state === 'handed_off' ? 'info' : 'muted'));
        return '<span class="dzn-status dzn-status--' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
    }

    private static function deliveryLabel(string $category): string {
        return match ($category) {
            NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED => 'Delivery confirmed',
            NotificationDeliveryStatusReadService::HANDED_OFF => 'Handed off — delivery not confirmed',
            NotificationDeliveryStatusReadService::QUEUED => 'Queued',
            NotificationDeliveryStatusReadService::FAILED => 'Failed',
            NotificationDeliveryStatusReadService::PORTAL_RECORDED => 'Recorded in site inbox',
            default => 'Closed / no delivery evidence',
        };
    }

    private static function hasActiveIntro(array $requests): bool {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($requests as $request) {
            $expiry = (string) ($request->retention_due_at ?? '');
            if ($expiry !== '' && $expiry <= $now) continue;
            if ((string) ($request->lifecycle_status ?? '') === 'submitted') return true;
            if ((string) ($request->lifecycle_status ?? '') === 'closed' && (string) ($request->resolution_state ?? '') === 'teacher_confirmed') return true;
        }
        return false;
    }

    private static function directorySortHeader(string $key, string $label, array $filters): string {
        $next = $filters['sort'] === $key && $filters['direction'] === 'asc' ? 'desc' : 'asc';
        $url = add_query_arg(array(
            'page' => self::PAGE,
            'q' => $filters['query'],
            'student_status' => $filters['status'],
            'student_sort' => $key,
            'student_order' => $next,
            'student_page' => 1,
        ), admin_url('admin.php'));
        $sort = $filters['sort'] === $key ? ' aria-sort="' . ($filters['direction'] === 'asc' ? 'ascending' : 'descending') . '"' : '';
        $arrow = $filters['sort'] === $key ? ($filters['direction'] === 'asc' ? ' ↑' : ' ↓') : '';
        return '<th scope="col"' . $sort . '><a href="' . esc_url($url) . '">' . esc_html($label . $arrow) . '</a></th>';
    }

    private static function studentName(object $student): string {
        $name = trim((string) ($student->display_name ?? ''));
        if ($name !== '') return $name;
        $name = trim((string) ($student->first_name ?? '') . ' ' . (string) ($student->last_name ?? ''));
        return $name !== '' ? $name : 'Student';
    }

    private static function accountLabel(array $user): string {
        return trim((string) $user['display_name']) . ' · ' . (string) $user['email'];
    }

    private static function emailVerificationLabel(string $state): string {
        return match ($state) {
            'verified_by_account_claim' => 'Verified by a completed account claim',
            'not_verified' => 'Not verified by the platform',
            default => 'Claim ' . str_replace('_', ' ', $state),
        };
    }

    private static function claimLabel(array $claim): string {
        $state = ucfirst(str_replace('_', ' ', (string) $claim['state']));
        if ($claim['generation_state']) $state .= ' · generation ' . (string) $claim['generation'];
        if ($claim['expires_at']) $state .= ' · expires ' . self::human((string) $claim['expires_at']);
        return $state;
    }

    private static function money(int $minor, string $currency): string {
        $formatted = number_format_i18n($minor / 100, 2);
        return $currency !== '' ? $currency . ' ' . $formatted : $formatted;
    }

    private static function profileReturnUrl(string $section = ''): string {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $url = add_query_arg(array('page' => self::PAGE, 'id' => $id), admin_url('admin.php'));
        return $section !== '' ? $url . '#' . $section : $url;
    }

    private static function centralUrl(string $page, array $args = array(), string $section = ''): string {
        return add_query_arg(
            array_merge(array('page' => $page), $args, array('dzn_return_to' => self::profileReturnUrl($section))),
            admin_url('admin.php')
        );
    }

    private static function human(string $value): string {
        $value = trim($value);
        if ($value === '') return '—';
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return function_exists('wp_date') ? wp_date('j M Y H:i', $date->getTimestamp(), new \DateTimeZone('UTC')) : $date->format('j M Y H:i');
        } catch (\Throwable) { return $value; }
    }

    private static function input(string $name, string $label, bool $required = false, string $type = 'text', string $value = ''): void {
        echo '<label class="dzn-field"><span>' . esc_html($label) . ($required ? ' *' : '') . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '></label>';
    }

    private static function select(string $name, string $label, array $options, string $value): void {
        echo '<label class="dzn-field"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '">';
        foreach ($options as $optionValue => $optionLabel) echo '<option value="' . esc_attr((string) $optionValue) . '"' . selected($value, (string) $optionValue, false) . '>' . esc_html((string) $optionLabel) . '</option>';
        echo '</select></label>';
    }

    private static function notices(): void {
        RecordActionRenderer::renderNotices();
        if (!isset($_GET['dzn_notice'])) return;
        $message = sanitize_text_field(wp_unslash((string) $_GET['dzn_notice']));
        if ($message === '') return;
        $error = (string) ($_GET['dzn_error'] ?? '') === '1';
        echo '<div class="notice ' . esc_attr($error ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($message) . '</p></div>';
    }
}
