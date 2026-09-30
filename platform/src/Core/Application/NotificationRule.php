<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Locked Phase 2A.2-S canonical notification & communications authority rule
 * (docs/PHASE-2A-2S-IMPLEMENTATION-CONTRACT.md, Schema 032).
 *
 * S consumes the finalised R2 intent set from the shared `platform_outbox` seam and owns eligibility,
 * workflow versioning, scheduling, idempotency/retry policy, rendered-parameter snapshots, the
 * notification/attempt/delivery lifecycle and channel-independent diagnostics. It never owns identity,
 * contact source-of-truth or transport, and it never re-derives a Term, Lesson, schedule, attendance,
 * obligation, settlement, renewal cycle, capacity or refund decision.
 *
 * Every vocabulary in this file is closed and machine-checked: an implementation may read these tables
 * but may not widen, rebind or invent a member. The §6.2.2 binding matrix in particular is the *only*
 * authoritative-fact binding a `subject_state_is` rule may carry, and it is derived read-only from the
 * owning module's locked transition table (RecurringRule::AGGREGATE_EVENT_TRANSITIONS).
 */
final class NotificationRule {
    public const RULE_VERSION='notification_communications_v1';
    public const DOMAIN='notification_v1';
    public const EVIDENCE_CHANNELS=array('staff_record','authenticated_platform','document_reference','provider_evidence','system');

    /** Workflow identity states. A workflow is the stable identity of one communication workflow. */
    public const WORKFLOW_STATES=array('draft','active','retired');
    /** Version states. `draft → active → superseded | retired`; a retired/superseded version never returns. */
    public const VERSION_STATES=array('draft','active','superseded','retired');
    public const TEMPLATE_STATES=array('draft','active','retired');
    /** The single active-routing slot value (§6.1): `1` on the one active version, NULL on every other row. */
    public const ACTIVE_SLOT=1;

    /**
     * The R2 finalised channel-neutral intent set S consumes. Names only: R2 publishes no audience, no
     * template, no recipient and no delivery state, so a workflow definition — never the intent name —
     * resolves the audience.
     */
    public const INTENTS=array(
        'AUTOMATIC_RENEWAL_UPCOMING','AUTOMATIC_RENEWAL_CHARGED','AUTOMATIC_RENEWAL_FAILED',
        'MANUAL_RENEWAL_PAYMENT_REQUIRED','GUARANTEE_DEADLINE_APPROACHING','GUARANTEE_EXPIRED',
        'PAYMENT_FAILED','PAYMENT_RECOVERED','TERM_LAPSED','REFUND_REVIEW_REQUIRED','REFUND_RESOLVED',
    );

    /**
     * The Phase-1 delivery seam S shares `platform_outbox` with and must never claim. `prepared` is owned
     * by `PrincipalInvitationRepository::markDeliveryPrepared()`, and the invitation columns identify the
     * rows; S mutates only rows it owns (`workflow_key IS NOT NULL` with an S-registered intent).
     */
    public const LEGACY_EXCLUDED_EVENT_TYPES=array('teacher_invitation.delivery_requested');
    public const LEGACY_OWNED_OUTBOX_STATUSES=array('prepared');

    /** The intent R2 declares but no R2 writer publishes: reserved-unbound, never registered or delivered. */
    public const UNBOUND_INTENTS=array('GUARANTEE_EXPIRED');

    /** The proposed workflow keys and audiences of §4. A key is identity, not authority. */
    public const INTENT_WORKFLOW_KEYS=array(
        'AUTOMATIC_RENEWAL_UPCOMING'=>'renewal.automatic_upcoming',
        'AUTOMATIC_RENEWAL_CHARGED'=>'renewal.automatic_charged',
        'AUTOMATIC_RENEWAL_FAILED'=>'renewal.automatic_failed',
        'MANUAL_RENEWAL_PAYMENT_REQUIRED'=>'renewal.manual_payment_required',
        'GUARANTEE_DEADLINE_APPROACHING'=>'renewal.guarantee_deadline_approaching',
        'GUARANTEE_EXPIRED'=>'renewal.guarantee_deadline_expired',
        'PAYMENT_FAILED'=>'payment.failed',
        'PAYMENT_RECOVERED'=>'payment.recovered',
        'TERM_LAPSED'=>'renewal.term_lapsed',
        'REFUND_REVIEW_REQUIRED'=>'refund.review_required',
        'REFUND_RESOLVED'=>'refund.resolved',
    );

