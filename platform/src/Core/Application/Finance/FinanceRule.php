<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Locked Phase 2A.2-U finance vocabulary and structural constants (contract §5).
 *
 * This class is the phase's single vocabulary source. A disposition, a basis code, a scope kind, a
 * compensation basis, a statement state, a statement event type, a correction kind, a finding code or
 * a reason code never comes from a caller: `FinanceRule::REASON_CODES` is the one allowlist, the two
 * declared views over it own the exception and finding rows, and the derivation table below is the
 * single place a payability disposition is decided.
 */
final class FinanceRule {
    /** The declared command domain recorded on every `finance_*_commands` row. */
    public const DOMAIN='finance_v1';

    /** The derivation version that binds the §5.3 payability table. */
    public const PAYABILITY_DERIVATION_VERSION='finance_payability_v1';
    /** The rule version recorded on every immutable per-Lesson snapshot. */
    public const SNAPSHOT_RULE_VERSION='finance_snapshot_v1';
    /** The rule version recorded on every statement. */
    public const STATEMENT_RULE_VERSION='finance_statement_v1';
    /** The rule version recorded on every reconciliation run. */
    public const RECONCILIATION_RULE_VERSION='finance_reconciliation_v1';
    /** U-D4: a snapshot is taken at the recorded occurrence start, never at a wall clock. */
    public const SNAPSHOT_BOUNDARY='occurrence_start';
    /** U-D13/§10.1: a statement covers at most 62 days. */
    public const MAX_STATEMENT_PERIOD_DAYS=62;
    /** U-D13: one statement carries exactly one currency. */
    public const STATEMENT_CURRENCY_RULE='single_currency';
    /** U-D1: every amount is an exact integer number of minor units. */
    public const AMOUNT_EXACTNESS='exact_integer';
    /** §10.1: the only source of a statement's human-facing timezone is the recorded policy. */
    public const FINANCE_TIMEZONE_SOURCE='recorded_policy';
    /** §6.3/U-D19: the single declared constant that binds the policy admissibility guard. */
    public const POLICY_ADMISSIBILITY_RULE='later_than_recorded_consumption';

    /**
     * §15.3/§15.6/§15.8: the declared `result_state` of every `finance_*_commands` row.
     *
     * Every command table's `result_state` is `NOT NULL`, so a command that inserts its result row
     * without a declared state rolls back at insertion, and a refusal whose evidence row is written
     * without the one declared refusal state cannot meet §15.8. One success state is therefore declared
     * per mutating operation, plus the single declared refusal state every business refusal records.
     */
    public const COMMAND_SUCCESS_STATES=array(
        'record'=>'recorded','capture'=>'recorded','correct_snapshot'=>'recorded','evaluate'=>'recorded',
        'override'=>'recorded','draft'=>'recorded','resolve_exception'=>'recorded','run'=>'completed',
        'close'=>'closed','issue'=>'issued','supersede'=>'superseded','withdraw'=>'withdrawn',
    );
    /** §15.8: the one declared state of a refused command row, with its `NULL` typed result and reason. */
    public const COMMAND_REFUSAL_STATE='refused';
    /** §15.8: a run that cannot hydrate its scope is recorded `failed` rather than silently skipped. */
    public const COMMAND_FAILED_STATE='failed';
    /** Every state a command table row may carry: the declared successes plus the two terminal states. */
    public const COMMAND_RESULT_STATES=array('recorded','completed','closed','issued','superseded','withdrawn','failed','refused');

