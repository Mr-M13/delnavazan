<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\ProviderIntegrationRule;

/** Persistence boundary for the invitation-only teacher onboarding lifecycle. */
final class TeacherOnboardingRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'Transaction start failed' ); }
    public function commit(): void { global $wpdb; if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Transaction commit failed' ); }
    public function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }

    public function linkedTeacherForUser(int $userId, bool $forUpdate = false): ?object {
        global $wpdb; $lock = $forUpdate ? ' FOR UPDATE' : '';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*,l.wordpress_user_id,l.status principal_status,o.id onboarding_id,o.state onboarding_state,o.readiness_state,o.profile_state,o.availability_state,o.agreement_state,o.submitted_at,o.reviewed_at,o.reviewed_by,o.review_reason_code,o.activated_at,o.version onboarding_version
             FROM {$this->prefix}teacher_principal_links l
             INNER JOIN {$this->prefix}teachers t ON t.id=l.teacher_id
             LEFT JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=t.id
             WHERE l.wordpress_user_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.archived_at IS NULL LIMIT 1{$lock}",
            $userId
        ) );
    }

    public function teacherForUpdate(int $teacherId): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teachers WHERE id=%d FOR UPDATE", $teacherId ) ); }
    public function stateForUpdate(int $teacherId): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_onboarding_states WHERE teacher_id=%d FOR UPDATE", $teacherId ) ); }
    public function state(int $teacherId): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_onboarding_states WHERE teacher_id=%d", $teacherId ) ); }
    public function availabilityProfile(int $teacherId): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_profiles WHERE teacher_id=%d", $teacherId ) ); }
    public function availabilityHasFacts(int $profileId): bool { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}teacher_availability_rules WHERE profile_id=%d LIMIT 1", $profileId ) ) || (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}teacher_availability_exceptions WHERE profile_id=%d LIMIT 1", $profileId ) ); }
    public function availabilityRules(int $teacherId): array { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT id,weekday,local_start_time,local_end_time,state,timezone,status FROM {$this->prefix}teacher_availability_rules WHERE teacher_id=%d AND status='active' ORDER BY weekday,local_start_time,id", $teacherId ) ); }
    public function hasUsableAvailability(int $teacherId, string $timezone): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT r.id FROM {$this->prefix}teacher_availability_profiles p INNER JOIN {$this->prefix}teacher_availability_rules r ON r.profile_id=p.id AND r.teacher_id=p.teacher_id WHERE p.teacher_id=%d AND p.status='active' AND p.timezone=%s AND r.status='active' AND r.timezone=p.timezone AND r.state IN ('preferred','requestable') LIMIT 1",
            $teacherId,
            $timezone
        ) );
    }
    public function hasUsableGoogleConnection(int $teacherId): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT c.id FROM {$this->prefix}integration_connections c INNER JOIN {$this->prefix}integration_credentials k ON k.connection_id=c.id AND k.state='active' WHERE c.teacher_id=%d AND c.provider_code='google_calendar' AND c.connection_state='connected' AND c.active_slot=1 AND c.identity_state='verified' AND BINARY c.scope_snapshot = BINARY %s LIMIT 1",
            $teacherId,ProviderIntegrationRule::GOOGLE_CALENDAR_SCOPE
        ));
    }

    public function updateTeacherProfile(int $teacherId, array $data, string $now, int $actor): void {
        global $wpdb; $data['updated_at'] = $now; $data['updated_by'] = $actor;
        $n = $wpdb->update( $this->prefix . 'teachers', $data, array( 'id' => $teacherId, 'archived_at' => null ) );
        if ( $n === false ) throw new \RuntimeException( 'Teacher profile persistence failed: ' . $wpdb->last_error );
    }

    public function saveState(int $teacherId, array $data, string $now, ?int $actor): object {
        global $wpdb; $current = $this->stateForUpdate( $teacherId );
        if ( $current ) {
            $data['version'] = (int) $current->version + 1; $data['updated_at'] = $now; $data['updated_by'] = $actor;
            $n = $wpdb->update( $this->prefix . 'teacher_onboarding_states', $data, array( 'id' => $current->id, 'version' => $current->version ) );
            if ( $n !== 1 ) throw new \RuntimeException( 'Teacher onboarding changed concurrently' );
        } else {
            $data += array( 'state' => 'linked_pending', 'readiness_state' => 'not_ready', 'profile_state' => 'incomplete', 'availability_state' => 'incomplete', 'agreement_state' => 'not_required' );
            $data += array( 'teacher_id' => $teacherId, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor );
            if ( $wpdb->insert( $this->prefix . 'teacher_onboarding_states', $data ) === false ) throw new \RuntimeException( 'Teacher onboarding persistence failed: ' . $wpdb->last_error );
        }
        $saved = $this->stateForUpdate( $teacherId ); if ( ! $saved ) throw new \RuntimeException( 'Teacher onboarding state unavailable after save' ); return $saved;
    }

    public function appendEvent(int $teacherId, ?string $from, string $to, ?string $reason, ?int $actor, string $now): void {
        global $wpdb;
        $sequence = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->prefix}teacher_onboarding_events WHERE teacher_id=%d FOR UPDATE", $teacherId ) );
        $ok = $wpdb->insert( $this->prefix . 'teacher_onboarding_events', array(
            'teacher_id' => $teacherId, 'event_sequence' => $sequence, 'from_state' => $from, 'to_state' => $to,
            'reason_code' => $reason, 'actor_type' => $actor ? 'user' : 'system', 'actor_id' => $actor,
            'occurred_at' => $now, 'created_at' => $now,
        ) );
        if ( $ok === false ) throw new \RuntimeException( 'Teacher onboarding event persistence failed: ' . $wpdb->last_error );
    }

    /** Read-only onboarding workspace projection: no credentials, tokens or provider references. */
    public function workspaceRows(int $limit = 100): array {
        global $wpdb;
        $limit = max(1, min(200, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id teacher_id,t.display_name,t.email,t.country_code,t.timezone,t.locale,t.calendar_preference,t.status teacher_status,t.archived_at,
                    o.state,o.readiness_state,o.profile_state,o.availability_state,o.agreement_state,o.submitted_at,o.reviewed_at,o.review_reason_code,
                    l.wordpress_user_id linked_user_id,l.status principal_status
             FROM {$this->prefix}teachers t
             LEFT JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=t.id
             LEFT JOIN {$this->prefix}teacher_principal_links l ON l.teacher_id=t.id AND l.status='active' AND l.revoked_at IS NULL
             WHERE t.archived_at IS NULL
             ORDER BY CASE WHEN o.state='pending_review' THEN 0 WHEN o.id IS NOT NULL THEN 1 ELSE 2 END,o.updated_at DESC,t.id DESC
             LIMIT %d",
            $limit
        ) ) ?: array();
    }

    /** Latest Google account connection shape without credential material. */
    public function googleConnection(int $teacherId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT connection_state,identity_state,scope_snapshot,connected_at,failure_reason_code,updated_at
             FROM {$this->prefix}integration_connections
             WHERE teacher_id=%d AND provider_code='google_calendar' AND active_slot=1 ORDER BY id DESC LIMIT 1",
            $teacherId
        ) );
    }

    /** Counts only. Provider event/calendar/meeting references never leave this repository. */
    public function projectionReadiness(int $teacherId): array {
        global $wpdb;
        $calendar = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN m.projection_state='verified' AND m.active_slot=1 AND m.revoked_at IS NULL THEN 1 ELSE 0 END),0) verified,COALESCE(SUM(CASE WHEN m.projection_state='pending' AND m.revoked_at IS NULL THEN 1 ELSE 0 END),0) pending,MAX(m.updated_at) last_at
             FROM {$this->prefix}provider_calendar_event_mappings m INNER JOIN {$this->prefix}lessons l ON l.id=m.lesson_id WHERE l.teacher_id=%d",
            $teacherId
        ) );
        $meeting = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN m.projection_state='verified' AND m.active_slot=1 AND m.revoked_at IS NULL THEN 1 ELSE 0 END),0) verified,COALESCE(SUM(CASE WHEN m.projection_state='pending' AND m.revoked_at IS NULL THEN 1 ELSE 0 END),0) pending,MAX(m.updated_at) last_at
             FROM {$this->prefix}provider_meeting_mappings m INNER JOIN {$this->prefix}lessons l ON l.id=m.lesson_id WHERE l.teacher_id=%d",
            $teacherId
        ) );
        return array(
            'calendar' => array('total' => (int) ($calendar->total ?? 0), 'verified' => (int) ($calendar->verified ?? 0), 'pending' => (int) ($calendar->pending ?? 0), 'last_at' => $calendar->last_at ?? null),
            'meeting' => array('total' => (int) ($meeting->total ?? 0), 'verified' => (int) ($meeting->verified ?? 0), 'pending' => (int) ($meeting->pending ?? 0), 'last_at' => $meeting->last_at ?? null),
        );
    }

    public function reviewRows(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT t.id teacher_id,t.display_name,t.email,t.timezone,o.state,o.readiness_state,o.profile_state,o.availability_state,o.agreement_state,o.submitted_at,o.reviewed_at,o.review_reason_code
             FROM {$this->prefix}teachers t INNER JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=t.id
             WHERE t.archived_at IS NULL ORDER BY o.updated_at DESC,t.id DESC LIMIT 100"
        );
    }
}
