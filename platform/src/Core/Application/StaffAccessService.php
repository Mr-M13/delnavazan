<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Narrow, auditable staff-area access grants.
 *
 * This service owns only the capabilities it lists below. WordPress roles, teacher/student
 * portal identity and every capability outside this catalogue are left untouched. A grant is
 * stored as user meta so a later save can distinguish a managed grant from an unrelated
 * capability that happens to share the same WordPress account.
 */
final class StaffAccessService {
    public const META_KEY = 'dzn_staff_access_grants_v1';
    public const AUDIT_AGGREGATE = 'staff_access';
    private const MANAGE_ACTOR_CAPABILITY = 'manage_options';

    /** View and manage capabilities that already exist in the platform. */
    public const AREAS = array(
        'overview' => array(
            'label' => 'Overview',
            'description' => 'Read the academy overview and booking-request pipeline.',
            'view' => array('dzn_view_booking_requests'),
            'manage' => array('dzn_manage_booking_request_coordination'),
            'effect' => 'View: see the overview and requests. Manage: coordinate booking requests through the existing authority.',
        ),
        'teachers' => array(
            'label' => 'Teachers',
            'description' => 'Teacher profiles, invitations, onboarding, eligibility and availability.',
            'view' => array(),
            'manage' => array(
                'dzn_manage_teachers','dzn_manage_onboarding','dzn_issue_teacher_invitations',
                'dzn_revoke_teacher_invitations','dzn_view_onboarding_audit',
                'dzn_manage_teaching_eligibility','dzn_manage_teacher_availability',
            ),
            'effect' => 'Manage: use the existing Teacher maintenance, invitation, onboarding, eligibility and availability actions.',
        ),
        'students' => array(
            'label' => 'Students',
            'description' => 'Student directory and identity/account review.',
            'view' => array('dzn_view_student_acceptance_eligibility'),
            'manage' => array('dzn_manage_students'),
            'effect' => 'View: review identity and account evidence. Manage: maintain student records through the existing service.',
        ),
        'classes' => array(
            'label' => 'Classes',
            'description' => 'Class schedules, delivery evidence and attendance review.',
            'view' => array('dzn_view_canonical_attendance_review'),
            'manage' => array('dzn_manage_lessons'),
            'effect' => 'View: review canonical attendance evidence. Manage: use the existing class and schedule authorities.',
        ),
        'enrolment' => array(
            'label' => 'Enrolment',
            'description' => 'Bookings, enrolments, terms, catalogue and post-intro work.',
            'view' => array('dzn_view_booking_requests'),
            'manage' => array('dzn_manage_enrolments','dzn_manage_terms'),
            'effect' => 'View: read the enrolment queues. Manage: use the existing booking, enrolment and term actions.',
        ),
        'finance' => array(
            'label' => 'Finance',
            'description' => 'Read finance authority and manage the permitted finance configuration.',
            'view' => array(
                'dzn_view_finance_authority','dzn_view_payment_execution_authority',
                'dzn_view_cash_ledger','dzn_view_commercial_authority',
            ),
            'manage' => array(
                'dzn_manage_finance_policies','dzn_manage_finance_statements','dzn_manage_teacher_rates',
                'dzn_manage_lesson_payability','dzn_manage_cash_ledger',
            ),
            'effect' => 'View: read statements, payments and ledger evidence. Manage: use the listed finance configuration authorities, never payment execution.',
        ),
        'settings' => array(
            'label' => 'Settings',
            'description' => 'Read diagnostics and the settings surfaces.',
            'view' => array('dzn_view_diagnostics'),
            'manage' => array(),
            'effect' => 'View: inspect settings and diagnostics. Sensitive settings actions are granted separately below.',
        ),
    );

