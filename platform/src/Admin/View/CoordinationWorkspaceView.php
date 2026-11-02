<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\CoordinationCaseController;

/**
 * Human-readable presentation for the Booking Coordination command centre.
 * All forms post back to the same protected route; every mutation is delegated by
 * the controller to the owning application service.
 */
final class CoordinationWorkspaceView {
    private const CAPABILITY_LABELS = array(
        'dzn_manage_booking_request_coordination' => 'Booking coordination',
        'dzn_resolve_booking_request_student_identity' => 'Student identity resolution',
        'dzn_view_student_acceptance_eligibility' => 'Acceptance eligibility review',
        'dzn_manage_student_acceptance_authority' => 'Student/guardian acceptance authority',
        'dzn_manage_teacher_availability_assent' => 'Teacher agreement recording',
        'dzn_issue_booking_request_proposals' => 'Proposal issuance',
        'dzn_record_booking_request_provisional_acceptance' => 'Provisional acceptance recording',
        'dzn_finalize_service_arrangements' => 'Final acceptance',
        'dzn_convert_service_arrangements_to_enrolments' => 'Enrolment conversion',
        'dzn_manage_teacher_assignments' => 'Teacher assignment',
        'dzn_issue_student_account_claims' => 'Student account invitation',
        'dzn_operate_notification_dispatch' => 'Notification dispatch retry',
        'dzn_manage_payment_execution' => 'Payment execution',
        'dzn_manage_exceptions' => 'Exception review',
        'dzn_view_diagnostics' => 'Integration diagnostics',
        'dzn_view_provider_integrations' => 'Provider integration review',
    );