    /**
     * §6.2.2 — the closed intent → authoritative-fact binding matrix.
     *
     * Each row names exactly one authoritative subject aggregate and exactly one bound event type, with
     * the committed `from → to` variants that event type may record for that aggregate (`''` is a null
     * `from_state`). `to_states` is the *derived* B2 allowlist: a version's `subject_state_is` allowlist
     * must equal it exactly, so a widened, narrowed or rebound allowlist is refused. `tier` is the §6.2.1
     * tier of the intent, and `instant` names the persisted subject column a tier-F intent schedules from.
     */
    public const BINDING_MATRIX=array(
        'AUTOMATIC_RENEWAL_UPCOMING'=>array(
            'aggregate'=>'renewal_cycle','event_type'=>'opened','transitions'=>array('|pending'),
            'to_states'=>array('pending'),'tier'=>'F','instant'=>'automatic_charge_at',
        ),
        'MANUAL_RENEWAL_PAYMENT_REQUIRED'=>array(
            'aggregate'=>'renewal_cycle','event_type'=>'payment_required',
            'transitions'=>array('pending|payment_required','guarantee_protected|payment_required'),
            'to_states'=>array('payment_required'),'tier'=>'F','instant'=>'guarantee_deadline_at',
        ),
        'GUARANTEE_DEADLINE_APPROACHING'=>array(
            'aggregate'=>'renewal_cycle','event_type'=>'guarantee_protected',
            'transitions'=>array('pending|guarantee_protected'),
            'to_states'=>array('guarantee_protected'),'tier'=>'F','instant'=>'guarantee_deadline_at',
        ),
        'GUARANTEE_EXPIRED'=>null,
        'AUTOMATIC_RENEWAL_CHARGED'=>array(
            'aggregate'=>'collection_intent','event_type'=>'confirmed','transitions'=>array('submitted|confirmed'),
            'to_states'=>array('confirmed'),'tier'=>'P','instant'=>null,
        ),
        'AUTOMATIC_RENEWAL_FAILED'=>array(
            'aggregate'=>'collection_intent','event_type'=>'failed','transitions'=>array('submitted|failed'),
            'to_states'=>array('failed'),'tier'=>'P','instant'=>null,
        ),
        'PAYMENT_FAILED'=>array(
            'aggregate'=>'collection_intent','event_type'=>'failed','transitions'=>array('submitted|failed'),
            'to_states'=>array('failed'),'tier'=>'P','instant'=>null,
        ),
        'PAYMENT_RECOVERED'=>array(
            'aggregate'=>'recovery_case','event_type'=>'recovered',
            'transitions'=>array('open|recovered','recovering|recovered'),
            'to_states'=>array('recovered'),'tier'=>'P','instant'=>null,
        ),
        'TERM_LAPSED'=>array(
            'aggregate'=>'renewal_cycle','event_type'=>'lapsed',
            'transitions'=>array('pending|lapsed','guarantee_protected|lapsed','payment_required|lapsed','collected|lapsed'),
            'to_states'=>array('lapsed'),'tier'=>'P','instant'=>null,
        ),
        'REFUND_REVIEW_REQUIRED'=>array(
            'aggregate'=>'refund_review','event_type'=>'review_required',
            'transitions'=>array('open|review_required'),
            'to_states'=>array('review_required'),'tier'=>'P','instant'=>null,
        ),
        'REFUND_RESOLVED'=>array(
            'aggregate'=>'refund_review','event_type'=>'resolved','transitions'=>array('review_required|resolved'),
            'to_states'=>array('resolved'),'tier'=>'P','instant'=>null,
        ),
    );

