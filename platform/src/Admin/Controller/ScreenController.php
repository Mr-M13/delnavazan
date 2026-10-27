<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\Diagnostic\NonceLifecycleDiagnostic;
use Delnavazan\Platform\Core\Application\{
    ArchiveService,
    CanonicalEnrolmentLifecycleService,
    CanonicalLessonAuthorityService,
    CanonicalLessonCompletionPolicy,
    CanonicalLessonDeliveryReadService,
    CanonicalLessonDeliveryService,
    CanonicalLessonScheduleReadService,
    CanonicalLessonScheduleService,
    CanonicalTermAuthorityService,
    CatalogueService,
    CoreReadService,
    DiagnosticsService,
    ExceptionService,
    LessonScheduleService,
    LessonService,
    PostIntroPreparationService,
    StudentService,
    TeacherService,
    TermService
};

final class ScreenController {
    private const ENTITIES = [
        'teacher' => ['Teachers', Menu::TEACHER_OPERATIONS_CAP],
        'student' => ['Students', 'dzn_manage_students'],
        'instrument' => ['Instruments', 'dzn_manage_courses'],
        'course' => ['Courses', 'dzn_manage_courses'],
        'enrolment' => ['Enrolments', 'dzn_manage_enrolments'],
        'term' => ['Terms', 'dzn_manage_terms'],
        'lesson' => ['Lessons', 'dzn_manage_lessons'],
    ];
    // Form data crosses into repositories through the application services.
    // Never pass nonce, action, referrer, or other request-control fields on.
    private const CREATE_FIELDS = [
        'teacher' => ['persian_name', 'english_name', 'display_name', 'email', 'phone', 'whatsapp_phone', 'country_code', 'city', 'timezone', 'timezone_source', 'locale', 'calendar_preference', 'status'],
        'student' => ['first_name', 'last_name', 'display_name', 'email', 'phone', 'whatsapp_phone', 'country_code', 'city', 'timezone', 'timezone_source', 'locale', 'calendar_preference', 'status'],
        'instrument' => ['name', 'standard_duration_minutes', 'introductory_lesson_billing', 'gap_between_lessons_minutes'],
        'course' => ['instrument_id', 'name_fa', 'name_en', 'course_type', 'status', 'default_duration_minutes', 'default_buffer_minutes'],
        'term' => ['enrolment_id', 'sequence_number', 'status', 'lesson_allocation', 'replacement_allowance', 'payment_state'],
        'lesson' => ['student_id', 'teacher_id', 'course_id', 'lesson_type', 'status', 'enrolment_id', 'term_id', 'replacement_for_lesson_id'],
    ];

    public static function status(): void {
        self::renderMessages();
        try { $status = (new DiagnosticsService())->status(); } catch (\Throwable) { self::forbidden(); return; }
        echo '<div class="wrap"><h1>Delnavazan Core Status</h1>';
        echo '<p>Platform ' . esc_html((string) $status['platform_version']) . ' · Build ' . esc_html(DZN_PLATFORM_BUILD_ID) . ' · Schema ' . esc_html((string) $status['schema_version']) . '</p>';
        echo '<p><strong>' . esc_html($status['healthy'] ? 'Core schema ready' : 'Core/schema problem detected') . '</strong></p>';
        echo '<p>Tables: ' . esc_html($status['tables_healthy'] ? 'ready' : 'problem') . ' · Schema version: ' . esc_html($status['schema_current'] ? 'current' : 'wrong') . ' · Required migration: ' . esc_html($status['required_migration_complete'] ? 'recorded' : 'missing') . '</p>';
        echo '<h2>Migration state</h2><p>' . esc_html(implode(', ', array_map('strval', $status['migration_state'])) ?: 'No completed migrations') . '</p>';
        echo '<h2>Core tables and counts</h2><table class="widefat striped"><thead><tr><th>Table</th><th>State</th><th>Count</th></tr></thead><tbody>';
        foreach ($status['tables'] as $name => $table) echo '<tr><td>' . esc_html('dzn_' . $name) . '</td><td>' . esc_html($table['exists'] ? 'ready' : 'missing') . '</td><td>' . esc_html($table['count'] === null ? '—' : (string) $table['count']) . '</td></tr>';
        echo '</tbody></table><h2>Active exceptions</h2><p>Open: ' . esc_html((string) ($status['exception_counts']['open'] ?? '—')) . ' · Acknowledged: ' . esc_html((string) ($status['exception_counts']['acknowledged'] ?? '—')) . '</p></div>';
    }

    public static function screen(string $screen): void {
        NonceLifecycleDiagnostic::logStage('submenu_callback');
        if ($screen === 'exception') { self::exceptionScreen(); return; }
        if (!isset(self::ENTITIES[$screen]) || !current_user_can(self::ENTITIES[$screen][1])) { self::forbidden(); return; }
        self::renderMessages();
        \Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer::renderNotices();
        $id = absint($_GET['id'] ?? 0);
        echo '<div class="wrap"><h1>Delnavazan ' . esc_html(self::ENTITIES[$screen][0]) . '</h1>';
        if ($id) {
            try { self::entityDetail($screen, $id); }
            catch (\InvalidArgumentException $exception) {
                if ($exception->getMessage() !== 'data_integrity_conflict') throw $exception;
                echo '<p class="notice notice-error">Canonical lifecycle history failed integrity validation.</p>';
            }
        } else {
            if ($screen === 'student') self::studentCreateDisclosure();
            elseif ($screen === 'enrolment') echo '<p>Enrolments are read-only. Generic creation is disabled; canonical conversion authority is not part of this phase.</p>';
            elseif ($screen === 'term') self::termCreateSection();
            elseif ($screen === 'lesson') self::classCreateSection();
            elseif ($screen !== 'teacher') self::entityCreateForm($screen);
            self::entityList($screen);
        }
        echo '</div>';
    }

