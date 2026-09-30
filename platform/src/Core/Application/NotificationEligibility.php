<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * §6.2 — S's eligibility authority: the closed required rule set and the bound-evidence predicate.
 *
 * The required set is the union of the mandatory baseline and the version's intent/audience tier, so a
 * version can never activate or dispatch with a narrower set than the matrix demands. `subject_state_is`
 * is an *evidence* predicate over the bound aggregate's append-only history — never a current-state
 * lookup — and its allowlist is derived from the §6.2.2 binding rather than authored by a version.
 */
final class NotificationEligibility {
    /**
     * Validate one version's complete required eligibility set against §6.2.1 and §6.2.2.
     *
     * @throws \InvalidArgumentException `audience_not_authorised`, `eligibility_rule_set_incomplete`
     *         or `eligibility_binding_mismatch`.
     */
    public static function validateRequiredSet(string $intent,string $audience,string $recipientKind,array $rows):array{
        // The audience pair is checked first: an unauthorised audience is refused as such, never as a
        // missing rule.
        if(!NotificationRule::authorisedPair($audience,$recipientKind))throw new \InvalidArgumentException('audience_not_authorised');
        $binding=NotificationRule::requiredBinding($intent);
        $required=NotificationRule::requiredEligibilityCodes($intent,$audience,$recipientKind);
        $seen=array();
        $validated=array();
        foreach($rows as $row){
            $kind=(string)($row['rule_kind']??'');
            $code=(string)($row['rule_code']??'');
            if($kind!=='eligibility'||!isset(NotificationRule::ELIGIBILITY_RULES[$code]))throw new \InvalidArgumentException('eligibility_rule_set_incomplete');
            if((int)($row['ordinal']??1)!==1)throw new \InvalidArgumentException('eligibility_rule_set_incomplete');
            if(isset($seen[$kind.'|'.$code]))throw new \InvalidArgumentException('eligibility_rule_set_incomplete');
            $seen[$kind.'|'.$code]=true;
            $validated[$code]=self::validateBinding($intent,$binding,$code,$row);
        }
        foreach($required as $code)if(!isset($seen['eligibility|'.$code]))throw new \InvalidArgumentException('eligibility_rule_set_incomplete');
        // A version may add rules, but nothing outside the closed vocabulary may appear at all: the loop
        // above already refuses an unknown code, and this check refuses a stray rule kind.
        foreach($validated as $code=>$rule){
            if(!in_array($code,$required,true)&&!isset(NotificationRule::ELIGIBILITY_RULES[$code]))throw new \InvalidArgumentException('eligibility_rule_set_incomplete');
        }
        return $validated;
    }
    /** §6.2.1 binding rules: a mandated rule bound to another aggregate, or a widened/narrowed allowlist. */
    private static function validateBinding(string $intent,array $binding,string $code,array $row):array{
        $aggregate=$binding['aggregate'];
        $parameterA=$row['parameter_a']??null;
        $parameterB=$row['parameter_b']??null;
        $parameterC=$row['parameter_c']??null;
        $parameterD=$row['parameter_d']??null;
        if($code==='subject_exists'||$code==='subject_state_is'){
            if((string)$parameterA!==$aggregate)throw new \InvalidArgumentException('eligibility_binding_mismatch');
        }elseif($parameterA!==null)throw new \InvalidArgumentException('eligibility_binding_mismatch');
        if($code==='subject_state_is'){
            // The declared allowlist must *equal* the matrix row's committed `to_state` set: empty,
            // widened, narrowed or rebound is refused, and a state outside the owning module's published
            // vocabulary is refused too (the vocabulary is the aggregate's own locked state table).
            $declared=self::allowlist((string)$parameterB);
            $expected=$binding['to_states'];
            sort($declared);sort($expected);
            if($declared!==$expected)throw new \InvalidArgumentException('eligibility_binding_mismatch');
            $vocabulary=RecurringRule::aggregateStates($aggregate);
            foreach($declared as $state)if(!in_array($state,$vocabulary,true))throw new \InvalidArgumentException('eligibility_binding_mismatch');
        }elseif($parameterB!==null)throw new \InvalidArgumentException('eligibility_binding_mismatch');
        if($code===NotificationRule::LEAD_TIME_RULE){
            if(!is_numeric($parameterC)||(int)$parameterC<0)throw new \InvalidArgumentException('eligibility_binding_mismatch');
        }elseif($parameterC!==null||$parameterD!==null)throw new \InvalidArgumentException('eligibility_binding_mismatch');
        return array(
            'rule_code'=>$code,
            'parameter_a'=>$code==='subject_exists'||$code==='subject_state_is'?$aggregate:null,
            'parameter_b'=>$code==='subject_state_is'?implode(',',$binding['to_states']):null,
            'parameter_c'=>$code===NotificationRule::LEAD_TIME_RULE?(int)$parameterC:null,
            'parameter_d'=>null,
        );
    }
    /** Parse a declared `to_state` allowlist into its members, refusing an empty or malformed one. */
    private static function allowlist(string $declared):array{
        $declared=trim($declared);
        if($declared==='')return array();
        $members=array_map('trim',explode(',',$declared));
        foreach($members as $member)if($member==='')return array();
        return array_values(array_unique($members));
    }
    /**
     * §6.2.1(b): a tier-F version's eligibility lead time may never exceed its §6.3 anchor rule's
     * `lead_time_minutes`, because the pair would then be self-contradictory.
     */
    public static function assertTierFLeadTime(array $validated,array $composition,string $tier):void{
        if($tier!=='F')return;
        $declared=$validated[NotificationRule::LEAD_TIME_RULE]['parameter_c']??null;
        if($declared===null)throw new \InvalidArgumentException('eligibility_rule_set_incomplete');
        if($composition['anchor']['code']!=='lead_time')throw new \InvalidArgumentException('schedule_composition_invalid');
        if((int)$declared>(int)$composition['anchor']['lead_time_minutes'])throw new \InvalidArgumentException('eligibility_binding_mismatch');
    }