    /** The append-only subject-history table of each bound aggregate (never the mutable aggregate row). */
    public const SUBJECT_EVENT_TABLES=array(
        'renewal_cycle'=>'renewal_cycle_events',
        'collection_intent'=>'collection_intent_events',
        'recovery_case'=>'recovery_case_events',
        'refund_review'=>'refund_review_events',
    );
    /** The subject id column of each append-only subject-history table. */
    public const SUBJECT_EVENT_ID_COLUMNS=array(
        'renewal_cycle'=>'renewal_cycle_id',
        'collection_intent'=>'collection_intent_id',
        'recovery_case'=>'recovery_case_id',
        'refund_review'=>'refund_review_id',
    );
    /** The owning subject table of each bound aggregate, read only for its persisted tier-F instant. */
    public const SUBJECT_TABLES=array(
        'renewal_cycle'=>'renewal_cycles',
        'collection_intent'=>'collection_intents',
        'recovery_case'=>'recovery_cases',
        'refund_review'=>'refund_review_cases',
    );
    /** The foreign-key column that names the subject aggregate id in the owning table. */
    public const SUBJECT_ID_COLUMNS=array(
        'renewal_cycle'=>'id',
        'collection_intent'=>'id',
        'recovery_case'=>'id',
        'refund_review'=>'id',
    );
    /**
     * §6.2.4(c): the commercial-policy, pattern and guarantee-fallback seams S may never read, name or
     * call. They are listed here so the contract test can prove the S sources never reference them.
     */
    public const FORBIDDEN_S_INPUTS=array(
        'CommercialPolicyService','MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS','automaticChargeAt',
        'guaranteeFallback','guaranteeDeadlineForPattern','resolveWallClock','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME',
    );

    /** Workflow rule kinds. */
    public const RULE_KINDS=array('eligibility','schedule','retry');

    /** §6.2 eligibility rule codes and their fail-closed outcomes. */
    public const ELIGIBILITY_RULES=array(
        'subject_exists'=>'eligibility_unresolved',
        'subject_state_is'=>'ineligible_subject_state',
        'subject_instant_in_future'=>'eligibility_expired',
        'recipient_resolvable'=>'recipient_unresolved',
        'recipient_opted_in'=>'consent_absent',
        'not_suppressed'=>'suppressed',
        'guardian_authority_present'=>'authority_absent',
        'lead_time_at_least'=>'lead_time_insufficient',
    );
    /** §6.2.1(a) mandatory baseline, required on every version of every intent and audience. */
    public const MANDATORY_BASELINE=array('subject_exists','subject_state_is','recipient_resolvable','recipient_opted_in','guardian_authority_present','not_suppressed');
    /** §6.2.1(b) intent/audience-specific mandatory tier codes. */
    public const TIER_RULES=array('F'=>array('subject_instant_in_future','lead_time_at_least'),'P'=>array());
    /** The eligibility codes whose `parameter_c` is the declared lead-time slack in minutes. */
    public const LEAD_TIME_RULE='lead_time_at_least';
    /**
     * The declared slot usage of the eligibility rules (§6.2/§7.2). An eligibility row carries
     * `ordinal = 1`; a rule that needs no parameter leaves `parameter_a`…`parameter_d` NULL.
     */
    public const ELIGIBILITY_RULE_SLOTS=array(
        'subject_exists'=>array('parameter_a'=>'subject aggregate'),
        'subject_state_is'=>array('parameter_a'=>'subject aggregate','parameter_b'=>'committed to_state allowlist'),
        'subject_instant_in_future'=>array(),
        'recipient_resolvable'=>array(),
        'recipient_opted_in'=>array(),
        'not_suppressed'=>array(),
        'guardian_authority_present'=>array(),
        'lead_time_at_least'=>array('parameter_c'=>'lead_time_minutes'),
    );
    /** §6.2.1(c) — the authorised audience/`recipient_kind` pairs; everything else fails closed. */
    public const AUTHORISED_PAIRS=array(array('student','student'));
    /** The reserved pairs the authorising phase owns: present so the refusal is explicit and stable. */
    public const RESERVED_PAIRS=array(array('guardian','guardian'),array('academy','staff'));

