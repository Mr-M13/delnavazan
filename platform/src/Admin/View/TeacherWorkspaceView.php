<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\Menu;
use Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer;
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\TeacherAvailabilitySummary;
use Delnavazan\Platform\Core\Application\TeacherUnavailabilityService;
use Delnavazan\Platform\Core\Application\TeacherWorkspaceReadService;

/**
 * Presentation for the consolidated Teachers workspace.
 *
 * All values arrive pre-resolved from TeacherWorkspaceReadService. The view
 * renders controls only for the capability that owns the underlying service and
 * never writes a canonical record itself.
 */
final class TeacherWorkspaceView {
    private const PAGE = 'dzn-teachers-menu';
    private const WEEKDAYS = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');

    public static function render(TeacherWorkspaceReadService $read, string $page = self::PAGE): void {
        $teacherId = isset($_GET['id']) && is_scalar($_GET['id']) ? absint($_GET['id']) : 0;
        if ($teacherId > 0 && TeacherWorkspaceReadService::canManageTeachers()) {
            self::profile($read, $teacherId, $page);
            return;
        }
        self::directoryPage($read, $page);
        $view = isset($_GET['view']) && is_scalar($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
        if ($view === 'onboarding') echo '<script>(function(){var section=document.getElementById("teacher-onboarding");if(section){section.scrollIntoView({block:"start"});section.setAttribute("tabindex","-1");section.focus({preventScroll:true});}})();</script>';
    }

    private static function directoryPage(TeacherWorkspaceReadService $read, string $page): void {
        $showArchived = isset($_GET['show_archived']) && (string) $_GET['show_archived'] === '1';
        $month = $read->monthBounds();
        echo '<header class="dzn-tw-hero"><div><p class="dzn-tw-eyebrow">Academy operations</p><h1>Teachers</h1><p>One workspace for every Teacher’s directory record, onboarding state, availability, students, classes, performance and finance. Each statistic is derived from its canonical record.</p></div><div class="dzn-tw-hero__meta"><span class="dzn-tw-pill">' . esc_html($month['label']) . '</span><span class="dzn-tw-muted">Statistics use ' . esc_html($month['timezone']) . '</span></div></header>';
        if (TeacherWorkspaceReadService::canManageTeachers()) {
            $rows = $showArchived ? $read->archivedDirectory() : $read->directory(false);
            self::directory($rows, $showArchived, $page);
        } else {
            echo '<div class="notice notice-info inline"><p>Teacher maintenance is not part of your account. Your permitted invitation and onboarding work is available below.</p></div>';
        }
        self::onboarding($read, $page);
    }

    /** @param list<object> $rows */
    private static function directory(array $rows, bool $showArchived, string $page): void {
        echo '<section class="dzn-tw-section" id="teacher-directory" aria-labelledby="teacher-directory-title">';
        echo '<div class="dzn-tw-section__head"><div><h2 id="teacher-directory-title">' . ($showArchived ? 'Archived teachers' : 'Teacher directory') . '</h2><p>' . ($showArchived ? 'Archived records are retained for audit and controlled restoration. They do not appear in normal operations.' : 'All active and currently managed teachers. Active teachers appear first; unavailable teachers follow. Archived teachers are hidden from this directory.') . '</p></div><div class="dzn-tw-section__actions">';
        if ($showArchived) echo '<a class="button" href="' . esc_url(self::url($page)) . '">Back to active directory</a>';
        else echo '<a class="button" href="' . esc_url(self::url($page, array('show_archived' => '1'))) . '">Archived records</a>';
        echo '</div></div>';
        if (!$rows) {
            echo '<p class="dzn-tw-empty">' . ($showArchived ? 'No archived teacher records are retained.' : 'No teachers are currently in the directory. Invite a teacher below to begin onboarding.') . '</p></section>';
            return;
        }
        echo '<p class="dzn-tw-count">' . esc_html(number_format_i18n(count($rows))) . ' ' . ($showArchived ? 'archived record' : 'teacher') . (count($rows) === 1 ? '' : 's') . '</p>';
        echo '<div class="dzn-tw-table-scroll" role="region" aria-label="Teacher directory table" tabindex="0"><table class="widefat striped dzn-tw-table dzn-tw-directory"><caption class="screen-reader-text">Complete teacher directory with contact, instruments, status, availability, golden-hour coverage, students, teaching, introductions, conversion, rate, payment and actions.</caption><thead><tr><th scope="col">Teacher</th><th scope="col">Contact</th><th scope="col">Instruments</th><th scope="col">Status</th><th scope="col">Availability</th><th scope="col">Golden hours</th><th scope="col">Students</th><th scope="col">Teaching</th><th scope="col">Introductions</th><th scope="col">Conversion</th><th scope="col">Rate</th><th scope="col">Payments</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $profileUrl = self::url($page, array('id' => (int) $row->id));
            $stats = (array) ($row->stats ?? array());
            $intro = (array) ($stats['intro'] ?? array());
            $response = (array) ($stats['response'] ?? array());
            $golden = (array) ($stats['golden'] ?? array());
            $payment = (array) ($row->payment ?? array());
            $contact = trim(implode(' · ', array_filter(array((string) ($row->phone ?? ''), (string) ($row->whatsapp_phone ?? '')))));
            $instrumentSummary = trim((string) ($stats['instruments'] ?? ''));
            $statusClass = self::statusClass((string) $row->directory_status);
            $paymentClass = self::paymentClass((string) ($payment['state'] ?? ''));
            echo '<tr class="dzn-tw-directory__row">';
            echo '<td class="dzn-tw-teacher-cell"><a class="dzn-tw-teacher-name" href="' . esc_url($profileUrl) . '">' . esc_html((string) $row->display_name_effective) . '</a><span class="dzn-tw-email">' . esc_html((string) ($row->email ?: 'No email recorded')) . '</span></td>';
            echo '<td>' . esc_html($contact !== '' ? $contact : 'Not recorded') . '</td>';
            echo '<td>' . esc_html($instrumentSummary !== '' ? $instrumentSummary : 'None recorded') . '</td>';
            echo '<td><span class="dzn-tw-status dzn-tw-status--' . esc_attr($statusClass) . '">' . esc_html((string) $row->onboarding_label) . '</span></td>';
            echo '<td>' . esc_html((string) $row->availability_label) . '</td>';
            echo '<td>' . self::goldenCell($golden) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($stats['students'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(self::teachingLabel((int) ($stats['teaching_minutes'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($intro['total'] ?? 0))) . '<span class="dzn-tw-sub">' . esc_html((int) ($intro['pending'] ?? 0) . ' pending') . '</span></td>';
            echo '<td>' . esc_html((string) ($intro['rate_label'] ?? 'Not available')) . '<span class="dzn-tw-sub">' . esc_html((int) ($intro['pending'] ?? 0) . ' pending · ' . (int) ($intro['unsuccessful'] ?? 0) . ' unsuccessful') . '</span></td>';
            echo '<td>' . self::rateCell((array) ($row->finance ?? array())) . '</td>';
            echo '<td><span class="dzn-tw-payment dzn-tw-payment--' . esc_attr($paymentClass) . '" title="' . esc_attr((string) ($payment['detail'] ?? '')) . '">' . esc_html((string) ($payment['label'] ?? 'Not applicable')) . '</span></td>';
            echo '<td class="dzn-tw-actions"><a class="button button-small" href="' . esc_url($profileUrl) . '">Open profile</a>';
            if (!$showArchived) echo '<a class="button button-small" href="' . esc_url($profileUrl . '#teacher-status') . '">Set unavailable</a>';
            if (!$showArchived) echo '<a class="button button-small" href="' . esc_url($profileUrl . '#teacher-actions') . '">Archive</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="dzn-tw-note">Teaching and introductory statistics cover the current calendar month. Conversion uses completed introductory lessons and a 30-day attribution window; pending outcomes are never treated as failures.</p>';
        echo '</section>';
    }

    private static function profile(TeacherWorkspaceReadService $read, int $teacherId, string $page): void {
        $profile = $read->profile($teacherId);
        if ($profile === null) {
            echo '<div class="notice notice-error inline"><p>Teacher not found in the active or archived directory.</p></div><p><a class="button" href="' . esc_url(self::url($page)) . '">Back to Teachers</a></p>';
            return;
        }
        $record = $profile['record'];
        $stats = (array) ($record->stats ?? array());
        $payment = (array) ($record->payment ?? array());
        $onboarding = $profile['onboarding'];
        $availability = $profile['availability'];
        $finance = $profile['finance'];
        $archived = (string) ($record->status ?? '') === 'archived' || (string) ($record->archived_at ?? '') !== '';
        echo '<p class="dzn-tw-back"><a href="' . esc_url(self::url($page)) . '">← Back to teacher directory</a></p>';
        echo '<header class="dzn-tw-profile-head"><div><p class="dzn-tw-eyebrow">Teacher management record</p><h1>' . esc_html((string) $record->display_name_effective) . '</h1><p><a href="mailto:' . esc_attr((string) $record->email) . '">' . esc_html((string) ($record->email ?: 'No email recorded')) . '</a>' . ((string) ($record->phone ?? '') !== '' ? ' · ' . esc_html((string) $record->phone) : '') . '</p></div><div class="dzn-tw-profile-head__side"><span class="dzn-tw-status dzn-tw-status--' . esc_attr(self::statusClass((string) $record->directory_status)) . '">' . esc_html((string) $record->onboarding_label) . '</span><span class="dzn-tw-payment dzn-tw-payment--' . esc_attr(self::paymentClass((string) ($payment['state'] ?? ''))) . '">' . esc_html((string) ($payment['label'] ?? 'Payment not recorded')) . '</span></div></header>';
        self::profileSummary($record, $stats, $payment);
        echo '<nav class="dzn-tw-section-nav" aria-label="Teacher profile sections"><a href="#teacher-details">Teacher details</a><a href="#teacher-status">Status &amp; intake</a><a href="#teacher-account">Onboarding &amp; account</a><a href="#teacher-instruments">Instruments</a><a href="#teacher-availability">Availability</a><a href="#teacher-students">Students</a><a href="#teacher-classes">Classes</a><a href="#teacher-tasks">Tasks</a><a href="#teacher-finance">Finance</a><a href="#teacher-actions">Administrative actions</a></nav>';
        self::detailsSection($record, $onboarding, $page, $archived);
        if (!$archived) {
            self::statusSection($read, $record, $profile, $page);
            self::accountSection($read, $record, $onboarding, $profile['account'], $page);
            self::instrumentsSection($read, $record, $profile['eligibility'], $page);
            self::availabilitySection($read, $record, $availability, $profile['eligibility'], $page);
            self::studentsSection($record, $stats, $profile['students'], $page);
            self::classesSection($profile['classes'], $page);
            self::tasksSection($profile['tasks'], $profile['response'], $stats);
            self::financeSection($read, $record, $finance, $page);
            self::actionsSection($record, $page);
        } else {
            echo '<section class="dzn-tw-section" id="teacher-actions"><h2>Archived record</h2><p>Teaching, finance and audit history is retained. Restoration makes the profile eligible for a new invitation but never restores Teacher access automatically.</p>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-restore'));
            echo '</section>';
        }
    }

    private static function profileSummary(object $record, array $stats, array $payment): void {
        $intro = (array) ($stats['intro'] ?? array());
        $response = (array) ($stats['response'] ?? array());
        $golden = (array) ($stats['golden'] ?? array());
        $cards = array(
            array('Active students', number_format_i18n((int) ($stats['students'] ?? 0)), 'Current assignments'),
            array('Teaching this month', self::teachingLabel((int) ($stats['teaching_minutes'] ?? 0)), 'Delivered time'),
            array('Introductions', number_format_i18n((int) ($intro['total'] ?? 0)), (int) ($intro['pending'] ?? 0) . ' pending outcome'),
            array('Conversion', (string) ($intro['rate_label'] ?? 'Not available'), (int) ($intro['converted'] ?? 0) . ' converted'),
            array('Golden hours', (string) ($golden['label'] ?? 'Not available'), 'Selected coverage'),
            array('Response', self::responseLabel($response), 'Actionable requests'),
        );
        echo '<div class="dzn-tw-summary-grid">';
        foreach ($cards as [$label, $value, $hint]) echo '<div class="dzn-tw-summary"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong><small>' . esc_html((string) $hint) . '</small></div>';
        echo '</div>';
    }

    private static function detailsSection(object $record, ?array $onboarding, string $page, bool $archived): void {
        $teacher = $onboarding['teacher'] ?? $record;
        $preferred = (string) ($record->whatsapp_phone ?? '') !== '' ? 'WhatsApp' : ((string) ($record->phone ?? '') !== '' ? 'Phone' : 'Email');
        echo '<section class="dzn-tw-section" id="teacher-details"><div class="dzn-tw-section__head"><div><h2>Teacher details</h2><p>Human-readable profile used for scheduling, onboarding and contact. Canonical name variants remain preserved internally.</p></div></div>';
        echo '<div class="dzn-tw-facts"><div><span>Display name</span><strong>' . esc_html((string) $record->display_name_effective) . '</strong></div><div><span>Email</span><strong>' . esc_html((string) ($record->email ?: 'Not recorded')) . '</strong></div><div><span>Phone</span><strong>' . esc_html((string) ($record->phone ?: 'Not recorded')) . '</strong></div><div><span>WhatsApp</span><strong>' . esc_html((string) ($record->whatsapp_phone ?: 'Not recorded')) . '</strong></div><div><span>Preferred contact (inferred)</span><strong>' . esc_html($preferred) . '</strong></div><div><span>Timezone</span><strong>' . esc_html((string) ($record->timezone ?: 'Not recorded')) . '</strong></div><div><span>Locale</span><strong>' . esc_html((string) ($record->locale ?: 'Not recorded')) . '</strong></div><div><span>Calendar</span><strong>' . esc_html((string) ($record->calendar_preference ?: 'Not recorded')) . '</strong></div><div><span>Status</span><strong>' . esc_html((string) $record->onboarding_label) . '</strong></div><div><span>Onboarding activated</span><strong>' . esc_html(self::dateValue((string) ($teacher->activated_at ?? ''))) . '</strong></div></div>';
        if (!$archived && current_user_can('dzn_manage_teachers')) {
            echo '<details class="dzn-tw-disclosure"><summary>Edit teacher details</summary>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-profile'));
            echo '</details>';
        }
        echo '</section>';
    }

    private static function statusSection(TeacherWorkspaceReadService $read, object $record, array $profile, string $page): void {
        global $wpdb;
        $teacherId = (int) $record->id;
        $exceptions = (array) ($profile['availability']['exceptions'] ?? array());
        $timezone = (string) ($profile['availability']['profile']->timezone ?? $record->timezone ?? '');
        $futureBlocked = array_values(array_filter($exceptions, static function (object $row) use ($timezone): bool {
            $today = (new \DateTimeImmutable('today', new \DateTimeZone($timezone ?: 'UTC')))->format('Y-m-d');
            return (string) $row->status === 'active' && (string) $row->state === 'blocked' && (string) $row->reason_code === 'temporary_unavailability' && (string) $row->local_date >= $today;
        }));
        echo '<section class="dzn-tw-section" id="teacher-status"><div class="dzn-tw-section__head"><div><h2>Operational status and student intake</h2><p>Teaching status and new-student intake are separate. A teacher may keep existing students while intake is paused.</p></div></div>';
        echo '<div class="dzn-tw-inline-cards"><div class="dzn-tw-inline-card"><span>Teaching status</span><strong>' . esc_html((string) $record->onboarding_label) . '</strong><small>' . (($record->directory_status === 'active') ? 'Eligible to teach and be matched.' : 'Not currently a ready active teacher.') . '</small></div>';
        echo '<div class="dzn-tw-inline-card"><span>Student intake</span><strong>' . esc_html(self::intakeLabel((string) ($record->accepting_state ?? ''))) . '</strong><small>Affects new assignments only; existing students remain.</small></div>';
        echo '<div class="dzn-tw-inline-card"><span>Temporary unavailability</span><strong>' . ($futureBlocked ? esc_html(count($futureBlocked) . ' blocked day' . (count($futureBlocked) === 1 ? '' : 's')) : 'None scheduled') . '</strong><small>' . ($futureBlocked ? esc_html(self::dateValue((string) $futureBlocked[0]->local_date) . ' → ' . self::dateValue((string) $futureBlocked[count($futureBlocked) - 1]->local_date)) : 'New bookings follow weekly availability.') . '</small></div></div>';
        if (current_user_can('dzn_manage_teaching_eligibility')) {
            echo '<details class="dzn-tw-disclosure"><summary>Change student intake</summary><p class="dzn-tw-note">Student intake changes take effect immediately. A future-dated intake change is not modelled by the canonical accepting-state authority; use a temporary unavailable period for a dated booking stop.</p>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-accepting-state'));
            echo '</details>';
        }
        if (current_user_can('dzn_manage_teacher_availability')) {
            echo '<h3>Temporarily unavailable</h3><p>Stops new eligible bookings from the effective date without cancelling confirmed lessons or rewriting history. Existing future lessons that conflict are identified before anything is saved.</p>';
            $previewRequested = isset($_GET['unavailability_preview']) && (string) $_GET['unavailability_preview'] === '1' && (int) ($_GET['teacher_id'] ?? 0) === $teacherId;
            $from = isset($_GET['unavailability_from']) && is_scalar($_GET['unavailability_from']) ? sanitize_text_field(wp_unslash((string) $_GET['unavailability_from'])) : '';
            $to = isset($_GET['unavailability_to']) && is_scalar($_GET['unavailability_to']) ? sanitize_text_field(wp_unslash((string) $_GET['unavailability_to'])) : '';
            if ($previewRequested) {
                try {
                    $preview = (new TeacherUnavailabilityService())->preview($teacherId, $from, $to);
                    echo '<div class="notice notice-info inline"><p><strong>Period:</strong> ' . esc_html(self::dateValue($preview['from'])) . ' → ' . esc_html(self::dateValue($preview['to'])) . ' · ' . esc_html($preview['timezone']) . '</p></div>';
                    if ($preview['conflicts']) {
                        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html(count($preview['conflicts'])) . ' future lesson' . (count($preview['conflicts']) === 1 ? '' : 's') . ' conflict with this period.</strong> They will not be cancelled automatically. Confirm below to record unavailability and resolve them separately.</p></div>';
                        echo '<table class="widefat striped"><thead><tr><th>When</th><th>Student</th><th>Class</th><th>Reference</th><th>Resolve</th></tr></thead><tbody>';
                        foreach ($preview['conflicts'] as $conflict) {
                            $resolve = (string) $conflict['kind'] === 'intro'
                                ? admin_url('admin.php?page=dzn-booking-requests&request_id=' . (int) $conflict['identity'])
                                : admin_url('admin.php?page=dzn-lesson&id=' . (int) $conflict['identity']);
                            echo '<tr><td>' . esc_html(self::utcLabel((string) $conflict['starts_at_utc'], (string) $conflict['timezone'])) . '</td><td>' . esc_html((string) $conflict['student']) . '</td><td>' . esc_html((string) $conflict['course']) . '</td><td>' . esc_html((string) $conflict['reference']) . '</td><td><a class="button button-small" href="' . esc_url($resolve) . '">' . ((string) $conflict['kind'] === 'intro' ? 'Open booking' : 'Open class') . '</a></td></tr>';
                        }
                        echo '</tbody></table>';
                    } else {
                        echo '<p class="dzn-tw-note">No future lessons conflict with this period.</p>';
                    }
                    echo '<form method="post" class="dzn-tw-form">';
                    self::formFields('apply_teacher_unavailability', $teacherId);
                    echo '<input type="hidden" name="from" value="' . esc_attr($preview['from']) . '"><input type="hidden" name="to" value="' . esc_attr($preview['to']) . '">';
                    echo '<label>Reason <input type="text" name="reason" maxlength="64" required placeholder="For example: medical leave"></label>';
                    if ($preview['conflicts']) echo '<label class="dzn-tw-check"><input type="checkbox" name="acknowledge_conflicts" value="1" required> I have reviewed the conflicting lessons; they are not cancelled by this action.</label>';
                    echo '<button class="button button-primary">Record temporary unavailability</button> <a class="button" href="' . esc_url(self::url($page, array('id' => $teacherId)) . '#teacher-status') . '">Cancel</a></form>';
                } catch (\Throwable $error) {
                    echo '<div class="notice notice-error inline"><p>' . esc_html(self::safeError($error)) . '</p></div>';
                }
            }
            echo '<form method="get" class="dzn-tw-form dzn-tw-form--inline"><input type="hidden" name="page" value="' . esc_attr($page) . '"><input type="hidden" name="id" value="' . esc_attr((string) $teacherId) . '"><input type="hidden" name="unavailability_preview" value="1"><label>Start date <input type="date" name="unavailability_from" value="' . esc_attr(gmdate('Y-m-d')) . '" required></label><label>End date <span class="dzn-tw-muted">(optional; defaults to 90 days)</span><input type="date" name="unavailability_to"></label><button class="button">Review conflicts</button></form>';
            if ($futureBlocked) {
                echo '<form method="post" class="dzn-tw-form">';
                self::formFields('clear_teacher_unavailability', $teacherId);
                echo '<p class="dzn-tw-note">Reactivating removes only future temporary-unavailability blocks. Historical dates and confirmed lessons remain untouched.</p><button class="button">Reactivate availability</button></form>';
            }
        }
        echo '</section>';
    }

    private static function accountSection(TeacherWorkspaceReadService $read, object $record, ?array $onboarding, array $account, string $page): void {
        $teacherId = (int) $record->id;
        $progress = is_array($onboarding['progress'] ?? null) ? $onboarding['progress'] : array();
        $google = is_array($onboarding['google'] ?? null) ? $onboarding['google'] : array();
        echo '<section class="dzn-tw-section" id="teacher-account"><div class="dzn-tw-section__head"><div><h2>Onboarding and account</h2><p>Invitation, verified WordPress identity, profile, availability and Google connection readiness are shown separately. A connection is not proof that Google Meet works.</p></div></div>';
        echo '<div class="dzn-tw-facts"><div><span>Invitation</span><strong>' . esc_html(self::invitationLabel((string) ($record->generation_status ?? ''), (string) ($record->invitation_status ?? ''))) . '</strong></div><div><span>Accepted</span><strong>' . ((int) ($record->linked_user_id ?? 0) > 0 || (string) ($record->generation_status ?? '') === 'claimed' ? 'Yes' : 'Not yet') . '</strong></div><div><span>WordPress account</span><strong>' . esc_html($account['active'] ? $account['display_name'] . ' · ' . $account['email'] : 'Not linked') . '</strong></div><div><span>Profile</span><strong>' . esc_html(self::readinessLabel((string) ($progress['profile_state'] ?? $record->profile_state ?? ''))) . '</strong></div><div><span>Availability</span><strong>' . esc_html(self::readinessLabel((string) ($progress['availability_state'] ?? $record->availability_state ?? ''))) . '</strong></div><div><span>Google account</span><strong>' . esc_html((string) ($google['label'] ?? 'Not connected')) . '</strong></div><div><span>Onboarding review</span><strong>' . esc_html(self::reviewLabel((string) ($record->onboarding_state ?? ''), (string) ($record->readiness_state ?? ''))) . '</strong></div><div><span>Account roles</span><strong>' . esc_html($account['roles'] ? implode(', ', $account['roles']) : 'None recorded') . '</strong></div></div>';
        if (!empty($google['meet_caveat'])) echo '<p class="dzn-tw-note">' . esc_html((string) $google['meet_caveat']) . '</p>';
        echo '<p class="dzn-tw-note">Google authorisation is Teacher-owned. When reconnection is required, the Teacher reconnects from the Teacher portal; this workspace never impersonates the Teacher or creates a parallel OAuth flow.' . (current_user_can('dzn_access_provider_integration_settings') ? ' <a href="' . esc_url(admin_url('admin.php?page=dzn-integration-google')) . '">Open Google integration settings</a>.' : '') . '</p>';
        if (is_array($onboarding) && !empty($onboarding['blockers'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Outstanding blockers</strong></p><ul class="dzn-tw-list">';
            foreach ((array) $onboarding['blockers'] as $blocker) echo '<li>' . esc_html((string) $blocker) . '</li>';
            echo '</ul><p><strong>Next action:</strong> ' . esc_html((string) ($onboarding['next_action'] ?? '')) . '</p></div>';
        } elseif (is_array($onboarding)) {
            echo '<p class="dzn-tw-note">No onboarding blocker is currently recorded. Next action: ' . esc_html((string) ($onboarding['next_action'] ?? 'No onboarding action required.')) . '</p>';
        }
        echo '<div class="dzn-tw-controls">';
        if (current_user_can('dzn_issue_teacher_invitations') && (int) ($record->linked_user_id ?? 0) < 1 && $account['active'] === false) {
            echo '<details class="dzn-tw-disclosure"><summary>Resend invitation</summary><p>Resending supersedes the outstanding claimable token; the same Teacher record and email are reused.</p><form method="post" class="dzn-tw-form">';
            self::formFields('issue_teacher_invitation_by_email', $teacherId);
            echo '<input type="hidden" name="recipient" value="' . esc_attr((string) $record->email) . '"><input type="hidden" name="invitation_form_id" value="' . esc_attr(wp_generate_uuid4()) . '"><button class="button button-primary">Resend invitation</button></form></details>';
        }
        if (current_user_can('dzn_revoke_teacher_invitations') && in_array((string) ($record->generation_status ?? ''), array('queued_for_delivery', 'active'), true)) {
            echo '<details class="dzn-tw-disclosure"><summary>Revoke invitation</summary><p>Revoking invalidates every outstanding invitation token. It does not remove teaching history.</p><form method="post" class="dzn-tw-form">';
            self::formFields('revoke_teacher_invitation', $teacherId);
            echo '<button class="button">Revoke invitation</button></form></details>';
        }
        if (current_user_can('dzn_manage_onboarding') && current_user_can('dzn_manage_teachers')) {
            echo '<details class="dzn-tw-disclosure"><summary>Reset onboarding</summary><p>Only for an account with no teaching, finance or attendance history. Revokes Teacher access, clears the failed onboarding attempt and allows a fresh invitation to the same email. Other WordPress roles are preserved.</p><form method="post" class="dzn-tw-form" onsubmit="return confirm(\'Reset this onboarding attempt? This cannot restore prior invitation tokens.\')">';
            self::formFields('reset_teacher_onboarding', $teacherId);
            echo '<label>Reason <input type="text" name="reason" maxlength="64" required placeholder="For example: wrong email used"></label><button class="button">Reset onboarding</button></form></details>';
        }
        if (current_user_can('dzn_manage_teachers') && current_user_can('dzn_revoke_teacher_invitations') && (int) ($record->linked_user_id ?? 0) < 1) {
            echo '<details class="dzn-tw-disclosure"><summary>Revoke and remove invitation-only record</summary><p>Only available when the invitation never produced an account or any protected history. Audit evidence and the anonymised invitation record are retained.</p><form method="post" class="dzn-tw-form" onsubmit="return confirm(\'Remove this invitation-only Teacher record?\')">';
            self::formFields('revoke_remove_teacher_invitation', $teacherId);
            echo '<label>Reason <input type="text" name="reason" maxlength="64" required></label><button class="button">Revoke and remove</button></form></details>';
        }
        if (current_user_can('dzn_manage_onboarding')) {
            echo '<details class="dzn-tw-disclosure"><summary>Verified WordPress account linking</summary><p>Use only after independently verifying the person’s identity. Linking bypasses email delivery only; it does not bypass onboarding readiness. The same account cannot be linked to two teachers.</p><form method="post" class="dzn-tw-form">';
            self::formFields('admin_link_teacher_principal', $teacherId);
            echo '<label>Find account <input type="search" class="dzn-tw-account-search" placeholder="Type a name or email" autocomplete="off"></label><label>Existing WordPress account <select name="wordpress_user_id" class="dzn-tw-account-select" required><option value="">Choose a verified account</option>';
            foreach ($read->eligibleWordpressUsers() as $user) echo '<option value="' . esc_attr((string) $user->ID) . '">' . esc_html(trim((string) $user->display_name) . ' · ' . (string) $user->user_email) . '</option>';
            echo '</select></label><button class="button">Link verified account</button></form></details>';
        }
        if (current_user_can('dzn_relink_teacher_principal') && $account['active']) {
            echo '<details class="dzn-tw-disclosure"><summary>Deactivate linked account</summary>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-offboard'));
            echo '</details>';
        }
        if (current_user_can('dzn_manage_onboarding') && (string) ($record->onboarding_state ?? '') === 'pending_review') {
            echo '<details class="dzn-tw-disclosure" open><summary>Onboarding review decision</summary>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-approve-onboarding', 'teacher-return-onboarding', 'teacher-reject-onboarding'));
            echo '</details>';
        }
        echo '</div></section>';
    }

    private static function instrumentsSection(TeacherWorkspaceReadService $read, object $record, array $eligibility, string $page): void {
        $teacherId = (int) $record->id;
        echo '<section class="dzn-tw-section" id="teacher-instruments"><div class="dzn-tw-section__head"><div><h2>Instruments and course eligibility</h2><p>Eligibility uses the existing catalogue and instrument records. Removing eligibility never invalidates historical classes or completed teaching.</p></div></div>';
        if (!$eligibility) {
            echo '<p class="dzn-tw-empty">No course eligibility is recorded.</p>';
        } else {
            echo '<div class="dzn-tw-instrument-grid">';
            foreach ($eligibility as $row) {
                $active = (string) $row->status === 'active';
                $image = function_exists('dzn_theme_instrument_image') ? dzn_theme_instrument_image((string) $row->instrument_name) : '';
                echo '<article class="dzn-tw-instrument' . ($active ? ' is-active' : '') . '">';
                echo '<div class="dzn-tw-instrument__thumb">' . ($image !== '' ? '<img src="' . esc_url(get_theme_file_uri('assets/images/' . $image)) . '" alt="" loading="lazy" width="64" height="64">' : '<span aria-hidden="true">♪</span>') . '</div>';
                echo '<div class="dzn-tw-instrument__body"><strong>' . esc_html((string) $row->instrument_name) . '</strong><span>' . esc_html((string) $row->course_name) . '</span><small>' . esc_html($active ? 'Eligible' : 'Not eligible') . ' · ' . esc_html(number_format_i18n((int) $row->current_students)) . ' current student' . ((int) $row->current_students === 1 ? '' : 's') . '</small>';
                if ($active && current_user_can('dzn_manage_teaching_eligibility')) {
                    echo '<form method="post" class="dzn-tw-inline-form" onsubmit="return confirm(\'Remove eligibility for this course? Historical classes are preserved.\')">';
                    self::formFields('remove_teacher_course_eligibility', $teacherId);
                    echo '<input type="hidden" name="course_id" value="' . esc_attr((string) $row->course_id) . '"><button class="button-link-delete">Remove eligibility</button></form>';
                }
                echo '</div></article>';
            }
            echo '</div>';
        }
        if (current_user_can('dzn_manage_teaching_eligibility')) {
            echo '<details class="dzn-tw-disclosure"><summary>Add course eligibility</summary>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-eligibility'));
            echo '</details>';
        }
        echo '<p class="dzn-tw-note">Intake is currently a Teacher-level canonical state, not a per-instrument state. Student counts beside each course record show the current assignments affected by an eligibility change; historical classes are never invalidated.</p>';
        echo '</section>';
    }

    private static function availabilitySection(TeacherWorkspaceReadService $read, object $record, array $availability, array $eligibility, string $page): void {
        $teacherId = (int) $record->id;
        $profile = $availability['profile'] ?? null;
        $rules = (array) ($availability['rules'] ?? array());
        $exceptions = (array) ($availability['exceptions'] ?? array());
        $golden = (array) ($availability['golden'] ?? array());
        $weeklyMinutes = 0;
        foreach ($rules as $rule) {
            if (!in_array((string) $rule->state, array('preferred', 'requestable'), true)) continue;
            $start = self::minutes((string) $rule->local_start_time);
            $end = self::minutes((string) $rule->local_end_time);
            if ($start !== null && $end !== null && $end > $start) $weeklyMinutes += $end - $start;
        }
        $capacity = 0;
        foreach ($eligibility as $course) {
            if ((string) $course->status !== 'active') continue;
            $slot = max(1, (int) $course->default_duration_minutes + (int) $course->default_buffer_minutes);
            $capacity = max($capacity, (int) floor($weeklyMinutes / $slot));
        }
        echo '<section class="dzn-tw-section" id="teacher-availability"><div class="dzn-tw-section__head"><div><h2>Weekly availability and golden hours</h2><p>Uses the same canonical availability service as the Teacher portal. Unselected times are unavailable by default; golden-hour slots are highlighted from the existing finance policy.</p></div></div>';
        echo '<div class="dzn-tw-inline-cards"><div class="dzn-tw-inline-card"><span>Timezone</span><strong>' . esc_html((string) ($profile->timezone ?? $record->timezone ?? 'Not configured')) . '</strong><small>Local wall-clock availability authority.</small></div><div class="dzn-tw-inline-card"><span>Weekly availability</span><strong>' . esc_html(self::minutesLabel($weeklyMinutes)) . '</strong><small>' . esc_html(number_format_i18n(count($rules)) . ' selected window' . (count($rules) === 1 ? '' : 's')) . '</small></div><div class="dzn-tw-inline-card"><span>Golden-hour coverage</span><strong>' . esc_html((string) ($golden['minutes_label'] ?? 'Not available')) . '</strong><small>' . esc_html((string) ($golden['label'] ?? 'No eligible overlap')) . '</small></div><div class="dzn-tw-inline-card"><span>Estimated capacity</span><strong>' . esc_html($capacity > 0 ? number_format_i18n($capacity) . ' classes/week' : 'Not available') . '</strong><small>Uses each eligible course duration + buffer.</small></div></div>';
        if (!empty($golden['windows'])) {
            echo '<div class="dzn-tw-golden"><strong>Selected golden-hour windows</strong><ul class="dzn-tw-list">';
            foreach ((array) $golden['windows'] as $window) echo '<li>' . esc_html((string) $window) . '</li>';
            echo '</ul><small>Policy bonus: +' . esc_html((string) ($golden['percentage_label'] ?? '0')) . '%. Potential eligibility only; the committed bonus is determined by the finance snapshot after delivery.</small></div>';
        }
        echo '<h3>Weekly windows</h3>';
        if (!$rules) {
            echo '<p class="dzn-tw-empty">No active weekly availability is recorded.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Day</th><th>From</th><th>To</th><th>Availability</th><th>Timezone</th><th>Action</th></tr></thead><tbody>';
            foreach ($rules as $rule) {
                echo '<tr><td>' . esc_html(self::weekday((int) $rule->weekday)) . '</td><td>' . esc_html(substr((string) $rule->local_start_time, 0, 5)) . '</td><td>' . esc_html(substr((string) $rule->local_end_time, 0, 5)) . '</td><td>' . esc_html(self::availabilityState((string) $rule->state)) . '</td><td>' . esc_html((string) $rule->timezone) . '</td><td>';
                if (current_user_can('dzn_manage_teacher_availability')) {
                    echo '<form method="post" class="dzn-tw-inline-form" onsubmit="return confirm(\'Remove this weekly availability window? Existing lessons are not moved.\')">';
                    self::formFields('retire_teacher_availability_rule', $teacherId);
                    echo '<input type="hidden" name="rule_id" value="' . esc_attr((string) $rule->id) . '"><button class="button-link-delete">Remove</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h3>Holidays and dated exceptions</h3>';
        if (!$exceptions) {
            echo '<p class="dzn-tw-empty">No dated exceptions are recorded.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Coverage</th><th>Availability</th><th>Timezone</th><th>Reason</th><th>Action</th></tr></thead><tbody>';
            foreach ($exceptions as $exception) {
                $coverage = (int) $exception->all_day === 1 ? 'All day' : substr((string) $exception->local_start_time, 0, 5) . '–' . substr((string) $exception->local_end_time, 0, 5);
                echo '<tr><td>' . esc_html(self::dateValue((string) $exception->local_date)) . '</td><td>' . esc_html($coverage) . '</td><td>' . esc_html(self::availabilityState((string) $exception->state)) . '</td><td>' . esc_html((string) $exception->timezone) . '</td><td>' . esc_html((string) ($exception->reason_code ?: '—')) . '</td><td>';
                if (current_user_can('dzn_manage_teacher_availability')) {
                    echo '<form method="post" class="dzn-tw-inline-form" onsubmit="return confirm(\'Remove this dated exception?\')">';
                    self::formFields('retire_teacher_availability_exception', $teacherId);
                    echo '<input type="hidden" name="exception_id" value="' . esc_attr((string) $exception->id) . '"><button class="button-link-delete">Remove</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (current_user_can('dzn_manage_teacher_availability')) {
            echo '<details class="dzn-tw-disclosure"><summary>Change timezone</summary>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-availability-profile'));
            echo '</details>';
            echo '<details class="dzn-tw-disclosure"><summary>Add weekly availability</summary>';
            RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-availability-rule'));
            echo '</details>';
            echo '<details class="dzn-tw-disclosure"><summary>Add holiday or dated exception</summary><form method="post" class="dzn-tw-form">';
            self::formFields('set_availability_exception', $teacherId);
            echo '<label>Date <input type="date" name="local_date" required></label><label>Coverage <select name="all_day"><option value="1">All day</option><option value="">Time window</option></select></label><label>From <input type="time" name="local_start_time"></label><label>To <input type="time" name="local_end_time"></label><label>Availability <select name="state"><option value="blocked">Unavailable</option><option value="requestable">Available on request</option><option value="preferred">Preferred</option></select></label><label>Timezone <input type="text" name="timezone" value="' . esc_attr((string) ($profile->timezone ?? $record->timezone ?? '')) . '" required></label><label>Reason <input type="text" name="reason_code" maxlength="64"></label><button class="button">Save exception</button></form></details>';
        }
        echo '</section>';
    }

    private static function studentsSection(object $record, array $stats, array $students, string $page): void {
        $intro = (array) ($stats['intro'] ?? array());
        echo '<section class="dzn-tw-section" id="teacher-students"><div class="dzn-tw-section__head"><div><h2>Students and teaching activity</h2><p>Current canonical assignments only. Closed or historical enrolments are excluded from the active count.</p></div></div>';
        echo '<div class="dzn-tw-inline-cards"><div class="dzn-tw-inline-card"><span>Active students</span><strong>' . esc_html(number_format_i18n((int) ($stats['students'] ?? 0))) . '</strong></div><div class="dzn-tw-inline-card"><span>Completed lessons this month</span><strong>' . esc_html(number_format_i18n((int) ($stats['completed_lessons_month'] ?? 0))) . '</strong></div><div class="dzn-tw-inline-card"><span>Teaching hours this month</span><strong>' . esc_html(self::teachingLabel((int) ($stats['teaching_minutes'] ?? 0))) . '</strong></div><div class="dzn-tw-inline-card"><span>Introductions this month</span><strong>' . esc_html(number_format_i18n((int) ($intro['total'] ?? 0))) . '</strong><small>' . esc_html((int) ($intro['converted'] ?? 0) . ' converted · ' . (int) ($intro['pending'] ?? 0) . ' pending') . '</small></div><div class="dzn-tw-inline-card"><span>Last completed class</span><strong>' . esc_html(self::dateValue((string) ($stats['last_class_utc'] ?? ''))) . '</strong><small>Delivery evidence only.</small></div></div>';
        if (!$students) {
            echo '<p class="dzn-tw-empty">No current student assignment is recorded.</p></section>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Student</th><th>Instrument</th><th>Current term</th><th>Completed</th><th>Remaining</th><th>Next lesson</th><th>Assignment</th><th>Action</th></tr></thead><tbody>';
        foreach ($students as $student) {
            $studentUrl = add_query_arg(array('page' => 'dzn-student', 'id' => (int) $student->student_id), admin_url('admin.php'));
            echo '<tr><td><a href="' . esc_url($studentUrl) . '">' . esc_html((string) $student->student_name) . '</a></td><td>' . esc_html((string) $student->instrument_name) . '</td><td>' . esc_html((int) ($student->sequence_number ?? 0) > 0 ? 'Term ' . (int) $student->sequence_number : 'No current term') . '</td><td>' . esc_html(number_format_i18n((int) $student->completed_lessons)) . '</td><td>' . esc_html(number_format_i18n((int) $student->remaining_lessons)) . '</td><td>' . esc_html(self::dateValue((string) ($student->next_lesson_utc ?? ''))) . '</td><td>' . esc_html(self::enrolmentLabel((string) $student->enrolment_state)) . '</td><td><a class="button button-small" href="' . esc_url($studentUrl) . '">Open student</a></td></tr>';
        }
        echo '</tbody></table><p class="dzn-tw-note">Assignment changes use the existing Student and Enrolment workflows; this profile never invents a second assignment authority.</p></section>';
    }

    private static function classesSection(array $classes, string $page): void {
        echo '<section class="dzn-tw-section" id="teacher-classes"><div class="dzn-tw-section__head"><div><h2>Classes and attendance</h2><p>Canonical schedule and delivery facts. Reschedule, attendance and classroom actions remain with their existing authorities.</p></div></div>';
        self::classTable('Today', (array) ($classes['today'] ?? array()), $page);
        self::classTable('Upcoming', (array) ($classes['upcoming'] ?? array()), $page);
        self::classTable('Recently completed', (array) ($classes['recent'] ?? array()), $page);
        self::classTable('Replacement lessons', (array) ($classes['replacements'] ?? array()), $page);
        $attendance = (array) ($classes['attendance'] ?? array());
        echo '<h3>Attendance exceptions</h3>';
        if (!$attendance) {
            echo '<p class="dzn-tw-empty">No open attendance case is recorded.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Occurrence</th><th>Student</th><th>State</th><th>Action</th></tr></thead><tbody>';
            foreach ($attendance as $row) echo '<tr><td>' . esc_html(self::utcLabel((string) $row->occurrence_start_utc, '')) . '</td><td>' . esc_html((string) $row->student_name) . '</td><td>' . esc_html(ucfirst(str_replace('_', ' ', (string) $row->state))) . '</td><td><a class="button button-small" href="' . esc_url(admin_url('admin.php?page=dzn-attendance-review&lesson_id=' . (int) $row->lesson_id)) . '">Review attendance</a></td></tr>';
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    private static function classTable(string $title, array $rows, string $page): void {
        echo '<h3>' . esc_html($title) . '</h3>';
        if (!$rows) {
            echo '<p class="dzn-tw-empty">No class in this group.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>When</th><th>Student</th><th>Course</th><th>Type</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 10) as $row) {
            $url = admin_url('admin.php?page=dzn-lesson&id=' . (int) $row->id);
            $when = (string) ($row->starts_at_utc ?? '') !== '' ? self::utcLabel((string) $row->starts_at_utc, (string) ($row->schedule_timezone ?? '')) : 'Not scheduled';
            echo '<tr><td>' . esc_html($when) . '</td><td>' . esc_html((string) ($row->student_name ?: 'Student')) . '</td><td>' . esc_html((string) ($row->course_name ?: 'Course')) . '</td><td>' . esc_html(ucfirst((string) $row->lesson_type)) . '</td><td>' . esc_html(self::classState((string) $row->lifecycle_state, (string) ($row->delivery_state ?? ''))) . '</td><td><a class="button button-small" href="' . esc_url($url) . '">Inspect class</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function tasksSection(array $tasks, array $response, array $stats): void {
        echo '<section class="dzn-tw-section" id="teacher-tasks"><div class="dzn-tw-section__head"><div><h2>Tasks and response performance</h2><p>Unresolved actions use the same underlying task records as the Academy Action Centre and the existing scheduling authority.</p></div></div>';
        $median = self::secondsLabel($response['median_seconds'] ?? null);
        $average = self::secondsLabel($response['average_seconds'] ?? null);
        echo '<div class="dzn-tw-inline-cards"><div class="dzn-tw-inline-card"><span>Median response</span><strong>' . esc_html($median) . '</strong><small>' . esc_html('Average ' . $average . ' · ' . (int) ($response['count'] ?? 0) . ' measurable responses over ' . (int) ($response['window_days'] ?? 90) . ' days') . '</small></div><div class="dzn-tw-inline-card"><span>Outstanding decisions</span><strong>' . esc_html(number_format_i18n(count(array_filter($tasks, static fn(array $task): bool => in_array((string) $task['status'], array('Pending', 'Open', 'Draft'), true))))) . '</strong><small>Pending requests, reschedules and finance work.</small></div><div class="dzn-tw-inline-card"><span>Upcoming lessons</span><strong>' . esc_html(number_format_i18n((int) ($stats['upcoming'] ?? 0))) . '</strong><small>Next: ' . esc_html(self::dateValue((string) ($stats['next_lesson_utc'] ?? ''))) . '</small></div><div class="dzn-tw-inline-card"><span>Replacement lessons</span><strong>' . esc_html(number_format_i18n((int) ($stats['replacement_lessons'] ?? 0))) . '</strong><small>Authorised replacements awaiting delivery.</small></div></div>';
        if (!$tasks) {
            echo '<p class="dzn-tw-empty">No unresolved task is recorded for this Teacher.</p></section>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Task</th><th>Created</th><th>Waiting</th><th>Priority</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($tasks as $task) {
            echo '<tr><td>' . esc_html((string) $task['task']) . '</td><td>' . esc_html(self::dateValue((string) $task['created_at'])) . '</td><td>' . esc_html(self::waiting((string) $task['created_at'])) . '</td><td><span class="dzn-tw-status dzn-tw-status--' . esc_attr((string) $task['priority'] === 'high' ? 'error' : 'warning') . '">' . esc_html(ucfirst((string) $task['priority'])) . '</span></td><td>' . esc_html((string) $task['status']) . '</td><td><a class="button button-small" href="' . esc_url((string) $task['url']) . '">' . esc_html((string) $task['action']) . '</a></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function financeSection(TeacherWorkspaceReadService $read, object $record, array $finance, string $page): void {
        $teacherId = (int) $record->id;
        echo '<section class="dzn-tw-section" id="teacher-finance"><div class="dzn-tw-section__head"><div><h2>Finance and compensation</h2><p>Embedded canonical finance surfaces. Rates, statements, payability and settlements are never recalculated or duplicated here.</p></div></div>';
        if (empty($finance['available'])) {
            echo '<p class="dzn-tw-empty">Finance access is not part of your account.</p></section>';
            return;
        }
        $current = $finance['current_rate'] ?? null;
        echo '<h3>Current rate</h3>';
        if ($current) {
            echo '<div class="dzn-tw-inline-cards"><div class="dzn-tw-inline-card"><span>Effective rate</span><strong>' . esc_html(PricingMoney::format((int) $current['amount_minor'], (string) $current['currency'])) . '</strong><small>' . esc_html(ucfirst(str_replace('_', ' ', (string) $current['compensation_basis']))) . ' · ' . esc_html((string) ($current['rate_origin'] ?? 'explicit')) . '</small></div><div class="dzn-tw-inline-card"><span>Scope</span><strong>' . esc_html((string) $current['scope_kind'] === 'teacher' ? 'All courses' : (string) ($current['course_name'] ?? 'One course')) . '</strong><small>Effective from ' . esc_html(self::dateValue((string) $current['effective_from'])) . '</small></div><div class="dzn-tw-inline-card"><span>Rate source</span><strong>' . esc_html((string) ($current['rate_origin'] ?? 'explicit') === 'global_inherited' ? 'Default' : 'Teacher-specific override') . '</strong><small>Historical versions are preserved.</small></div></div>';
        } else {
            echo '<p class="dzn-tw-empty">No effective-dated rate currently covers this Teacher.</p>';
        }
        if (current_user_can('dzn_manage_teacher_rates')) {
            echo '<details class="dzn-tw-disclosure"><summary>Record a new effective-dated rate</summary><p>Rate changes append a new version. The prior version is never overwritten.</p><form method="post" class="dzn-tw-form">';
            self::formFields('save_teacher_rate', $teacherId);
            echo '<label>Amount (TOM) <input type="number" name="amount" min="1" step="1" required></label><label>Effective from (UTC) <input type="datetime-local" name="effective_from" value="' . esc_attr(gmdate('Y-m-d\TH:i')) . '"></label><label>Scope <select name="scope_kind"><option value="teacher">All courses</option><option value="teacher_course">One course</option></select></label><label>Course <select name="course_scope_id"><option value="0">All courses</option>';
            foreach ($read->activeCourses() as $course) echo '<option value="' . esc_attr((string) $course->id) . '">' . esc_html((string) $course->name) . '</option>';
            echo '</select></label><label>Evidence reference <input type="text" name="evidence_reference" maxlength="191"></label><button class="button button-primary">Save rate</button></form></details>';
        }
        echo '<h3>Rate timeline</h3>';
        $rates = (array) ($finance['rates']['rates'] ?? array());
        if (!$rates) {
            echo '<p class="dzn-tw-empty">No rate history is recorded.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Version</th><th>Scope</th><th>From</th><th>Until</th><th>Status</th><th>Amount</th></tr></thead><tbody>';
            foreach ($rates as $rate) {
                $scope = (string) $rate['scope_kind'] === 'teacher' ? 'All courses' : ((string) ($rate['course_name'] ?? 'Course'));
                echo '<tr><td>v' . esc_html((string) $rate['rate_version']) . '</td><td>' . esc_html($scope) . '</td><td>' . esc_html(self::dateValue((string) $rate['effective_from'])) . '</td><td>' . esc_html(self::dateValue((string) ($rate['effective_until'] ?? ''))) . '</td><td>' . esc_html(ucfirst((string) $rate['status'])) . '</td><td>' . esc_html(PricingMoney::format((int) $rate['amount_minor'], (string) $rate['currency'])) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h3>Statements</h3>';
        $statements = (array) ($finance['statements'] ?? array());
        if (current_user_can('dzn_manage_finance_statements')) {
            echo '<details class="dzn-tw-disclosure"><summary>Draft a statement</summary><p>Uses the canonical statement authority. Every lesson in the completed period must already be finalised and payability-evaluated.</p><form method="post" class="dzn-tw-form">';
            self::formFields('draft_teacher_statement', $teacherId);
            $defaultEnd = gmdate('Y-m-d', strtotime('-1 day'));
            $defaultStart = gmdate('Y-m-01', strtotime('-1 month'));
            echo '<label>Period start (UTC date) <input type="date" name="period_start" value="' . esc_attr($defaultStart) . '" max="' . esc_attr($defaultEnd) . '" required></label><label>Period end (inclusive, UTC date) <input type="date" name="period_end" value="' . esc_attr($defaultEnd) . '" max="' . esc_attr($defaultEnd) . '" required></label><button class="button button-primary">Draft statement</button></form></details>';
        }
        if (!$statements) {
            echo '<p class="dzn-tw-empty">No statement has been drafted for this Teacher.</p>';
        } else {
            echo '<div class="dzn-tw-table-scroll" role="region" aria-label="Teacher statements" tabindex="0"><table class="widefat striped"><thead><tr><th>Statement</th><th>Period</th><th>Amount</th><th>Status</th><th>Issued</th><th>Payment</th><th>Actions</th></tr></thead><tbody>';
            foreach ($statements as $statement) {
                $state = (string) $statement['state'];
                $amount = PricingMoney::format((int) $statement['payable_amount_minor'], (string) $statement['currency']);
                $period = (string) ($statement['period_label'] ?: self::dateValue((string) $statement['period_start_utc']) . ' → ' . self::dateValue((string) $statement['period_end_utc']));
                $payment = !empty($statement['payment_recorded']) ? 'Paid ' . self::dateValue((string) $statement['paid_at']) : ($state === 'issued' ? 'Not recorded' : '—');
                echo '<tr><td>Statement v' . esc_html((string) $statement['statement_version']) . '</td><td>' . esc_html($period) . '</td><td>' . esc_html($amount) . '</td><td>' . esc_html(ucfirst($state)) . '</td><td>' . esc_html(self::dateValue((string) ($statement['issued_at'] ?? ''))) . '</td><td>' . esc_html($payment) . '</td><td>';
                if (current_user_can('dzn_manage_finance_statements') && $state === 'draft') {
                    echo '<form method="post" class="dzn-tw-inline-form" onsubmit="return confirm(\'Issue this statement? The canonical issuance gate must pass.\')">';
                    self::formFields('issue_teacher_statement', $teacherId);
                    echo '<input type="hidden" name="statement_id" value="' . esc_attr((string) $statement['statement_id']) . '"><button class="button button-small button-primary">Issue</button></form>';
                }
                if (current_user_can('dzn_manage_finance_statements') && $state === 'issued' && empty($statement['payment_recorded'])) {
                    echo '<details class="dzn-tw-disclosure"><summary>Record payment</summary><form method="post" class="dzn-tw-form">';
                    self::formFields('record_teacher_statement_payment', $teacherId);
                    echo '<input type="hidden" name="statement_id" value="' . esc_attr((string) $statement['statement_id']) . '"><label>Paid on <input type="date" name="payment_date" value="' . esc_attr(gmdate('Y-m-d')) . '" required></label><label>Method <select name="payment_method"><option value="manual_bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="other">Other</option></select></label><label>Evidence reference <input type="text" name="payment_reference" maxlength="191" required></label><button class="button">Record payment</button></form></details>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<h3>Lesson payability</h3>';
        $lines = (array) ($finance['lines'] ?? array());
        if (!$lines) {
            echo '<p class="dzn-tw-empty">No stated lesson is recorded. Draft a statement to derive payability from canonical lesson facts.</p>';
        } else {
            echo '<div class="dzn-tw-table-scroll" role="region" aria-label="Teacher lesson payability" tabindex="0"><table class="widefat striped"><thead><tr><th>Lesson date</th><th>Student</th><th>Instrument</th><th>Course</th><th>Type</th><th>Payable status</th><th>Applied rate</th><th>Amount</th><th>Statement</th></tr></thead><tbody>';
            foreach ($lines as $line) {
                echo '<tr><td>' . esc_html(self::dateValue((string) $line->occurrence_starts_at_utc)) . '</td><td>' . esc_html((string) ($line->student_name ?: 'Student')) . '</td><td>' . esc_html((string) ($line->instrument_name ?: '—')) . '</td><td>' . esc_html((string) $line->course_name) . '</td><td>' . esc_html(ucfirst((string) $line->lesson_kind)) . '</td><td>' . esc_html(ucfirst((string) $line->disposition)) . '</td><td>' . esc_html(PricingMoney::format((int) $line->rate_amount_minor, (string) $line->currency)) . '</td><td>' . esc_html(PricingMoney::format((int) $line->line_amount_minor, (string) $line->currency)) . '</td><td>' . esc_html((string) ($line->statement_reference ?: 'Statement')) . '<span class="dzn-tw-sub">' . esc_html(ucfirst((string) $line->statement_state)) . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<p class="dzn-tw-note">A statement records compensation facts only. It is not a payout receipt, tax invoice or bank instruction. Payment is shown only when an append-only settlement event exists. The canonical settlement authority records full settlement once; partial settlement is not modelled and is never inferred.</p>';
        echo '<p class="dzn-tw-links"><a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-finance-rates', 'teacher_id' => $teacherId), admin_url('admin.php'))) . '">Rate timeline</a> <a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-finance-statements', 'teacher_id' => $teacherId), admin_url('admin.php'))) . '">Statements &amp; reconciliation</a> <a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-finance-payability', 'teacher_id' => $teacherId), admin_url('admin.php'))) . '">Lesson payability</a></p></section>';
    }

    private static function actionsSection(object $record, string $page): void {
        echo '<section class="dzn-tw-section" id="teacher-actions"><div class="dzn-tw-section__head"><div><h2>Administrative actions</h2><p>Consequential changes require confirmation. Archiving preserves teaching, finance and audit history and revokes Teacher-specific access only.</p></div></div>';
        echo '<div class="dzn-tw-action-grid">';
        RecordActionRenderer::renderSelected('teacher', $record, $page, array('teacher-profile', 'teacher-accepting-state', 'teacher-eligibility', 'teacher-availability-profile', 'teacher-availability-rule', 'teacher-offboard', 'teacher-archive'));
        echo '</div></section>';
    }

    private static function onboardingHeader(): string {
        return '<div class="dzn-tw-section__head"><div><h2>Onboarding and invitations</h2><p>One consolidated current-state table for invited and onboarding teachers. Status is read from canonical records on every page load; unknown states are never shown as healthy.</p></div></div>';
    }

    private static function onboarding(TeacherWorkspaceReadService $read, string $page): void {
        if (!TeacherWorkspaceReadService::canReadOnboarding()) return;
        $overview = $read->onboardingOverview();
        echo '<section class="dzn-tw-section" id="teacher-onboarding">' . self::onboardingHeader();
        if (($overview['can_invite'] ?? false)) {
            echo '<h3>Invite a teacher</h3><p>Issuing creates a delivery intent; it does not mean an email was received. Email is enough to authorise account registration. Raw invitation secrets are never rendered, retained, logged or placed in URLs.</p>';
            echo '<form method="post" class="dzn-tw-invite-form">';
            self::formFields('issue_teacher_invitation_by_email', 0);
            echo '<input type="hidden" name="invitation_form_id" value="' . esc_attr(wp_generate_uuid4()) . '"><label for="dzn-teacher-invite-email">Teacher email</label><input id="dzn-teacher-invite-email" type="email" name="recipient" required placeholder="teacher@example.com"><button class="button button-primary">Send invitation</button></form>';
        }
        $rows = (array) ($overview['rows'] ?? array());
        echo '<h3>Onboarding and invitation status</h3>';
        if (!$rows) {
            echo '<p class="dzn-tw-empty">No invitation or onboarding record is currently active.</p></section>';
            return;
        }
        echo '<div class="dzn-tw-table-scroll" role="region" aria-label="Teacher onboarding and invitation status" tabindex="0"><table class="widefat striped dzn-tw-onboarding-table"><caption class="screen-reader-text">Consolidated invitation and onboarding status for each teacher.</caption><thead><tr><th>Teacher</th><th>Invitation</th><th>Accepted</th><th>Account</th><th>Profile</th><th>Availability</th><th>Google</th><th>Expiry</th><th>Delivery</th><th>Blockers</th><th>Review</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $teacherId = (int) $row['teacher_id'];
            $profileUrl = TeacherWorkspaceReadService::canManageTeachers() ? self::url($page, array('id' => $teacherId)) : '';
            $availability = (array) ($row['availability_detail'] ?? array());
            $golden = (array) ($availability['golden'] ?? array());
            $availabilityTip = 'Timezone: ' . (string) ($availability['timezone'] ?: 'not recorded') . ' · ' . (int) ($availability['rules'] ?? 0) . ' selected slots · Golden-hour coverage: ' . (string) ($golden['label'] ?? 'not available');
            $google = (array) ($row['google'] ?? array());
            $review = (array) ($row['review'] ?? array());
            echo '<tr><td>' . ($profileUrl !== '' ? '<a href="' . esc_url($profileUrl) . '"><strong>' . esc_html((string) $row['name']) . '</strong></a>' : '<strong>' . esc_html((string) $row['name']) . '</strong>') . '<span class="dzn-tw-email">' . esc_html((string) $row['email']) . '</span></td>';
            $invitationIcon = match ((string) ($row['invitation']['state'] ?? 'not_sent')) {
                'claimed', 'account_linked', 'claim_available' => 'complete',
                'revoked', 'expired', 'superseded', 'teacher_archived' => 'error',
                'queued_for_delivery' => 'pending',
                default => 'warning',
            };
            echo '<td>' . self::statusIcon($invitationIcon, (string) ($row['invitation']['label'] ?? ''), (string) ($row['invitation']['label'] ?? '')) . '</td>';
            echo '<td>' . ((bool) ($row['accepted'] ?? false) ? self::statusIcon('complete', 'Accepted', 'Accepted') : self::statusIcon('pending', 'Not yet accepted', 'Not yet accepted')) . '</td>';
            echo '<td>' . self::statusIcon((string) ($row['account_state'] ?? 'pending'), (string) ($row['account_label'] ?? ''), (string) ($row['account_label'] ?? '')) . '</td>';
            echo '<td>' . self::statusIcon((string) ($row['profile']['state'] ?? 'unknown'), (string) ($row['profile']['label'] ?? ''), (string) ($row['profile']['label'] ?? '')) . '</td>';
            echo '<td><details class="dzn-tw-snapshot"><summary data-dzn-tip="' . esc_attr($availabilityTip) . '" aria-label="' . esc_attr($availabilityTip) . '">' . self::statusIcon((string) ($row['availability']['state'] ?? 'unknown'), (string) ($row['availability']['label'] ?? ''), (string) ($row['availability']['label'] ?? '')) . '</summary><div class="dzn-tw-snapshot__body"><strong>' . esc_html((string) ($availability['timezone'] ?: 'Timezone not recorded')) . '</strong><span>' . esc_html(number_format_i18n((int) ($availability['rules'] ?? 0)) . ' selected weekly slot' . ((int) ($availability['rules'] ?? 0) === 1 ? '' : 's')) . '</span><span>' . esc_html('Golden hours: ' . (string) ($golden['label'] ?? 'not available')) . '</span>';
            if (!empty($golden['windows'])) echo '<ul class="dzn-tw-list">' . implode('', array_map(static fn($window): string => '<li>' . esc_html((string) $window) . '</li>', array_slice((array) $golden['windows'], 0, 4))) . '</ul>';
            echo '</div></details></td>';
            $googleState = (string) ($row['google']['connection_state'] ?? '');
            $googleIcon = !empty($row['google']['ready_for_activation']) ? 'complete' : (in_array($googleState, array('refresh_failed', 'authorisation_expired', 'disconnected'), true) ? 'error' : 'warning');
            echo '<td>' . self::statusIcon($googleIcon, (string) ($row['google']['label'] ?? ''), (string) ($row['google']['label'] ?? '')) . '</td>';
            echo '<td>' . self::statusIcon((string) ($row['expiry']['state'] ?? 'unknown'), (string) ($row['expiry']['label'] ?? ''), (string) ($row['expiry']['label'] ?? '')) . '</td>';
            echo '<td>' . self::statusIcon((string) ($row['delivery']['state'] ?? 'unknown'), (string) ($row['delivery']['label'] ?? ''), (string) ($row['delivery']['label'] ?? '')) . '</td>';
            echo '<td class="dzn-tw-blockers">';
            if (!empty($row['blockers'])) {
                echo '<ul class="dzn-tw-list">';
                foreach (array_slice((array) $row['blockers'], 0, 4) as $blocker) echo '<li>' . esc_html((string) $blocker) . '</li>';
                echo '</ul>';
            }
            echo '<small>' . esc_html((string) ($row['next_action'] ?? '')) . '</small></td>';
            $reviewIcon = match ((string) ($review['state'] ?? '')) {
                'approved' => 'complete',
                'pending_review' => 'warning',
                'error' => 'error',
                'ready' => 'warning',
                default => 'pending',
            };
            echo '<td>' . self::statusIcon($reviewIcon, (string) ($review['label'] ?? ''), (string) ($review['label'] ?? '')) . '</td>';
            echo '<td class="dzn-tw-actions">';
            if ($profileUrl !== '') echo '<a class="button button-small" href="' . esc_url($profileUrl) . '">Open</a>';
            if (($overview['can_invite'] ?? false) && (int) ($row['accepted'] ?? false) === 0) {
                echo '<form method="post" class="dzn-tw-inline-form">';
                self::formFields('issue_teacher_invitation_by_email', $teacherId);
                echo '<input type="hidden" name="recipient" value="' . esc_attr((string) $row['email']) . '"><input type="hidden" name="invitation_form_id" value="' . esc_attr(wp_generate_uuid4()) . '"><button class="button button-small">Resend</button></form>';
            }
            if (($overview['can_revoke'] ?? false) && in_array((string) ($row['invitation']['state'] ?? ''), array('queued_for_delivery', 'claim_available'), true)) {
                echo '<form method="post" class="dzn-tw-inline-form" onsubmit="return confirm(\'Revoke this invitation?\')">';
                self::formFields('revoke_teacher_invitation', $teacherId);
                echo '<button class="button button-small">Revoke</button></form>';
            }
            echo '</td></tr>';
            echo '<tr class="dzn-tw-onboarding-expand"><td colspan="12"><details><summary>Verified account linking and onboarding controls</summary>';
            echo '<div class="dzn-tw-facts"><div><span>Invitation</span><strong>' . esc_html((string) ($row['invitation']['label'] ?? 'Not sent')) . '</strong></div><div><span>Delivery</span><strong>' . esc_html((string) ($row['delivery']['label'] ?? 'No evidence')) . '</strong></div><div><span>Availability</span><strong>' . esc_html($availabilityTip) . '</strong></div><div><span>Next action</span><strong>' . esc_html((string) ($row['next_action'] ?? '')) . '</strong></div></div>';
            if (($overview['can_link'] ?? false)) {
                echo '<form method="post" class="dzn-tw-form">';
                self::formFields('admin_link_teacher_principal', $teacherId);
                echo '<label>Find account <input type="search" class="dzn-tw-account-search" placeholder="Name or email" autocomplete="off"></label><label>Verified WordPress account <select name="wordpress_user_id" class="dzn-tw-account-select" required><option value="">Choose an account</option>';
                foreach ((array) ($overview['users'] ?? array()) as $user) echo '<option value="' . esc_attr((string) $user->ID) . '">' . esc_html(trim((string) $user->display_name) . ' · ' . (string) $user->user_email) . '</option>';
                echo '</select></label><button class="button">Link verified account</button></form>';
            }
            if (($overview['can_manage'] ?? false) && ($overview['can_revoke'] ?? false) && !(bool) ($row['accepted'] ?? false)) {
                echo '<form method="post" class="dzn-tw-form" onsubmit="return confirm(\'Remove this invitation-only record?\')"><input type="hidden" name="dzn_action" value="revoke_remove_teacher_invitation"><input type="hidden" name="teacher_id" value="' . esc_attr((string) $teacherId) . '">';
                wp_nonce_field('dzn_platform_revoke_remove_teacher_invitation');
                echo '<label>Reason <input type="text" name="reason" maxlength="64" required></label><button class="button">Revoke &amp; remove</button></form>';
            }
            if (($overview['can_manage'] ?? false) && current_user_can('dzn_manage_onboarding') && ($row['review']['state'] ?? '') !== 'approved' && ($row['review']['state'] ?? '') !== 'ready') {
                echo '<form method="post" class="dzn-tw-form" onsubmit="return confirm(\'Reset this onboarding attempt? Only records without protected history can be reset.\')"><input type="hidden" name="dzn_action" value="reset_teacher_onboarding"><input type="hidden" name="teacher_id" value="' . esc_attr((string) $teacherId) . '">';
                wp_nonce_field('dzn_platform_reset_teacher_onboarding');
                echo '<label>Reason <input type="text" name="reason" maxlength="64" required></label><button class="button">Reset onboarding</button></form>';
            }
            echo '</details></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function statusIcon(string $state, string $tip, string $label): string {
        $glyph = match ($state) {
            'complete' => '✓',
            'warning' => '!',
            'error' => '✕',
            'pending' => '○',
            default => '?',
        };
        $class = match ($state) {
            'complete' => 'complete',
            'warning' => 'warning',
            'error' => 'error',
            'pending' => 'pending',
            default => 'unknown',
        };
        return '<span class="dzn-tw-status-icon dzn-tw-status-icon--' . esc_attr($class) . '" tabindex="0" role="img" aria-label="' . esc_attr($tip !== '' ? $tip : $label) . '" title="' . esc_attr($tip !== '' ? $tip : $label) . '" data-dzn-tip="' . esc_attr($tip !== '' ? $tip : $label) . '">' . esc_html($glyph) . '<span class="screen-reader-text">' . esc_html($label !== '' ? $label : $tip) . '</span></span>';
    }

    private static function goldenCell(array $golden): string {
        $minutes = (int) ($golden['minutes'] ?? 0);
        if ($minutes < 1) return '<span class="dzn-tw-muted">No overlap</span>';
        $tip = (string) ($golden['label'] ?? '');
        if (!empty($golden['windows'])) $tip .= ' · ' . implode('; ', (array) $golden['windows']);
        $first = !empty($golden['windows']) ? (string) $golden['windows'][0] : '';
        return '<span title="' . esc_attr($tip) . '">' . esc_html((string) ($golden['minutes_label'] ?? self::minutesLabel($minutes))) . '</span>' . ($first !== '' ? '<span class="dzn-tw-sub">' . esc_html($first) . '</span>' : '');
    }

    private static function rateCell(array $finance): string {
        $rate = $finance['current_rate'] ?? null;
        if (!is_array($rate)) return '<span class="dzn-tw-muted">Not available</span>';
        try { return esc_html(PricingMoney::format((int) $rate['amount_minor'], (string) $rate['currency'])); } catch (\Throwable) { return '<span class="dzn-tw-muted">Not available</span>'; }
    }

    private static function formFields(string $action, int $teacherId): void {
        echo '<input type="hidden" name="dzn_action" value="' . esc_attr($action) . '">';
        if ($teacherId > 0) echo '<input type="hidden" name="teacher_id" value="' . esc_attr((string) $teacherId) . '">';
        wp_nonce_field('dzn_platform_' . $action);
    }

    private static function url(string $page, array $args = array()): string {
        return add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php'));
    }

    private static function statusClass(string $status): string {
        return match ($status) {
            'active' => 'active',
            'inactive', 'access_revoked' => 'unavailable',
            'archived' => 'archived',
            'invitation_queued', 'invitation_available', 'onboarding_required' => 'onboarding',
            default => 'neutral',
        };
    }

    private static function paymentClass(string $state): string {
        return match ($state) {
            'paid' => 'paid',
            'pending' => 'pending',
            'awaiting_statement' => 'awaiting',
            default => 'neutral',
        };
    }

    private static function intakeLabel(string $state): string {
        return match ($state) {
            'accepting' => 'Open · accepting students',
            'limited' => 'Open · limited availability',
            'paused' => 'Paused',
            default => 'Not recorded',
        };
    }

    private static function invitationLabel(string $generationStatus, string $invitationStatus): string {
        if ($invitationStatus === 'revoked') return 'Revoked';
        return match ($generationStatus) {
            'queued_for_delivery' => 'Queued for delivery',
            'active' => 'Sent · awaiting acceptance',
            'claimed' => 'Accepted',
            'expired' => 'Expired',
            'superseded' => 'Replaced',
            default => $invitationStatus === 'active' ? 'Pending' : 'Not sent',
        };
    }

    private static function readinessLabel(string $state): string {
        return match ($state) {
            'complete' => 'Complete',
            'incomplete' => 'Incomplete',
            'connected' => 'Connected',
            'not_connected' => 'Not connected',
            'ready' => 'Ready',
            'not_ready' => 'Not ready',
            default => $state === '' ? 'Not available' : ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private static function reviewLabel(string $state, string $readiness): string {
        if ($state === 'pending_review') return 'Awaiting review';
        if ($state === 'active' && $readiness === 'ready') return 'Approved';
        if ($state === 'returned') return 'Returned for changes';
        if ($state === 'rejected') return 'Rejected';
        if ($state === 'offboarded') return 'Access revoked';
        return $state === '' ? 'Not available' : ucfirst(str_replace('_', ' ', $state));
    }

    private static function availabilityState(string $state): string {
        return match ($state) {
            'preferred' => 'Preferred',
            'requestable' => 'Available on request',
            'blocked' => 'Unavailable',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private static function enrolmentLabel(string $state): string {
        return match ($state) {
            'current' => 'Active',
            'authorised' => 'Authorised',
            'paused' => 'Paused',
            'closed' => 'Closed',
            default => $state === '' ? 'Not recorded' : ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private static function classState(string $lifecycle, string $delivery): string {
        if ($delivery === 'delivered') return 'Completed · delivered';
        if ($delivery === 'not_delivered') return 'Not delivered';
        if ($delivery === 'unknown' && $delivery !== '') return 'Delivery review';
        return match ($lifecycle) {
            'authorised' => 'Scheduled',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => $lifecycle === '' ? 'Not recorded' : ucfirst(str_replace('_', ' ', $lifecycle)),
        };
    }

    private static function responseLabel(array $response): string {
        return self::secondsLabel($response['median_seconds'] ?? $response['average_seconds'] ?? null);
    }

    private static function secondsLabel(mixed $seconds): string {
        if ($seconds === null) return 'Not available';
        $minutes = (int) round(((int) $seconds) / 60);
        if ($minutes < 1) return '< 1 min';
        if ($minutes < 60) return $minutes . ' min';
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $hours . 'h' . ($rest > 0 ? ' ' . $rest . 'm' : '');
    }

    private static function teachingLabel(int $minutes): string {
        if ($minutes < 1) return '0h';
        return self::minutesLabel($minutes);
    }

    private static function minutesLabel(int $minutes): string {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours > 0 && $rest > 0) return $hours . 'h ' . $rest . 'm';
        if ($hours > 0) return $hours . 'h';
        return $rest . 'm';
    }

    private static function minutes(string $value): ?int {
        $value = trim($value);
        if ($value === '24:00' || $value === '24:00:00') return 1440;
        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $value) !== 1) return null;
        return ((int) substr($value, 0, 2)) * 60 + (int) substr($value, 3, 2);
    }

    private static function weekday(int $weekday): string {
        return self::WEEKDAYS[$weekday] ?? 'Day';
    }

    private static function dateValue(string $value): string {
        $value = trim($value);
        if ($value === '') return '—';
        $timestamp = strtotime($value . (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) ? ' UTC' : ''));
        return $timestamp === false ? $value : wp_date('j M Y', $timestamp);
    }

    private static function utcLabel(string $value, string $timezone = ''): string {
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false) return $value;
        if ($timezone !== '') {
            try { return wp_date('j M Y, H:i', $timestamp, new \DateTimeZone($timezone)) . ' ' . $timezone; } catch (\Throwable) {}
        }
        return wp_date('j M Y, H:i', $timestamp) . ' UTC';
    }

    private static function waiting(string $createdAt): string {
        if (trim($createdAt) === '') return '—';
        $timestamp = strtotime($createdAt . ' UTC');
        if ($timestamp === false) return '—';
        $seconds = max(0, time() - $timestamp);
        $days = intdiv($seconds, DAY_IN_SECONDS);
        if ($days > 0) return $days . 'd';
        $hours = intdiv($seconds, HOUR_IN_SECONDS);
        if ($hours > 0) return $hours . 'h';
        return max(1, intdiv($seconds, MINUTE_IN_SECONDS)) . 'm';
    }

    private static function safeError(\Throwable $error): string {
        $message = trim($error->getMessage());
        if (preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1) {
            return ucfirst(str_replace('_', ' ', $message)) . '.';
        }
        return 'The operation could not be completed. No change was saved.';
    }
}