    /** §15.3: the declared success state of one mutating Finance operation. */
    public static function commandSuccessState(string $operation):string{
        if(!isset(self::COMMAND_SUCCESS_STATES[$operation]))throw new \InvalidArgumentException('finance_vocabulary_member_not_allowed');
        return self::COMMAND_SUCCESS_STATES[$operation];
    }
    /** §15.3/§15.8: a recorded command state is always one of the declared members. */
    public static function commandResultState(string $state):bool{
        return in_array($state,self::COMMAND_RESULT_STATES,true);
    }
    /**
     * §15.3: every declared non-refusal outcome state of one mutating operation.
     *
     * A recorded command outcome is the operation's *one* declared success state, or — only for the
     * reconciliation `run` that could not hydrate its scope — the declared `failed` state. The one
     * refusal state is never an outcome this helper returns: §15.8 owns it, and a replayed refusal
     * converges on its refusal rather than being presented as a successful result.
     */
    public static function commandOutcomeStates(string $operation):array{
        $success=self::commandSuccessState($operation);
        return $operation==='run'?array($success,self::COMMAND_FAILED_STATE):array($success);
    }

    /**
     * §15.3: the typed `result_*` columns each declared command table carries.
     *
     * Every command table declares only typed result references and no polymorphic `result_id`, so the
     * exact shape of a recorded command's result is declarable: an operation records the typed results
     * below and leaves every other typed result column of its own table `NULL`.
     */
    public const COMMAND_RESULT_COLUMNS=array(
        'finance_policy_commands'=>array('result_policy_id'),
        'finance_teacher_rate_commands'=>array('result_rate_id'),
        'finance_snapshot_commands'=>array('result_snapshot_id','result_correction_id'),
        'finance_payability_commands'=>array('result_evaluation_id','result_override_id'),
        'finance_statement_commands'=>array('result_statement_id'),
        'finance_reconciliation_commands'=>array('result_run_id','result_exception_id'),
    );

    /**
     * §15.3: the exact typed result fields each operation records, and the ones it must never carry.
     *
     * The declaration is keyed by command table because three operation names (`record`, `supersede`,
     * `withdraw`) exist on more than one table with a different typed result. A replay may only report the
     * typed result shape its own operation declared: a required typed result that is absent, or any other
     * typed result column of the same table carrying a value, fails closed (`command_replay_conflict`) so
     * a corrupted command row can never smuggle an unrelated override, correction, run or exception into a
     * successful replay of another operation.
     */
    public const COMMAND_OPERATION_RESULTS=array(
        'finance_policy_commands'=>array(
            'record'=>array('result_policy_id'),'supersede'=>array('result_policy_id'),'withdraw'=>array('result_policy_id'),
        ),
        'finance_teacher_rate_commands'=>array(
            'record'=>array('result_rate_id'),'close'=>array('result_rate_id'),'withdraw'=>array('result_rate_id'),
        ),
        'finance_snapshot_commands'=>array(
            'capture'=>array('result_snapshot_id'),
            'correct_snapshot'=>array('result_snapshot_id','result_correction_id'),
        ),
        'finance_payability_commands'=>array(
            'evaluate'=>array('result_evaluation_id'),
            'override'=>array('result_evaluation_id','result_override_id'),
        ),
        'finance_statement_commands'=>array(
            'draft'=>array('result_statement_id'),'issue'=>array('result_statement_id'),
            'withdraw'=>array('result_statement_id'),'supersede'=>array('result_statement_id'),
        ),
        'finance_reconciliation_commands'=>array(
            'run'=>array('result_run_id'),
            'resolve_exception'=>array('result_exception_id'),
        ),
    );

    /** §15.3: the typed result columns of one declared command table, or an empty set if undeclared. */
    public static function commandResultColumns(string $commandTable):array{
        return self::COMMAND_RESULT_COLUMNS[$commandTable]??array();
    }
    /** §15.3: the exact typed result columns one command operation records, or `null` if undeclared. */
    public static function commandOperationResults(string $commandTable,string $operation):?array{
        if(!in_array($commandTable,self::TABLES,true))throw new \InvalidArgumentException('Declared Finance command table required');
        return self::COMMAND_OPERATION_RESULTS[$commandTable][$operation]??null;
    }
    /** U-D18: the evidence channels a Finance command may record. */
    public const EVIDENCE_CHANNELS=array('staff_record','authenticated_platform','document_reference');
    /** §11.2: reconciliation finding severities. */
    public const SEVERITIES=array('informational','blocking');
    /** §13.2: a `finance_reconciliation_runs` row state. */
    public const RECONCILIATION_RUN_STATES=array('completed','failed');
    /** §13.2: a `finance_exceptions` row state. */
    public const EXCEPTION_STATES=array('open','resolved');
    /** §13.2: the one declared root key of the global policy serialisation root. */
    public const POLICY_ROOT_KEY='finance_policy';