    /**
     * §6.3 canonical schedule parameter encoding: one row per parameter, `ordinal` counting from 1 with
     * no gap, the canonical value in `parameter_a` and `parameter_b`/`parameter_c`/`parameter_d` NULL.
     * Each entry is `ordinal => [parameter name, kind, admissible minimum, admissible maximum]`, where a
     * `basis` kind is the three-value timezone vocabulary and `time` is a zero-padded `HH:MM`.
     */
    public const SCHEDULE_CODES=array(
        'immediate'=>array(),
        'lead_time'=>array(1=>array('lead_time_minutes','minutes',1,525600000)),
        'fixed_local_time'=>array(1=>array('local_time','time',null,null),2=>array('timezone_basis','basis',null,null)),
        'send_window'=>array(
            1=>array('send_window_start_local','time',null,null),
            2=>array('send_window_end_local','time',null,null),
            3=>array('weekday_mask','bitmask',1,127),
            4=>array('timezone_basis','basis',null,null),
        ),
        'deferral'=>array(1=>array('defer_ceiling_minutes','minutes',1,52560000),2=>array('max_deferrals','count',0,65535)),
        'coalesce'=>array(1=>array('coalesce_window_minutes','minutes',1,525600000)),
        'expiry'=>array(1=>array('expiry_minutes','minutes',1,525600000)),
    );
    /** §6.3(a) — the closed composition: one anchor per tier, 0-or-1 optional codes, exactly one expiry. */
    public const SCHEDULE_SLOTS=array(
        'anchor_F'=>'lead_time','anchor_P'=>'immediate',
        'optional'=>array('fixed_local_time','send_window','deferral','coalesce'),
        'required'=>array('expiry'),
    );
    /** The timezone-sensitive schedule codes: they must name one and the same basis (§6.3). */
    public const TIMEZONE_SENSITIVE_CODES=array('fixed_local_time','send_window');
    public const TIMEZONE_BASES=array('recipient_local','academy_local','subject_local');
    /** The maximum offset any composition may accumulate, and the bounded send-window search. */
    public const DEFERRAL_PRODUCT_MAX=52560000;
    public const SEND_WINDOW_SEARCH_DAYS=14;

    /**
     * §9 retry rule-set: one `retry` code with five contiguous parameters in the same canonical
     * encoding. `minimum`/`maximum` is the *narrow-only* interval, and every upper bound **is** the
     * approved class baseline, so the check `declared <= baseline` is the coordinatewise partial order.
     */
    public const RETRY_CODE='retry';
    public const RETRY_PARAMETERS=array(
        1=>array('retry_max_attempts',1,3,3),
        2=>array('retry_initial_backoff_seconds',1,120,120),
        3=>array('retry_backoff_multiplier_bp',10000,30000,30000),
        4=>array('retry_max_backoff_seconds',1,3600,3600),
        5=>array('retry_jitter_bp',0,1000,1000),
    );
    /** The class baseline applied when a version registers no retry rule (§9). */
    public const RETRY_BASELINE=array(
        'retry_max_attempts'=>3,'retry_initial_backoff_seconds'=>120,'retry_backoff_multiplier_bp'=>30000,
        'retry_max_backoff_seconds'=>3600,'retry_jitter_bp'=>1000,
    );
    /** The retry parameter whose lower bound tracks another parameter rather than a constant. */
    public const RETRY_MAX_BACKOFF_FLOOR_PARAMETER='retry_initial_backoff_seconds';
    public const RETRY_KEY_PREFIX='retry_jitter:';
    public const RETRY_EVIDENCE_PREFIX='retry_evidence:';