    /**
     * §6.2.3 — resolve the bound evidence of one notification from the subject's append-only history.
     *
     * Returns `[event_type, from_state, to_state, occurred_at]` for the single recorded variant of the
     * intent's bound transition, or null when the bound fact is absent. The match is bounded by the
     * notification's own persisted `observed_at`, so a delayed observation and a delayed dispatch
     * evaluate the same immutable evidence; a later legal successor transition never invalidates it.
     */
    public static function boundEvidence(string $intent,int $aggregateId,string $observedAt):?array{
        $binding=NotificationRule::requiredBinding($intent);
        $aggregate=$binding['aggregate'];
        global $wpdb;$prefix=$wpdb->prefix.'dzn_';
        $table=$prefix.NotificationRule::SUBJECT_EVENT_TABLES[$aggregate];
        $column=NotificationRule::SUBJECT_EVENT_ID_COLUMNS[$aggregate];
        $observedSeconds=NotificationSupport::seconds($observedAt);
        if($observedSeconds===null)return null;
        foreach($binding['transitions'] as $transition){
            [$from,$to]=explode('|',$transition,2);
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT event_type,from_state,to_state,occurred_at FROM {$table} WHERE {$column}=%d AND event_type=%s AND COALESCE(from_state,'')=%s AND to_state=%s AND occurred_at<=%s ORDER BY event_sequence ASC LIMIT 1",
                $aggregateId,$binding['event_type'],$from,$to,$observedAt
            ));
            if($row){
                return array(
                    'event_type'=>(string)$row->event_type,
                    'from_state'=>$row->from_state===null?null:(string)$row->from_state,
                    'to_state'=>(string)$row->to_state,
                    'occurred_at'=>(string)$row->occurred_at,
                );
            }
        }
        return null;
    }
    /** The digest a frozen B2 decision records, reproduced from the subject history by a later reader. */
    public static function boundEvidenceDigest(string $intent,int $aggregateId,array $evidence):string{
        return NotificationSupport::boundEvidenceDigest(
            NotificationRule::boundAggregate($intent),$aggregateId,
            (string)$evidence['event_type'],$evidence['from_state']??null,(string)$evidence['to_state'],(string)$evidence['occurred_at']
        );
    }

    /**
     * Evaluate the frozen required set over already-resolved facts.
     *
     * The caller resolves each source through its own read port; this method applies the fail-closed
     * outcome of each rule and returns the first refusal code, or `pass`. Unreadable sources never
     * default to eligible, and an instant-based tier-F rule is always evaluated over persisted values.
     */
    public static function evaluate(array $validated,array $facts):array{
        if(isset($validated['subject_exists'])&&empty($facts['subject_exists']))return self::refusal('eligibility_unresolved');
        if(isset($validated['subject_state_is'])&&empty($facts['bound_evidence']))return self::refusal('ineligible_subject_state');
        if(isset($validated['recipient_resolvable'])&&empty($facts['recipient_resolvable']))return self::refusal('recipient_unresolved');
        if(isset($validated['recipient_opted_in'])&&empty($facts['recipient_opted_in']))return self::refusal('consent_absent');
        if(isset($validated['guardian_authority_present'])&&empty($facts['guardian_authority_present']))return self::refusal('authority_absent');
        if(isset($validated['not_suppressed'])&&!empty($facts['suppressed']))return self::refusal('suppressed');
        if(isset($validated['subject_instant_in_future'])){
            $scheduledFor=(string)($facts['scheduled_for']??'');
            $subjectInstant=(string)($facts['subject_instant']??'');
            if($scheduledFor===''||$subjectInstant==='')return self::refusal('eligibility_expired');
            // Both instants are persisted columns, so an unreadable one is a refusal rather than a zero.
            $scheduledSeconds=NotificationSupport::seconds($scheduledFor);
            $subjectSeconds=NotificationSupport::seconds($subjectInstant);
            if($scheduledSeconds===null||$subjectSeconds===null)return self::refusal('eligibility_expired');
            if($scheduledSeconds>=$subjectSeconds)return self::refusal('eligibility_expired');
        }
        if(isset($validated[NotificationRule::LEAD_TIME_RULE])){
            $lead=(int)$validated[NotificationRule::LEAD_TIME_RULE]['parameter_c'];
            $scheduledFor=(string)($facts['scheduled_for']??'');
            $subjectInstant=(string)($facts['subject_instant']??'');
            if($scheduledFor===''||$subjectInstant==='')return self::refusal('lead_time_insufficient');
            $scheduledSeconds=NotificationSupport::seconds($scheduledFor);
            $subjectSeconds=NotificationSupport::seconds($subjectInstant);
            if($scheduledSeconds===null||$subjectSeconds===null)return self::refusal('lead_time_insufficient');
            if($subjectSeconds-$scheduledSeconds<$lead*60)return self::refusal('lead_time_insufficient');
        }
        return array('outcome'=>'pass','code'=>null);
    }
    private static function refusal(string $code):array{return array('outcome'=>$code,'code'=>$code);}
}