    public static function handlePost(string $screen): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $post = wp_unslash($_POST);
        $action = sanitize_key($post['dzn_action'] ?? '');
        $capability = $screen === 'teacher'
            ? self::teacherActionCapability($action)
            : ($screen === 'lesson' && in_array($action,array('complete_canonical_lesson'),true)
            ? 'dzn_manage_canonical_lessons'
            : ($screen === 'lesson' && in_array($action,array('record_canonical_delivery','correct_canonical_delivery','reconcile_canonical_delivery','dispute_canonical_delivery'),true)
            ? 'dzn_manage_canonical_lesson_delivery'
            : ($screen === 'exception'
            ? 'dzn_manage_exceptions'
            : (self::ENTITIES[$screen][1] ?? ''))));
        if ($capability === '' || !current_user_can($capability)) {
            wp_die('Access denied.', 'Delnavazan', ['response' => 403]);
        }
        NonceLifecycleDiagnostic::logStage('mutation_load_hook');
        self::handleMutation();
    }

    private static function teacherActionCapability(string $action): string {
        return match ($action) {
            'create_teacher', 'update_teacher_profile', 'archive', 'restore' => 'dzn_manage_teachers',
            'issue_teacher_invitation_by_email' => 'dzn_issue_teacher_invitations',
            'revoke_teacher_invitation' => 'dzn_revoke_teacher_invitations',
            'admin_link_teacher_principal' => 'dzn_manage_onboarding',
            'offboard_teacher_principal' => 'dzn_relink_teacher_principal',
            'approve_teacher_onboarding', 'return_teacher_onboarding', 'reject_teacher_onboarding' => 'dzn_manage_onboarding',
            'set_teacher_course_eligibility', 'set_teacher_accepting_state' => 'dzn_manage_teaching_eligibility',
            'set_availability_profile', 'set_availability_rule' => 'dzn_manage_teacher_availability',
            default => '',
        };
    }

    private static function exceptionScreen(): void {
        if (!current_user_can('dzn_manage_exceptions')) { self::forbidden(); return; }
        self::renderMessages(); $id = absint($_GET['id'] ?? 0);
        echo '<div class="wrap"><h1>Delnavazan Exceptions</h1>';
        if ($id) self::exceptionDetail($id); else self::exceptionList();
        echo '</div>';
    }

    private static function handleMutation(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        $post = wp_unslash($_POST); $action = sanitize_key($post['dzn_action'] ?? '');
        if ($action === '') return;
        self::logNonceDiagnostic($action, $post);
        check_admin_referer(self::nonceAction($action));
        NonceLifecycleDiagnostic::logStage('nonce_verified');
        try {
            $id = match ($action) {
                'create_teacher' => (new TeacherService())->create(self::createPayload('teacher', $post)),
                'update_teacher_profile' => self::updateTeacherProfile($post),
                'issue_teacher_invitation_by_email' => self::issueTeacherInvitation($post),
                'revoke_teacher_invitation' => self::revokeTeacherInvitation($post),
                'admin_link_teacher_principal' => self::linkTeacherPrincipal($post),
                'offboard_teacher_principal' => self::offboardTeacherPrincipal($post),
                'approve_teacher_onboarding' => self::reviewTeacherOnboarding($post, 'approve'),
                'return_teacher_onboarding' => self::reviewTeacherOnboarding($post, 'return'),
                'reject_teacher_onboarding' => self::reviewTeacherOnboarding($post, 'reject'),
                'set_teacher_course_eligibility' => (new \Delnavazan\Platform\Core\Application\TeachingEligibilityService())->setEligibility($post),
                'set_teacher_accepting_state' => (new \Delnavazan\Platform\Core\Application\TeacherAcceptingStateService())->set($post),
                'set_availability_profile' => (new \Delnavazan\Platform\Core\Application\TeacherAvailabilityService())->setProfile($post),
                'set_availability_rule' => (new \Delnavazan\Platform\Core\Application\TeacherAvailabilityService())->setRecurringRule($post),
                'create_student' => (new StudentService())->create(self::createPayload('student', $post)),
                'create_instrument' => (new CatalogueService())->setupInstrument(self::createPayload('instrument', $post))['instrument_id'],
                'create_course' => (new CatalogueService())->course(self::createPayload('course', $post)),
                'create_term' => (new TermService())->create(self::createPayload('term', $post)),
                'create_canonical_term' => self::canonicalTermCreate($post),
                'activate_canonical_enrolment' => self::canonicalEnrolmentTransition($post, 'activate'),
                'pause_canonical_enrolment' => self::canonicalEnrolmentTransition($post, 'pause'),
                'resume_canonical_enrolment' => self::canonicalEnrolmentTransition($post, 'resume'),
                'close_canonical_enrolment' => self::canonicalEnrolmentTransition($post, 'close'),
                'activate_canonical_term' => self::canonicalTermTransition($post, 'activate'),
                'close_canonical_term' => self::canonicalTermTransition($post, 'close'),
                'cancel_canonical_term' => self::canonicalTermTransition($post, 'cancel'),
                'create_lesson' => (new LessonService())->create(self::createPayload('lesson', $post)),
                'create_canonical_lesson' => self::canonicalLessonCreate($post),
                'complete_canonical_lesson' => self::completeCanonicalLesson($post),
                'record_canonical_delivery' => self::recordCanonicalDelivery($post),
                'correct_canonical_delivery' => self::correctCanonicalDelivery($post),
                'reconcile_canonical_delivery' => self::reconcileCanonicalDelivery($post),
                'dispute_canonical_delivery' => self::disputeCanonicalDelivery($post),
                'record_intro_outcome' => self::recordIntroOutcome($post),
                'schedule_canonical_lesson' => self::canonicalScheduleAction($post, 'schedule_initial'),
                'revise_canonical_lesson' => self::canonicalScheduleAction($post, 'schedule_revise'),
                'release_canonical_lesson' => self::canonicalScheduleAction($post, 'schedule_release'),
                'archive' => self::archive($post), 'restore' => self::restore($post),
                'initial_schedule' => self::schedule($post, true), 'reschedule' => self::schedule($post, false),
                'acknowledge_exception' => self::transition($post, 'acknowledged'),
                'resolve_exception' => self::transition($post, 'resolved'),
                'dismiss_exception' => self::transition($post, 'dismissed'),
                'retry_exception' => self::retry($post),
                default => throw new \InvalidArgumentException('Unsupported action'),
            };
            NonceLifecycleDiagnostic::logStage('mutation_complete');
            $teacherActions=['update_teacher_profile','revoke_teacher_invitation','admin_link_teacher_principal','offboard_teacher_principal','approve_teacher_onboarding','return_teacher_onboarding','reject_teacher_onboarding','set_teacher_course_eligibility','set_teacher_accepting_state','set_availability_profile','set_availability_rule'];
            $teacherLifecycleAction=in_array($action,['archive','restore'],true)&&sanitize_key($post['entity']??'')==='teacher';
            $currentPage=sanitize_key(wp_unslash($_GET['page']??''));
            self::redirectNotice('Saved' . ($id && $currentPage !== 'dzn-teachers-menu' ? ' #' . (int) $id : ''), false, in_array($action,$teacherActions,true) ? ['id' => absint($post['teacher_id'] ?? 0)] : ($teacherLifecycleAction?['id'=>absint($post['id']??0)]:[]));
        } catch (\Throwable $e) {
            if ($action === 'issue_teacher_invitation_by_email' && $e->getMessage() !== 'invitation_dispatch_failed') {
                $category = \Delnavazan\Platform\Core\Application\TeacherInvitationIssuanceFailure::category($e);
                \Delnavazan\Platform\Core\Application\TeacherInvitationIssuanceFailure::log($category);
                self::redirectNotice(\Delnavazan\Platform\Core\Application\TeacherInvitationIssuanceFailure::notice($category), true);
            }
            self::redirectNotice($e->getMessage()==='invitation_dispatch_failed'?'Invitation dispatch failed; the recorded status below is authoritative.':'Operation failed; no change was saved.', true, ['id' => absint($post['teacher_id'] ?? 0)]);
        }
    }

    private static function createPayload(string $entity, array $post): array {
        if (!isset(self::CREATE_FIELDS[$entity])) throw new \InvalidArgumentException('Unsupported create entity');
        return array_intersect_key($post, array_flip(self::CREATE_FIELDS[$entity]));
    }

    private static function nonceAction(string $action): string {
        return 'dzn_platform_' . $action;
    }

    private static function logNonceDiagnostic(string $action, array $post): void {
        if (!defined('DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS') || DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS !== true) return;
        $noncePresent = array_key_exists('_wpnonce', $post);
        $nonceScalar = $noncePresent && is_string($post['_wpnonce']) && $post['_wpnonce'] !== '';
        $verification = $nonceScalar ? (int) wp_verify_nonce($post['_wpnonce'], self::nonceAction($action)) : 0;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $entity = str_starts_with($page, 'dzn-') ? substr($page, 4) : '';
        $capability = $entity === 'exception' ? 'dzn_manage_exceptions' : (self::ENTITIES[$entity][1] ?? '');
        $diagnostic = [
            'action' => $action,
            'nonce_present' => $noncePresent,
            'nonce_scalar' => $nonceScalar,
            'nonce_verify' => $verification,
            'user_id' => get_current_user_id(),
            'capability_allowed' => $capability !== '' && current_user_can($capability),
            'page' => $page,
            'method' => sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')),
        ];
        error_log('[Delnavazan Platform Phase 1F nonce] ' . wp_json_encode($diagnostic));
    }

    private static function archive(array $post): int { $entity=self::entityFromPost($post);if($entity==='teacher')throw new \InvalidArgumentException('Use the authorised Teacher record action');$id=absint($post['id']??0);(new ArchiveService())->archive($entity,$id);return $id; }
    private static function restore(array $post): int { $entity=self::entityFromPost($post);if($entity==='teacher')throw new \InvalidArgumentException('Use the authorised Teacher record action');$id=absint($post['id']??0);(new ArchiveService())->restore($entity,$id);return $id; }
    private static function schedule(array $post, bool $initial): int { $id = absint($post['lesson_id'] ?? 0); return $initial ? (new LessonScheduleService())->initial($id, $post) : (new LessonScheduleService())->reschedule($id, $post); }
    private static function transition(array $post, string $to): int { (new ExceptionService())->transition(absint($post['id'] ?? 0), $to, $post['resolution_note'] ?? null); return 0; }
    private static function retry(array $post): int { (new ExceptionService())->incrementRetry(absint($post['id'] ?? 0)); return 0; }

    private static function entityFromPost(array $post): string {
        $entity = sanitize_key($post['entity'] ?? '');
        if (!isset(self::ENTITIES[$entity])) throw new \InvalidArgumentException('Unsupported entity');
        return $entity;
    }

    private static function entityList(string $entity): void {
        if ($entity === 'student') { self::studentList(); return; }
        if($entity==='lesson'){
            $records=(new CoreReadService())->classOperatorRows();
            echo '<h2>Classes and schedule</h2><p>Review each learner, teacher, class state and next scheduling handoff. Schedule changes stay with the authorised schedule workflow. Confirmed introductory bookings are shown from their booking confirmation until the post-intro workflow creates the canonical Lesson.</p>';
            if(!$records){echo '<p>No classes to review.</p>';return;}
            echo '<table class="widefat striped"><thead><tr><th>Class</th><th>Student / course</th><th>Teacher</th><th>Class status</th><th>Schedule</th><th>Next step</th></tr></thead><tbody>';
            foreach($records as$row){
                $intro=($row->record_model??'')==='confirmed_intro_booking_v1';
                $url=$intro?add_query_arg(array('page'=>'dzn-booking-requests','request_id'=>(int)$row->booking_request_id),admin_url('admin.php')):add_query_arg(array('page'=>'dzn-lesson','id'=>(int)$row->id),admin_url('admin.php'));
                $label=(string)($row->reference_code?:($intro?'Confirmed intro':'Class'));
                echo '<tr><td><a href="'.esc_url($url).'">'.esc_html($label).'</a></td><td>'.esc_html((string)($row->student_name?:'Student')).'<br>'.esc_html((string)($row->course_name?:'Course')).'</td><td>'.esc_html((string)($row->teacher_name?:'Teacher')).'</td><td>'.esc_html((string)$row->class_status).'</td><td>'.esc_html((string)$row->schedule_label).'</td><td><a class="button" href="'.esc_url($url).'">'.esc_html((string)$row->next_action).'</a></td></tr>';
            }
            echo '</tbody></table>';return;
        }
        if ($entity === 'teacher') {
            $view = isset($_GET['view']) && is_scalar($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
            // Generic onboarding links (Academy, dashboard widgets and the operations portal)
            // predate the two-tab Teachers shell. Land onboarding-only staff on their own view.
            if ($view === '' && !current_user_can('dzn_manage_teachers') && current_user_can(Menu::TEACHER_ONBOARDING_CAP)) {
                $view = 'onboarding';
            }
            if ($view === 'onboarding') {
                if (!current_user_can(Menu::TEACHER_ONBOARDING_CAP)) { self::forbidden(); return; }
                self::teacherInvitationPanel();
                return;
            }
            if (!current_user_can('dzn_manage_teachers')) {
                echo '<p>Teacher overview requires teacher maintenance access. Use <strong>Onboarding &amp; Invitations</strong> for your permitted work.</p>';
                return;
            }
            self::teacherDirectory();
            self::teacherCoverage();
            return;
        }
        try { $records = in_array($entity,['course','instrument'],true)?(new CoreReadService())->catalogueRows($entity):(new CoreReadService())->recent($entity); }
        catch (\InvalidArgumentException $exception) {
            if ($entity !== 'enrolment' || $exception->getMessage() !== 'data_integrity_conflict') throw $exception;
            echo '<p class="notice notice-error">Canonical lifecycle history failed integrity validation.</p>';
            return;
        }
        echo '<h2>Recent records</h2>';
        if (in_array($entity, ['student','teacher'], true)) self::objectTable($records, 'dzn-' . $entity, self::profileFields($entity));
        elseif(in_array($entity,['course','instrument'],true)) self::objectTable($records, 'dzn-' . $entity, self::catalogueFields($entity));
        else self::objectTable($records, 'dzn-' . $entity);
    }

    private static function teacherDirectory(): void {
        $search = isset($_GET['teacher_search']) && is_scalar($_GET['teacher_search']) ? sanitize_text_field(wp_unslash((string)$_GET['teacher_search'])) : '';
        $status = isset($_GET['teacher_status']) && is_scalar($_GET['teacher_status']) ? sanitize_key(wp_unslash((string)$_GET['teacher_status'])) : 'all';
        $page = max(1, absint($_GET['teacher_page'] ?? 1));
        $sort = isset($_GET['teacher_sort']) && is_scalar($_GET['teacher_sort']) ? sanitize_key(wp_unslash((string)$_GET['teacher_sort'])) : 'name';
        $direction = isset($_GET['teacher_order']) && is_scalar($_GET['teacher_order']) ? sanitize_key(wp_unslash((string)$_GET['teacher_order'])) : 'asc';
        $sortLabels = array('name'=>'Teacher','email'=>'Email','status'=>'Status','intake'=>'Student intake','instruments'=>'Instruments','availability'=>'Weekly availability','classes'=>'Current classes');
        if (!isset($sortLabels[$sort])) $sort = 'name';
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $result = (new CoreReadService())->teacherAdminDirectory($search, $status, $page, $sort, $direction);
        echo '<h2>Teacher directory</h2><p>Search by teacher name, email or reference. Operational indicators summarize current course eligibility, availability and applicable classes.</p>';
        echo '<form method="get" class="search-form"><input type="hidden" name="page" value="dzn-teachers-menu"><input type="hidden" name="teacher_sort" value="'.esc_attr($sort).'"><input type="hidden" name="teacher_order" value="'.esc_attr($direction).'">';
        echo '<label class="screen-reader-text" for="dzn-teacher-search">Search teachers</label><input id="dzn-teacher-search" type="search" name="teacher_search" value="'.esc_attr($search).'" placeholder="Name, email or reference"> ';
        echo '<label for="dzn-teacher-status">Status </label><select id="dzn-teacher-status" name="teacher_status">';
        foreach (array('all'=>'All teachers','active'=>'Active','pending'=>'Pending','inactive'=>'Inactive','archived'=>'Archived') as $value=>$label) echo '<option value="'.esc_attr($value).'"'.selected($status,$value,false).'>'.esc_html($label).'</option>';
        echo '</select> <button class="button">Search</button></form>';
        if (!$result['rows']) { echo '<p>No teachers match these filters. Try a broader search or choose another status.</p>'; return; }
        echo '<p>'.esc_html(number_format_i18n($result['total'])).' teacher'.($result['total']===1?'':'s').'</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($sortLabels as $key => $label) {
            $nextDirection = $sort === $key && $direction === 'asc' ? 'desc' : 'asc';
            $url = add_query_arg(array('page'=>'dzn-teachers-menu','teacher_search'=>$search,'teacher_status'=>$status,'teacher_sort'=>$key,'teacher_order'=>$nextDirection,'teacher_page'=>1),admin_url('admin.php'));
            $state = $sort === $key ? ($direction === 'asc' ? ' aria-sort="ascending"' : ' aria-sort="descending"') : '';
            $arrow = $sort === $key ? ($direction === 'asc' ? ' ↑' : ' ↓') : '';
            echo '<th scope="col"'.$state.'><a href="'.esc_url($url).'">'.esc_html($label.$arrow).'</a></th>';
        }
        echo '<th>Profile details</th><th>Actions</th></tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            $url=add_query_arg(array('page'=>'dzn-teachers-menu','id'=>(int)$row->id),admin_url('admin.php'));
            $name=(string)($row->display_name?:$row->english_name?:$row->persian_name?:'Teacher');
            $detail=$row->reference_code?'<br><small>'.esc_html((string)$row->reference_code).'</small>':'';
            $archived=$row->archived_at!==null;
            echo '<tr><td><a href="'.esc_url($url).'"><strong>'.esc_html($name).'</strong></a>'.$detail.'</td><td>'.esc_html((string)($row->email?:'—')).'</td><td>'.esc_html($archived?'Archived':self::teacherStatus((string)($row->directory_status??$row->status))).'</td>';
            echo '<td>'.esc_html(self::acceptingLabel((string)$row->accepting_state)).'</td><td>'.esc_html((string)$row->instrument_names!==''?(string)$row->instrument_names:'None recorded').'</td>';
            $availability=(int)$row->availability_rule_count>0?number_format_i18n((int)$row->availability_rule_count).' time windows':((string)$row->availability_status==='not_set'?'Not set':'No active time windows');
            echo '<td>'.esc_html($availability).'</td><td>'.esc_html(number_format_i18n((int)$row->current_class_count)).'</td>';
            echo '<td><details><summary>Expand</summary><p><strong>Instruments:</strong> '.esc_html((string)$row->instrument_names!==''?(string)$row->instrument_names:'None recorded').'</p><p><strong>Weekly availability ('.esc_html((string)($row->timezone?:'timezone not set')).'):</strong> '.esc_html((string)$row->availability_summary!==''?(string)$row->availability_summary:'No active weekly windows').'</p><p><a href="'.esc_url($url).'">Open teacher profile and controls</a></p></details></td>';
            $actionsUrl = $url . '#teacher-actions';
            $onboardingAnchor = in_array((string) ($row->directory_status ?? ''), array('not_invited', 'archived'), true) ? '' : '#' . self::teacherAnchor($row);
            $onboardingUrl = add_query_arg(array('page' => 'dzn-teachers-menu', 'view' => 'onboarding'), admin_url('admin.php')) . $onboardingAnchor;
            $onboardingButton = current_user_can(Menu::TEACHER_ONBOARDING_CAP) ? ' <a class="button" href="' . esc_url($onboardingUrl) . '">Onboarding</a>' : '';
            echo '<td><a class="button" href="' . esc_url($actionsUrl) . '">Manage</a>' . $onboardingButton . '</td></tr>';
        }
        echo '</tbody></table>';
        $pages=(int)ceil($result['total']/$result['per_page']);
        if($pages>1){$base=add_query_arg(array('page'=>'dzn-teachers-menu','teacher_search'=>$search,'teacher_status'=>$status,'teacher_sort'=>$sort,'teacher_order'=>$direction,'teacher_page'=>'%#%'),admin_url('admin.php'));echo '<nav class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>$base,'format'=>'','current'=>$page,'total'=>$pages,'type'=>'plain','prev_text'=>'‹ Previous','next_text'=>'Next ›'))).'</div></nav>';}
    }

    private static function teacherStatus(string $status): string { return match($status){'active'=>'Active and ready','pending'=>'Pending','inactive'=>'Inactive','archived'=>'Archived','invitation_queued'=>'Invitation queued','invitation_available'=>'Invitation created; claim pending','onboarding_required'=>'Account linked; onboarding incomplete','access_revoked'=>'Teacher access revoked','not_invited'=>'Not invited',default=>ucfirst(str_replace('_',' ',$status))}; }
    private static function acceptingLabel(string $state): string { return match($state){'accepting'=>'Accepting students','limited'=>'Limited availability','paused'=>'Paused','not_set'=>'Not recorded',default=>ucfirst(str_replace('_',' ',$state))}; }
    private static function teacherOnboardingSummary(object $teacher): string {
        $principal = match ((string)($teacher->principal_status ?? '')) {
            'active' => 'Account linked', '', 'not_linked' => 'Account not linked',
            default => ucfirst(str_replace('_', ' ', (string)$teacher->principal_status)),
        };
        $onboarding = match ((string)($teacher->onboarding_state ?? '')) {
            'active' => 'Onboarding complete', '', 'not_started' => 'Onboarding not started',
            default => ucfirst(str_replace('_', ' ', (string)$teacher->onboarding_state)),
        };
        $readiness = match ((string)($teacher->readiness_state ?? '')) {
            'ready' => 'Ready to teach', '', 'not_ready' => 'Not ready to teach',
            default => ucfirst(str_replace('_', ' ', (string)$teacher->readiness_state)),
        };
        return $principal . ' · ' . $onboarding . ' · ' . $readiness;
    }
    private static function teacherIntegrationStatus(string $state): string { return match($state){'connected'=>'Connected','not_connected'=>'Not connected',''=>'Not connected',default=>ucfirst(str_replace('_',' ',$state))}; }

    private static function entityDetail(string $entity, int $id): void {
        if ($entity === 'teacher' && !current_user_can('dzn_manage_teachers')) { self::forbidden(); return; }
        $record = $entity==='lesson'?(new CoreReadService())->lessonOperatorRecord($id):($entity==='teacher'?(new CoreReadService())->teacherAdminProfile($id):(new CoreReadService())->find($entity, $id));
        if (!$record && $entity==='term' && current_user_can('dzn_manage_canonical_terms')) $record=(new CoreReadService())->canonicalTerm($id);
        if (!$record) { echo '<p>Record not found.</p>'; return; }
        $profile=in_array($entity,['student','teacher'],true);
        $canonicalTerm=$entity==='term'&&($record->record_model??null)==='canonical_enrolment_term_v1';
        $canonicalLesson=$entity==='lesson'&&($record->record_model??null)==='canonical_term_lesson_v1';
        if ($entity === 'student') {
            $studentName = trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? ''));
            $heading = 'Student · ' . (string) ($record->display_name ?: ($studentName !== '' ? $studentName : 'Profile'));
        } else {
            $heading = $profile ? ucfirst($entity) . ' profile · ' . (string) ($record->reference_code ?? '') : ($canonicalTerm ? 'Term · ' . (string) ($record->reference_code ?? '') : ($canonicalLesson ? 'Class · ' . (string) ($record->reference_code ?? '') : 'Record #' . (string) $id));
        }
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . ($entity==='teacher'?'dzn-teachers-menu':'dzn-'.$entity))) . '">← Back to list</a></p><h2>' . esc_html($heading) . '</h2>';
        if ($entity === 'student') {
            self::studentDetailTabs($record);
            return;
        }
        $displayRecord=in_array($entity,['course','instrument'],true)?(new CoreReadService())->catalogueRecord($entity,$id):$record;
        $allowed=$profile?self::profileFields($entity):(in_array($entity,['course','instrument'],true)?self::catalogueFields($entity):($canonicalTerm?self::canonicalTermFields():(($entity==='lesson')?array('reference_code','student_name','teacher_name','course_name','lesson_type','class_status','schedule_label'):null)));
        self::objectTable([$displayRecord??$record], null, $allowed);
        if ( $entity === 'teacher' && current_user_can( 'dzn_manage_teachers' ) && $record->archived_at === null && $record->status !== 'archived' ) {
            $teacherId=(int)$record->id;
            echo '<h2>Teaching, account and availability</h2><table class="widefat striped"><tbody><tr><th>Google Calendar</th><td>'.esc_html(self::teacherIntegrationStatus((string)($record->google_calendar_state ?? ''))).'</td></tr><tr><th>Account and onboarding</th><td>'.esc_html(self::teacherOnboardingSummary($record)).'</td></tr><tr><th>Instruments</th><td>'.esc_html((string)($record->instrument_names?:'Not recorded')).'</td></tr><tr><th>Weekly availability</th><td>'.esc_html((string)($record->availability_summary?:'No active weekly windows')).'</td></tr><tr><th>Linked students</th><td>'.esc_html((string)($record->linked_student_names?:'None recorded')).'</td></tr><tr><th>Current teaching</th><td>'.esc_html((string)($record->linked_student_count??0).' linked students · '.(string)($record->class_count??0).' applicable classes').'</td></tr></tbody></table>';
            echo '<section id="teacher-actions"><h2>Teacher actions</h2><p>Use the focused actions below to maintain this teacher. Each action checks your permission and the current lifecycle state.</p>';
            self::teacherReviewControls($record); self::teacherInlineControls($record); echo '<details><summary>Profile details and lifecycle actions</summary>';
            self::teacherMaintenanceForm( $record );
            \Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer::renderSelected('teacher', $record, 'dzn-teachers-menu', array('teacher-archive', 'teacher-restore'));
            echo '</details></section>';
            self::teacherFinanceLinks($record);
        }
        if ($entity === 'teacher' && ($record->archived_at !== null || $record->status === 'archived')) { echo '<p class="notice notice-info">This Teacher is archived. Teaching and audit history is retained; invitations and Teacher access are revoked. Restore makes the profile eligible for a new invitation but does not restore access automatically.</p>'; \Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer::render('teacher', $record, 'dzn-teachers-menu'); }
        if (!$canonicalTerm && ($entity !== 'enrolment' || ($record->record_model ?? 'legacy_phase1') === 'legacy_phase1') && $entity !== 'teacher') self::archiveActions($entity, $id, (string) $record->status);
        if ($entity === 'enrolment') {
            echo '<h2>Canonical lifecycle history</h2>';
            try { $history = (new CoreReadService())->enrolmentLifecycle($id); }
            catch (\InvalidArgumentException $exception) {
                if ($exception->getMessage() !== 'data_integrity_conflict') throw $exception;
                echo '<p class="notice notice-error">Canonical lifecycle history failed integrity validation.</p>';
                return;
            }
            if ($history) self::objectTable($history, null); else echo '<p>No canonical lifecycle history.</p>';
            if (($record->record_model ?? '') === 'canonical_student_course_v1' && current_user_can('dzn_manage_canonical_enrolment_lifecycle')) self::canonicalEnrolmentLifecycleForm($record);
        }
        if ($canonicalTerm && current_user_can('dzn_manage_canonical_terms')) { self::canonicalTermHistory($id); self::canonicalTermLifecycleForm($record); }
        if ($entity === 'lesson') { if(!$canonicalLesson)self::introductoryOutcomeForm($record); if($canonicalLesson){self::canonicalLessonCompletionForm($record);self::canonicalLessonDeliverySection($record);if(current_user_can('dzn_manage_canonical_lesson_schedules'))self::canonicalLessonSchedule($record);else echo '<p>Schedule changes are handled by staff with class scheduling access.</p>';}else self::lessonSchedules($id,(string)$record->status,(int)($record->current_schedule_version_id??0)); }
    }

    private static function updateTeacherProfile(array $post): int {
        if ( ! current_user_can( 'dzn_manage_teachers' ) ) throw new \RuntimeException( 'Unauthorized' );
        $fields = ['persian_name','english_name','display_name','email','phone','whatsapp_phone','country_code','city','timezone','locale','calendar_preference'];
        ( new \Delnavazan\Platform\Core\Application\TeacherOnboardingService() )->updateByAdministrator( absint( $post['teacher_id'] ?? 0 ), array_intersect_key( $post, array_flip( $fields ) ) );
        return absint( $post['teacher_id'] ?? 0 );
    }

    /** Invitation is the authorization decision. A mail failure is surfaced as a failure, never as "sent". */
    private static function issueTeacherInvitation(array $post): int {
        $formId=sanitize_text_field($post['invitation_form_id']??'');
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',$formId))throw new \InvalidArgumentException('invitation_form_expired');
        $service=new \Delnavazan\Platform\Core\Application\PrincipalInvitationService();
        $result=$service->issueForEmail(sanitize_email($post['recipient']??''),604800,hash('sha256','dzn_teacher_invitation_form:'.$formId));
        $generation=(int)($result['generation_id']??0);
        if($generation>0&&\Delnavazan\Platform\Core\Application\TeacherInvitationDeliveryWorker::dispatchNow($generation)==='failed')throw new \RuntimeException('invitation_dispatch_failed');
        return (int)($result['teacher_id']??0);
    }
    private static function revokeTeacherInvitation(array $post): int { (new \Delnavazan\Platform\Core\Application\PrincipalInvitationService())->revoke(absint($post['teacher_id']??0),'admin_revoked'); return absint($post['teacher_id']??0); }
    private static function linkTeacherPrincipal(array $post): int { $teacherId=absint($post['teacher_id']??0); (new \Delnavazan\Platform\Core\Application\PrincipalInvitationService())->linkExistingPrincipal($teacherId,absint($post['wordpress_user_id']??0)); return $teacherId; }
    private static function offboardTeacherPrincipal(array $post): int { (new \Delnavazan\Platform\Core\Application\PrincipalInvitationService())->offboard(absint($post['teacher_id']??0),'admin_offboarded'); return absint($post['teacher_id']??0); }
    private static function reviewTeacherOnboarding(array $post,string $decision):int{$teacherId=absint($post['teacher_id']??0);(new \Delnavazan\Platform\Core\Application\TeacherOnboardingService())->review($teacherId,$decision,sanitize_key($post['reason_code']??''));return$teacherId;}

    private static function teacherInvitationPanel(): void {
        echo '<section id="teacher-invitation"><h2>Teacher onboarding &amp; invitations <a class="page-title-action" href="#teacher-invitation">Add New</a></h2><p>Issuing creates a delivery intent; it does not mean an email was sent or received. Email is enough to authorise account registration. Teaching access remains unavailable until the Teacher submits complete profile and availability details and staff approve them. “Accepted by transport” means WordPress accepted the message, not that the recipient received it. Raw invitation secrets are never rendered, retained, logged, or placed in URLs.</p>';
        if(current_user_can('dzn_issue_teacher_invitations')) { self::formStart('issue_teacher_invitation_by_email'); echo '<input type="hidden" name="invitation_form_id" value="'.esc_attr(wp_generate_uuid4()).'"><p><label>Email <input required type="email" name="recipient"></label> '; submit_button('Send Invitation','primary','submit',false); echo '</p></form>'; }
        $canReadInvitationStatus=current_user_can('dzn_manage_onboarding')||current_user_can('dzn_issue_teacher_invitations')||current_user_can('dzn_revoke_teacher_invitations');
        if(!$canReadInvitationStatus) { echo '<p>Invitation status requires invitation or onboarding access.</p></section>'; return; }
        $page=max(1,absint($_GET['invitation_page']??1));$read=new \Delnavazan\Platform\Core\Application\PrincipalInvitationReadService();$rows=$read->rows($page,5);$total=$read->count();
        echo '<h3>Recent invitations</h3><table class="widefat striped"><thead><tr><th>Teacher</th><th>Invitation</th><th>Expiry</th><th>Delivery status</th><th>Safe actions</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="5">No invitations yet.</td></tr>';
        foreach($rows as $row){$delivery=(string)($row->delivery_status??'');$state=$delivery==='delivered'?'Accepted by WordPress mail transport; recipient delivery is not confirmed':($delivery==='failed'?'Failed: '.(string)($row->delivery_failure_reason?:'transport_failed'):($delivery==='pending'?'Queued for dispatch':($delivery==='prepared'?'Interrupted; resend creates a replacement':'No delivery recorded')));$teacherId=(int)$row->teacher_id;$teacherName=esc_html((string)$row->display_name);if(current_user_can('dzn_manage_teachers')){$profile=add_query_arg(['page'=>'dzn-teachers-menu','id'=>$teacherId],admin_url('admin.php'));$teacherName='<a href="'.esc_url($profile).'">'.$teacherName.'</a>';}
            echo '<tr><td>'.$teacherName.'<br><small>'.esc_html((string)$row->email).'</small></td><td>'.esc_html(self::teacherInvitationStatus((string)($row->lifecycle_status??'unavailable'))).'</td><td>'.esc_html((string)($row->generation_expires_at?:'—')).'</td><td>'.esc_html($state).'</td><td>';
            if(in_array((string)($row->lifecycle_status??''),['queued_for_delivery','claim_available'],true)&&current_user_can('dzn_revoke_teacher_invitations')){self::formStart('revoke_teacher_invitation');echo '<input type="hidden" name="teacher_id" value="'.esc_attr((string)$teacherId).'">';submit_button('Revoke','secondary','submit',false);echo '</form> ';}
            if((in_array($delivery,['failed','prepared'],true)||in_array((string)($row->lifecycle_status??''),['expired','revoked'],true))&&current_user_can('dzn_issue_teacher_invitations')){self::formStart('issue_teacher_invitation_by_email');echo '<input type="hidden" name="recipient" value="'.esc_attr((string)$row->email).'"><input type="hidden" name="invitation_form_id" value="'.esc_attr(wp_generate_uuid4()).'">';submit_button('Resend as replacement','secondary','submit',false);echo '</form>';}
            echo '</td></tr>';
        }
        echo '</tbody></table>';$pages=(int)ceil($total/5);if($pages>1){$base=add_query_arg(['page'=>'dzn-teachers-menu','view'=>'onboarding','invitation_page'=>'%#%'],admin_url('admin.php'));echo '<p class="tablenav">'.wp_kses_post(paginate_links(['base'=>$base,'format'=>'','current'=>$page,'total'=>$pages])).'</p>';}
        self::teacherReadinessPanel();
        self::teacherPrincipalLinkPanel();
        echo '</section>';
    }

    private static function teacherInvitationStatus(string $status):string{return match($status){'queued_for_delivery'=>'Queued for dispatch','claim_available'=>'Claim code created; account not yet linked','account_linked','claimed'=>'Claimed and account linked','expired'=>'Expired; issue a replacement if still needed','revoked'=>'Revoked','superseded'=>'Replaced by a newer invitation','teacher_archived'=>'Teacher archived; invitation revoked','unavailable'=>'No claimable invitation',default=>ucfirst(str_replace('_',' ',$status))};}

    private static function teacherReadinessPanel(): void {
        if (!current_user_can('dzn_manage_onboarding')) {
            echo '<p>Onboarding progress requires onboarding access. Invitation status remains available above.</p>';
            return;
        }
        $rows = (new \Delnavazan\Platform\Core\Application\TeacherOnboardingWorkspaceReadService())->rows(50);
        echo '<h3>Onboarding readiness</h3><p>Teachers complete their profile and weekly availability, then submit them for an explicit staff decision. Blockers and the next required action are shown for each Teacher.</p>';
        echo '<p class="description">A connected Google account is not proof that Google Meet works. Calendar and Meet columns report verified projection evidence only; “no evidence” means readiness is unproven, not failed.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Teacher</th><th>Account</th><th>Profile</th><th>Availability</th><th>Google account</th><th>Calendar</th><th>Meet</th><th>Blockers / next action</th><th>Review</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="9">No teachers are available for onboarding review.</td></tr>';
        foreach ($rows as $item) {
            $teacher = $item['teacher'];
            $progress = $item['progress'];
            $name = esc_html((string) $teacher->display_name);
            if (current_user_can('dzn_manage_teachers')) {
                $profile = add_query_arg(array('page' => 'dzn-teachers-menu', 'id' => (int) $teacher->id), admin_url('admin.php'));
                $name = '<a href="' . esc_url($profile) . '">' . $name . '</a>';
            }
            $account = (string) ($teacher->principal_status ?? '') === 'active' ? 'Linked' : 'Not linked';
            echo '<tr id="' . esc_attr(self::teacherAnchor($teacher)) . '"><td>' . $name . '<br><small>' . esc_html((string) $teacher->email) . '</small></td>';
            echo '<td>' . esc_html($account) . '</td>';
            echo '<td>' . esc_html(self::readinessLabel((string) $progress['profile_state'])) . '</td>';
            echo '<td>' . esc_html(self::readinessLabel((string) $progress['availability_state'])) . '</td>';
            echo '<td>' . esc_html((string) $item['google']['label']) . '<br><small>' . esc_html((string) $item['google']['meet_caveat']) . '</small></td>';
            echo '<td>' . esc_html((string) $item['calendar']['label']) . '</td>';
            echo '<td>' . esc_html((string) $item['meeting']['label']) . '</td>';
            echo '<td>';
            if ($item['blockers']) {
                echo '<ul class="dzn-teacher-blockers">';
                foreach ($item['blockers'] as $blocker) echo '<li>' . esc_html((string) $blocker) . '</li>';
                echo '</ul>';
            } else {
                echo '<p>No blocking onboarding step.</p>';
            }
            echo '<small>' . esc_html((string) $item['next_action']) . '</small></td>';
            echo '<td>';
            if ((string) ($teacher->state ?? '') === 'pending_review' && current_user_can('dzn_manage_onboarding')) {
                \Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer::renderSelected('teacher', $teacher, 'dzn-teachers-menu', array('teacher-approve-onboarding', 'teacher-return-onboarding', 'teacher-reject-onboarding'));
            } else {
                echo '<span>No decision available</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function teacherAnchor(object $teacher): string {
        $label = (string) ($teacher->reference_code ?: $teacher->email ?: $teacher->display_name ?: 'teacher');
        return 'dzn-teacher-onboarding-' . sanitize_title($label);
    }

    private static function readinessLabel(string $state): string {
        return match ($state) {
            'complete' => 'Complete',
            'incomplete' => 'Incomplete',
            'connected' => 'Connected',
            'not_connected' => 'Not connected',
            'not_ready' => 'Not ready',
            'ready' => 'Ready',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private static function teacherPrincipalLinkPanel(): void {
        if (!current_user_can('dzn_manage_onboarding')) return;
        $teachers=(new \Delnavazan\Platform\Core\Application\PrincipalInvitationReadService())->rows(1,100);
        echo '<details><summary>Link a verified existing WordPress account</summary><p><strong>Use only after verifying the person’s identity.</strong> This bypasses email delivery only; it does not bypass principal ownership safeguards or onboarding readiness.</p>';
        self::formStart('admin_link_teacher_principal');echo '<p><label>Teacher <select required name="teacher_id"><option value="">Select teacher</option>';foreach($teachers as $teacher)echo '<option value="'.esc_attr((string)$teacher->teacher_id).'">'.esc_html((string)$teacher->display_name.' · '.(string)$teacher->email).'</option>';echo '</select></label></p><p><label>Existing WordPress user <select required name="wordpress_user_id"><option value="">Select account</option>';foreach(get_users(['number'=>200,'orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name','user_login','user_email']]) as $user){$label=trim((string)$user->display_name).' ('.(string)$user->user_login.' · '.(string)$user->user_email.')';echo '<option value="'.esc_attr((string)$user->ID).'">'.esc_html($label).'</option>';}echo '</select></label></p>';submit_button('Link existing account to Teacher','secondary');echo '</form></details>';
    }

    private static function teacherCoverage(): void {
        if(!current_user_can('dzn_view_booking_requests'))return;
        $rows=(new \Delnavazan\Platform\Core\Application\AcademyCoverageReadService())->matrix();
        echo '<section id="teacher-coverage"><h2>Coverage</h2><p>Decision support from current eligibility and declared availability only. When there is no usable supply or demand basis, the result is unavailable rather than invented.</p><table class="widefat striped"><thead><tr><th>Instrument</th><th>Region</th><th>Covered / insufficient</th><th>Uncovered times</th></tr></thead><tbody>';
        foreach($rows as$r){$g=array_slice($r['gaps'],0,4);$labels=[];foreach($g as$x)$labels[]='day '.$x['weekday'].' '.sprintf('%02d:%02d',intdiv($x['minute'],60),$x['minute']%60);$coverage=($r['metric_state']??'available')==='insufficient'?'Insufficient: no eligible teacher availability recorded':((int)$r['total_slots']>0?(int)$r['coverage_percent'].'% · '.(int)$r['covered_slots'].'/'.(int)$r['total_slots']:'Unavailable');echo '<tr><td>'.esc_html((string)$r['instrument']).'</td><td>'.esc_html((string)$r['region_label']).'</td><td>'.esc_html($coverage).'</td><td>'.esc_html($labels?implode(', ',$labels):'No uncovered measured slots').'</td></tr>';}
        echo '</tbody></table></section>';
    }

    private static function teacherMaintenanceForm(object $teacher): void {
        echo '<h2>Profile actions</h2><p>Edit contact, location, timezone and calendar preferences. Teacher ID, account link, lifecycle status and audit fields remain controlled by their lifecycle operations.</p>';
        \Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer::renderSelected('teacher', $teacher, 'dzn-teachers-menu', array('teacher-profile'));
    }

    private static function teacherInlineControls(object $teacher): void {
        echo '<h3>Instruments, eligibility and availability</h3><p>These actions use the existing teaching-eligibility and availability authorities. Actions are shown only when you hold the matching permission.</p>';
        \Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer::renderSelected('teacher', $teacher, 'dzn-teachers-menu', array(
            'teacher-accepting-state',
            'teacher-eligibility',
            'teacher-availability-profile',
            'teacher-availability-rule',
            'teacher-offboard',
        ));
    }

    private static function teacherReviewControls(object $teacher):void{
        if(!current_user_can('dzn_manage_onboarding')||(string)($teacher->onboarding_state??'')!=='pending_review')return;
        echo '<h3>Onboarding review</h3><p>This Teacher submitted a profile and availability declaration. Review the evidence and blockers in Onboarding &amp; Invitations before deciding. Use <strong>Approve and activate</strong> only when the profile, availability and connection evidence are complete.</p>';
        \Delnavazan\Platform\Admin\RecordAction\RecordActionRenderer::renderSelected('teacher', $teacher, 'dzn-teachers-menu', array('teacher-approve-onboarding', 'teacher-return-onboarding', 'teacher-reject-onboarding'));
    }

    private static function teacherFinanceLinks(object $teacher): void {
        if (!current_user_can('dzn_view_finance_authority')) return;
        $id = (int) $teacher->id;
        echo '<section id="teacher-finance"><h2>Finance follow-up</h2><p>Teacher-specific rates, statements and reconciliation move onto this record in the next implementation stage. The current central reports remain available.</p><p>';
        echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-finance', 'tab' => 'settings', 'teacher_id' => $id), admin_url('admin.php'))) . '">Current rate report</a> ';
        echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-finance', 'tab' => 'statements', 'teacher_id' => $id), admin_url('admin.php'))) . '">Current statement report</a> ';
        echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-finance', 'tab' => 'ledger'), admin_url('admin.php'))) . '">Central reconciliation</a>';
        echo '</p></section>';
    }

    /**
     * Canonical authority first. The legacy creator remains available only as a labelled
     * compatibility path and only when a real legacy Enrolment can satisfy it.
     */
    private static function termCreateSection(): void {
        $canonical = current_user_can('dzn_manage_canonical_terms');
        if ($canonical) self::canonicalTermCreateForm();
        try { $legacy = (new CoreReadService())->legacyEnrolmentOptionsForTerm(); }
        catch (\Throwable) { $legacy = array(); }
        if (!$legacy) {
            if ($canonical) echo '<p class="description">Canonical Terms are the current workflow. The legacy Term form is unavailable because no legacy Enrolment exists; create the Term above instead.</p>';
            else self::entityCreateForm('term');
            return;
        }
        echo '<details class="dzn-legacy-term-create"><summary class="button">Legacy Term compatibility</summary>';
        echo '<p class="description">This compatibility form accepts legacy Enrolments only. For a converted Enrolment, use <strong>Create canonical Term</strong> above.</p>';
        self::entityCreateForm('term');
        echo '</details>';
    }

    /**
     * Canonical class issuance is the primary workflow. Introductory/legacy class setup stays
     * available because an introductory Lesson is deliberately outside a Term.
     */
    private static function classCreateSection(): void {
        $canonical = current_user_can('dzn_manage_canonical_lessons');
        if ($canonical) self::canonicalLessonCreateForm();
        if (!$canonical) { self::entityCreateForm('lesson'); return; }
        echo '<details class="dzn-legacy-class-create"><summary class="button">Create introductory or legacy class</summary>';
        echo '<p class="description">An introductory class may be created without an Enrolment or Term. Standard paid classes are issued from the canonical Term above, and a replacement class from an eligible cancelled standard class.</p>';
        self::entityCreateForm('lesson');
        echo '</details>';
    }

    private static function entityCreateForm(string $entity): void {
        echo '<h2>Create</h2>'; self::formStart('create_' . $entity);
        match ($entity) {
            'teacher' => self::teacherFields(), 'student' => self::studentFields(), 'instrument' => self::instrumentFields(),
            'course' => self::courseFields(), 'term' => self::termFields(), 'lesson' => self::lessonFields(),
        };
        submit_button('Create ' . self::ENTITIES[$entity][0]); echo '</form>';
    }

    private static function studentCreateDisclosure(): void {
        echo '<details class="dzn-student-create"><summary class="button">Add student</summary><p>Create a student profile for a learner already identified by your team.</p>';
        self::entityCreateForm('student');
        echo '</details>';
    }

    private static function studentList(): void {
        $query = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $requested = sanitize_key(wp_unslash($_GET['student_status'] ?? 'active'));
        $status = in_array($requested, array('active','inactive','archived','all'), true) ? $requested : 'active';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $result = (new CoreReadService())->studentAdminRows($query, $status, $page);
        echo '<h2>Student directory</h2><p>Active students appear first. Search by name, email, phone or reference.</p>';
        echo '<form method="get" class="dzn-student-filters"><input type="hidden" name="page" value="dzn-student"><label>Search students <input type="search" name="s" value="'.esc_attr($query).'" placeholder="Name, email, phone or reference"></label> <label>Show <select name="student_status">';
        foreach (array('active'=>'Active students','inactive'=>'Inactive students','archived'=>'Archived students','all'=>'All non-archived students') as $value=>$label) echo '<option value="'.esc_attr($value).'"'.selected($status,$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <button class="button">Filter</button> <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-student')).'">Reset</a></form>';
        if (!$result['rows']) { echo '<p>No students match these filters. Try another search or choose a different status.</p>'; return; }
        echo '<p><strong>'.esc_html(number_format_i18n((int)$result['total'])).'</strong> student'.($result['total']===1?'':'s').'</p><table class="widefat striped"><thead><tr><th>Student</th><th>Contact</th><th>Location / time zone</th><th>Status</th><th>Profile</th></tr></thead><tbody>';
        foreach ($result['rows'] as $student) {
            $url = add_query_arg(array('page'=>'dzn-student','id'=>(int)$student->id),admin_url('admin.php'));
            $name = (string)($student->display_name ?: trim((string)($student->first_name ?? '').' '.(string)($student->last_name ?? '')) ?: 'Student');
            $contact = array_filter(array((string)($student->email ?? ''),(string)($student->phone ?? '')));
            $place = array_filter(array((string)($student->city ?? ''),(string)($student->country_code ?? '')));
            echo '<tr><td><a href="'.esc_url($url).'"><strong>'.esc_html($name).'</strong></a>'.($student->reference_code?'<br><small>'.esc_html((string)$student->reference_code).'</small>':'').'</td><td>'.esc_html($contact ? implode(' · ',$contact) : 'No contact details').'</td><td>'.esc_html($place ? implode(', ',$place) : 'Location not recorded').'<br><small>'.esc_html((string)($student->timezone ?: 'Time zone not recorded')).'</small></td><td>'.esc_html(self::studentStatus((string)($student->status ?? ''),(bool)($student->archived_at ?? false))).'</td><td><a class="button" href="'.esc_url($url).'">Open profile</a></td></tr>';
        }
        echo '</tbody></table>';
        $base = array('page'=>'dzn-student','s'=>$query,'student_status'=>$status);
        echo '<p class="tablenav"><span>'.esc_html((string)$result['total']).' students · Page '.esc_html((string)$result['page']).' of '.esc_html((string)$result['pages']).'</span> ';
        if ($result['page'] > 1) echo '<a class="button" href="'.esc_url(add_query_arg($base + array('paged'=>$result['page']-1),admin_url('admin.php'))).'">Previous</a> ';
        if ($result['page'] < $result['pages']) echo '<a class="button" href="'.esc_url(add_query_arg($base + array('paged'=>$result['page']+1),admin_url('admin.php'))).'">Next</a>';
        echo '</p>';
    }

    private static function studentStatus(string $status, bool $archived): string {
        if ($archived || $status === 'archived') return 'Archived';
        return match ($status) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            default => $status === '' ? 'Not recorded' : ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private static function studentDetailTabs(object $student): void {
        $id = (int)$student->id;
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'overview'));
        if (!in_array($tab, array('overview','account','administration'), true)) $tab = 'overview';
        $url = static fn(string $key): string => add_query_arg(array('page'=>'dzn-student','id'=>$id,'tab'=>$key),admin_url('admin.php'));
            echo '<nav class="nav-tab-wrapper" aria-label="Student profile sections"><a class="nav-tab'.($tab==='overview'?' nav-tab-active':'').'" href="'.esc_url($url('overview')).'">Overview</a><a class="nav-tab'.($tab==='account'?' nav-tab-active':'').'" href="'.esc_url($url('account')).'">Account &amp; Communication</a><a class="nav-tab'.($tab==='administration'?' nav-tab-active':'').'" href="'.esc_url($url('administration')).'">Administration</a></nav>';
        if ($tab === 'overview') {
            self::objectTable(array($student), null, array('reference_code','display_name','first_name','last_name','status','city','country_code','timezone','locale','calendar_preference'));
            echo '<p><a class="button" href="'.esc_url($url('account')).'">Continue to account &amp; communication</a></p>';
        } elseif ($tab === 'account') {
            self::objectTable(array($student), null, array('email','phone','whatsapp_phone'));
            if (current_user_can('dzn_view_student_acceptance_eligibility')) echo '<p><a class="button" href="'.esc_url(add_query_arg(array('page'=>'dzn-student-identity-authority','student_id'=>$id),admin_url('admin.php'))).'">Review account &amp; acceptance status</a></p>';
            else echo '<p>Account and acceptance details are available to staff with account review access.</p>';
            echo '<p>Communication history is not available on this profile.</p>';
        } else {
            echo '<h3>Administration</h3><p>Archiving removes this student from active operational lists. Existing records remain available.</p>';
            self::archiveActions('student',$id,(string)$student->status);
        }
    }

    private static function canonicalTermCreate(array $post): int {
        if (!current_user_can('dzn_manage_canonical_terms')) throw new \RuntimeException('Unauthorized');
        $expected = absint($post['expected_latest_term_id'] ?? 0);
        $result = (new CanonicalTermAuthorityService())->create(absint($post['enrolment_id'] ?? 0), $expected ?: null, $expected ? sanitize_key($post['expected_latest_state'] ?? '') : null, self::canonicalEvidence($post), (string)($post['idempotency_key'] ?? ''));
        return (int)$result['term_id'];
    }

    /**
     * Issue one canonical standard or replacement Lesson for a current Term and current Teacher
     * Assignment. Lifecycle starts at `authorised`; the class type is never a status value.
     */
    private static function canonicalLessonCreate(array $post): int {
        if (!current_user_can('dzn_manage_canonical_lessons')) throw new \RuntimeException('Unauthorized');
        $type = sanitize_key((string) ($post['lesson_type'] ?? 'standard'));
        if (!in_array($type, array('standard', 'replacement'), true)) throw new \InvalidArgumentException('Controlled class type required');
        $evidence = self::canonicalEvidence($post);
        $key = (string) ($post['idempotency_key'] ?? '');
        $service = new CanonicalLessonAuthorityService();
        if ($type === 'replacement') {
            $originId = absint($post['replacement_for_lesson_id'] ?? 0);
            if ($originId < 1) throw new \InvalidArgumentException('Eligible original class required');
            $origin = (new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository())->lesson($originId);
            if (!$origin) throw new \InvalidArgumentException('Eligible original class required');
            $assignment = (new \Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAssignmentRepository())->currentForEnrolment((int) $origin->enrolment_id);
            if (!$assignment) throw new \InvalidArgumentException('Current Teacher Assignment required');
            $result = $service->createReplacement((int) $origin->term_id, (int) $assignment->id, $originId, $evidence, $key);
            return (int) $result['lesson_id'];
        }
        $termId = absint($post['term_id'] ?? 0);
        $assignmentId = absint($post['teacher_assignment_id'] ?? 0);
        if ($termId < 1) throw new \InvalidArgumentException('Canonical Term required');
        if ($assignmentId < 1) {
            // The form carries the current Assignment; resolve it only as a safe fallback so the
            // service can still prove the same expected Assignment under its locks.
            $term = (new \Delnavazan\Platform\Core\Infrastructure\Repository\TermRepository())->findAny($termId);
            $assignment = $term ? (new \Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAssignmentRepository())->currentForEnrolment((int) $term->enrolment_id) : null;
            if (!$assignment) throw new \InvalidArgumentException('Current Teacher Assignment required');
            $assignmentId = (int) $assignment->id;
        }
        $result = $service->createStandard($termId, $assignmentId, $evidence, $key);
        return (int) $result['lesson_id'];
    }

    /** Completion remains canonical, evidence-backed and unavailable before the scheduled end. */
    private static function completeCanonicalLesson(array $post): int {
        if (!current_user_can('dzn_manage_canonical_lessons')) throw new \RuntimeException('Unauthorized');
        $lessonId = absint($post['lesson_id'] ?? 0);
        $lesson = (new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository())->lesson($lessonId);
        if (!$lesson || (string) ($lesson->record_model ?? '') !== 'canonical_term_lesson_v1') throw new \InvalidArgumentException('canonical_lesson_required');
        if ((string) $lesson->lifecycle_state !== 'authorised') throw new \InvalidArgumentException('stale_lesson_state');
        $state = (new CanonicalLessonScheduleReadService())->forLesson($lessonId);
        $active = $state['active_version'] ?? null;
        if (!$active) throw new \InvalidArgumentException('confirmed_occurrence_required');
        if ((string) $active->ends_at_utc > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('class_not_finished');
        $result = (new CanonicalLessonAuthorityService())->complete(
            $lessonId,
            'authorised',
            self::canonicalEvidence($post),
            (string) ($post['idempotency_key'] ?? '')
        );
        return (int) $result['lesson_id'];
    }

    /**
     * Canonical delivery/attendance outcome recording. The same append-only authority is used for a
     * first record, a late correction of a presumed completion, and an explicit non-delivery
     * reconciliation. Every command records the acting administrator, UTC instant, reason, prior
     * outcome and resulting outcome; no historical row is rewritten.
     */
    private static function recordCanonicalDelivery(array $post): int {
        if (!current_user_can('dzn_manage_canonical_lesson_delivery')) throw new \RuntimeException('Unauthorized');
        $lessonId = absint($post['lesson_id'] ?? 0);
        $lesson = (new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository())->lesson($lessonId);
        if (!$lesson || (string) ($lesson->record_model ?? '') !== 'canonical_term_lesson_v1') throw new \InvalidArgumentException('canonical_lesson_required');
        $state = (string) $lesson->lifecycle_state;
        if (!in_array($state, array('authorised', 'completed'), true)) throw new \InvalidArgumentException('lesson_not_delivery_recordable');
        $outcome = sanitize_key((string) ($post['outcome_code'] ?? ''));
        if (!in_array($outcome, array('delivered', 'student_no_show', 'interruption', 'teacher_non_delivery', 'review_required'), true)) throw new \InvalidArgumentException('Controlled delivery outcome code required');
        if ($state === 'completed' && in_array($outcome, array('teacher_non_delivery', 'review_required'), true)) throw new \InvalidArgumentException('lesson_completed_reconciliation_required');
        $result = (new CanonicalLessonDeliveryService())->record($lessonId, $state, self::deliveryInput($post, $outcome), (string) ($post['idempotency_key'] ?? ''));
        return (int) $result['lesson_id'];
    }

    private static function correctCanonicalDelivery(array $post): int {
        if (!current_user_can('dzn_manage_canonical_lesson_delivery')) throw new \RuntimeException('Unauthorized');
        $lessonId = absint($post['lesson_id'] ?? 0);
        $outcomeId = absint($post['expected_outcome_id'] ?? 0);
        $outcome = sanitize_key((string) ($post['outcome_code'] ?? ''));
        if (!in_array($outcome, array('delivered', 'student_no_show', 'interruption', 'review_required'), true)) throw new \InvalidArgumentException('Controlled delivery outcome code required');
        $lesson = (new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository())->lesson($lessonId);
        if (!$lesson || (string) ($lesson->record_model ?? '') !== 'canonical_term_lesson_v1') throw new \InvalidArgumentException('canonical_lesson_required');
        $state = (string) $lesson->lifecycle_state;
        if ($state === 'completed' && $outcome === 'review_required') throw new \InvalidArgumentException('use_explicit_dispute_command');
        $result = (new CanonicalLessonDeliveryService())->correct($lessonId, $outcomeId, self::deliveryInput($post, $outcome), (string) ($post['idempotency_key'] ?? ''));
        return (int) $result['lesson_id'];
    }

    private static function reconcileCanonicalDelivery(array $post): int {
        if (!current_user_can('dzn_manage_canonical_lesson_delivery')) throw new \RuntimeException('Unauthorized');
        $result = (new CanonicalLessonDeliveryService())->reconcile(absint($post['lesson_id'] ?? 0), self::deliveryInput($post, 'teacher_non_delivery'), (string) ($post['idempotency_key'] ?? ''));
        return (int) $result['lesson_id'];
    }

    private static function disputeCanonicalDelivery(array $post): int {
        if (!current_user_can('dzn_manage_canonical_lesson_delivery')) throw new \RuntimeException('Unauthorized');
        $result = (new CanonicalLessonDeliveryService())->dispute(absint($post['lesson_id'] ?? 0), self::deliveryInput($post, 'review_required'), (string) ($post['idempotency_key'] ?? ''));
        return (int) $result['lesson_id'];
    }

    private static function deliveryInput(array $post, string $outcome): array {
        return array(
            'outcome_code' => $outcome,
            'reason_code' => sanitize_key((string) ($post['reason_code'] ?? 'administrative_delivery_outcome')),
        ) + self::canonicalEvidence($post);
    }

    private static function canonicalLessonDeliverySection(object $lesson): void {
        if (!current_user_can('dzn_manage_canonical_lesson_delivery')) return;
        $read = new CanonicalLessonDeliveryReadService();
        $history = $read->forLesson((int) $lesson->id);
        $lifecycle = (new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository())->events((int) $lesson->id);
        $basis = CanonicalLessonCompletionPolicy::completionBasis($lifecycle);
        echo '<h2>Delivery &amp; attendance</h2>';
        echo '<p><strong>Completion basis:</strong> ' . ($basis === 'presumed' ? 'Presumed completed from elapsed scheduled time; attendance is not independently verified.' : ($basis === 'verified' ? 'Completed with explicit operator evidence.' : 'Not completed.')) . '</p>';
        echo '<p>Human Teacher/Student reports are evidence only. Apply a canonical outcome here after reviewing the exact Lesson record and any attendance review case.</p>';
        if ($history['outcomes']) {
            $rows = array();
            foreach ($history['outcomes'] as $outcome) {
                $rows[] = array(
                    'outcome_sequence' => (int) $outcome->outcome_sequence,
                    'outcome_code' => (string) $outcome->outcome_code,
                    'delivery_state' => (string) $outcome->delivery_state,
                    'attendance_state' => (string) $outcome->attendance_state,
                    'remedy_class' => (string) $outcome->remedy_class,
                    'applicable' => (int) ($outcome->applicable_slot ?? 0) === 1 ? 'current' : 'superseded',
                    'reason_code' => (string) $outcome->reason_code,
                    'recorded_at' => (string) $outcome->recorded_at,
                    'recorded_by' => (int) $outcome->recorded_by,
                    'superseded_by_outcome_id' => $outcome->superseded_by_outcome_id === null ? '' : (int) $outcome->superseded_by_outcome_id,
                );
            }
            self::objectTable(array_map(static fn(array $row): object => (object) $row, $rows), null, array('outcome_sequence','outcome_code','delivery_state','attendance_state','remedy_class','applicable','reason_code','recorded_at','recorded_by','superseded_by_outcome_id'));
        } else {
            echo '<p>No exceptional delivery outcome is recorded. Ordinary delivery is represented by absence of an outcome; a presumed completion remains unverified.</p>';
        }
        $effective = $history['effective'];
        if ((string) $lesson->lifecycle_state === 'completed') {
            if ($effective && (string) $effective->outcome_code !== 'teacher_non_delivery') {
                self::formStart('correct_canonical_delivery');
                echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '"><input type="hidden" name="expected_outcome_id" value="' . esc_attr((string) $effective->id) . '">';
                echo '<p><label>Correct effective outcome<br><select name="outcome_code"><option value="delivered">Delivered</option><option value="student_no_show">Student no-show</option><option value="interruption">Interrupted</option></select></label></p>';
                self::input('reason_code', 'Reason code', true, 'text', 'administrator_correction');
                self::canonicalEvidenceFields();
                submit_button('Append corrected outcome', 'secondary');
                echo '</form>';
            } elseif ($effective) {
                echo '<p class="notice notice-info">A reconciled academy non-delivery is protected from conversion into verified delivery by the append-only O-D8 rule.</p>';
            } else {
                self::formStart('record_canonical_delivery');
                echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '">';
                echo '<p><label>Record verified outcome<br><select name="outcome_code"><option value="delivered">Delivered</option><option value="student_no_show">Student no-show</option><option value="interruption">Interrupted</option></select></label></p>';
                self::input('reason_code', 'Reason code', true, 'text', 'administrator_verified_outcome');
                self::canonicalEvidenceFields();
                submit_button('Record verified outcome', 'secondary');
                echo '</form>';
            }
            if (!$effective || (string) $effective->outcome_code !== 'teacher_non_delivery') {
                self::formStart('reconcile_canonical_delivery');
                echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '">';
                self::input('reason_code', 'Reason code', true, 'text', 'administrator_non_delivery');
                self::canonicalEvidenceFields();
                submit_button('Reconcile completion as not delivered', 'secondary');
                echo '</form>';
            }
            if (!$effective || !in_array((string) $effective->outcome_code, array('review_required', 'teacher_non_delivery'), true)) {
                self::formStart('dispute_canonical_delivery');
                echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '">';
                self::input('reason_code', 'Reason code', true, 'text', 'late_report_dispute');
                self::canonicalEvidenceFields();
                submit_button('Mark completion disputed', 'secondary');
                echo '</form>';
            }
        } elseif ($effective) {
            self::formStart('correct_canonical_delivery');
            echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '"><input type="hidden" name="expected_outcome_id" value="' . esc_attr((string) $effective->id) . '">';
            echo '<p><label>Correct effective outcome<br><select name="outcome_code"><option value="delivered">Delivered</option><option value="student_no_show">Student no-show</option><option value="interruption">Interrupted</option></select></label></p>';
            self::input('reason_code', 'Reason code', true, 'text', 'administrator_correction');
            self::canonicalEvidenceFields();
            submit_button('Append corrected outcome', 'secondary');
            echo '</form>';
        } elseif ((string) $lesson->lifecycle_state === 'authorised' && !$effective) {
            try { $schedule = (new CanonicalLessonScheduleReadService())->forLesson((int) $lesson->id); }
            catch (\Throwable) { $schedule = array('active_version' => null); }
            $current = $schedule['active_version'] ?? null;
            if ($current && (string) $current->ends_at_utc <= gmdate('Y-m-d H:i:s')) {
                self::formStart('record_canonical_delivery');
                echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '">';
                echo '<p><label>Record occurrence outcome<br><select name="outcome_code"><option value="delivered">Delivered</option><option value="student_no_show">Student no-show</option><option value="interruption">Interrupted</option><option value="teacher_non_delivery">Teacher/academy non-delivery</option><option value="review_required">Review required</option></select></label></p>';
                self::input('reason_code', 'Reason code', true, 'text', 'administrator_occurrence_outcome');
                self::canonicalEvidenceFields();
                submit_button('Record outcome', 'secondary');
                echo '</form>';
            }
        }
    }

    /**
     * Record a real introductory delivery outcome. A booking-linked introduction goes through the
     * post-intro authority so a delivered outcome starts the existing continuation workflow; a
     * standalone introductory Lesson is completed through the existing Lesson authority.
     */
    private static function recordIntroOutcome(array $post): int {
        if (!current_user_can('dzn_manage_lessons')) throw new \RuntimeException('Unauthorized');
        $lessonId = absint($post['id'] ?? 0);
        $lesson = (new \Delnavazan\Platform\Core\Infrastructure\Repository\LessonRepository())->findAny($lessonId);
        if (!$lesson || ($lesson->record_model ?? 'legacy_phase1') !== 'legacy_phase1'
            || (string) $lesson->lesson_type !== 'introductory' || $lesson->archived_at !== null) {
            throw new \InvalidArgumentException('introductory_lesson_required');
        }
        if ((string) $lesson->status === 'completed') return $lessonId;
        if (!in_array((string) $lesson->status, array('draft', 'scheduled'), true)) throw new \InvalidArgumentException('introductory_lesson_not_completable');

        $schedule = (new \Delnavazan\Platform\Core\Infrastructure\Repository\LessonScheduleVersionRepository())->current($lessonId);
        if (!$schedule || (int) $lesson->current_schedule_version_id !== (int) $schedule->id) throw new \InvalidArgumentException('confirmed_occurrence_required');
        if ((string) $schedule->ends_at_utc > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('intro_not_finished');

        $outcome = sanitize_key((string) ($post['outcome'] ?? ''));
        $bookingId = (int) ($lesson->booking_request_id ?? 0);
        $allowed = $bookingId > 0
            ? array('delivered', 'student_no_show', 'teacher_cancelled', 'student_cancelled')
            : array('delivered');
        if (!in_array($outcome, $allowed, true)) throw new \InvalidArgumentException('intro_outcome_invalid');

        if ($bookingId > 0) {
            if (!current_user_can('dzn_manage_post_intro_automation')) throw new \RuntimeException('Unauthorized');
            $confirmation = (new \Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository())->confirmationByBooking($bookingId);
            if (!$confirmation || (string) $confirmation->state !== 'confirmed') throw new \InvalidArgumentException('intro_booking_not_confirmed');
            $note = sanitize_textarea_field((string) ($post['note'] ?? ''));
            (new PostIntroPreparationService())->recordAdministrativeOutcome((string) $confirmation->uid, (int) get_current_user_id(), $outcome, $note);
            return $lessonId;
        }

        (new LessonService())->completeScheduledIntroductory($lessonId, (int) $schedule->id);
        return $lessonId;
    }

    private static function canonicalEnrolmentTransition(array $post, string $operation): int {
        if (!current_user_can('dzn_manage_canonical_enrolment_lifecycle')) throw new \RuntimeException('Unauthorized');
        $service = new CanonicalEnrolmentLifecycleService();
        $result = $service->{$operation}(absint($post['enrolment_id'] ?? 0), sanitize_key($post['expected_state'] ?? ''), self::canonicalEvidence($post), (string)($post['idempotency_key'] ?? ''));
        return (int)$result['enrolment_id'];
    }

    private static function canonicalEnrolmentLifecycleForm(object $enrolment): void {
        $operations = match ((string)$enrolment->lifecycle_state) {
            'authorised' => array('activate','close'),
            'current' => array('pause','close'),
            'paused' => array('resume','close'),
            default => array(),
        };
        echo '<h2>Canonical Enrolment lifecycle</h2>';
        foreach ($operations as $operation) { self::formStart($operation.'_canonical_enrolment'); echo '<input type="hidden" name="enrolment_id" value="'.esc_attr((string)$enrolment->id).'"><input type="hidden" name="expected_state" value="'.esc_attr((string)$enrolment->lifecycle_state).'">'; self::canonicalEvidenceFields(); submit_button(ucfirst($operation).' canonical Enrolment'); echo '</form>'; }
    }

    private static function canonicalTermTransition(array $post, string $operation): int {
        if (!current_user_can('dzn_manage_canonical_terms')) throw new \RuntimeException('Unauthorized');
        $service = new CanonicalTermAuthorityService();
        $result = $service->{$operation}(absint($post['term_id'] ?? 0), sanitize_key($post['expected_state'] ?? ''), self::canonicalEvidence($post), (string)($post['idempotency_key'] ?? ''));
        return (int)$result['term_id'];
    }

    private static function canonicalEvidence(array $post): array { return array('evidence_channel'=>sanitize_key($post['evidence_channel']??''),'evidence_reference'=>sanitize_text_field($post['evidence_reference']??''),'evidence_at'=>sanitize_text_field($post['evidence_at']??'')); }

    private static function canonicalTermCreateForm(): void {
        $options=(new CoreReadService())->canonicalTermCreateOptions();
        echo '<h2>Create canonical Term</h2>';
        if(!$options){echo '<p>No canonical Enrolment is ready for another Term.</p>';return;}
        $requested=wp_unslash($_GET['enrolment_id']??'');$selected=is_scalar($requested)&&preg_match('/^[1-9][0-9]*$/D',(string)$requested)?(string)absint($requested):'';
        self::formStart('create_canonical_term');
        echo '<p><label>Student and Course<br><select required name="enrolment_id">';
        foreach($options as $option){
            $value=(string)$option['enrolment_id'];
            $expectedTerm=esc_attr((string)$option['expected_latest_term_id']);
            $expectedState=esc_attr((string)$option['expected_latest_state']);
            $label=esc_html((string)$option['label']);
            echo '<option value="'.esc_attr($value).'" data-expected-term="'.$expectedTerm.'" data-expected-state="'.$expectedState.'"'.selected($selected,$value,false).'>'.$label.'</option>';
        }
        echo '</select></label></p><input type="hidden" name="expected_latest_term_id" value=""><input type="hidden" name="expected_latest_state" value="">';
        echo '<p class="description">The latest closed or cancelled Term is checked automatically before creation.</p>';
        self::canonicalEvidenceFields(); submit_button('Create canonical Term'); echo '</form>';
        echo '<script>(function(){const f=document.querySelector("input[name=\'dzn_action\'][value=\'create_canonical_term\']")?.form;if(!f)return;const s=f.querySelector("select[name=\'enrolment_id\']"),i=f.querySelector("[name=\'expected_latest_term_id\']"),t=f.querySelector("[name=\'expected_latest_state\']");const sync=()=>{const o=s.options[s.selectedIndex];i.value=o?.dataset.expectedTerm||"";t.value=o?.dataset.expectedState||"";};s.addEventListener("change",sync);sync();})();</script>';
    }

    private static function canonicalLessonCreateForm(): void {
        $options = (new CoreReadService())->canonicalLessonIssueOptions();
        $terms = array_values(array_filter($options['terms'] ?? array(), static fn(array $term): bool => (int) ($term['standard_remaining'] ?? 0) > 0));
        $replacements = $options['replacements'] ?? array();
        echo '<h2>Create class</h2>';
        echo '<p class="description">A standard paid class is issued against a current canonical Term and its current Teacher Assignment. A replacement class is issued from an eligible cancelled standard class. Both begin in the authorised lifecycle state; class type is never a status.</p>';
        if (!$terms && !$replacements) {
            echo '<p>No current canonical Term with a current Teacher Assignment is ready. Create and activate the canonical Term first, or use the introductory class setup below.</p>';
            return;
        }
        self::formStart('create_canonical_lesson');
        echo '<p><label>Class type<br><select name="lesson_type">';
        if ($terms) echo '<option value="standard">Standard paid class</option>';
        if ($replacements) echo '<option value="replacement">Replacement class</option>';
        echo '</select></label></p>';
        if ($terms) {
            echo '<p><label>Course, Term and Teacher Assignment<br><select required name="term_id" id="dzn-canonical-lesson-term">';
            foreach ($terms as $term) {
                echo '<option value="' . esc_attr((string) $term['term_id']) . '" data-assignment-id="' . esc_attr((string) $term['assignment_id']) . '">' . esc_html((string) $term['label']) . '</option>';
            }
            echo '</select><br><span class="description">Only current Terms with a current Teacher Assignment are listed.</span></label></p>';
            echo '<input type="hidden" name="teacher_assignment_id" id="dzn-canonical-lesson-assignment" value="' . esc_attr((string) ($terms[0]['assignment_id'] ?? '')) . '">';
        }
        if ($replacements) {
            echo '<p><label>Replacement for<br><select name="replacement_for_lesson_id"><option value="">Choose an eligible cancelled class</option>';
            foreach ($replacements as $replacement) {
                echo '<option value="' . esc_attr((string) $replacement['lesson_id']) . '">' . esc_html((string) $replacement['label']) . '</option>';
            }
            echo '</select><br><span class="description">Only a cancelled standard class with controlled replacement eligibility and no existing replacement is listed.</span></label></p>';
        }
        self::canonicalEvidenceFields();
        submit_button('Create class');
        echo '</form>';
        if ($terms) {
            echo '<script>(function(){const f=document.querySelector("input[name=\'dzn_action\'][value=\'create_canonical_lesson\']")?.form;if(!f)return;const s=f.querySelector("#dzn-canonical-lesson-term"),a=f.querySelector("#dzn-canonical-lesson-assignment");const sync=()=>{const o=s.options[s.selectedIndex];if(a)a.value=o?.dataset.assignmentId||"";};s.addEventListener("change",sync);sync();})();</script>';
        }
    }

    private static function introductoryOutcomeForm(object $lesson): void {
        if (($lesson->record_model ?? 'legacy_phase1') !== 'legacy_phase1' || (string) $lesson->lesson_type !== 'introductory' || $lesson->archived_at !== null) return;
        if ((string) $lesson->status === 'completed') { echo '<p class="notice notice-success">The introductory delivery outcome is recorded as completed.</p>'; return; }
        if (!in_array((string) $lesson->status, array('draft', 'scheduled'), true)) return;
        $schedule = (new \Delnavazan\Platform\Core\Infrastructure\Repository\LessonScheduleVersionRepository())->current((int) $lesson->id);
        if (!$schedule || (int) $lesson->current_schedule_version_id !== (int) $schedule->id) {
            echo '<p class="notice notice-info">A confirmed occurrence is required before the delivery outcome can be recorded.</p>';
            return;
        }
        if ((string) $schedule->ends_at_utc > gmdate('Y-m-d H:i:s')) {
            echo '<p class="notice notice-info">The delivery outcome can be recorded after the scheduled introductory occurrence ends.</p>';
            return;
        }
        $bookingLinked = (int) ($lesson->booking_request_id ?? 0) > 0;
        if ($bookingLinked && (string) $schedule->ends_at_utc < PostIntroPreparationService::ELIGIBLE_FROM) {
            echo '<p class="notice notice-info">This booking-linked introductory occurrence predates the post-intro automation window and cannot start a payment invitation.</p>';
            return;
        }
        if ($bookingLinked && !current_user_can('dzn_manage_post_intro_automation')) {
            echo '<p>Recording this booking-linked outcome requires post-intro automation access.</p>';
            return;
        }
        echo '<h2>Introductory delivery outcome</h2>';
        echo '<p class="description">Record what actually happened. Only a delivered outcome starts the continuation workflow; a no-show or cancellation is retained as an exception and never sends a payment invitation.</p>';
        self::formStart('record_intro_outcome');
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $lesson->id) . '">';
        echo '<p><label>Outcome<br><select name="outcome" required>';
        echo '<option value="delivered">Delivered</option>';
        if ($bookingLinked) {
            echo '<option value="student_no_show">Student no-show</option>';
            echo '<option value="student_cancelled">Student cancelled</option>';
            echo '<option value="teacher_cancelled">Teacher cancelled</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Note (optional)<br><textarea name="note" rows="3" cols="60"></textarea></label></p>';
        submit_button('Record outcome');
        echo '</form>';
    }

    private static function canonicalTermLifecycleForm(object $term): void {
        echo '<h2>Canonical lifecycle</h2>';
        foreach (array('activate','close','cancel') as $operation) { self::formStart($operation.'_canonical_term'); echo '<input type="hidden" name="term_id" value="'.esc_attr((string)$term->id).'"><input type="hidden" name="expected_state" value="'.esc_attr((string)$term->lifecycle_state).'">'; self::canonicalEvidenceFields(); submit_button(ucfirst($operation).' canonical Term'); echo '</form>'; }
    }

    private static function canonicalEvidenceFields(): void { self::select('evidence_channel','Evidence source',['staff_record','authenticated_platform','document_reference'],'staff_record'); self::input('evidence_reference','Operator evidence reference',true); self::input('evidence_at','Evidence time (UTC)',true,'text',gmdate('Y-m-d H:i:s')); echo '<input type="hidden" name="idempotency_key" value="'.esc_attr(wp_generate_uuid4()).'">'; }

    private static function profileFields(string $entity): array {
        return $entity==='teacher'
            ? array('id','reference_code','display_name','persian_name','english_name','email','phone','whatsapp_phone','country_code','city','timezone','locale','calendar_preference','status')
            : array('id','reference_code','display_name','first_name','last_name','email','phone','whatsapp_phone','country_code','city','timezone','locale','calendar_preference','status');
    }
    private static function catalogueFields(string $entity): array { return $entity==='course'?array('id','reference_code','name_en','name_fa','instrument','course_type','default_duration_minutes','default_buffer_minutes','status'):array('id','reference_code','name_en','name_fa','status'); }
    private static function canonicalTermFields(): array { return array('id','reference_code','student_name','course_name','sequence_number','lifecycle_state','lesson_allocation','replacement_allowance'); }
    private static function canonicalTermHistory(int $termId): void { $events=(new CoreReadService())->canonicalTermHistory($termId); echo '<h2>Term history</h2>'; if(!$events){echo '<p>No lifecycle events recorded.</p>';return;} $rows=array_map(static fn(array $event):object=>(object)$event,$events); self::objectTable($rows,null,array('event_sequence','from_state','to_state','reason_code','evidence_channel','occurred_at')); }

    private static function teacherFields(): void {
        self::input('persian_name', 'Persian name'); self::input('english_name', 'English name'); self::input('display_name', 'Display name', true); self::input('email', 'Email', false, 'email'); self::input('phone', 'Phone'); self::input('whatsapp_phone', 'WhatsApp'); self::input('country_code', 'Country code', false, 'text', 'IR'); self::input('city', 'City'); self::input('timezone', 'Timezone', false, 'text', 'Asia/Tehran'); self::select('timezone_source', 'Timezone source', ['admin_selected', 'student_selected', 'imported', 'system_suggested'], 'admin_selected'); self::input('locale', 'Locale', false, 'text', 'fa_IR'); self::select('calendar_preference', 'Calendar', ['persian', 'gregorian', 'auto'], 'persian'); self::select('status', 'Status', ['active', 'inactive'], 'active');
    }

    private static function studentFields(): void {
        self::input('first_name', 'First name'); self::input('last_name', 'Last name'); self::input('display_name', 'Display name', true); self::input('email', 'Email', false, 'email'); self::input('phone', 'Phone'); self::input('whatsapp_phone', 'WhatsApp'); self::input('country_code', 'Country code', false, 'text', 'AU'); self::input('city', 'City'); self::input('timezone', 'Timezone', false, 'text', 'Australia/Brisbane'); self::select('timezone_source', 'Timezone source', ['admin_selected', 'student_selected', 'imported', 'system_suggested'], 'admin_selected'); self::input('locale', 'Locale', false, 'text', 'en_AU'); self::select('calendar_preference', 'Calendar', ['gregorian', 'persian', 'auto'], 'gregorian'); self::select('status', 'Status', ['active', 'inactive'], 'active');
    }

    private static function instrumentFields(): void { self::input('name', 'Instrument name', true); self::input('standard_duration_minutes', 'Standard lesson duration (minutes)', true, 'number', '30'); echo '<input type="hidden" name="introductory_lesson_billing" value="free"><p class="description"><strong>Introductory lesson availability</strong> is created here. Its free or paid price is configured by currency region in <a href="'.esc_url(FinanceAdminController::tabUrl(FinanceAdminController::TAB_SETTINGS)).'#regional-pricing">Finance → Settings</a>; no instrument-specific price is configured.</p>'; self::input('gap_between_lessons_minutes', 'Gap between lessons (minutes)', true, 'number', '15'); echo '<p class="description">The introductory Course uses this same duration and gap. New Courses are named “کلاس معارفه [نام ساز]” and “کلاس [نام ساز]”. This creates or safely reconciles the introductory and standard Courses, the standard commercial product, and the public introductory default. The shared regional price is applied automatically.</p>'; }
    private static function courseFields(): void { self::instrumentSelector(); self::input('name_fa', 'Persian name', true); self::input('name_en', 'English name', true); self::select('course_type', 'Course type', ['standard', 'introductory'], 'standard'); self::select('status', 'Status', ['active', 'inactive'], 'active'); self::input('default_duration_minutes', 'Duration minutes', true, 'number', '30'); self::input('default_buffer_minutes', 'Buffer minutes', true, 'number', '15'); }
    private static function instrumentSelector(): void {
        $instruments = (new CoreReadService())->instrumentOptionsForCourse();
        echo '<p><label>Instrument<br><select required name="instrument_id"><option value="">Choose an instrument</option>';
        foreach ($instruments as $instrument) {
            $name = (string) ($instrument->name_en ?: $instrument->name_fa ?: 'Instrument');
            $label = $name . ((string) $instrument->status === 'active' ? '' : ' (inactive)');
            echo '<option value="' . esc_attr((string) $instrument->id) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label><br><span class="description">Choose the instrument this Course teaches. Archived instruments are unavailable.</span></p>';
    }
    private static function termFields(): void { self::legacyEnrolmentSelector('Enrolment', true); self::input('sequence_number', 'Sequence', true, 'number', '1'); self::select('status', 'Status', ['draft', 'awaiting_payment', 'active', 'completed', 'cancelled'], 'draft'); self::input('lesson_allocation', 'Lesson allocation', true, 'number', '12'); self::input('replacement_allowance', 'Replacement allowance', true, 'number', '2'); self::select('payment_state', 'Payment state', ['not_required', 'pending', 'paid', 'failed', 'refunded'], 'not_required'); }
    private static function lessonFields(): void { self::lessonSelector('student','Student',true); self::lessonSelector('teacher','Teacher',true); self::lessonSelector('course','Course',true); self::select('lesson_type', 'Lesson type', ['introductory', 'standard', 'replacement'], 'introductory'); self::select('status', 'Status', ['draft'], 'draft'); self::lessonSelector('enrolment','Enrolment (optional for introductory lessons)',false); self::lessonSelector('term','Term (requires Enrolment)',false); self::lessonSelector('lesson','Original Lesson (replacement only)',false); }

    private static function lessonSelector(string $entity, string $label, bool $required): void {
        $rows=(new CoreReadService())->lessonOperatorOptions($entity);
        echo '<p><label>'.esc_html($label).'<br><select name="'.esc_attr($entity==='lesson'?'replacement_for_lesson_id':$entity.'_id').'"'.($required?' required':'').'><option value="">Choose '.esc_html(strtolower($label)).'</option>';
        foreach($rows as $row){$status=(string)($row->status??'');$text=match($entity){'student'=>(string)($row->display_name?:trim(($row->first_name??'').' '.($row->last_name??''))?:'Student'),'teacher'=>(string)($row->display_name?:$row->english_name?:$row->persian_name?:'Teacher'),'course'=>(string)($row->name_en?:$row->name_fa?:'Course'),'enrolment'=>(string)($row->student?:'Student').' · '.(string)($row->course?:'Course').' · '.(string)($row->teacher?:'Teacher'),'term'=>'Term '.(string)($row->sequence_number??'').' · '.(string)($row->student?:'Student'),'lesson'=>(string)($row->student?:'Student').' · '.(string)($row->course?:'Course').' · '.ucfirst((string)($row->lesson_type??'lesson')),default=>'Record'};if($status!==''&&$status!=='active')$text.=' ('.str_replace('_',' ',$status).')';echo '<option value="'.esc_attr((string)$row->id).'">'.esc_html($text).'</option>';}
        echo '</select></label></p>';
    }

    private static function legacyEnrolmentSelector(string $label, bool $required): void { $rows=(new CoreReadService())->legacyEnrolmentOptionsForTerm(); echo '<p><label>'.esc_html($label).'<br><select name="enrolment_id"'.($required?' required':'').'><option value="">Choose a legacy Enrolment</option>'; foreach($rows as$row){$text=(string)($row->student?:'Student').' · '.(string)($row->course?:'Course').' · '.(string)($row->teacher?:'Teacher');if($row->status!=='active')$text.=' ('.str_replace('_',' ',(string)$row->status).')';echo '<option value="'.esc_attr((string)$row->id).'">'.esc_html($text).'</option>';}echo '</select><br><span class="description">This compatibility form accepts legacy Enrolments only. Use the canonical Term workflow above for a converted Enrolment.</span></label></p>'; }

    private static function lessonSchedules(int $lessonId, string $lessonStatus, int $schedulePointer): void {
        $state = (new CoreReadService())->lessonSchedule($lessonId);
        $fields=array('version_number','local_wall_date','local_wall_time','schedule_timezone');
        echo '<h2>Schedule</h2>'; if ($state['current']) self::objectTable([$state['current']], null,$fields); else echo '<p>No current schedule.</p>';
        echo '<h3>Schedule history</h3>'; self::objectTable($state['history'], null,$fields);
        if($schedulePointer!==(int)($state['current']->id??0)){echo '<p class="notice notice-error">The class schedule pointer needs staff review before it can be changed.</p>';return;}
        if(!in_array($lessonStatus,array('draft','scheduled'),true)){echo '<p>Scheduling is closed for this class status.</p>';return;}
        self::formStart($state['current'] ? 'reschedule' : 'initial_schedule'); echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lessonId) . '">';
        self::input('schedule_timezone', 'Schedule timezone', true, 'text', 'Australia/Brisbane'); self::input('local_wall_date', 'Local date', true, 'date'); self::input('local_wall_time', 'Local time (HH:MM:SS)', true, 'text'); self::input('reason', 'Reason'); submit_button($state['current'] ? 'Reschedule' : 'Create initial schedule'); echo '</form>';
    }

    private static function exceptionList(): void {
        $requested = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : ''; $status = in_array($requested, ['open', 'acknowledged', 'resolved', 'dismissed'], true) ? $requested : null;
        echo '<p>'; foreach (['' => 'All', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $value => $label) { $url = add_query_arg(array_filter(['page' => 'dzn-exception', 'status' => $value]), admin_url('admin.php')); echo '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a> '; } echo '</p>';
        self::objectTable((new CoreReadService())->exceptions($status), 'dzn-exception');
    }

    private static function exceptionDetail(int $id): void {
        $exception = (new CoreReadService())->exception($id);
        if (!$exception) { echo '<p>Exception not found.</p>'; return; }
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=dzn-exception')) . '">← Back to list</a></p><h2>Exception #' . esc_html((string) $id) . '</h2>';
        self::objectTable([$exception], null, ['id', 'uid', 'exception_type', 'severity', 'entity_type', 'entity_id', 'status', 'summary', 'safe_detail', 'error_code', 'detected_at', 'last_seen_at', 'resolved_at', 'resolved_by', 'resolution_note', 'retry_available', 'retry_count']);
        if ($exception->status === 'open') self::actionForm('acknowledge_exception', $id, 'Acknowledge');
        if (in_array($exception->status, ['open', 'acknowledged'], true)) { self::noteActionForm('resolve_exception', $id, 'Resolve'); self::noteActionForm('dismiss_exception', $id, 'Dismiss'); if ((int) $exception->retry_available === 1) self::actionForm('retry_exception', $id, 'Increment retry'); }
    }

    private static function archiveActions(string $entity, int $id, string $status): void { if ($status === 'archived') { self::entityActionForm('restore', $entity, $id, 'Restore'); return; } self::entityActionForm('archive', $entity, $id, 'Archive'); }
    private static function entityActionForm(string $action, string $entity, int $id, string $label): void { self::formStart($action); echo '<input type="hidden" name="entity" value="' . esc_attr($entity) . '"><input type="hidden" name="id" value="' . esc_attr((string) $id) . '">'; submit_button($label, 'secondary', 'submit', false); echo '</form> '; }
    private static function actionForm(string $action, int $id, string $label): void { self::formStart($action); echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">'; submit_button($label, 'secondary', 'submit', false); echo '</form> '; }
    private static function noteActionForm(string $action, int $id, string $label): void { self::formStart($action); echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '"><p><label>Resolution note<br><textarea name="resolution_note" rows="3" cols="60"></textarea></label></p>'; submit_button($label, 'secondary', 'submit', false); echo '</form> '; }
    private static function formStart(string $action): void { echo '<form method="post">'; wp_nonce_field(self::nonceAction($action)); echo '<input type="hidden" name="dzn_action" value="' . esc_attr($action) . '">'; }
    private static function input(string $name, string $label, bool $required = false, string $type = 'text', string $value = ''): void { echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '></label></p>'; }
    private static function select(string $name, string $label, array $options, string $selected): void { echo '<p><label>' . esc_html($label) . '<br><select name="' . esc_attr($name) . '">'; foreach ($options as $option) echo '<option value="' . esc_attr($option) . '"' . selected($selected, $option, false) . '>' . esc_html($option) . '</option>'; echo '</select></label></p>'; }

    private static function objectTable(array $records, ?string $page, ?array $allowed = null): void {
        if (!$records) { echo '<p>No records.</p>'; return; }
        $humanReference=$allowed!==null&&in_array('id',$allowed,true)&&in_array('reference_code',$allowed,true);
        $fields = $allowed ?? array_keys(get_object_vars($records[0])); if($humanReference)$fields=array_values(array_filter($fields,static fn($field)=>$field!=='id'));
        $labels=array('reference_code'=>'Reference','display_name'=>'Display name','persian_name'=>'Persian name','english_name'=>'English name','name_en'=>'English name','name_fa'=>'Persian name','first_name'=>'First name','last_name'=>'Last name','student_name'=>'Student','teacher_name'=>'Teacher','course_name'=>'Course','class_status'=>'Class status','schedule_label'=>'Schedule','lesson_type'=>'Class type','canonical_sequence'=>'Class in Term','whatsapp_phone'=>'WhatsApp','country_code'=>'Country','timezone'=>'Time zone','schedule_timezone'=>'Time zone','local_wall_date'=>'Local date','local_wall_time'=>'Local start time','duration_minutes'=>'Lesson length (minutes)','buffer_minutes'=>'Changeover buffer (minutes)','availability_basis'=>'Availability check','version_number'=>'Schedule version','event_type'=>'Schedule change','recorded_at'=>'Recorded','locale'=>'Language/locale','calendar_preference'=>'Calendar','status'=>'Status','instrument'=>'Instrument','course_type'=>'Course type','default_duration_minutes'=>'Lesson duration','default_buffer_minutes'=>'Buffer between lessons','sequence_number'=>'Term number','lifecycle_state'=>'Term status','lesson_allocation'=>'Lessons included','replacement_allowance'=>'Replacement lessons allowed','event_sequence'=>'Event','from_state'=>'Previous status','to_state'=>'New status','reason_code'=>'Reason','evidence_channel'=>'Evidence source','occurred_at'=>'Recorded at');
        echo '<table class="widefat striped"><thead><tr>'; foreach ($fields as $field) echo '<th>' . esc_html($labels[$field]??ucfirst(str_replace('_',' ',(string)$field))) . '</th>'; echo '</tr></thead><tbody>';
        foreach ($records as $record) { echo '<tr>'; foreach ($fields as $field) { $value = $record->$field ?? null; if ($field === 'reference_code' && $humanReference && $page !== null) { $link = '<a href="' . esc_url(add_query_arg(['page' => $page, 'id' => (int) ($record->id??0)], admin_url('admin.php'))) . '">' . esc_html(self::operatorValue((string)$field,$value)) . '</a>'; echo '<td>' . $link . '</td>'; } elseif ($field === 'id' && $page !== null) { $link = '<a href="' . esc_url(add_query_arg(['page' => $page, 'id' => (int) $value], admin_url('admin.php'))) . '">' . esc_html((string) $value) . '</a>'; echo '<td>' . $link . '</td>'; } else echo '<td>' . esc_html(self::operatorValue((string)$field,$value)) . '</td>'; } echo '</tr>'; }
        echo '</tbody></table>';
    }
    private static function operatorValue(string $field,mixed $value):string { if(in_array($field,['status','lifecycle_state','from_state','to_state'],true))return match((string)$value){'active'=>'Active','inactive'=>'Inactive','archived'=>'Archived','authorised'=>'Authorised','current'=>'Current','paused'=>'Paused','closed'=>'Closed','cancelled'=>'Cancelled',default=>self::displayValue($value)}; if($field==='course_type')return match((string)$value){'introductory'=>'Introductory Course','standard'=>'Standard Course',default=>self::displayValue($value)}; if($field==='lesson_type')return match((string)$value){'introductory'=>'Introductory class','standard'=>'Regular class','replacement'=>'Replacement class',default=>self::displayValue($value)}; return self::displayValue($value); }
    private static function displayValue(mixed $value): string { return ($value === null || $value === '' || !is_scalar($value)) ? '—' : (string) $value; }
    private static function redirectNotice(string $notice, bool $error = false, array $extra = []): never {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? 'dzn-platform'));
        $view = sanitize_key(wp_unslash($_GET['view'] ?? ''));
        $viewArgs = $page === 'dzn-teachers-menu' && $view === 'onboarding' ? ['view' => 'onboarding'] : [];
        $url = add_query_arg(array_merge(['page' => $page, 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0'], $viewArgs, $extra), admin_url('admin.php'));
        NonceLifecycleDiagnostic::logStage('redirect_enter');
        nocache_headers();
        if (wp_safe_redirect($url)) exit;
        NonceLifecycleDiagnostic::logStage('redirect_failed');
        wp_die(esc_html($notice), 'Delnavazan', ['response' => $error ? 500 : 200, 'back_link' => true]);
    }
    private static function renderMessages(): void { if (!isset($_GET['dzn_notice'])) return; $notice = sanitize_text_field(wp_unslash($_GET['dzn_notice'])); echo '<div class="notice ' . esc_attr((($_GET['dzn_error'] ?? '') === '1') ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice) . '</p></div>'; }
    private static function forbidden(): void { echo '<div class="wrap"><h1>Delnavazan</h1><p>Access denied.</p></div>'; }
    /** Phase 2A.2-N: minimum administrator invocation for canonical Lesson scheduling. */
    private static function canonicalScheduleAction(array $post, string $operation): int {
        if (!current_user_can('dzn_manage_canonical_lesson_schedules')) throw new \RuntimeException('Unauthorized');
        $service = new CanonicalLessonScheduleService();
        $lessonId = absint($post['lesson_id'] ?? 0);
        $key = (string) ($post['idempotency_key'] ?? '');
        $reason = sanitize_key($post['reason_code'] ?? '');
        if ($operation === 'schedule_release') {
            $result = $service->release($lessonId, array(
                'expected_schedule_version_id' => absint($post['expected_schedule_version_id'] ?? 0),
                'reason_code' => $reason,
            ) + self::canonicalEvidence($post), $key);
            return (int) $result['schedule_version_id'];
        }
        $input = array(
            'schedule_timezone' => sanitize_text_field($post['schedule_timezone'] ?? ''),
            'local_wall_date' => sanitize_text_field($post['local_wall_date'] ?? ''),
            'local_wall_time' => sanitize_text_field($post['local_wall_time'] ?? ''),
            'reason_code' => $reason,
            'duration_minutes' => sanitize_text_field($post['duration_minutes'] ?? ''),
            'expected_schedule_version_id' => absint($post['expected_schedule_version_id'] ?? 0),
        ) + self::canonicalEvidence($post);
        if (!empty($post['availability_override'])) {
            $input['availability_override'] = true;
            $input['override_reason_code'] = sanitize_key($post['override_reason_code'] ?? '');
            $input['override_evidence_channel'] = sanitize_key($post['override_evidence_channel'] ?? '');
            $input['override_evidence_reference'] = sanitize_text_field($post['override_evidence_reference'] ?? '');
            $input['override_evidence_at'] = sanitize_text_field($post['override_evidence_at'] ?? '');
        }
        $expectedAssignment = absint($post['expected_assignment_id'] ?? 0);
        $result = $operation === 'schedule_initial' ? $service->schedule($lessonId, $expectedAssignment, $input, $key) : $service->revise($lessonId, $expectedAssignment, $input, $key);
        return (int) $result['schedule_version_id'];
    }
    private static function canonicalLessonSchedule(object $lesson): void {
        if (!current_user_can('dzn_manage_canonical_lesson_schedules')) return;
        echo '<h2>Canonical Lesson schedule</h2>';
        try { $state = (new CanonicalLessonScheduleReadService())->forLesson((int) $lesson->id); }
        catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'canonical_schedule_integrity_conflict') throw $exception;
            echo '<p class="notice notice-error">Canonical schedule failed integrity validation.</p>';
            return;
        }
        $scheduleFields=array('version_number','local_wall_date','local_wall_time','schedule_timezone','duration_minutes','buffer_minutes','availability_basis');
        if ($state['active_version']) self::objectTable(array($state['active_version']), null, $scheduleFields); else echo '<p>No applicable canonical schedule.</p>';
        if ($state['versions']) { echo '<h3>Schedule history</h3>'; self::objectTable($state['versions'], null, $scheduleFields); }
        if ($state['events']) { echo '<h3>Schedule changes</h3>'; self::objectTable(array_map(static fn(object $event):array=>array('event_type'=>$event->event_type,'recorded_at'=>$event->recorded_at),$state['events']), null, array('event_type','recorded_at')); }
        if((string)$lesson->lifecycle_state!=='authorised'){echo '<p>Scheduling is closed for this class status.</p>';return;}
        $active = $state['active_version'];
        self::formStart($active ? 'revise_canonical_lesson' : 'schedule_canonical_lesson');
        echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '">';
        echo '<input type="hidden" name="expected_assignment_id" value="' . esc_attr((string) $lesson->teacher_assignment_id) . '">';
        if ($active) echo '<input type="hidden" name="expected_schedule_version_id" value="' . esc_attr((string) $active->id) . '">';
        self::input('schedule_timezone', 'Schedule timezone', true, 'text', 'Australia/Brisbane');
        self::input('local_wall_date', 'Local date', true, 'date');
        self::input('local_wall_time', 'Local time (HH:MM:SS)', true, 'text');
        self::input('duration_minutes', 'Duration override (minutes, optional)');
        self::input('reason_code', 'Reason code', true);
        self::canonicalEvidenceFields();
        if (current_user_can('dzn_override_canonical_lesson_schedule_availability')) {
            echo '<p><label><input type="checkbox" name="availability_override" value="1"> Administrative availability override</label></p>';
            self::input('override_reason_code', 'Override reason code');
            self::select('override_evidence_channel', 'Override evidence channel', array('staff_record','authenticated_platform','document_reference'), 'staff_record');
            self::input('override_evidence_reference', 'Override evidence reference');
            self::input('override_evidence_at', 'Override evidence UTC', false, 'text', gmdate('Y-m-d H:i:s'));
        }
        submit_button($active ? 'Revise canonical schedule' : 'Create canonical schedule');
        echo '</form>';
        if (!$active) return;
        self::formStart('release_canonical_lesson');
        echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '">';
        echo '<input type="hidden" name="expected_schedule_version_id" value="' . esc_attr((string) $active->id) . '">';
        self::input('reason_code', 'Reason code', true);
        self::canonicalEvidenceFields();
        submit_button('Release canonical schedule');
        echo '</form>';
    }

    /** Contextual manual completion is independent of schedule-edit access. */
    private static function canonicalLessonCompletionForm(object $lesson): void {
        if (!current_user_can('dzn_manage_canonical_lessons') || (string) $lesson->lifecycle_state !== 'authorised') return;
        try { $state = (new CanonicalLessonScheduleReadService())->forLesson((int) $lesson->id); }
        catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'canonical_schedule_integrity_conflict') throw $exception;
            echo '<p class="notice notice-error">Class completion is unavailable because the schedule failed integrity validation.</p>';
            return;
        }
        $active = $state['active_version'] ?? null;
        if (!$active) { echo '<p class="notice notice-info">Mark as completed becomes available after a canonical schedule is confirmed.</p>'; return; }
        if ((string) $active->ends_at_utc > gmdate('Y-m-d H:i:s')) { echo '<p class="notice notice-info">Mark as completed becomes available after the scheduled class ends.</p>'; return; }
        echo '<h2>Class completion</h2><p>Record the completed class with an operator evidence reference. This final action remains subject to the canonical delivery integrity checks.</p>';
        self::formStart('complete_canonical_lesson');
        echo '<input type="hidden" name="lesson_id" value="' . esc_attr((string) $lesson->id) . '">';
        self::canonicalEvidenceFields();
        submit_button('Mark class as completed', 'primary');
        echo '</form>';
    }
}