    /** §6.6 failure classes and their closure codes. */
    public const FAILURE_CLASSES=array('retryable','defer','terminal');
    public const NON_TERMINAL_CLOSURE_CODES=array('retryable'=>'retryable','defer'=>'deferred');
    public const LEASE_EXPIRED_CLASS='retryable';
    public const LEASE_EXPIRED_OUTCOME='lease_expired';
    /**
     * §6.6/§9 — the fourth, audited closure class: an **already-open** attempt whose dispatch-time
     * re-evaluation refused the notification. It is deliberately outside `FAILURE_CLASSES` (the closed
     * caller-declarable vocabulary), so only the refusal path can write it, it is never a retry closure —
     * it derives and persists no retry schedule and never re-arms the outbox row — and its code is one
     * member of the fail-closed refusal set below.
     */
    public const ELIGIBILITY_ABORT_CLASS='eligibility_abort';
    public const ELIGIBILITY_ABORT_OUTCOMES=array(
        'eligibility_unresolved','ineligible_subject_state','recipient_unresolved','consent_absent',
        'authority_absent','suppressed','eligibility_expired','lead_time_insufficient',
    );
    /**
     * §6.6/§10 — the fifth, audited closure class: the live attempt of a notification that a **terminal
     * command** (`cancel`/`expire`/`suppress`) resolved while the lease was held. A terminal command that
     * reaches a `dispatching` notification must never leave the lease open, so the open attempt is closed
     * `abandoned` with this class, its `outcome_code` equal to the terminal state the command produced and
     * no retry schedule — never a retry closure, never re-armed and never a terminal reason.
     */
    public const LEASE_CANCELLED_CLASS='lease_cancelled';
    public const LEASE_CANCELLED_OUTCOMES=array('cancelled','suppressed','expired');
    /** The closed, channel-neutral terminal-reason vocabulary (§6.6). */
    public const TERMINAL_REASONS=array('contact_unusable','send_refused','no_route');
    /** The two exhaustion codes, written on the notification alone (§6.6/§9). */
    public const CEILING_EXHAUSTION_CODE='retry_exhausted';
    public const WINDOW_EXHAUSTION_CODE='retry_window_exhausted';
    /**
     * §6.6 — the acknowledgement's single normalised member: the closed attempt state `acknowledged` and,
     * identically, the `outcome_code` the acknowledgement writes. It is the one closure that carries **no**
     * `failure_class` at all — the port accepted the hand-off, so there is no failure to classify and no
     * retry schedule to derive.
     */
    public const ACKNOWLEDGED_OUTCOME='acknowledged';
    /** The event type appended when a closure re-arms the outbox row (§9). */
    public const RETRY_SCHEDULED_EVENT='retry_scheduled';

    /**
     * §6.4 — the canonical rendered-template variable contract.
     *
     * A template version freezes the sorted, deduplicated variable codes it requires as canonical
     * comma-separated text; the digest is derived from exactly that text and the required count from the
     * same list, so a snapshot can never claim a code set that is not the frozen contract.
     */
    public const VARIABLE_CODE_PATTERN='/^[a-z][a-z0-9_]{0,63}$/';
    public const VARIABLE_CONTRACT_MAX=191;
    public const VARIABLE_CONTRACT_PREFIX='variable_contract:';
    public const PARAMS_DIGEST_PREFIX='params:';

    /** §6.5 notification aggregate states and the legal transition table. */
    public const NOTIFICATION_STATES=array('pending','scheduled','queued','dispatching','dispatched','delivered','closed','suppressed','cancelled','expired','failed');
    public const NOTIFICATION_TERMINAL_STATES=array('closed','suppressed','cancelled','expired','failed');
    public const NOTIFICATION_CLAIMABLE_STATES=array('queued');
    public const NOTIFICATION_EVENT_TYPES=array('observed','scheduled','queued','dispatching','dispatched','delivered','closed','suppressed','cancelled','expired','failed',self::RETRY_SCHEDULED_EVENT,'deferred');
    public const NOTIFICATION_TRANSITIONS=array(
        '|pending','pending|scheduled','scheduled|queued','queued|dispatching','dispatching|dispatched',
        'dispatched|delivered','delivered|closed',
        'dispatching|queued',
        'pending|suppressed','scheduled|suppressed','queued|suppressed','dispatching|suppressed',
        'pending|cancelled','scheduled|cancelled','queued|cancelled','dispatching|cancelled',
        'pending|expired','scheduled|expired','queued|expired','dispatching|expired',
        'pending|failed','scheduled|failed','queued|failed','dispatching|failed',
    );

    /** §6.6 attempt lifecycle. */
    public const ATTEMPT_STATES=array('leased','handed_off','acknowledged','failed','expired','abandoned');
    public const ATTEMPT_EVENT_TYPES=array('leased','handed_off','acknowledged','failed','expired','abandoned',self::RETRY_SCHEDULED_EVENT);
    public const ATTEMPT_TRANSITIONS=array(
        '|leased','leased|handed_off','handed_off|acknowledged','handed_off|failed',
        'leased|expired','leased|failed','leased|abandoned','handed_off|abandoned',
    );
    /** The two attempt states in which the row is still open (`finished_at IS NULL`), and its complements. */
    public const ATTEMPT_OPEN_STATES=array('leased','handed_off');
    public const ATTEMPT_CLOSED_STATES=array('acknowledged','failed','expired','abandoned');