    /** §5.2 payability disposition. */
    public const DISPOSITIONS=array('payable','non_payable','pending');
    /** §5.2 payability basis. */
    public const BASIS_CODES=array('delivered_occurrence','student_no_show','interruption','teacher_non_delivery','academy_obligation','delivery_review_required','occurrence_not_attempted','lesson_not_finalised','introductory_policy_non_payable','administrator_override');
    /** §5.2 teacher rate scope. */
    public const RATE_SCOPE_KINDS=array('teacher','teacher_course');
    /** §5.2 teacher rate status. */
    public const RATE_STATES=array('active','superseded','withdrawn');
    /** §5.2 compensation basis: `per_session` is the only member this phase may record. */
    public const COMPENSATION_BASES=array('per_session');
    /** §5.2 statement state. */
    public const STATEMENT_STATES=array('draft','issued','superseded','withdrawn');
    /** §5.2 statement event type. */
    public const STATEMENT_EVENT_TYPES=array('drafted','issued','superseded','withdrawn');
    /** §5.2 correction kind. */
    public const CORRECTION_KINDS=array('snapshot_correction','payability_override','statement_supersession');
    /** §7.2 teacher-rate event type. */
    public const RATE_EVENT_TYPES=array('closed','superseded','withdrawn');
    /** §13.2 policy value types. */
    public const POLICY_VALUE_TYPES=array('policy_reference','timezone');

    /** §6.1: only these four keys may exist. */
    public const POLICY_KEYS=array('INTRO_PAYABILITY_POLICY','STUDENT_NO_SHOW_COMPENSATION_POLICY','INTERRUPTION_COMPENSATION_POLICY','FINANCE_STATEMENT_TIMEZONE');
    /** §6.1/§13.5: the three keys the migration seeds with their declared default. */
    public const SEEDED_POLICIES=array(
        'INTRO_PAYABILITY_POLICY'=>array('policy_value'=>'non_payable','value_type'=>'policy_reference'),
        'STUDENT_NO_SHOW_COMPENSATION_POLICY'=>array('policy_value'=>'payable','value_type'=>'policy_reference'),
        'INTERRUPTION_COMPENSATION_POLICY'=>array('policy_value'=>'payable','value_type'=>'policy_reference'),
    );
    /** §6.1/§13.5: the one key that is deliberately never seeded. */
    public const UNSET_POLICY_KEY='FINANCE_STATEMENT_TIMEZONE';

    /** §17: the only three notification intents this phase ever publishes. */
    public const OUTBOX_INTENTS=array('TEACHER_STATEMENT_ISSUED','TEACHER_STATEMENT_SUPERSEDED','FINANCE_RECONCILIATION_EXCEPTION_RAISED');
    /** §17: the declared Finance aggregate each intent names. */
    public const INTENT_AGGREGATES=array(
        'TEACHER_STATEMENT_ISSUED'=>'finance_statements',
        'TEACHER_STATEMENT_SUPERSEDED'=>'finance_statements',
        'FINANCE_RECONCILIATION_EXCEPTION_RAISED'=>'finance_reconciliation_runs',
    );

