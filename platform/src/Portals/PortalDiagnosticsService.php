<?php
namespace Delnavazan\Platform\Portals;

final class PortalDiagnosticsService {
    public function summary():array { global $wpdb; $p=$wpdb->prefix.'dzn_'; return array(
        'capabilities_by_purpose_state'=>$wpdb->get_results("SELECT purpose,state,COUNT(*) total FROM {$p}portal_public_capabilities GROUP BY purpose,state",ARRAY_A),
        'live_generations'=>$wpdb->get_results("SELECT lesson_id,purpose,generation FROM {$p}portal_public_capabilities WHERE active_slot=1 AND state='active'",ARRAY_A),
        'expired_not_rotated'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}portal_public_capabilities WHERE state='active' AND expires_at<=UTC_TIMESTAMP()"),
        'redemptions_by_outcome'=>$wpdb->get_results("SELECT purpose,action_state,COUNT(*) total FROM {$p}portal_public_action_events GROUP BY purpose,action_state",ARRAY_A),
        'refusals_by_reason'=>$wpdb->get_results("SELECT reason_code,COUNT(*) total FROM {$p}portal_public_capability_commands WHERE result_state='refused' GROUP BY reason_code",ARRAY_A),
        'action_refusals_by_reason'=>$wpdb->get_results("SELECT outcome_reason_code,COUNT(*) total FROM {$p}portal_public_action_events WHERE action_state='refused' GROUP BY outcome_reason_code",ARRAY_A),
        'denials_by_surface_reason'=>$wpdb->get_results("SELECT surface,reason_code,COUNT(*) total FROM {$p}portal_access_denials GROUP BY surface,reason_code",ARRAY_A),
        'refusals_by_surface'=>$wpdb->get_results("SELECT 'portal_capability_admin' surface,operation,reason_code,COUNT(*) total FROM {$p}portal_public_capability_commands WHERE result_state='refused' GROUP BY operation,reason_code",ARRAY_A),
        // §12/§15.8: a confirmed claim with no recorded terminal outcome past the declared delegation
        // lease bound is the first-class abandoned-claim count; a lease inside the bound is in flight.
        'confirmed_unresolved'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}portal_public_action_events c WHERE c.action_state='confirmed_submitting' AND c.occurred_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".PortalRule::DELEGATION_LEASE_SECONDS." SECOND) AND NOT EXISTS (SELECT 1 FROM {$p}portal_public_action_events t WHERE t.capability_id=c.capability_id AND t.confirmation_digest=c.confirmation_digest AND t.action_state IN ('submitted','refused'))"),
        'delegations_in_flight'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}portal_public_action_events WHERE action_state='delegating' AND occurred_at > DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".PortalRule::DELEGATION_LEASE_SECONDS." SECOND)"),
        'delegations_abandoned'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}portal_public_action_events d WHERE d.action_state='delegating' AND d.occurred_at <= DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".PortalRule::DELEGATION_LEASE_SECONDS." SECOND) AND NOT EXISTS (SELECT 1 FROM {$p}portal_public_action_events t WHERE t.capability_id=d.capability_id AND t.confirmation_digest=d.confirmation_digest AND t.action_state IN ('submitted','refused'))"),
        'access_denials'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}portal_access_denials"),
        'public_actions_enabled'=>(string)get_option(PortalRule::PUBLIC_ACTION_OPTION,'')===PortalRule::PUBLIC_ACTION_ENABLED_VALUE,
        'structural_invariants'=>array('provider_calls'=>PortalRule::PROVIDER_CALLS,'outbox_writes'=>PortalRule::OUTBOX_WRITES,'theme_writes'=>PortalRule::THEME_WRITES),
    ); }
}