    /** §6.7 delivery lifecycle and its fixed monotonic rank. */
    public const DELIVERY_STATES=array('accepted','sent','delivered','undelivered','failed','expired');
    public const DELIVERY_TERMINAL_STATES=array('delivered','undelivered','failed','expired');
    public const DELIVERY_RANKS=array('accepted'=>1,'sent'=>2,'delivered'=>3,'undelivered'=>4,'failed'=>5,'expired'=>6);
    public const DELIVERY_APPLIED=1;
    public const DELIVERY_NOT_APPLIED=0;

    /** §6.8 suppression. */
    public const SUPPRESSION_STATES=array('active','released');
    public const SUPPRESSION_EVENT_TYPES=array('suppressed','released');
    public const SUPPRESSION_TRANSITIONS=array('|active','active|released');

    /** §6.4 rendered-snapshot direction (never a provider template name, id or language tag). */
    public const TEMPLATE_DIRECTIONS=array('outbound');
    public const CIPHER_VERSIONS=array('sodium_secretbox_v1','aes_256_gcm_v1');

    /** §9 command domains, and the replay outcome a same-key/different-payload command produces. */
    public const COMMAND_DOMAINS=array('notification_workflow','notification_template','notification','notification_suppression','notification_privacy');
    public const REPLAY_CONFLICT='notification_replay_conflict';

    /** §14 — the fixed, channel-independent diagnostic vocabulary. */
    public const DIAGNOSTICS=array(
        'unregistered_intent','unroutable_intent','orchestration_backlog','stuck_lease','expired_lease',
        'orphan_outbox_row','workflow_version_integrity','workflow_rule_set_mutated','intent_routing_conflict',
        'template_variable_mismatch','eligibility_rule_set_incomplete','eligibility_binding_mismatch',
        'audience_not_authorised','ineligible_subject_state','consent_absent','recipient_unresolved',
        'suppressed','retry_schedule_divergence','retry_policy_invalid','retry_exhausted',
        'retry_window_exhausted','terminal_reason_invalid','retry_exhaustion_invalid',
        'retry_window_exhaustion_invalid','intent_unbound','tier_f_instant_unavailable',
        'tier_f_instant_divergence','schedule_expiry_missing','schedule_composition_invalid',
        'schedule_timezone_unresolved','schedule_timezone_basis_conflict','schedule_derivation_divergence',
        'eligibility_abort_invalid','delivery_regression_attempt','delivery_event_stale',
        'attempt_lifecycle_invalid','lease_cancellation_invalid',
        'envelope_decrypt_failure','retention_overdue',
    );

    /**
     * §15 — the closed failure vocabulary every service, verifier and diagnostic shares. A refusal is
     * always reported by one of these codes, never by free text and never by a provider status string.
     */
    public const FAILURE_VOCABULARY=array(
        'eligibility_rule_set_incomplete','eligibility_binding_mismatch','audience_not_authorised',
        'intent_unbound','schedule_expiry_missing','schedule_composition_invalid','schedule_timezone_unresolved',
        'schedule_timezone_basis_conflict','schedule_derivation_divergence','retry_policy_invalid',
        'tier_f_instant_unavailable','tier_f_instant_divergence','terminal_reason_invalid',
        'retry_exhaustion_invalid','retry_window_exhaustion_invalid','retry_schedule_divergence',
        'eligibility_abort_invalid','lease_cancellation_invalid','attempt_lifecycle_invalid',
        'notification_attempt_state_conflict',
        'workflow_rules_frozen','workflow_rule_set_mutated','workflow_version_integrity','intent_routing_conflict',
        'template_variable_mismatch','notification_replay_conflict','notification_dispatch_in_flight',
        'envelope_decrypt_failure','invalid_notification_state','notification_not_found','workflow_not_found',
        'invalid_workflow_state','invalid_version_state','unregistered_intent','unroutable_intent',
        'unsupported_intent','ineligible_subject_state','eligibility_expired','lead_time_insufficient',
        'recipient_unresolved','consent_absent','authority_absent','suppressed','eligibility_unresolved',
    );