    /**
     * Sensitive actions are deliberately not implied by an area checkbox. Each entry is a
     * narrow existing capability, not WordPress Administrator.
     */
    public const SENSITIVE = array(
        'payment_execution' => array(
            'label' => 'Execute payments',
            'caps' => array('dzn_manage_payment_execution'),
            'warning' => 'Allows payment execution through the existing payment authority. It does not grant Administrator access.',
        ),
        'payment_ingestion' => array(
            'label' => 'Ingest payment evidence',
            'caps' => array('dzn_ingest_commercial_payment_evidence','dzn_ingest_payment_provider_events'),
            'warning' => 'Allows provider and commercial payment evidence ingestion. It is not ordinary Finance viewing.',
        ),
        'identity_resolution' => array(
            'label' => 'Resolve student identity',
            'caps' => array('dzn_manage_student_acceptance_authority','dzn_resolve_booking_request_student_identity'),
            'warning' => 'Allows identity resolution. This is separate from a teacher or student portal identity.',
        ),
        'provider_secrets' => array(
            'label' => 'Manage provider secrets',
            'caps' => array('dzn_manage_provider_integrations','dzn_manage_payment_providers'),
            'warning' => 'Allows saving provider credentials. Secret values remain write-only and are never displayed.',
        ),
        'notification_dispatch' => array(
            'label' => 'Operate notification dispatch',
            'caps' => array('dzn_operate_notification_dispatch'),
            'warning' => 'Allows the system dispatch and restricted manual retry actions. It does not allow arbitrary messages.',
        ),
        'notification_templates' => array(
            'label' => 'Edit notification templates',
            'caps' => array('dzn_manage_notification_templates'),
            'warning' => 'Allows draft, publish and rollback of notification copy. Provider template approval remains separate.',
        ),
        'commercial_policies' => array(
            'label' => 'Record commercial policies',
            'caps' => array('dzn_manage_commercial_policies'),
            'warning' => 'Allows a new versioned commercial policy value, including the booking horizon. Historical versions remain.',
        ),
        'portal_capabilities' => array(
            'label' => 'Mint and revoke portal capabilities',
            'caps' => array('dzn_manage_portal_capabilities'),
            'warning' => 'Allows bounded portal join/absence capability commands. It does not grant public actions by itself.',
        ),
        'exceptions' => array(
            'label' => 'Manage operational exceptions',
            'caps' => array('dzn_manage_exceptions'),
            'warning' => 'Allows acknowledging, resolving or dismissing operational exceptions.',
        ),
    );

    /** @return array<string,array> */
    public static function areas(): array { return self::AREAS; }

    /** @return array<string,array> */
    public static function sensitiveActions(): array { return self::SENSITIVE; }

    /**
     * Current saved or inferred selection for one account.
     *
     * A missing meta row means this account predates the consolidated screen. Its current
     * capabilities are read once and shown as the initial selection, so opening and saving the
     * screen preserves existing staff access rather than silently revoking it.
     *
     * @return array{areas:array<string,array{view:bool,manage:bool}>,sensitive:array<string,bool>}
     */
    public function selectionFor(int $userId): array {
        $saved = $userId > 0 && function_exists('get_user_meta') ? get_user_meta($userId, self::META_KEY, true) : array();
        if (is_array($saved) && isset($saved['areas']) && is_array($saved['areas'])) {
            return $this->normaliseSelection($saved);
        }
        $user = $userId > 0 && function_exists('get_userdata') ? get_userdata($userId) : false;
        return $user ? $this->inferSelection($user) : $this->emptySelection();
    }

    /** @return array{areas:array<string,array{view:bool,manage:bool}>,sensitive:array<string,bool>} */
    public function emptySelection(): array {
        $areas = array();
        foreach (self::AREAS as $key => $area) $areas[$key] = array('view' => false, 'manage' => false);
        $sensitive = array();
        foreach (self::SENSITIVE as $key => $definition) $sensitive[$key] = false;
        return array('areas' => $areas, 'sensitive' => $sensitive);
    }