    /** §13.1: the twenty-one declared Finance tables. */
    public const TABLES=array('finance_policy_roots','finance_teacher_roots','finance_policies','finance_policy_commands','finance_teacher_rates','finance_teacher_rate_events','finance_teacher_rate_commands','finance_lesson_snapshots','finance_snapshot_corrections','finance_snapshot_commands','finance_payability_evaluations','finance_payability_overrides','finance_payability_commands','finance_statements','finance_statement_lines','finance_statement_events','finance_statement_commands','finance_reconciliation_runs','finance_reconciliation_findings','finance_reconciliation_commands','finance_exceptions');
    /** §13.1: the five tables addressed by a stable public handle. */
    public const HANDLE_TABLES=array('finance_teacher_rates','finance_lesson_snapshots','finance_statements','finance_reconciliation_runs','finance_exceptions');
    /** §15.7: the two pre-existing infrastructure seams this phase may write, insert-only. */
    public const INFRASTRUCTURE_TABLES=array('platform_audit_events','platform_outbox');

    /**
     * §8.1/§8.3: the declared digest field order of a per-Lesson snapshot.
     *
     * The digest is recomputed on every read and on every statement derivation, so a snapshot whose
     * recorded facts no longer match the inputs it named fails closed with `snapshot_derivation_mismatch`.
     */
    public const SNAPSHOT_DIGEST_FIELDS=array('lesson_id','enrolment_id','term_id','teacher_id','teacher_assignment_id','course_id','lesson_kind','schedule_version_id','occurrence_starts_at_utc','occurrence_ends_at_utc','duration_minutes','snapshot_instant_utc','snapshot_boundary','rate_id','rate_version','scope_kind','compensation_basis','rate_amount_minor','currency','derived_amount_minor','intro_policy_key','intro_policy_version');
    /** §9.1: the declared digest field order of a payability evaluation. */
    public const EVALUATION_DIGEST_FIELDS=array('lesson_id','disposition','basis_code','lesson_kind','delivery_outcome_id','delivery_state','attendance_state','remedy_class','academy_obligation_id','schedule_version_id','snapshot_id','override_id','policy_key','policy_version');
    /** §10.2: the declared digest field order of a statement line, and of the statement over its lines. */
    public const STATEMENT_LINE_DIGEST_FIELDS=array('lesson_id','disposition','basis_code','snapshot_id','snapshot_correction_id','payability_evaluation_id','rate_id','rate_version','line_amount_minor','currency');
    /** §12.2: the declared digest field order of a snapshot correction. */
    public const CORRECTION_DIGEST_FIELDS=array('snapshot_id','lesson_id','corrected_rate_id','corrected_rate_version','corrected_rate_amount_minor','corrected_currency','corrected_derived_amount_minor','intro_policy_key','intro_policy_version','prior_snapshot_digest');

    /**
     * Durable exception and refusal reasons (contract §5.2.1, 43 members).
     *
     * Written to `finance_exceptions.reason_code`, to every refused command result, and to a
     * chain/event `reason_code` that records a refusal.
     */
    public const EXCEPTION_REASON_CODES=array(
        'finance_vocabulary_member_not_allowed','finance_parent_not_declared','finance_parent_not_live',
        'finance_policy_key_not_allowed','finance_policy_version_conflict','finance_policy_effective_from_missing',
        'finance_policy_value_type_invalid','finance_policy_timeline_overlap','policy_effective_from_precedes_recorded_consumption',
        'finance_policy_unset','policy_unset_for_statement_period','rate_missing_for_lesson','ambiguous_teacher_rate',
        'teacher_rate_timeline_overlap','teacher_rate_timeline_gap','teacher_rate_state_not_resolvable','rate_scope_violation',
        'rate_referenced_by_snapshot','rate_effective_from_precedes_snapshot','occurrence_anchor_missing',
        'finance_lesson_kind_not_allowed','finance_amount_not_exact','snapshot_lesson_not_finalised','snapshot_missing_for_lesson',
        'snapshot_derivation_mismatch','snapshot_correction_incomplete','command_replay_conflict','payability_pending',
        'payability_conflicts_with_delivery_fact','payability_supersession_conflict','payability_override_target_invalid',
        'statement_period_too_long','statement_period_not_elapsed','statement_period_overlap','statement_state_transition_conflict',
        'statement_supersession_required','statement_totals_mismatch','statement_derivation_mismatch',
        'statement_timezone_representation_invalid','lesson_stated_twice','lesson_not_finalised_in_period',
        'currency_mismatch_for_statement','upstream_aggregate_invalid',
    );

