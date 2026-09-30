<?php
namespace Delnavazan\Platform\Portals;

final class PortalRule {
    public const JOIN='lesson_join';
    public const ABSENCE='lesson_absence';
    public const PURPOSES=array('lesson_join','lesson_absence');
    public const CAPABILITY_STATES=array('active','consumed','revoked','superseded');
    public const CAPABILITY_EVENT_TYPES=array('minted','rotated','revoked','consumed');
    /**
     * `confirmed_submitting` is the redemption claim, `delegating` is the durable single-delegator
     * lease keyed to the confirmation (W-D19/§15.3), and `submitted`/`refused`/`redirected` are
     * recorded outcomes.
     */
    public const ACTION_STATES=array('confirmed_submitting','delegating','submitted','redirected','refused');
    public const CAPABILITY_COMMAND_OPERATIONS=array('mint','rotate','revoke','consume','unknown');
    /** `unknown` is command evidence only, never a capability purpose. */
    public const COMMAND_PURPOSE_UNKNOWN='unknown';
    public const HANDOFF_TARGETS=array('canonical_attendance_evidence');
    public const SURFACES=array('portal_public_join','portal_public_absence','portal_student_lesson','portal_student_enrolment','portal_teacher_lesson','portal_teacher_assignment','portal_principal','portal_capability_admin');
    public const PRINCIPAL_KINDS=array('administrator','teacher','student','guardian');
    /** The existing Phase-F grant scope that authorizes a guardian's portal read. */
    public const GUARDIAN_PORTAL_READ_SCOPE='service_acceptance';
    public const READ_MODEL_VERSIONS=array('portal_lesson_v1','portal_enrolment_v1','portal_principal_v1');
    public const PROVIDER_CALLS=0;
    public const OUTBOX_WRITES=0;
    public const THEME_WRITES=0;
    public const CAPABILITY_BINDING_VERSION='portal_capability_binding_v1';
    public const HANDLE_ENTROPY_BYTES=32;
    public const MAX_CAPABILITY_TTL_SECONDS=2592000;
    public const PUBLIC_ACTION_OPTION='dzn_platform_portal_actions';
    public const PUBLIC_ACTION_ENABLED_VALUE='enabled';
    public const SAFE_JOIN_HOSTS=array('meet.google.com');
    public const JOIN_ACTION_MODE='repeatable_evidence_and_redirect';
    public const ABSENCE_ACTION_MODE='confirm_then_single_submission';
    public const CONFIRMATION_TOKEN_BYTES=32;
    /**
     * Declared bound (seconds) of one delegation lease.  A lease younger than this belongs to a live
     * delegator, so an exact replay never delegates past it; a lease older than it is the abandoned
     * claim of §15.8 and the exact replay of the same confirmation takes it over.
     */
    public const DELEGATION_LEASE_SECONDS=900;
    /** Bounded re-check budget for an exact replay that arrives while another delegator holds the lease. */
    public const DELEGATION_WAIT_ATTEMPTS=10;
    public const DELEGATION_WAIT_MILLISECONDS=200;
    public const EXCEPTION_REASON_CODES=array('portal_vocabulary_member_not_allowed','portal_parent_not_declared','portal_parent_not_live','portal_principal_required','portal_principal_unresolved','portal_principal_ambiguous','portal_principal_kind_not_permitted','portal_object_not_found','portal_object_not_owned','portal_object_not_portal_visible','portal_upstream_aggregate_invalid','portal_capability_unknown','portal_capability_handle_malformed','portal_capability_signature_invalid','portal_capability_expired','portal_capability_revoked','portal_capability_superseded','portal_capability_consumed','portal_capability_purpose_mismatch','portal_capability_stale_schedule','portal_capability_expiry_missing','portal_capability_ttl_not_allowed','portal_capability_binding_mismatch','portal_capability_generation_conflict','portal_join_target_not_allowlisted','portal_join_target_unavailable','portal_join_target_not_declared','portal_absence_window_closed','portal_absence_outcome_final','portal_absence_late_evidence','portal_absence_submission_pending','portal_confirmation_required','portal_confirmation_invalid','command_replay_conflict','portal_rate_limited','portal_route_disabled');
    public const OPERATOR_REASON_CODES=array('phase_w_declared_default','operator_decision','operator_recorded_error','operator_suspected_leak','operator_reschedule_rotation','operator_cancellation_rotation','operator_archive_rotation');
    /**
     * §15.6 — the two Phase-W persistence/corruption failure codes. They are declared so a caller can
     * tell an infrastructure failure apart from a business refusal: a failure carrying one of these
     * codes has already rolled its own transaction back and must propagate unchanged, never becoming
     * new refusal evidence.
     */
    public const PERSISTENCE_FAILURE_CODES=array('portal_action_evidence_persistence_failed','portal_capability_persistence_failed');
    public const REASON_CODES=array('phase_w_declared_default','operator_decision','operator_recorded_error','operator_suspected_leak','operator_reschedule_rotation','operator_cancellation_rotation','operator_archive_rotation','portal_vocabulary_member_not_allowed','portal_parent_not_declared','portal_parent_not_live','portal_principal_required','portal_principal_unresolved','portal_principal_ambiguous','portal_principal_kind_not_permitted','portal_object_not_found','portal_object_not_owned','portal_object_not_portal_visible','portal_upstream_aggregate_invalid','portal_capability_unknown','portal_capability_handle_malformed','portal_capability_signature_invalid','portal_capability_expired','portal_capability_revoked','portal_capability_superseded','portal_capability_consumed','portal_capability_purpose_mismatch','portal_capability_stale_schedule','portal_capability_expiry_missing','portal_capability_ttl_not_allowed','portal_capability_binding_mismatch','portal_capability_generation_conflict','portal_join_target_not_allowlisted','portal_join_target_unavailable','portal_join_target_not_declared','portal_absence_window_closed','portal_absence_outcome_final','portal_absence_late_evidence','portal_absence_submission_pending','portal_confirmation_required','portal_confirmation_invalid','command_replay_conflict','portal_rate_limited','portal_route_disabled');
    public static function reason(string $reason):string { if(!in_array($reason,self::REASON_CODES,true)) throw new \InvalidArgumentException('portal_vocabulary_member_not_allowed'); return $reason; }
    public static function exceptionReason(string $reason):string { if(!in_array($reason,self::EXCEPTION_REASON_CODES,true)) throw new \InvalidArgumentException('portal_vocabulary_member_not_allowed'); return $reason; }
    public static function operatorReason(string $reason):string { if(!in_array($reason,self::OPERATOR_REASON_CODES,true)) throw new \InvalidArgumentException('portal_vocabulary_member_not_allowed'); return $reason; }
    /**
     * §15.6 — the declared business refusal a throwable carries, or `null` when it carries none.  Only a
     * declared business refusal may append refusal evidence; every other throwable is a persistence,
     * corruption or infrastructure failure whose transaction has already rolled back, so it propagates
     * unchanged and leaves no command, action, denial or audit row behind.
     */
    public static function refusalReason(\Throwable $error):?string { $message=$error->getMessage(); return in_array($message,self::EXCEPTION_REASON_CODES,true)&&!in_array($message,self::PERSISTENCE_FAILURE_CODES,true)?$message:null; }
}