    /** Capability names (§3/§8.1). Read and write capabilities are separate, and dispatch is separate again. */
    public const CAPABILITIES=array(
        'dzn_manage_notification_workflows','dzn_manage_notification_templates','dzn_manage_notifications',
        'dzn_operate_notification_dispatch','dzn_manage_notification_suppressions','dzn_manage_notification_privacy',
    );
    public const READ_CAPABILITY='dzn_view_notification_authority';

    /** §7.1 — the additive `platform_outbox` columns and indexes migration 027 declares. */
    public const OUTBOX_ADDED_COLUMNS=array(
        'notification_id','workflow_key','workflow_version','intent_key','audience','scheduled_for',
        'expires_at','deferral_count','priority','lease_token_digest','failure_reason_code',
    );
    public const OUTBOX_ADDED_INDEXES=array(
        array('notification_id',true,array('notification_id')),
        array('dispatch',false,array('status','scheduled_for','available_at')),
        array('intent_version',false,array('intent_key','workflow_version')),
    );
    /** The status superset §12 requires: the new vocabulary plus the existing Phase-1 `prepared`. */
    public const OUTBOX_STATUS_VOCABULARY=array('pending','scheduled','queued','leased','dispatched','delivered','failed','cancelled','expired','suppressed','prepared');

    /** §7.2 — the eighteen S-owned tables migration 027 declares, each exactly once. */
    public const OWNED_TABLES=array(
        'notification_workflows','notification_workflow_versions','notification_workflow_rules',
        'notification_workflow_commands','notification_templates','notification_template_versions',
        'notification_template_commands','notification_rendered_snapshots','notifications',
        'notification_events','notification_commands','notification_attempts','notification_attempt_events',
        'notification_deliveries','notification_suppressions','notification_suppression_events',
        'notification_suppression_commands','notification_privacy_tombstones',
    );
    /** The append-only tables: they never carry `updated_at` and never gain one (§7.2/§7.3). */
    public const APPEND_ONLY_TABLES=array(
        'notification_workflow_rules','notification_workflow_commands','notification_template_versions',
        'notification_template_commands','notification_rendered_snapshots','notification_events',
        'notification_commands','notification_attempt_events','notification_deliveries',
        'notification_suppression_events','notification_suppression_commands','notification_privacy_tombstones',
    );
    /** The immutable digest-only command tables. */
    public const COMMAND_TABLES=array(
        'notification_workflow_commands','notification_template_commands','notification_commands',
        'notification_suppression_commands',
    );

    public const MIGRATION_ID='032_notification_communications_authority';
    public const MIGRATION_INSTALLER='install_notification_communications_authority';
    public const MIGRATION_VERIFIER='verify_notification_communications_schema';