    /** Reconciliation finding codes written to `finance_reconciliation_findings.finding_code` (contract §5.2.1, 21 members). */
    public const FINDING_CODES=array(
        'lesson_missing_from_statement','lesson_stated_twice','lesson_not_finalised_in_period',
        'line_amount_differs_from_recomputation','statement_totals_mismatch','statement_derivation_mismatch',
        'snapshot_missing_for_lesson','snapshot_derivation_mismatch','rate_missing_for_snapshot',
        'teacher_rate_timeline_overlap','teacher_rate_timeline_gap','payability_pending',
        'payability_conflicts_with_delivery_fact','currency_mismatch_for_statement','statement_period_overlap',
        'lesson_archived_after_issue','delivery_outcome_changed_after_issue','snapshot_corrected_after_issue',
        'override_applied','legacy_flag_differs','provider_evidence_unmatched',
    );

    /** Operator-supplied and migration reasons (§5.2.1, 4 members): a stated reason, never an exception. */
    public const OPERATOR_REASON_CODES=array('phase_u_declared_default','operator_recorded_error','operator_decision','operator_evidence_correction');

    /** The twelve members deliberately shared by the exception and finding views (§5.2.1). */
    public const SHARED_REASON_CODES=array('lesson_stated_twice','lesson_not_finalised_in_period','statement_totals_mismatch','statement_derivation_mismatch','snapshot_missing_for_lesson','snapshot_derivation_mismatch','payability_pending','payability_conflicts_with_delivery_fact','currency_mismatch_for_statement','statement_period_overlap','teacher_rate_timeline_overlap','teacher_rate_timeline_gap');

    /**
     * §5.3: the ordered payability derivation table.
     *
     * Each row declares the canonical fact that decides it, the disposition, the basis code, whether it
     * blocks statement issuance and — for the three policy-governed rows — the policy key whose recorded
     * version must be resolved *at the snapshot instant* before the row may decide. The table is
     * evaluated in this order and the first matching row decides; an audited override is the only thing
     * that may replace that verdict.
     */
    public const PAYABILITY_DERIVATION=array(
        array('row'=>1,'fact'=>'academy_obligation','disposition'=>'non_payable','basis_code'=>'academy_obligation','blocks_issue'=>false,'policy_key'=>null),
        array('row'=>2,'fact'=>'not_finalised_with_snapshot','disposition'=>'pending','basis_code'=>'lesson_not_finalised','blocks_issue'=>true,'policy_key'=>null),
        array('row'=>3,'fact'=>'outcome_review_required','disposition'=>'pending','basis_code'=>'delivery_review_required','blocks_issue'=>true,'policy_key'=>null),
        array('row'=>4,'fact'=>'outcome_teacher_non_delivery','disposition'=>'non_payable','basis_code'=>'teacher_non_delivery','blocks_issue'=>false,'policy_key'=>null),
        array('row'=>5,'fact'=>'introductory_non_payable','disposition'=>'non_payable','basis_code'=>'introductory_policy_non_payable','blocks_issue'=>false,'policy_key'=>'INTRO_PAYABILITY_POLICY'),
        array('row'=>6,'fact'=>'completed_without_outcome','disposition'=>'payable','basis_code'=>'delivered_occurrence','blocks_issue'=>false,'policy_key'=>null),
        array('row'=>7,'fact'=>'outcome_delivered','disposition'=>'payable','basis_code'=>'delivered_occurrence','blocks_issue'=>false,'policy_key'=>null),
        array('row'=>8,'fact'=>'outcome_student_no_show','disposition'=>'policy','basis_code'=>'student_no_show','blocks_issue'=>false,'policy_key'=>'STUDENT_NO_SHOW_COMPENSATION_POLICY'),
        array('row'=>9,'fact'=>'outcome_interruption','disposition'=>'policy','basis_code'=>'interruption','blocks_issue'=>false,'policy_key'=>'INTERRUPTION_COMPENSATION_POLICY'),
        array('row'=>10,'fact'=>'cancelled_before_occurrence','disposition'=>'non_payable','basis_code'=>'occurrence_not_attempted','blocks_issue'=>false,'policy_key'=>null),
        array('row'=>11,'fact'=>'administrator_override','disposition'=>'override','basis_code'=>'administrator_override','blocks_issue'=>false,'policy_key'=>null),
    );