    /** @param array<string,mixed> $input Raw request selection. */
    public function sanitiseSelection(array $input): array {
        $selection = $this->emptySelection();
        $areas = isset($input['areas']) && is_array($input['areas']) ? $input['areas'] : array();
        foreach (self::AREAS as $key => $area) {
            $row = isset($areas[$key]) && is_array($areas[$key]) ? $areas[$key] : array();
            $selection['areas'][$key]['view'] = $area['view'] !== array() && !empty($row['view']);
            $selection['areas'][$key]['manage'] = $area['manage'] !== array() && !empty($row['manage']);
            if ($selection['areas'][$key]['manage'] && $area['view'] !== array()) $selection['areas'][$key]['view'] = true;
        }
        $sensitive = isset($input['sensitive']) && is_array($input['sensitive']) ? $input['sensitive'] : array();
        foreach (self::SENSITIVE as $key => $definition) $selection['sensitive'][$key] = !empty($sensitive[$key]);
        return $selection;
    }

    /**
     * Apply one selection to a non-administrator account and audit the difference.
     *
     * @return array{before:array,after:array,added:string[],removed:string[]}
     */
    public function apply(int $targetId, array $selection, int $actorId): array {
        if (!function_exists('current_user_can') || !current_user_can(self::MANAGE_ACTOR_CAPABILITY)) {
            throw new \RuntimeException('staff_access_forbidden');
        }
        if ($targetId < 1) throw new \InvalidArgumentException('staff_access_account_required');
        if ($targetId === $actorId) throw new \InvalidArgumentException('staff_access_self_grant_forbidden');
        $target = function_exists('get_user_by') ? get_user_by('id', $targetId) : false;
        if (!$target) throw new \InvalidArgumentException('staff_access_account_not_found');
        if (function_exists('user_can') && user_can($target, self::MANAGE_ACTOR_CAPABILITY)) {
            throw new \InvalidArgumentException('staff_access_administrator_is_read_only');
        }

        $before = $this->selectionFor($targetId);
        $after = $this->sanitiseSelection($selection);
        $desired = $this->capsForSelection($after);
        $managed = $this->managedCaps();
        $current = array();
        foreach ($managed as $cap) if (method_exists($target, 'has_cap') ? $target->has_cap($cap) : false) $current[] = $cap;

        $added = array_values(array_diff($desired, $current));
        $removed = array_values(array_diff($current, $desired));
        foreach ($removed as $cap) $target->remove_cap($cap);
        foreach ($added as $cap) $target->add_cap($cap);

        if (function_exists('update_user_meta')) update_user_meta($targetId, self::META_KEY, $after);
        $this->audit($actorId, $targetId, $before, $after, $added, $removed);
        return array('before' => $before, 'after' => $after, 'added' => $added, 'removed' => $removed);
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $targetId = 0, int $limit = 20): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return array();
        $table = $wpdb->prefix . 'dzn_platform_audit_events';
        $limit = max(1, min(100, $limit));
        try {
            if ($targetId > 0) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT event_type,actor_id,aggregate_id,safe_detail,occurred_at FROM {$table} WHERE aggregate_type=%s AND aggregate_id=%d ORDER BY id DESC LIMIT %d",
                    self::AUDIT_AGGREGATE, $targetId, $limit
                ));
            } else {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT event_type,actor_id,aggregate_id,safe_detail,occurred_at FROM {$table} WHERE aggregate_type=%s ORDER BY id DESC LIMIT %d",
                    self::AUDIT_AGGREGATE, $limit
                ));
            }
        } catch (\Throwable) { return array(); }
        return is_array($rows) ? $rows : array();
    }

    /** @return string[] */
    public function capsForSelection(array $selection): array {
        $selection = $this->normaliseSelection($selection);
        $caps = array();
        foreach (self::AREAS as $key => $area) {
            if (!empty($selection['areas'][$key]['view'])) foreach ($area['view'] as $cap) $caps[] = $cap;
            if (!empty($selection['areas'][$key]['manage'])) foreach ($area['manage'] as $cap) $caps[] = $cap;
        }
        foreach (self::SENSITIVE as $key => $definition) {
            if (!empty($selection['sensitive'][$key])) foreach ($definition['caps'] as $cap) $caps[] = $cap;
        }
        return array_values(array_unique($caps));
    }

    /** @return string[] */
    public function managedCaps(): array {
        $caps = array();
        foreach (self::AREAS as $area) {
            foreach ($area['view'] as $cap) $caps[] = $cap;
            foreach ($area['manage'] as $cap) $caps[] = $cap;
        }
        foreach (self::SENSITIVE as $definition) foreach ($definition['caps'] as $cap) $caps[] = $cap;
        return array_values(array_unique($caps));
    }

    private function inferSelection(object $user): array {
        $selection = $this->emptySelection();
        foreach (self::AREAS as $key => $area) {
            $selection['areas'][$key]['view'] = $area['view'] !== array() && $this->hasAny($user, $area['view']);
            $selection['areas'][$key]['manage'] = $area['manage'] !== array() && $this->hasAny($user, $area['manage']);
            if ($selection['areas'][$key]['manage'] && $area['view'] !== array()) $selection['areas'][$key]['view'] = true;
        }
        foreach (self::SENSITIVE as $key => $definition) $selection['sensitive'][$key] = $this->hasAny($user, $definition['caps']);
        return $selection;
    }

    private function normaliseSelection(array $selection): array {
        $normalised = $this->emptySelection();
        foreach (self::AREAS as $key => $area) {
            $row = isset($selection['areas'][$key]) && is_array($selection['areas'][$key]) ? $selection['areas'][$key] : array();
            $normalised['areas'][$key]['view'] = $area['view'] !== array() && !empty($row['view']);
            $normalised['areas'][$key]['manage'] = $area['manage'] !== array() && !empty($row['manage']);
            if ($normalised['areas'][$key]['manage'] && $area['view'] !== array()) $normalised['areas'][$key]['view'] = true;
        }
        foreach (self::SENSITIVE as $key => $definition) {
            $normalised['sensitive'][$key] = !empty($selection['sensitive'][$key]);
        }
        return $normalised;
    }

    private function hasAny(object $user, array $caps): bool {
        foreach ($caps as $cap) if (method_exists($user, 'has_cap') && $user->has_cap($cap)) return true;
        return false;
    }

    private function audit(int $actorId, int $targetId, array $before, array $after, array $added, array $removed): void {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'insert')) return;
        $now = gmdate('Y-m-d H:i:s');
        $detail = array(
            'areas_before' => $this->compactAreas($before),
            'areas_after' => $this->compactAreas($after),
            'sensitive_before' => array_keys(array_filter($before['sensitive'])),
            'sensitive_after' => array_keys(array_filter($after['sensitive'])),
            'added_count' => count($added),
            'removed_count' => count($removed),
        );
        $key = hash_hmac('sha256', 'staff-access:' . $targetId . ':' . $actorId . ':' . $now . ':' . wp_json_encode($detail), function_exists('wp_salt') ? wp_salt('dzn_platform_audit') : 'staff-access');
        try {
            $wpdb->insert($wpdb->prefix . 'dzn_platform_audit_events', array(
                'aggregate_type' => self::AUDIT_AGGREGATE,
                'aggregate_id' => $targetId,
                'event_type' => 'staff_access.updated',
                'actor_type' => 'user',
                'actor_id' => $actorId,
                'reason_code' => 'staff_access_selection_saved',
                'safe_detail' => wp_json_encode($detail),
                'idempotency_key' => $key,
                'occurred_at' => $now,
            ));
        } catch (\Throwable) {
            // The grant itself is already applied. Audit failure must not roll the account back
            // into an unverified state; Diagnostics reports the missing audit evidence.
        }
    }

    /** @return array<string,string> */
    private function compactAreas(array $selection): array {
        $out = array();
        foreach ($selection['areas'] as $key => $row) {
            $state = array();
            if (!empty($row['view'])) $state[] = 'view';
            if (!empty($row['manage'])) $state[] = 'manage';
            if ($state !== array()) $out[$key] = implode('+', $state);
        }
        return $out;
    }
}