    /** The subject aggregate of an intent, or null for the reserved-unbound one. */
    public static function binding(string $intent):?array{
        if(!in_array($intent,self::INTENTS,true))throw new \InvalidArgumentException('unsupported_intent');
        return self::BINDING_MATRIX[$intent]??null;
    }
    /** The §6.2.2 binding of an intent, refusing the reserved-unbound one with its own code. */
    public static function requiredBinding(string $intent):array{
        $binding=self::binding($intent);
        if($binding===null)throw new \InvalidArgumentException('intent_unbound');
        return $binding;
    }
    public static function intentTier(string $intent):string{return self::requiredBinding($intent)['tier'];}
    public static function boundAggregate(string $intent):string{return self::requiredBinding($intent)['aggregate'];}
    public static function boundEventType(string $intent):string{return self::requiredBinding($intent)['event_type'];}
    public static function boundAllowlist(string $intent):array{return self::requiredBinding($intent)['to_states'];}
    public static function boundTransitions(string $intent):array{return self::requiredBinding($intent)['transitions'];}
    public static function tierFInstantColumn(string $intent):?string{return self::requiredBinding($intent)['instant'];}
    public static function tierFIntents():array{return array_values(array_filter(self::INTENTS,static fn(string $intent):bool=>self::BINDING_MATRIX[$intent]['tier']==='F'));}
    public static function registeredIntent(string $intent):bool{return in_array($intent,self::INTENTS,true);}
    public static function unboundIntent(string $intent):bool{return in_array($intent,self::UNBOUND_INTENTS,true);}
    /** §6.2.1: the complete required eligibility code set of one intent/audience/recipient-kind triple. */
    public static function requiredEligibilityCodes(string $intent,string $audience,string $recipientKind):array{
        self::assertAuthorisedPair($audience,$recipientKind);
        $tier=self::intentTier($intent);
        return array_merge(self::MANDATORY_BASELINE,self::TIER_RULES[$tier]);
    }
    public static function assertAuthorisedPair(string $audience,string $recipientKind):void{
        if(!in_array(array($audience,$recipientKind),self::AUTHORISED_PAIRS,true))throw new \InvalidArgumentException('audience_not_authorised');
    }
    public static function authorisedPair(string $audience,string $recipientKind):bool{
        return in_array(array($audience,$recipientKind),self::AUTHORISED_PAIRS,true);
    }
    /** The canonical parameter list of one schedule code, keyed by ordinal. */
    public static function scheduleParameters(string $code):array{
        if(!array_key_exists($code,self::SCHEDULE_CODES))throw new \InvalidArgumentException('schedule_composition_invalid');
        return self::SCHEDULE_CODES[$code];
    }
    public static function scheduleCode(string $code):bool{return array_key_exists($code,self::SCHEDULE_CODES);}
    public static function timezoneSensitive(string $code):bool{return in_array($code,self::TIMEZONE_SENSITIVE_CODES,true);}
    public static function timezoneBasis(string $basis):bool{return in_array($basis,self::TIMEZONE_BASES,true);}
    /** The canonical retry parameter list, keyed by ordinal, and the class baseline it narrows. */
    public static function retryParameters():array{return self::RETRY_PARAMETERS;}
    public static function retryBaseline():array{return self::RETRY_BASELINE;}
    public static function terminalReason(string $code):bool{return in_array($code,self::TERMINAL_REASONS,true);}
    public static function nonTerminalClosureCode(string $failureClass):?string{return self::NON_TERMINAL_CLOSURE_CODES[$failureClass]??null;}
    /** The persisted closure class vocabulary: the three caller-declarable classes plus the two audited ones. */
    public static function closureClass(string $failureClass):bool{
        return in_array($failureClass,self::FAILURE_CLASSES,true)
            ||$failureClass===self::ELIGIBILITY_ABORT_CLASS
            ||$failureClass===self::LEASE_CANCELLED_CLASS;
    }
    /** §6.6 — the closed fail-closed refusal set an eligibility-abort closure may carry. */
    public static function eligibilityAbortOutcome(string $code):bool{return in_array($code,self::ELIGIBILITY_ABORT_OUTCOMES,true);}
    /** §6.6 — the closed terminal-state set a lease-cancellation closure may carry identically. */
    public static function leaseCancellationOutcome(string $code):bool{return in_array($code,self::LEASE_CANCELLED_OUTCOMES,true);}
    /**
     * §6.5/§6.8 — the controlled terminal state one fail-closed refusal maps to. The refusal path, the
     * claim refusal and the closure verifier all read this one mapping, so a notification's control state
     * can never disagree with the code that closed it.
     */
    public static function controlledState(string $code):string{
        if(in_array($code,array('eligibility_expired','lead_time_insufficient'),true))return 'expired';
        if($code==='suppressed')return 'suppressed';
        return 'failed';
    }
    public static function notificationState(string $state):bool{return in_array($state,self::NOTIFICATION_STATES,true);}
    public static function terminalNotificationState(string $state):bool{return in_array($state,self::NOTIFICATION_TERMINAL_STATES,true);}
    public static function deliveryRank(string $state):?int{return self::DELIVERY_RANKS[$state]??null;}
    public static function diagnostic(string $code):bool{return in_array($code,self::DIAGNOSTICS,true);}
    public static function failureCode(string $code):bool{return in_array($code,self::FAILURE_VOCABULARY,true);}
    public static function capability(string $capability):bool{return in_array($capability,self::CAPABILITIES,true);}
    public static function legalNotificationTransition(?string $from,string $to):bool{return in_array(($from??'').'|'.$to,self::NOTIFICATION_TRANSITIONS,true);}
    public static function legalAttemptTransition(?string $from,string $to):bool{return in_array(($from??'').'|'.$to,self::ATTEMPT_TRANSITIONS,true);}
    public static function legalSuppressionTransition(?string $from,string $to):bool{return in_array(($from??'').'|'.$to,self::SUPPRESSION_TRANSITIONS,true);}
}