    /** The two policy-governed compensation keys and the recorded value each accepts. */
    public const COMPENSATION_POLICY_VALUES=array(
        'STUDENT_NO_SHOW_COMPENSATION_POLICY'=>array('payable','non_payable'),
        'INTERRUPTION_COMPENSATION_POLICY'=>array('payable','non_payable'),
    );

    /** §5.2/§5.4: `FinanceRule::REASON_CODES` is exactly the union of the three declared sets. */
    public static function reasonCodes():array{
        return array_values(array_unique(array_merge(self::EXCEPTION_REASON_CODES,self::FINDING_CODES,self::OPERATOR_REASON_CODES)));
    }
    /** §5.4: a refusal/blocker/exception code is a member of the exception view. */
    public static function exceptionReason(string $code):bool{
        return in_array($code,self::EXCEPTION_REASON_CODES,true);
    }
    /** §5.4: a reconciliation difference is a member of the finding view. */
    public static function findingCode(string $code):bool{
        return in_array($code,self::FINDING_CODES,true);
    }
    /** §5.4: an operator-stated reason is a member of the operator set. */
    public static function operatorReason(string $code):bool{
        return in_array($code,self::OPERATOR_REASON_CODES,true);
    }
    /** §5.4: any durable reason this phase records is a member of `REASON_CODES`. */
    public static function reasonCode(string $code):bool{
        return in_array($code,self::reasonCodes(),true);
    }
    /** §5.2/§5.4: a controlled vocabulary member check. */
    public static function member(string $code,array $vocabulary):bool{
        return in_array($code,$vocabulary,true);
    }
    /** §6.1: only the four declared keys exist. */
    public static function policyKey(string $key):bool{
        return in_array($key,self::POLICY_KEYS,true);
    }
    /** §7.1: the declared sentinel a `teacher`-scoped rate carries in `course_scope_id`. */
    public static function scopeValid(string $scopeKind,int $courseScopeId):bool{
        if($scopeKind==='teacher')return $courseScopeId===0;
        if($scopeKind==='teacher_course')return $courseScopeId>0;
        return false;
    }
    /** §10.1: a half-open statement period of at most 62 days. */
    public static function periodValid(string $startUtc,string $endUtc):bool{
        if(!self::utc($startUtc)||!self::utc($endUtc))return false;
        $start=strtotime($startUtc.' UTC');$end=strtotime($endUtc.' UTC');
        if($start===false||$end===false||$end<=$start)return false;
        return ($end-$start)<=self::MAX_STATEMENT_PERIOD_DAYS*86400;
    }
    /** §17: the declared intent names only. */
    public static function notificationIntent(string $intent):bool{
        return in_array($intent,self::OUTBOX_INTENTS,true);
    }
    /** A strict UTC `datetime` literal. */
    public static function utc(string $value):bool{
        if(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value)!==1)return false;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return $parsed!==false&&$parsed->format('Y-m-d H:i:s')===$value;
    }
    /** §10.1: an IANA timezone identifier the statement triple may record. */
    public static function timezone(string $value):bool{
        if($value===''||strlen($value)>64)return false;
        return in_array($value,\DateTimeZone::listIdentifiers(),true);
    }
}