    /** @param array<int,array<string,mixed>> $cases @param array<int,array<string,mixed>> $openable @param array<string,mixed> $filters */
    public static function index(array $cases, array $openable, array $filters): void {
        $active = count(array_filter($cases, static fn(array $case): bool => empty($case['closed'])));
        $closed = count($cases) - $active;
        echo '<div class="wrap dzn-coordination dzn-coordination--index">';
        self::notices();
        echo '<header class="dzn-coordination__hero"><div><p class="dzn-eyebrow">Enrolment administration</p><h1>Booking coordination</h1>';
        echo '<p class="dzn-coordination__lede">One command centre for exceptional booking cases: identity, acceptance authority, Teacher agreement, proposals, final arrangement and the hand-off to Enrolment. Routine bookings that are accepted normally never need this queue.</p></div>';
        echo '<div class="dzn-coordination__hero-actions">';
        if (current_user_can('dzn_view_booking_requests')) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-enrolment-workspace&view=new')) . '">Open booking queue</a>';
        echo '</div></header>';
        echo '<div class="dzn-coordination__kpis">';
        self::kpi((string) $active, 'active coordination cases', 'Needs an operator decision');
        self::kpi((string) $closed, 'closed in this view', 'Readable for audit and recovery');
        self::kpi((string) count(array_filter($cases, static fn(array $case): bool => $case['identity'] === 'unresolved')), 'identity blockers', 'Resolve before final acceptance');
        self::kpi((string) count(array_filter($cases, static fn(array $case): bool => $case['open_exceptions'] > 0)), 'with open exceptions', 'Review in context');
        echo '</div>';
        self::openPanel($openable, (int) ($filters['request_id'] ?? 0));
        $q = (string) ($filters['q'] ?? ''); $scope = (string) ($filters['scope'] ?? 'active');
        echo '<section class="dzn-coordination__section"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Coordination queue</p><h2>Cases</h2></div><span class="dzn-count-chip">' . esc_html(number_format_i18n(count($cases))) . ' shown</span></div>';
        echo '<form method="get" class="dzn-coordination__filters"><input type="hidden" name="page" value="' . esc_attr(CoordinationCaseController::PAGE) . '">';
        echo '<label class="dzn-coordination__search"><span>Search</span><input type="search" name="q" value="' . esc_attr($q) . '" placeholder="Learner, reference, contact, instrument or course"></label>';
        echo '<label><span>Show</span><select name="scope">';
        foreach (array('active' => 'Active cases', 'closed' => 'Closed cases', 'all' => 'All cases') as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($scope, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label><button class="button">Apply</button>';
        if ($q !== '' || $scope !== 'active') echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=' . CoordinationCaseController::PAGE)) . '">Reset</a>';
        echo '</form>';
        if (!$cases) {
            echo '<div class="dzn-empty-state"><h3>No coordination cases match</h3><p>Open a case from an unresolved booking request, or reset the filters. Routine confirmed bookings are handled in the normal booking queue and do not require coordination.</p></div>';
        } else {
            echo '<div class="dzn-coordination__table-wrap"><table class="widefat striped dzn-coordination__table"><thead><tr><th>Learner</th><th>Request</th><th>Offer</th><th>State</th><th>Progress</th><th>Updated</th><th><span class="screen-reader-text">Open</span></th></tr></thead><tbody>';
            foreach ($cases as $case) {
                $url = add_query_arg(array('page' => CoordinationCaseController::PAGE, 'case_id' => (int) $case['id']), admin_url('admin.php'));
                echo '<tr>';
                echo '<td><a class="dzn-coordination__case-link" href="' . esc_url($url) . '"><strong>' . esc_html((string) $case['learner']) . '</strong><small>' . esc_html((string) $case['contact']) . '</small></a></td>';
                echo '<td><strong>' . esc_html((string) $case['request_reference']) . '</strong><br><small>' . esc_html((string) $case['reference']) . '</small></td>';
                echo '<td>' . esc_html((string) $case['instrument']) . '<br><small>' . esc_html((string) $case['course']) . ' · ' . esc_html((string) $case['lesson_kind']) . '</small></td>';
                echo '<td>' . self::badge((string) $case['state_label'], $case['closed'] ? 'muted' : 'teal') . '<br><small>' . esc_html((string) $case['identity_label']) . '</small></td>';
                echo '<td><span class="dzn-coordination__progress">' . esc_html((string) $case['active_candidates']) . ' candidates · ' . esc_html((string) $case['current_assents']) . ' agreements · ' . esc_html((string) $case['current_proposals']) . ' proposals</span><br><small>' . ($case['arrangements'] > 0 ? 'Final arrangement recorded' : ($case['acceptance_events'] > 0 ? 'Response recorded; final arrangement pending' : 'Acceptance pending')) . '</small></td>';
                echo '<td>' . esc_html((string) $case['updated_label']) . '<br><small>' . ($case['open_exceptions'] > 0 ? esc_html((string) $case['open_exceptions']) . ' open exception(s)' : 'No open exception') . '</small></td>';
                echo '<td><a class="button button-small" href="' . esc_url($url) . '">Open case</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
        echo '<aside class="dzn-coordination__routine-note"><h2>Routine or exceptional?</h2><p>A booking that a Student can request and a Teacher can accept normally stays in the standard booking flow. Use coordination when identity, authority, matching, evidence, payment or a public workflow exception needs a human decision. Coordination never replaces the routine booking path.</p></aside>';
        echo '</div>';
    }

    /** @param array<string,mixed> $detail */
    public static function detail(array $detail): void {
        $case = $detail['case'];
        $request = $detail['request'];
        $permissions = $detail['permissions'];
        $assessment = $detail['assessment'];
        echo '<div class="wrap dzn-coordination dzn-coordination--detail">';
        self::notices();
        echo '<p class="dzn-back"><a href="' . esc_url(admin_url('admin.php?page=' . CoordinationCaseController::PAGE)) . '">← Back to coordination cases</a></p>';
        echo '<header class="dzn-coordination__case-head"><div><p class="dzn-eyebrow">' . esc_html((string) $case['reference']) . ' · ' . esc_html((string) $request['reference']) . '</p><h1>' . esc_html((string) ($detail['student']['name'] ?? $request['learner_name'])) . '</h1><p>' . esc_html((string) $request['instrument']) . ' · ' . esc_html((string) $request['course']) . ' · ' . esc_html((string) $request['lesson_kind']) . '</p></div>';
        echo '<div class="dzn-coordination__case-state">' . self::badge((string) $case['state_label'], $case['closed'] ? 'muted' : 'teal') . self::badge((string) ($detail['identity'] === 'resolved' ? 'Identity verified' : 'Identity needed'), $detail['identity'] === 'resolved' ? 'success' : 'warning') . self::badge((string) $detail['capacity']['label'], $detail['capacity']['resolved'] ? 'success' : 'warning') . '</div></header>';
        echo '<nav class="dzn-coordination__top-actions" aria-label="Essential case actions">';
        self::topAction('Case overview', '#overview', true);
        self::topAction('Resolve blockers', '#action-required', !empty($assessment['blockers']));
        self::topAction('Teacher candidates', '#teacher-matching', !empty($permissions['coordination']));
        self::topAction('Agreements', '#agreements', !empty($permissions['assent']));
        self::topAction('Proposals', '#proposals', !empty($permissions['proposal']));
        self::topAction('Acceptance', '#acceptance', !empty($permissions['provisional']) || !empty($permissions['final_acceptance']));
        echo '</nav>';
        self::summary($detail);
        self::actionRequired($detail);
        self::recommendation($detail);
        echo '<nav class="dzn-coordination__workspace-nav" aria-label="Coordination workspace sections">';
        foreach (array(
            'overview' => 'Request details', 'identity' => 'Student', 'teacher-matching' => 'Teachers',
            'agreements' => 'Agreements', 'proposals' => 'Proposals', 'acceptance' => 'Acceptance',
            'enrolment' => 'Enrolment', 'scheduling' => 'Scheduling', 'finance' => 'Finance',
            'communications' => 'Communications', 'case-state' => 'Case state', 'activity' => 'Activity',
        ) as $anchor => $label) echo '<a href="#' . esc_attr($anchor) . '">' . esc_html($label) . '</a>';
        echo '</nav>';
        echo '<div class="dzn-coordination__grid"><main class="dzn-coordination__main">';
        self::overview($detail);
        self::identity($detail);
        self::teacherMatching($detail);
        self::agreements($detail);
        self::proposals($detail);
        self::acceptance($detail);
        self::enrolment($detail);
        self::scheduling($detail);
        echo '</main><aside class="dzn-coordination__side">';
        self::finance($detail);
        self::communications($detail);
        self::caseState($detail);
        self::activity($detail);
        self::advanced($detail);
        echo '</aside></div></div>';
    }

    /** @param array<string,mixed> $detail */
    private static function summary(array $detail): void {
        $request = $detail['request'];
        $student = $detail['student'];
        $arrangement = $detail['arrangement'];
        $enrolment = $detail['enrolment'];
        $teacher = '';
        foreach ($detail['candidates'] as $candidate) if (!empty($candidate['assent'])) { $teacher = (string) $candidate['teacher_name']; break; }
        if ($teacher === '' && $arrangement) $teacher = (string) $arrangement['teacher_name'];
        echo '<section class="dzn-coordination__summary" aria-label="Case summary">';
        self::summaryCard('Student', $student ? (string) $student['name'] : 'Identity unresolved', $student ? (string) $student['reference'] . ' · ' . $student['status_label'] : 'Resolve the identity before final acceptance.', $student ? 'success' : 'warning');
        self::summaryCard('Request', (string) $request['lesson_kind'], (string) $request['instrument'] . ' · ' . (string) $request['course'], 'teal');
        self::summaryCard('Preferred schedule', count((array) $request['times']) . ' option' . (count((array) $request['times']) === 1 ? '' : 's'), self::timesLine((array) $request['times']), count((array) $request['times']) ? 'teal' : 'warning');
        self::summaryCard('Teacher', $teacher !== '' ? $teacher : 'Not yet agreed', $arrangement ? 'Accepted in final arrangement' : 'Advisory candidates do not reserve capacity.', $teacher !== '' ? 'success' : 'muted');
        self::summaryCard('Arrangement', $arrangement ? (string) $arrangement['reference'] : 'Not finalised', $arrangement ? 'Accepted by ' . (string) $arrangement['authority_label'] : 'Proposal and acceptance evidence are prerequisites.', $arrangement ? 'success' : 'muted');
        self::summaryCard('Enrolment', $enrolment ? (string) $enrolment['reference'] : 'Not created', $enrolment ? (string) $enrolment['label'] . ($detail['assignment'] ? ' · Teacher assigned' : ' · Assignment pending') : 'Conversion follows final acceptance.', $enrolment ? 'success' : 'muted');
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function actionRequired(array $detail): void {
        $assessment = $detail['assessment'];
        echo '<section class="dzn-coordination__section dzn-coordination__section--blockers" id="action-required"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Prioritised by operational impact</p><h2>Action required</h2></div><span class="dzn-count-chip">' . esc_html((string) count($assessment['blockers'])) . ' open</span></div>';
        if (!$assessment['blockers']) {
            echo '<div class="dzn-coordination__resolved"><strong>No blockers detected.</strong><p>The supported coordination lifecycle is complete or requires no further decision. Review the activity and advanced details for evidence.</p></div>';
            return;
        }
        foreach ($assessment['blockers'] as $blocker) {
            echo '<article class="dzn-blocker dzn-blocker--' . esc_attr((string) $blocker['severity']) . '">';
            echo '<div class="dzn-blocker__head"><span class="dzn-blocker__dot" aria-hidden="true"></span><div><h3>' . esc_html((string) $blocker['title']) . '</h3><p><span class="screen-reader-text">What is missing: </span>' . esc_html((string) $blocker['what']) . '</p></div></div>';
            echo '<dl class="dzn-blocker__facts"><div><dt>Why it matters</dt><dd>' . esc_html((string) $blocker['why']) . '</dd></div><div><dt>Evidence required</dt><dd>' . esc_html((string) $blocker['evidence']) . '</dd></div><div><dt>What to do</dt><dd>' . esc_html((string) $blocker['resolve']) . '</dd></div><div><dt>Once resolved</dt><dd>' . esc_html((string) $blocker['unlocks']) . '</dd></div></dl>';
            if (!empty($blocker['actions'])) {
                $actions = $blocker['actions'];
                if (isset($actions['label'])) $actions = array($actions);
                echo '<div class="dzn-blocker__actions">';
                foreach ($actions as $action) {
                    if (!empty($action['available'])) echo '<a class="button button-primary" href="' . esc_url((string) $action['anchor']) . '">' . esc_html((string) $action['label']) . '</a>';
                    else echo '<span class="dzn-disabled-action" aria-disabled="true">' . esc_html((string) $action['label']) . '<small>Requires ' . esc_html(self::capabilityLabel((string) $action['capability'])) . '</small></span>';
                }
                echo '</div>';
            }
            echo '</article>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function recommendation(array $detail): void {
        $assessment = $detail['assessment'];
        $recommended = $assessment['recommended'];
        echo '<section class="dzn-coordination__recommend" aria-labelledby="dzn-recommended"><div class="dzn-coordination__recommend-copy"><p class="dzn-eyebrow">Recommended next action</p><h2 id="dzn-recommended">' . esc_html((string) ($recommended['label'] ?? 'Review the case')) . '</h2><p>' . esc_html((string) ($recommended['reason'] ?? 'Review the authoritative case state.')) . '</p></div>';
        if ($recommended) {
            if (!empty($recommended['available'])) echo '<a class="button button-primary" href="' . esc_url((string) ($recommended['anchor'] ?? '#workspace')) . '">' . esc_html((string) $recommended['label']) . '</a>';
            else echo '<span class="dzn-disabled-action" aria-disabled="true">' . esc_html((string) $recommended['label']) . '<small>Requires ' . esc_html(self::capabilityLabel((string) ($recommended['capability'] ?? ''))) . '</small></span>';
        }
        if (!empty($assessment['alternatives'])) {
            echo '<div class="dzn-coordination__alternatives"><strong>Other valid actions</strong><ul>';
            foreach ($assessment['alternatives'] as $alternative) {
                echo '<li>';
                if (!empty($alternative['available'])) echo '<a href="' . esc_url((string) $alternative['anchor']) . '">' . esc_html((string) $alternative['label']) . '</a>';
                else echo '<span aria-disabled="true">' . esc_html((string) $alternative['label']) . '</span> <small>Requires ' . esc_html(self::capabilityLabel((string) $alternative['capability'])) . '</small>';
                echo '</li>';
            }
            echo '</ul><p>These alternatives are shown so the administrator is not forced into a one-directional wizard. The backend still validates every chosen action.</p></div>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function overview(array $detail): void {
        $request = $detail['request'];
        echo '<section class="dzn-coordination__section" id="overview"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Case overview</p><h2>Request details</h2></div></div>';
        echo '<dl class="dzn-facts dzn-facts--two">';
        self::fact('Learner', (string) $request['learner_name']);
        self::fact('Contact', trim(implode(' · ', array_filter(array((string) $request['email'], (string) $request['phone'])))));
        self::fact('City / timezone', trim(implode(' · ', array_filter(array((string) $request['city'], (string) $request['country'], (string) $request['timezone'])))));
        self::fact('Request reference', (string) $request['reference']);
        self::fact('Offer', (string) $request['instrument'] . ' · ' . (string) $request['course']);
        self::fact('Classification', (string) $request['lesson_kind']);
        self::fact('Received', (string) $request['created_at'] . ' UTC' . ((string) $request['age_label'] !== '' ? ' · ' . $request['age_label'] : ''));
        self::fact('Current stage', (string) $request['stage_label']);
        self::fact('Last update', (string) $detail['case']['updated_at'] . ' UTC · ' . (string) $detail['case']['updated_by_name']);
        self::fact('Responsible operator', (string) $detail['case']['updated_by_name']);
        echo '</dl>';
        echo '<h3>Preferred scheduling options</h3>';
        if (!$request['times']) {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>No preferred time is recorded.</strong> Matching and scheduling cannot proceed until the learner supplies one through an authorised path.</div>';
        } else {
            echo '<ol class="dzn-time-list dzn-time-list--rich">';
            foreach ($request['times'] as $time) {
                echo '<li><span class="dzn-time-list__seq">' . esc_html((string) $time['sequence']) . '</span><div><strong>' . esc_html((string) $time['label']) . '</strong><small>' . esc_html((string) $time['duration_minutes']) . ' minutes · ' . esc_html((string) $time['timezone']) . ' · ' . esc_html((string) $time['status_label']) . '</small></div></li>';
            }
            echo '</ol>';
        }
        if (!empty($request['confirmed'])) {
            $confirmed = $request['confirmed'];
            echo '<div class="dzn-confirmed-booking"><h3>Confirmed introductory booking</h3><p><strong>' . esc_html((string) $confirmed['label']) . '</strong> · ' . esc_html((string) $confirmed['teacher_name']) . '</p><p>' . esc_html((string) $confirmed['state_label']) . ' · provider ' . esc_html((string) ($confirmed['provider_code'] ?: 'not recorded')) . '</p>';
            if ((string) ($confirmed['state'] ?? '') === 'confirmed' && current_user_can(\Delnavazan\Platform\Core\Application\ConfirmedIntroBookingCancellationService::CAPABILITY)) self::cancelConfirmedIntroForm($detail, $confirmed);
            elseif ((string) ($confirmed['state'] ?? '') === 'cancelled') echo '<p class="dzn-inline-note"><strong>Cancelled.</strong> Retraction and the Student notice are recorded; history is retained.</p>';
            echo '</div>';
        }
        echo '<p class="dzn-inline-note"><strong>Supported edit boundary:</strong> submitted preferred times are never rewritten. A confirmed introductory booking can now be cancelled through the audited cancellation authority above; a new request remains the normal rebooking path.</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function identity(array $detail): void {
        $identity = (string) $detail['identity'];
        $student = $detail['student'];
        $permissions = $detail['permissions'];
        echo '<section class="dzn-coordination__section" id="identity"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Canonical identity</p><h2>Student</h2></div>' . self::badge($student ? 'Verified Student' : 'Identity unresolved', $student ? 'success' : 'warning') . '</div>';
        if ($student) {
            echo '<dl class="dzn-facts dzn-facts--two">';
            self::fact('Student', (string) $student['name']);
            self::fact('Reference', (string) $student['reference']);
            self::fact('Email', (string) $student['email']);
            self::fact('Phone', (string) $student['phone']);
            self::fact('Timezone', (string) $student['timezone']);
            self::fact('Status', (string) $student['status_label']);
            echo '</dl>';
        } else {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>No verified Student is linked.</strong> Coordination can keep advisory candidates, but capacity, guardian authority and final acceptance are blocked. Resolve identity with reviewed evidence; contact details never create a Student automatically.</div>';
            if (!empty($permissions['identity_resolve'])) {
                echo '<details class="dzn-disclosure" open><summary>Resolve student identity</summary><div class="dzn-disclosure__body">';
                self::formStart($detail, 'resolve_identity');
                echo '<div class="dzn-field-grid"><label class="dzn-field"><span>Existing verified Student</span><select name="student_id"><option value="">Choose an existing Student</option>';
                foreach ((array) ($detail['options']['students'] ?? array()) as $choice) echo '<option value="' . esc_attr((string) $choice['id']) . '">' . esc_html((string) $choice['label']) . '</option>';
                echo '</select><small>Leave blank to create a reviewed Student from the name below.</small></label>';
                echo '<label class="dzn-field"><span>Reviewed display name</span><input type="text" name="display_name" maxlength="191" autocomplete="off"><small>Only when creating a distinct reviewed Student.</small></label>';
                self::evidenceFields((string) $detail['request']['timezone']);
                echo '</div><button class="button button-primary">Resolve identity</button></form>';
                self::formStart($detail, 'record_identity_outcome');
                echo '<div class="dzn-field-grid"><label class="dzn-field"><span>Or record a reviewed blocker</span><select name="outcome"><option value="ambiguous">Ambiguous identity</option><option value="rejected">Identity rejected</option><option value="unresolved">Keep unresolved</option></select></label>';
                self::evidenceFields((string) $detail['request']['timezone']);
                echo '</div><button class="button">Record blocker</button></form>';
                echo '</div></details>';
            } else {
                self::permissionNotice('dzn_resolve_booking_request_student_identity');
            }
        }
        if ($detail['proposal']['family']) {
            echo '<p class="dzn-field-help">Proposal history is visible in the <a href="#proposals">Proposals</a> section. Identity evidence remains append-only; corrections use the canonical review authority.</p>';
        }
        echo '</section>';
        self::acceptanceAuthority($detail);
    }

    /** @param array<string,mixed> $detail */
    private static function acceptanceAuthority(array $detail): void {
        $permissions = $detail['permissions'];
        $student = $detail['student'];
        $capacity = $detail['capacity'];
        $authority = $detail['authority'];
        if (!$student) return;
        echo '<section class="dzn-coordination__section" id="acceptance-authority"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Who can accept</p><h2>Acceptance authority</h2></div>' . self::badge((string) $capacity['label'], $capacity['resolved'] ? 'success' : 'warning') . '</div>';
        echo '<dl class="dzn-facts dzn-facts--two">';
        self::fact('Capacity', (string) $capacity['label'], (string) ($capacity['evidence_channel'] !== '' ? 'Evidence: ' . $capacity['evidence_channel'] : 'No current classification evidence.'));
        self::fact('Authority route', $authority['route'] === 'adult_principal' ? 'Adult self' : ($authority['route'] === 'guardian' ? 'Guardian representative' : 'Missing'));
        self::fact('Student principals', $authority['principals'] ? implode(', ', array_map(static fn(array $row): string => (string) $row['name'], $authority['principals'])) : 'None current');
        self::fact('Guardian representatives', $authority['guardians'] ? implode(', ', array_map(static fn(array $row): string => (string) $row['name'], $authority['guardians'])) : 'None current');
        echo '</dl>';
        if (!$capacity['resolved'] || $authority['route'] === 'missing') {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>Final acceptance is unavailable until this is resolved.</strong> This is not a frontend-only checkbox: Delnavazan needs reviewed capacity evidence and a current accepting account before it can create immutable acceptance evidence.</div>';
        }
        if (!empty($permissions['authority_manage'])) {
            $classification = (string) ($capacity['classification'] ?: 'unknown');
            echo '<div class="dzn-authority-grid">';
            echo '<details class="dzn-disclosure"' . (!$capacity['resolved'] ? ' open' : '') . '><summary>Classify acceptance capacity</summary><div class="dzn-disclosure__body">';
            self::formStart($detail, 'classify_capacity');
            echo '<div class="dzn-field-grid"><label class="dzn-field"><span>Classification</span><select name="classification">';
            foreach (array('adult' => 'Adult learner', 'minor' => 'Minor (guardian authority required)', 'unknown' => 'Unknown / defer') as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($classification, $value, false) . '>' . esc_html($label) . '</option>';
            echo '</select></label>';
            self::evidenceFields((string) $detail['request']['timezone']);
            echo '</div><button class="button button-primary">Save classification</button></form></div></details>';
            if ($classification === 'adult') {
                if ($authority['principals']) {
                    echo '<p class="dzn-field-help">A current Student principal is recorded. Adult self authority is ready for final acceptance.</p>';
                } else {
                    echo '<details class="dzn-disclosure" open><summary>Establish Student principal</summary><div class="dzn-disclosure__body">';
                    self::formStart($detail, 'establish_principal');
                    echo '<label class="dzn-field"><span>Verified WordPress account</span><select name="wordpress_user_id" required><option value="">Choose account</option>';
                    foreach ((array) ($detail['options']['assignable_accounts'] ?? array()) as $account) echo '<option value="' . esc_attr((string) $account['id']) . '">' . esc_html((string) $account['name']) . '</option>';
                    echo '</select><small>The service refuses a second current principal for this Student or account.</small></label>';
                    self::evidenceFields((string) $detail['request']['timezone']);
                    echo '<button class="button button-primary">Establish principal</button></form></div></details>';
                }
            } elseif ($classification === 'minor') {
                if ($authority['guardians']) {
                    echo '<p class="dzn-field-help">A current guardian representative is recorded. Guardian authority is ready for final acceptance.</p>';
                } else {
                    echo '<details class="dzn-disclosure" open><summary>Grant guardian representative authority</summary><div class="dzn-disclosure__body">';
                    self::formStart($detail, 'grant_guardian');
                    echo '<label class="dzn-field"><span>Verified WordPress account</span><select name="wordpress_user_id" required><option value="">Choose account</option>';
                    foreach ((array) ($detail['options']['assignable_accounts'] ?? array()) as $account) echo '<option value="' . esc_attr((string) $account['id']) . '">' . esc_html((string) $account['name']) . '</option>';
                    echo '</select></label><label class="dzn-field"><span>Authority valid until (local, optional)</span><input type="datetime-local" name="effective_until"></label>';
                    self::evidenceFields((string) $detail['request']['timezone']);
                    echo '<button class="button button-primary">Grant guardian authority</button></form></div></details>';
                }
            }
            echo '</div>';
        } else {
            self::permissionNotice('dzn_manage_student_acceptance_authority');
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function teacherMatching(array $detail): void {
        $permissions = $detail['permissions'];
        echo '<section class="dzn-coordination__section" id="teacher-matching"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Advisory only</p><h2>Teacher matching</h2></div><span class="dzn-count-chip">' . esc_html((string) count($detail['candidates'])) . ' considered</span></div>';
        echo '<p class="dzn-inline-note">Candidates are advisory. Adding one does not contact, reserve, assign, or select a Teacher. Eligibility, accepting state, availability and real occupancy are shown below; the canonical services re-check all of them when an agreement, proposal or booking is recorded.</p>';
        if (!empty($permissions['coordination'])) {
            echo '<details class="dzn-disclosure" open><summary>Add a Teacher candidate</summary><div class="dzn-disclosure__body">';
            self::formStart($detail, 'add_candidate');
            echo '<div class="dzn-field-grid"><label class="dzn-field"><span>Teacher</span><select name="teacher_id" required><option value="">Choose a Teacher</option>';
            foreach ((array) ($detail['options']['teachers'] ?? array()) as $teacher) {
                $flags = array();
                if (!$teacher['ready']) $flags[] = 'not ready';
                if (!$teacher['accepting']) $flags[] = 'not accepting';
                if (!$teacher['eligible']) $flags[] = 'course ineligible';
                echo '<option value="' . esc_attr((string) $teacher['id']) . '">' . esc_html((string) $teacher['label'] . ($flags ? ' · ' . implode(', ', $flags) : '')) . '</option>';
            }
            echo '</select></label><label class="dzn-field"><span>Why this Teacher?</span><select name="source"><option value="manual_search">Manual search</option><option value="advisory_match">Advisory match</option><option value="student_or_guardian_preference">Student or guardian preference</option><option value="teacher_referral">Teacher referral</option><option value="operational_referral">Operational referral</option><option value="approved_exception">Approved exception</option></select></label></div>';
            echo '<button class="button button-primary">Add candidate</button></form></div></details>';
        } else {
            self::permissionNotice('dzn_manage_booking_request_coordination');
        }
        if (!$detail['candidates']) {
            echo '<div class="dzn-empty-state dzn-empty-state--inline"><h3>No Teacher candidate yet</h3><p>The recommended next action will guide you through adding a candidate. Nothing is assigned or reserved by this step.</p></div>';
        } else {
            echo '<div class="dzn-candidate-list">';
            foreach ($detail['candidates'] as $candidate) {
                echo '<article class="dzn-candidate' . (!empty($candidate['viable']) ? ' is-viable' : '') . '">';
                echo '<div class="dzn-candidate__head"><div><h3>' . esc_html((string) $candidate['teacher_name']) . '</h3><p>' . esc_html((string) $candidate['teacher_email']) . ' · ' . esc_html((string) $candidate['timezone']) . '</p></div>' . self::badge((string) $candidate['status_label'], in_array((string) $candidate['status'], array('not_available', 'not_suitable', 'withdrawn_from_consideration', 'superseded', 'closed'), true) ? 'muted' : 'teal') . '</div>';
                echo '<ul class="dzn-candidate__badges">';
                echo '<li class="' . ($candidate['ready'] ? 'is-ok' : 'is-warn') . '">' . ($candidate['ready'] ? 'Ready' : 'Not ready') . '</li>';
                echo '<li class="' . ($candidate['accepting'] === 'accepting' || $candidate['accepting'] === 'limited' ? 'is-ok' : 'is-warn') . '">' . esc_html(ucfirst((string) $candidate['accepting'])) . '</li>';
                echo '<li class="' . ($candidate['eligible'] ? 'is-ok' : 'is-warn') . '">' . ($candidate['eligible'] ? 'Course eligible' : 'Not course eligible') . '</li>';
                echo '<li class="' . ($candidate['workload'] < 30 ? 'is-ok' : 'is-warn') . '">' . esc_html((string) $candidate['workload']) . ' classes in 60 days</li>';
                echo '</ul>';
                echo '<dl class="dzn-facts dzn-facts--two"><div><dt>Source</dt><dd>' . esc_html((string) $candidate['source_label']) . '</dd></div><div><dt>Availability</dt><dd>' . esc_html((string) $candidate['availability']['label']) . (($candidate['availability']['note'] ?? '') !== '' ? '<small>' . esc_html((string) $candidate['availability']['note']) . '</small>' : '') . '</dd></div><div><dt>Teacher conflicts</dt><dd>' . ((int) $candidate['teacher_conflicts'] > 0 ? '<span class="dzn-danger">' . esc_html((string) $candidate['teacher_conflicts']) . ' real collision(s)</span>' : 'None found') . '</dd></div><div><dt>Student conflicts</dt><dd>' . ((int) $candidate['student_conflicts'] > 0 ? '<span class="dzn-danger">' . esc_html((string) $candidate['student_conflicts']) . ' overlap(s)</span>' : 'None found') . '</dd></div></dl>';
                if (!empty($candidate['conflicts'])) {
                    echo '<ul class="dzn-conflict-list">';
                    foreach ($candidate['conflicts'] as $conflict) echo '<li><strong>Preferred time ' . esc_html((string) $conflict['sequence']) . '</strong><span>' . esc_html((string) $conflict['label']) . ($conflict['teacher'] ? ' · Teacher has an existing class' : '') . ($conflict['student'] ? ' · Student already occupied' : '') . '</span></li>';
                    echo '</ul>';
                }
                if (!empty($candidate['blockers'])) {
                    echo '<ul class="dzn-candidate__blockers">';
                    foreach ($candidate['blockers'] as $blocker) echo '<li>' . esc_html((string) $blocker) . '</li>';
                    echo '</ul>';
                }
                if (!empty($permissions['eligibility'])) {
                    echo '<details class="dzn-disclosure dzn-disclosure--inline"><summary>Teacher eligibility and accepting state</summary><div class="dzn-disclosure__body"><p class="dzn-field-help">These are authority-wide Teacher facts. A change affects every booking, not only this case. The canonical eligibility service validates them immediately.</p><div class="dzn-authority-grid">';
                    self::formStart($detail, 'teacher_eligibility', array('teacher_id' => (int) $candidate['teacher_id']));
                    echo '<div class="dzn-inline-form"><label class="dzn-field"><span>Course eligibility</span><select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label><button class="button">Save eligibility</button></div></form>';
                    self::formStart($detail, 'teacher_accepting_state', array('teacher_id' => (int) $candidate['teacher_id']));
                    echo '<div class="dzn-inline-form"><label class="dzn-field"><span>Accepting state</span><select name="state"><option value="accepting">Accepting</option><option value="limited">Limited</option><option value="paused">Paused</option></select></label><button class="button">Save accepting state</button></div></form>';
                    echo '</div></div></details>';
                }
                if (!empty($permissions['coordination'])) {
                    self::formStart($detail, 'candidate_status', array('candidate_id' => (int) $candidate['id'], 'version' => (int) $candidate['version']));
                    echo '<div class="dzn-inline-form"><label class="dzn-field"><span>Candidate decision</span><select name="status">';
                    foreach (array('unreviewed' => 'Not yet reviewed', 'under_discussion' => 'Under discussion', 'not_available' => 'Not available', 'not_suitable' => 'Not suitable', 'withdrawn_from_consideration' => 'Withdrawn from consideration', 'superseded' => 'Superseded', 'closed' => 'Closed') as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected((string) $candidate['status'], $value, false) . '>' . esc_html($label) . '</option>';
                    echo '</select></label><button class="button">Save candidate state</button></div></form>';
                }
                echo '</article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function agreements(array $detail): void {
        $permissions = $detail['permissions'];
        echo '<section class="dzn-coordination__section" id="agreements"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Teacher willingness</p><h2>Agreements</h2></div></div>';
        echo '<p class="dzn-inline-note">Teacher Availability Assent is attributable, time-bounded evidence for frozen facts. It does not reserve capacity, assign a Teacher, or create acceptance. A withdrawn or expired agreement cannot issue a Proposal.</p>';
        $viable = array_values(array_filter($detail['candidates'], static fn(array $c): bool => !empty($c['viable'])));
        if (!$viable) echo '<div class="dzn-empty-state dzn-empty-state--inline"><h3>No viable candidate</h3><p>Add or revise a Teacher candidate first. Agreement controls appear only when the canonical candidate context is viable.</p></div>';
        foreach ($viable as $candidate) {
            $assent = $candidate['assent'];
            echo '<article class="dzn-agreement' . ($assent ? ' is-recorded' : '') . '"><div class="dzn-agreement__head"><div><h3>' . esc_html((string) $candidate['teacher_name']) . '</h3><p>' . ($assent ? 'Current agreement recorded' : 'No current agreement') . '</p></div>' . self::badge($assent ? 'Agreement recorded' : 'Action needed', $assent ? 'success' : 'warning') . '</div>';
            if ($assent) {
                echo '<dl class="dzn-facts dzn-facts--two"><div><dt>Recorded</dt><dd>' . esc_html((string) $assent['recorded_at']) . ' UTC</dd></div><div><dt>Valid until</dt><dd>' . esc_html((string) ($assent['valid_until'] ?: 'Review date only')) . ' UTC</dd></div><div><dt>Evidence</dt><dd>' . esc_html((string) $assent['label']) . '</dd></div><div><dt>Candidate</dt><dd>' . esc_html((string) $candidate['status_label']) . '</dd></div></dl>';
                if (!empty($permissions['assent'])) {
                    self::formStart($detail, 'withdraw_assent', array('assent_id' => (int) $assent['id'], 'version' => (int) $assent['version']));
                    echo '<div class="dzn-inline-form"><label class="dzn-field"><span>Retire this agreement</span><select name="reason"><option value="teacher_withdrew">Teacher withdrew</option><option value="operational_unavailable">Operationally unavailable</option><option value="eligibility_changed">Eligibility changed</option><option value="attribution_unreliable">Attribution unreliable</option><option value="material_facts_changed">Material facts changed</option></select></label><button class="button">Withdraw agreement</button></div></form>';
                    self::formStart($detail, 'invalidate_assent', array('assent_id' => (int) $assent['id'], 'version' => (int) $assent['version']));
                    echo '<div class="dzn-inline-form"><label class="dzn-field"><span>Or invalidate from this point</span><select name="reason"><option value="attribution_unreliable">Attribution unreliable</option><option value="material_facts_changed">Material facts changed</option><option value="operational_unavailable">Operationally unavailable</option><option value="eligibility_changed">Eligibility changed</option></select></label><button class="button">Invalidate agreement</button></div></form>';
                }
                if (empty($candidate['option']) && !empty($permissions['proposal'])) {
                    self::formStart($detail, 'issue_proposal_initial', array('candidate_id' => (int) $candidate['id']));
                    echo '<div class="dzn-inline-form"><p class="dzn-field-help">The current agreement supplies the exact frozen facts and fingerprint. No technical value is required from the operator.</p><button class="button button-primary">Issue Proposal from this agreement</button></div></form>';
                }
            } else {
                if (empty($permissions['assent'])) { self::permissionNotice('dzn_manage_teacher_availability_assent'); continue; }
                echo '<details class="dzn-disclosure" open><summary>Record Teacher agreement</summary><div class="dzn-disclosure__body">';
                self::formStart($detail, 'record_assent', array('candidate_id' => (int) $candidate['id'], 'candidate_version' => (int) $candidate['version'], 'teacher_id' => (int) $candidate['teacher_id']));
                $times = (array) $detail['request']['times'];
                $first = $times[0] ?? array();
                echo '<div class="dzn-field-grid">';
                echo '<label class="dzn-field"><span>Course</span><select name="course_id">';
                echo '<option value="">Choose the intended Course</option>';
                echo '<option value="' . esc_attr((string) (int) $detail['request']['course_id']) . '" selected>' . esc_html((string) (int) $detail['request']['course_id'] > 0 ? (string) $detail['request']['course'] : 'Unresolved introductory Course') . '</option>';
                echo '</select><small>The Teacher must be currently eligible for this Course. An unresolved introductory Course remains explicitly unresolved.</small></label>';
                echo '<label class="dzn-field"><span>Delivery</span><select name="delivery_mode"><option value="online">Online</option><option value="in_person">In person</option><option value="hybrid">Hybrid</option></select></label>';
                echo '<label class="dzn-field"><span>Location scope</span><select name="location_scope"><option value="not_applicable">Not applicable</option><option value="teacher_location">Teacher location</option><option value="student_location">Student location</option><option value="location_to_be_agreed">To be agreed</option></select></label>';
                echo '<label class="dzn-field"><span>Frequency per week</span><input type="number" name="frequency_per_week" min="1" max="14" value="1" required></label>';
                echo '<label class="dzn-field"><span>Lesson length (minutes)</span><input type="number" name="expected_duration_minutes" min="15" max="480" value="' . esc_attr((string) ((int) ($first['duration_minutes'] ?? 30))) . '" required></label>';
                echo '<label class="dzn-field"><span>Commencement from (local)</span><input type="datetime-local" name="commencement_window_start" value="' . esc_attr(self::localInput((string) ($first['starts_at_utc'] ?? ''), (string) $detail['request']['timezone'])) . '" required><small>Converted to UTC by the server.</small></label>';
                echo '<label class="dzn-field"><span>Commencement until (local)</span><input type="datetime-local" name="commencement_window_end" value="' . esc_attr(self::localInput((string) ($first['starts_at_utc'] ?? ''), (string) $detail['request']['timezone'], '+14 days')) . '" required></label>';
                echo '<label class="dzn-field"><span>Agreement valid until (local)</span><input type="datetime-local" name="valid_until" value="' . esc_attr(self::localInput(gmdate('Y-m-d H:i:s', strtotime('+30 days')), (string) $detail['request']['timezone'])) . '"></label>';
                echo '<label class="dzn-field"><span>Review by (local, optional)</span><input type="datetime-local" name="review_by"></label>';
                echo '<label class="dzn-field"><span>Conditions</span><select name="conditions_code"><option value="none">None</option><option value="subject_to_later_assignment">Subject to later assignment</option><option value="subject_to_course_resolution">Subject to Course resolution</option></select></label>';
                echo '<label class="dzn-field"><span>Attribution basis</span><select name="attribution_basis"><option value="direct_teacher_statement">Direct Teacher statement</option><option value="authorised_staff_witness">Authorised staff witness</option></select></label>';
                echo '<label class="dzn-field"><span>Evidence channel</span><select name="evidence_channel"><option value="phone">Phone</option><option value="in_person">In person</option><option value="email_reference">Email reference</option><option value="message_reference">Message reference</option><option value="other_reference">Other reference</option></select></label>';
                echo '<label class="dzn-field"><span>Agreement observed (local)</span><input type="datetime-local" name="evidence_at" value="' . esc_attr(self::localNow((string) $detail['request']['timezone'], '-5 minutes')) . '" required></label>';
                echo '<label class="dzn-field"><span>Uncertainty (optional)</span><select name="uncertainty_code"><option value="">None recorded</option><option value="scope_unconfirmed">Scope unconfirmed</option><option value="timing_unconfirmed">Timing unconfirmed</option><option value="conditions_unconfirmed">Conditions unconfirmed</option></select></label>';
                echo '</div><button class="button button-primary">Record agreement</button></form></div></details>';
            }
            echo '</article>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function proposals(array $detail): void {
        $options = (array) $detail['proposal']['options'];
        $permissions = $detail['permissions'];
        echo '<section class="dzn-coordination__section" id="proposals"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Immutable offer authority</p><h2>Proposals</h2></div><span class="dzn-count-chip">' . esc_html((string) count($options)) . ' options</span></div>';
        echo '<p class="dzn-inline-note">A Family groups alternatives. Each Teacher has an independently acceptable Option and immutable Version lineage. A new revision supersedes the previous version; it never overwrites it.</p>';
        if (!$options) {
            echo '<div class="dzn-empty-state dzn-empty-state--inline"><h3>No Proposal yet</h3><p>Record a current Teacher agreement first. The agreement supplies the exact frozen facts and fingerprint; the operator does not type them.</p></div>';
            return;
        }
        foreach ($options as $option) {
            echo '<article class="dzn-proposal"><div class="dzn-proposal__head"><div><h3>' . esc_html((string) $option['teacher_name']) . '</h3><p>Option ' . esc_html(self::shortUid((string) $option['uid'])) . ' · Version ' . esc_html((string) $option['version_number']) . '</p></div>' . self::badge((string) $option['current'] ? 'Current' : 'Superseded', (string) $option['current'] ? 'success' : 'muted') . '</div>';
            echo '<dl class="dzn-facts dzn-facts--two"><div><dt>Issued</dt><dd>' . esc_html((string) $option['issued_at']) . ' UTC</dd></div><div><dt>Reason</dt><dd>' . esc_html(self::human((string) $option['reason'])) . '</dd></div><div><dt>Delivery</dt><dd>' . esc_html(self::human((string) $option['delivery_mode'])) . ' · ' . esc_html((string) $option['frequency_per_week'] . '× per week · ' . (string) $option['duration_minutes'] . ' minutes') . '</dd></div><div><dt>Commencement window</dt><dd>' . esc_html(self::localLabel((string) $option['window_start'], (string) $option['timezone']) . ' — ' . self::localLabel((string) $option['window_end'], (string) $option['timezone'])) . '</dd></div></dl>';
            if (!empty($option['version_id']) && !empty($permissions['proposal']) && empty($detail['arrangement'])) {
                echo '<details class="dzn-disclosure"><summary>Revise this Proposal Version</summary><div class="dzn-disclosure__body">';
                self::formStart($detail, 'issue_proposal_replacement', array('option_id' => (int) $option['id']));
                echo '<div class="dzn-inline-form"><label class="dzn-field"><span>Revision reason</span><select name="reason"><option value="material_facts_changed">Material facts changed</option><option value="assent_renewed">Agreement renewed</option><option value="operator_correction">Operator correction</option></select></label><button class="button">Issue replacement Version</button></div></form>';
                echo '<p class="dzn-field-help">The server resolves the current Version and current agreement fingerprint. A stale Version is refused.</p></div></details>';
            }
            echo '</article>';
        }
        self::provisionalAcceptance($detail);
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function provisionalAcceptance(array $detail): void {
        $permissions = $detail['permissions'];
        $current = array_values(array_filter((array) $detail['proposal']['options'], static fn(array $option): bool => !empty($option['current']) && !empty($option['version_id'])));
        echo '<div class="dzn-subsection" id="provisional"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Evidence, not finality</p><h3>Provisional acceptance</h3></div></div>';
        if (!$current) {
            echo '<p class="dzn-field-help">A current Proposal Version is required before learner/guardian response evidence can be recorded.</p>';
        } elseif (!empty($detail['arrangement'])) {
            echo '<p class="dzn-field-help">A final arrangement is recorded. Additional provisional evidence is not required here; the immutable history remains below.</p>';
        } elseif (empty($permissions['provisional'])) {
            self::permissionNotice('dzn_record_booking_request_provisional_acceptance');
        } else {
            echo '<details class="dzn-disclosure" open><summary>Record learner or guardian response</summary><div class="dzn-disclosure__body">';
            self::formStart($detail, 'record_provisional_acceptance');
            echo '<label class="dzn-field"><span>Exact Proposal Version</span><select name="option_id">';
            foreach ($current as $option) echo '<option value="' . esc_attr((string) $option['id']) . '">' . esc_html((string) $option['label']) . '</option>';
            echo '</select><small>The target family, option and version are resolved server-side.</small></label>';
            echo '<div class="dzn-field-grid"><label class="dzn-field"><span>Response channel</span><select name="evidence_channel"><option value="phone">Phone</option><option value="in_person">In person</option><option value="email_reference">Email reference</option><option value="message_reference">Message reference</option><option value="other_reference">Other reference</option></select></label>';
            echo '<label class="dzn-field"><span>Response observed (local)</span><input type="datetime-local" name="evidence_at" value="' . esc_attr(self::localNow((string) $detail['request']['timezone'])) . '" required></label></div>';
            echo '<button class="button button-primary">Record provisional response</button></form></div></details>';
        }
        if ($detail['acceptances']) {
            echo '<ul class="dzn-timeline dzn-timeline--compact">';
            foreach ($detail['acceptances'] as $event) echo '<li><span class="dzn-timeline__dot"></span><strong>Version ' . esc_html((string) $event['version_number']) . ' · ' . esc_html((string) $event['channel_label']) . '</strong><small>' . esc_html((string) $event['evidence_at']) . ' UTC · ' . esc_html((string) $event['recorded_by_name']) . '</small></li>';
            echo '</ul>';
        } else {
            echo '<p class="dzn-field-help">No provisional response is recorded.</p>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $detail */
    private static function acceptance(array $detail): void {
        $permissions = $detail['permissions'];
        $authority = $detail['authority'];
        $capacity = $detail['capacity'];
        $student = $detail['student'];
        echo '<section class="dzn-coordination__section" id="acceptance"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Final authority</p><h2>Final acceptance</h2></div>' . self::badge($detail['arrangement'] ? 'Arrangement recorded' : 'Not finalised', $detail['arrangement'] ? 'success' : 'muted') . '</div>';
        if ($detail['arrangement']) {
            $arrangement = $detail['arrangement'];
            echo '<div class="dzn-arrangement"><h3>' . esc_html((string) $arrangement['reference']) . '</h3><dl class="dzn-facts dzn-facts--two"><div><dt>Teacher</dt><dd>' . esc_html((string) $arrangement['teacher_name']) . '</dd></div><div><dt>Authority route</dt><dd>' . esc_html((string) $arrangement['authority_label']) . '</dd></div><div><dt>Accepted</dt><dd>' . esc_html((string) $arrangement['accepted_at']) . ' UTC</dd></div><div><dt>Confirmation</dt><dd>' . esc_html(self::human((string) $arrangement['confirmation_channel'])) . ' · ' . esc_html((string) $arrangement['confirmed_at']) . ' UTC</dd></div></dl></div>';
            echo '<p class="dzn-field-help">The arrangement is immutable. Corrections require the authorised cancellation/reversal or a new transaction; direct editing is deliberately unavailable.</p>';
            return;
        }
        $current = array_values(array_filter((array) $detail['proposal']['options'], static fn(array $option): bool => !empty($option['current']) && !empty($option['version_id'])));
        if (!$current) {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>Final acceptance is unavailable:</strong> no current Proposal Version exists. Record Teacher agreement and issue a Proposal first.</div>';
        } elseif (!$student) {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>Final acceptance is unavailable:</strong> Student identity is unresolved. Use the in-place <a href="#identity">Resolve identity</a> action; this is the canonical identity authority, not a frontend classification.</div>';
        } elseif (!$capacity['resolved']) {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>Final acceptance is unavailable:</strong> Student acceptance capacity is not currently classified as adult or minor. <a href="#acceptance-authority">Resolve acceptance authority</a> in place. The backend will still revalidate the evidence when you submit.</div>';
        } elseif ($authority['route'] === 'missing') {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>Final acceptance is unavailable:</strong> no current accepting principal or guardian representative is recorded. <a href="#acceptance-authority">Resolve acceptance authority</a> before finalising.</div>';
        } elseif (!$detail['provisional_current']) {
            echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>Final acceptance is unavailable:</strong> no provisional response is recorded for the current Proposal Version.</div>';
        } elseif (empty($permissions['final_acceptance'])) {
            self::permissionNotice('dzn_finalize_service_arrangements');
        } else {
            echo '<details class="dzn-disclosure" open><summary>Create the final Accepted Service Arrangement</summary><div class="dzn-disclosure__body">';
            self::formStart($detail, 'finalize_service_arrangement');
            wp_nonce_field('dzn_final_acceptance', 'dzn_final_acceptance_nonce');
            echo '<label class="dzn-field"><span>Exact Proposal Version</span><select name="option_id">';
            foreach ($current as $option) echo '<option value="' . esc_attr((string) $option['id']) . '">' . esc_html((string) $option['label']) . '</option>';
            echo '</select></label>';
            echo '<label class="dzn-field"><span>Accepting principal</span><select name="accepting_principal_id" required><option value="">Choose the verified account that confirmed</option>';
            foreach ((array) ($detail['options']['accounts'] ?? array()) as $account) echo '<option value="' . esc_attr((string) $account['id']) . '">' . esc_html((string) $account['label']) . '</option>';
            echo '</select><small>Adult self or guardian representative, according to the current capacity classification.</small></label>';
            echo '<div class="dzn-field-grid"><label class="dzn-field"><span>Confirmation channel</span><select name="confirmation_channel"><option value="in_person">In person</option><option value="phone">Phone</option><option value="email_reference">Email reference</option><option value="message_reference">Message reference</option><option value="other_reference">Other reference</option></select></label>';
            echo '<label class="dzn-field"><span>Fresh confirmation (local)</span><input type="datetime-local" name="confirmed_at" value="' . esc_attr(self::localNow((string) $detail['request']['timezone'])) . '" required></label></div>';
            echo '<button class="button button-primary">Create final arrangement</button></form></div></details>';
        }
        echo '<p class="dzn-field-help">Final acceptance does not create an Enrolment, class, payment, notification or calendar event. Those separate authorities are shown below.</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function enrolment(array $detail): void {
        $permissions = $detail['permissions'];
        $arrangement = $detail['arrangement'];
        echo '<section class="dzn-coordination__section" id="enrolment"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Follow-through</p><h2>Enrolment and assignment</h2></div>' . self::badge($detail['enrolment'] ? 'Enrolment exists' : 'Not converted', $detail['enrolment'] ? 'success' : 'muted') . '</div>';
        if (!$arrangement) {
            echo '<div class="dzn-empty-state dzn-empty-state--inline"><h3>Final arrangement required</h3><p>Enrolment conversion becomes available only after an immutable final arrangement exists.</p></div>';
            return;
        }
        $conversion = (string) $detail['conversion'];
        echo '<dl class="dzn-facts dzn-facts--two"><div><dt>Conversion readiness</dt><dd>' . esc_html(self::human($conversion)) . '</dd></div><div><dt>Enrolment</dt><dd>' . esc_html($detail['enrolment'] ? (string) $detail['enrolment']['reference'] . ' · ' . (string) $detail['enrolment']['label'] : 'Not created') . '</dd></div><div><dt>Teacher assignment</dt><dd>' . esc_html($detail['assignment'] ? (string) $detail['assignment']['teacher_name'] . ' · ' . (string) $detail['assignment']['label'] : 'Not assigned') . '</dd></div><div><dt>Student account</dt><dd>' . esc_html((string) $detail['claim']['label']) . '</dd></div></dl>';
        if (!$detail['enrolment']) {
            if (!empty($permissions['conversion'])) {
                if ($conversion === 'ready') {
                    self::formStart($detail, 'convert_enrolment');
                    echo '<div class="dzn-inline-form"><button class="button button-primary">Convert accepted arrangement to Enrolment</button></div></form>';
                } elseif ($conversion !== 'already_converted') {
                    echo '<div class="dzn-inline-note dzn-inline-note--warn"><strong>Conversion is not ready.</strong> The conversion record reports <strong>' . esc_html(self::human($conversion)) . '</strong>. Review the arrangement lineage and canonical Student/Course identity; coordination will not bypass those invariants.</div>';
                }
            } else { self::permissionNotice('dzn_convert_service_arrangements_to_enrolments'); }
        } elseif (!$detail['assignment']) {
            if (!empty($permissions['assignment'])) {
                self::formStart($detail, 'assign_initial_teacher');
                echo '<div class="dzn-inline-form"><p class="dzn-field-help">The accepted Teacher and retained arrangement are the only source for the initial assignment.</p><button class="button button-primary">Assign the accepted Teacher</button></div></form>';
            } else { self::permissionNotice('dzn_manage_teacher_assignments'); }
        }
        if ($detail['enrolment'] && !in_array((string) $detail['claim']['state'], array('active', 'claimed'), true)) {
            if (!empty($permissions['claim'])) {
                self::formStart($detail, 'issue_account_claim');
                echo '<div class="dzn-inline-form"><p class="dzn-field-help">Sends one canonical account claim invitation. Delivery remains a separate notification state.</p><button class="button">Issue Student account invitation</button></div></form>';
            } else { self::permissionNotice('dzn_issue_student_account_claims'); }
        }
        echo '<p class="dzn-field-help">Canonical Term creation, first Lesson issue and class scheduling are Enrolment/Class authorities, not Booking Request Coordination. This case hands the accepted arrangement and assignment to that workflow without pretending a lesson was created here.</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function scheduling(array $detail): void {
        $schedule = $detail['schedule'];
        $request = $detail['request'];
        echo '<section class="dzn-coordination__section" id="scheduling"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Availability and conflicts</p><h2>Scheduling</h2></div></div>';
        echo '<div class="dzn-schedule-grid"><div><h3>Learner preferences</h3>';
        if (!$request['times']) echo '<p>No preferred time recorded.</p>';
        else {
            echo '<ul class="dzn-schedule-list">';
            foreach ($request['times'] as $time) echo '<li><strong>' . esc_html((string) $time['label']) . '</strong><small>' . esc_html((string) $time['timezone']) . ' · ' . esc_html((string) $time['duration_minutes']) . ' minutes · ' . esc_html((string) $time['status_label']) . '</small></li>';
            echo '</ul>';
        }
        echo '</div><div><h3>Confirmed or projected booking</h3>';
        if ($schedule['confirmed']) {
            echo '<p><strong>' . esc_html((string) $schedule['confirmed']['label']) . '</strong></p><dl class="dzn-facts"><div><dt>State</dt><dd>' . esc_html((string) $schedule['calendar_label']) . '</dd></div><div><dt>Provider</dt><dd>' . esc_html((string) ($schedule['provider_code'] ?: 'Not recorded')) . '</dd></div><div><dt>Failure evidence</dt><dd>' . esc_html((string) ($schedule['failure'] ?: 'None recorded')) . '</dd></div></dl>';
            if (in_array((string) $schedule['calendar_state'], array('pending_projection', 'awaiting_conference', 'awaiting_payment', 'retryable'), true)) echo '<div class="dzn-inline-note dzn-inline-note--warn">Calendar or Meet projection is not confirmed. The existing provider worker owns retries; coordination must not fabricate a successful event or link.</div>';
        } else {
            echo '<p>No confirmed booking exists. A confirmed booking appears here only after the canonical Teacher match and provider workflow.</p>';
        }
        echo '</div><div><h3>Teacher availability and occupancy</h3>';
        if (!$schedule['candidate_availability']) echo '<p>No candidate is available to evaluate.</p>';
        else {
            echo '<ul class="dzn-schedule-list">';
            foreach ($schedule['candidate_availability'] as $candidate) {
                echo '<li><strong>' . esc_html((string) $candidate['name']) . '</strong><small>' . esc_html((string) $candidate['label']) . '</small>';
                if (!empty($candidate['conflicts'])) {
                    echo '<ul class="dzn-conflict-list">';
                    foreach ($candidate['conflicts'] as $conflict) echo '<li>Preferred time ' . esc_html((string) $conflict['sequence']) . ': ' . ($conflict['teacher'] ? 'Teacher occupied' : '') . ($conflict['teacher'] && $conflict['student'] ? ' · ' : '') . ($conflict['student'] ? 'Student occupied' : '') . '</li>';
                    echo '</ul>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div></div>';
        echo '<div class="dzn-inline-note"><strong>Unsupported in-place changes:</strong> submitted request preferences and confirmed-intro rescheduling remain unsupported in this workspace. Confirmed booking cancellation uses the dedicated audited authority above. The dashboard does not simulate them. Class rescheduling after conversion lives in the canonical schedule authority.</div>';
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function finance(array $detail): void {
        $payment = $detail['payment'];
        $permissions = $detail['permissions'];
        echo '<section class="dzn-coordination__section dzn-coordination__side-card" id="finance"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Payment authority</p><h2>Payment</h2></div>' . self::badge((string) $payment['label'], in_array((string) $payment['state'], array('paid'), true) ? 'success' : (in_array((string) $payment['state'], array('failed'), true) ? 'danger' : 'muted')) . '</div>';
        if ((string) $payment['state'] === 'none') {
            echo '<p>No introductory payment obligation is recorded. The dashboard does not invent one.</p>';
        } else {
            echo '<dl class="dzn-facts"><div><dt>Obligation</dt><dd>' . esc_html((string) $payment['reference']) . '</dd></div><div><dt>Amount</dt><dd>' . esc_html((string) $payment['amount']) . '</dd></div><div><dt>State</dt><dd>' . esc_html((string) $payment['label']) . '</dd></div><div><dt>Verified paid</dt><dd>' . esc_html((string) ($payment['paid_at'] ?: 'Not paid')) . '</dd></div>' . ((string) $payment['failure'] !== '' ? '<div><dt>Failure</dt><dd>' . esc_html((string) $payment['failure']) . '</dd></div>' : '') . '</dl>';
            if (in_array((string) $payment['state'], array('pending', 'failed'), true) && !empty($permissions['payment'])) {
                echo '<details class="dzn-disclosure"><summary>Record verified offline payment</summary><div class="dzn-disclosure__body">';
                self::formStart($detail, 'record_manual_payment', array('obligation_uid' => (string) $payment['uid']));
                echo '<label class="dzn-field"><span>Receipt or verified reference</span><input type="text" name="evidence_reference" maxlength="191" required></label>';
                echo '<label class="dzn-field"><span>Payment date and time (local)</span><input type="datetime-local" name="paid_at" value="' . esc_attr(self::localNow((string) $detail['request']['timezone'], '-5 minutes')) . '" required></label>';
                echo '<button class="button">Record verified settlement</button></form><p class="dzn-field-help">Amount and currency come from the stored obligation. A settled obligation cannot be settled again.</p></div></details>';
            } elseif (in_array((string) $payment['state'], array('pending', 'failed'), true)) {
                self::permissionNotice('dzn_manage_payment_execution');
            }
        }
        echo '<p class="dzn-field-help">Refunds, adjustments and payment state changes are not invented here. They require their existing finance authority.</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function communications(array $detail): void {
        $notifications = $detail['notifications'];
        $permissions = $detail['permissions'];
        echo '<section class="dzn-coordination__section dzn-coordination__side-card" id="communications"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Evidence, not assumption</p><h2>Communications</h2></div>' . self::badge($notifications['failed_count'] > 0 ? $notifications['failed_count'] . ' failed' : 'No failure', $notifications['failed_count'] > 0 ? 'warning' : 'success') . '</div>';
        if (!$notifications['rows']) {
            echo '<p>No canonical notification is recorded for this booking request.</p>';
        } else {
            echo '<ul class="dzn-notification-list">';
            foreach ($notifications['rows'] as $row) {
                echo '<li class="dzn-notification dzn-notification--' . esc_attr((string) $row['state']) . '"><div><strong>' . esc_html((string) $row['label']) . '</strong><small>' . esc_html((string) $row['event']) . ' · ' . esc_html((string) $row['audience']) . ' · ' . esc_html((string) $row['updated_at']) . ' UTC</small>' . ((string) $row['failure'] !== '' ? '<small class="dzn-danger">' . esc_html((string) $row['failure']) . '</small>' : '') . '</div>';
                if ($row['retryable']) {
                    if (!empty($permissions['notification_retry'])) {
                        self::formStart($detail, 'retry_notification', array('notification_id' => (int) $row['id']));
                        echo '<button class="button button-small">Retry exact notification</button></form>';
                    } else {
                        echo '<span class="dzn-disabled-action" aria-disabled="true">Retry<small>Requires ' . esc_html(self::capabilityLabel('dzn_operate_notification_dispatch')) . '</small></span>';
                    }
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '<p class="dzn-field-help">A transport hand-off is not delivery. Only provider evidence is shown as delivered. A retry is bound to the exact original notification; it cannot change recipient or template.</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function caseState(array $detail): void {
        $permissions = $detail['permissions'];
        $case = $detail['case'];
        echo '<section class="dzn-coordination__section dzn-coordination__side-card" id="case-state"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Exceptional recovery</p><h2>Case state</h2></div>' . self::badge((string) $case['state_label'], $case['closed'] ? 'muted' : 'teal') . '</div>';
        echo '<p class="dzn-field-help">Case state is advisory coordination state, not the booking, Teacher assignment or acceptance decision. Terminal states retain history. The current authority does not support reopening a closed case.</p>';
        if (!empty($permissions['coordination']) && !$case['closed']) {
            self::formStart($detail, 'transition_case', array('version' => (int) $case['version']));
            echo '<label class="dzn-field"><span>Change case state</span><select name="state">';
            foreach (array('open' => 'Open', 'candidate_search' => 'Searching for a Teacher', 'manual_search' => 'Manual Teacher search', 'waiting_for_availability' => 'Waiting for availability', 'unable_to_arrange' => 'Unable to arrange', 'withdrawn' => 'Withdrawn', 'declined' => 'Declined', 'abandoned' => 'Abandoned') as $value => $label) echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
            echo '</select></label><button class="button">Save case state</button></form>';
        } elseif ($case['closed']) {
            echo '<p>Closed with reason <strong>' . esc_html(self::human((string) $case['reason'])) . '</strong>. Reopening is not supported; create a new case only through an authorised new booking path.</p>';
        } else {
            self::permissionNotice('dzn_manage_booking_request_coordination');
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function activity(array $detail): void {
        echo '<section class="dzn-coordination__section dzn-coordination__side-card" id="activity"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">Chronological record</p><h2>Activity and audit</h2></div></div>';
        if (!$detail['activity']) {
            echo '<p>No audit events are recorded yet.</p>';
        } else {
            echo '<ol class="dzn-timeline">';
            foreach (array_slice($detail['activity'], 0, 40) as $event) {
                echo '<li><span class="dzn-timeline__dot"></span><strong>' . esc_html((string) $event['event_label']) . '</strong><small>' . esc_html((string) $event['occurred_label']) . ' · ' . esc_html((string) $event['actor']) . '</small><p>' . esc_html((string) $event['type_label']) . (($event['reason'] ?? '') !== 'Not recorded' ? ' · ' . esc_html((string) $event['reason']) : '') . '</p></li>';
            }
            echo '</ol>';
        }
        echo '<p class="dzn-field-help">Audit detail is bounded to controlled facts in the canonical audit table. Immutable evidence cannot be overwritten here.</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $detail */
    private static function advanced(array $detail): void {
        echo '<details class="dzn-diagnostics dzn-coordination__side-card" id="advanced"><summary>Advanced / audit details</summary><div class="dzn-diagnostics__body">';
        echo '<p>These identifiers are retained for verification and support. They are never required in ordinary operator forms.</p><dl class="dzn-facts"><div><dt>Coordination case</dt><dd><code>' . esc_html((string) $detail['advanced']['case_uid']) . '</code></dd></div><div><dt>Case ID / version</dt><dd><code>' . esc_html((string) $detail['advanced']['case_id']) . ' / ' . esc_html((string) $detail['advanced']['version']) . '</code></dd></div><div><dt>Booking request ID</dt><dd><code>' . esc_html((string) $detail['advanced']['request_id']) . '</code></dd></div><div><dt>Proposal family</dt><dd><code>' . esc_html((string) ($detail['advanced']['family_uid'] ?: 'Not created')) . '</code></dd></div>';
        foreach ((array) $detail['proposal']['options'] as $option) echo '<div><dt>Option ' . esc_html(self::shortUid((string) $option['uid'])) . '</dt><dd><code>' . esc_html((string) $option['uid']) . '</code> · fingerprint <code>' . esc_html(self::shortDigest((string) $option['fingerprint'])) . '</code></dd></div>';
        echo '</dl>';
        if ($detail['exceptions']) {
            echo '<h3>Operational exceptions</h3><ul class="dzn-exception-list">';
            foreach ($detail['exceptions'] as $exception) echo '<li><strong>' . esc_html((string) $exception['type_label']) . '</strong> · ' . esc_html((string) $exception['severity']) . '<br><span>' . esc_html((string) $exception['summary']) . '</span>' . ((string) $exception['detail'] !== '' ? '<small>' . esc_html((string) $exception['detail']) . '</small>' : '') . '</li>';
            echo '</ul>';
        }
        echo '</div></details>';
    }

    /** @param array<string,mixed> $detail @param array<string,mixed> $confirmed */
    private static function cancelConfirmedIntroForm(array $detail, array $confirmed): void {
        echo '<details class="dzn-action-panel dzn-action-panel--danger"><summary><strong>Cancel confirmed introductory booking</strong><br><small>Records immutable evidence, queues provider retraction and one Student notice.</small></summary><div class="dzn-action-panel__body">';
        self::formStart($detail, 'cancel_confirmed_intro', array('confirmation_uid' => (string) ($confirmed['confirmation_uid'] ?? '')));
        echo '<label class="dzn-field"><span>Cancellation reason</span><select name="reason_code" required><option value="">Choose a reason</option><option value="duplicate_request">Duplicate request</option><option value="student_request">Student requested cancellation</option><option value="teacher_unavailable">Teacher unavailable</option><option value="academy_cancelled">Academy cancelled</option><option value="operator_correction">Operator correction</option></select></label>';
        echo '<label class="dzn-field"><span>Evidence / operator reference</span><input type="text" name="evidence_reference" maxlength="191" required></label>';
        echo '<button class="button" onclick="return confirm(\'Cancel this confirmed introductory booking? Audit history remains and the provider event is retracted asynchronously.\')">Cancel confirmed booking</button></form>';
        echo '<p class="dzn-field-help">The confirmation, delivery and request are moved to cancelled state together. The retained duplicate, Student history and financial facts are not edited.</p></div></details>';
    }

    /** @param array<string,mixed> $detail @param array<string,mixed> $hidden */
    private static function formStart(array $detail, string $action, array $hidden = array()): void {
        $caseId = (int) $detail['case']['id'];
        echo '<form method="post" class="dzn-coordination__form">';
        wp_nonce_field('dzn_coordination_action_' . $caseId);
        echo '<input type="hidden" name="dzn_coordination_action" value="' . esc_attr($action) . '"><input type="hidden" name="case_id" value="' . esc_attr((string) $caseId) . '">';
        foreach ($hidden as $name => $value) echo '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '">';
    }

    /** @param array<int,array<string,mixed>> $openable */
    private static function openPanel(array $openable, int $requestId): void {
        echo '<section class="dzn-coordination__section dzn-coordination__open"><div class="dzn-coordination__section-head"><div><p class="dzn-eyebrow">New exceptional case</p><h2>Open coordination</h2></div></div>';
        echo '<p>Open coordination only when a booking request needs human review. A routine request that can be matched and accepted normally should stay in the standard booking queue.</p>';
        echo '<form method="post" class="dzn-coordination__form">';
        wp_nonce_field('dzn_coordination_open');
        echo '<input type="hidden" name="dzn_coordination_action" value="open"><div class="dzn-field-grid"><label class="dzn-field"><span>Booking request</span><select name="booking_request_id" required><option value="">Choose an unresolved request</option>';
        foreach ($openable as $request) {
            if ($request['has_case']) continue;
            echo '<option value="' . esc_attr((string) $request['id']) . '"' . selected($requestId, (int) $request['id'], false) . '>' . esc_html((string) $request['reference'] . ' · ' . $request['learner'] . ' · ' . $request['instrument'] . ' · ' . $request['state']) . '</option>';
        }
        echo '</select><small>Only unresolved intake or an authorised teacher-confirmed continuation is listed.</small></label><label class="dzn-field"><span>Reason</span><select name="reason"><option value="manual_review">Manual review</option><option value="admin_opened">Administrator opened</option></select></label></div>';
        echo '<button class="button button-primary">Open coordination case</button></form>';
        if (!$openable) echo '<p class="dzn-field-help">No unresolved booking request is available. New requests appear here after intake.</p>';
        elseif (!array_filter($openable, static fn(array $request): bool => !$request['has_case'])) echo '<p class="dzn-field-help">Every unresolved booking request already has a coordination case. Open it from the queue below.</p>';
        echo '</section>';
    }

    private static function notices(): void {
        if (!isset($_GET['dzn_notice'])) return;
        $error = (string) ($_GET['dzn_error'] ?? '0') === '1';
        echo '<div class="notice ' . ($error ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['dzn_notice']))) . '</p></div>';
    }

    private static function kpi(string $value, string $label, string $note): void {
        echo '<div class="dzn-coordination__kpi"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span><small>' . esc_html($note) . '</small></div>';
    }

    private static function summaryCard(string $label, string $value, string $note, string $tone): void {
        echo '<div class="dzn-coordination__summary-card dzn-coordination__summary-card--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong><small>' . esc_html($note) . '</small></div>';
    }

    private static function topAction(string $label, string $anchor, bool $available): void {
        if ($available) echo '<a class="dzn-coordination__top-action" href="' . esc_url($anchor) . '">' . esc_html($label) . '</a>';
    }

    private static function badge(string $label, string $tone): string {
        return '<span class="dzn-badge dzn-badge--' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
    }

    private static function fact(string $label, string $value, string $note = ''): void {
        echo '<div class="dzn-fact"><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value !== '' ? $value : '—') . ($note !== '' ? '<small>' . esc_html($note) . '</small>' : '') . '</dd></div>';
    }

    private static function evidenceFields(string $timezone = 'UTC'): void {
        echo '<label class="dzn-field"><span>Verification basis</span><select name="verification_basis"><option value="human_review">Human review</option><option value="verified_internal_record">Verified internal record</option><option value="legal_representative_review">Legal representative review</option></select></label>';
        echo '<label class="dzn-field"><span>Evidence channel</span><select name="evidence_channel"><option value="reviewer_attestation">Reviewer attestation</option><option value="internal_case">Internal case evidence</option><option value="verified_internal_record">Verified internal record</option></select></label>';
        echo '<label class="dzn-field"><span>Evidence observed (local)</span><input type="datetime-local" name="evidence_at" value="' . esc_attr(self::localNow($timezone, '-5 minutes')) . '" required></label>';
    }

    private static function permissionNotice(string $capability): void {
        echo '<div class="dzn-permission-note"><strong>Action unavailable for your role.</strong><p>This control requires <strong>' . esc_html(self::capabilityLabel($capability)) . '</strong>. The case remains readable so you can review the state or hand it to an authorised operator.</p></div>';
    }

    private static function capabilityLabel(string $capability): string {
        return self::CAPABILITY_LABELS[$capability] ?? ($capability !== '' ? ucfirst(str_replace('_', ' ', $capability)) : 'additional authority');
    }

    private static function timesLine(array $times): string {
        if (!$times) return 'No preferred time recorded.';
        $labels = array_map(static fn(array $time): string => (string) $time['label'], array_slice($times, 0, 3));
        return implode(' · ', $labels);
    }

    private static function localNow(string $timezone, string $modifier = ''): string {
        try { $date = new \DateTimeImmutable('now', new \DateTimeZone($timezone ?: 'UTC')); if ($modifier !== '') $date = $date->modify($modifier); return $date->format('Y-m-d\TH:i'); }
        catch (\Throwable) { return gmdate('Y-m-d\TH:i'); }
    }

    private static function localInput(string $utc, string $timezone, string $modifier = ''): string {
        if ($utc === '') return '';
        try {
            $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            if ($modifier !== '') $date = $date->modify($modifier);
            return $date->setTimezone(new \DateTimeZone($timezone ?: 'UTC'))->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function localLabel(string $utc, string $timezone): string {
        if ($utc === '') return '—';
        try {
            $zone = new \DateTimeZone($timezone ?: 'UTC');
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zone)->format('j M Y, H:i') . ' ' . $zone->getName();
        } catch (\Throwable) {
            return $utc . ' UTC';
        }
    }

    private static function human(string $value): string {
        return $value === '' ? 'Not recorded' : ucfirst(str_replace('_', ' ', $value));
    }

    private static function shortUid(string $uid): string {
        return $uid === '' ? '—' : substr($uid, 0, 8) . '…';
    }

    private static function shortDigest(string $digest): string {
        return $digest === '' ? '—' : substr($digest, 0, 12) . '…';
    }
}
