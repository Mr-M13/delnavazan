<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * §6.3 — S's one frozen, total scheduling function.
 *
 * The composition is closed and validated at activation; the derivation is a pure function of persisted
 * inputs only (`observed_at`, the persisted tier-F subject instant, the resolved single shared `timezone`,
 * the persisted `deferral_count` and the frozen rules). Every instant is computed in 64-bit integer
 * seconds and checked against the stored `datetime` domain, so nothing can wrap, truncate or silently
 * clamp. The class reads no ambient clock, no commercial policy and no pattern row.
 */
final class NotificationSchedule {
    /**
     * Validate the closed composition of one version's schedule rule rows (§6.3(a)).
     *
     * `$rows` is the version's `rule_kind = 'schedule'` set as stored: `rule_code`, `ordinal`,
     * `parameter_a`…`parameter_d`. A code whose parameter is not in the canonical encoding, a
     * non-contiguous ordinal, a value in the wrong column, a value outside its admissible range, a
     * zero/duplicate/tier-mismatched anchor, a second instance of a 0-or-1 code, a missing or malformed
     * `expiry`, a deferral product above its bound, a basis outside the vocabulary and two
     * timezone-sensitive rules naming different bases are all refused here.
     *
     * @throws \InvalidArgumentException with `schedule_composition_invalid`,
     *         `schedule_timezone_basis_conflict` or `schedule_expiry_missing`.
     */
    public static function validateComposition(array $rows,string $tier):array{
        if(!in_array($tier,array('F','P'),true))throw new \InvalidArgumentException('schedule_composition_invalid');
        $codes=array();
        foreach($rows as $row){
            $code=(string)($row['rule_code']??'');
            $ordinal=(int)($row['ordinal']??0);
            if(!NotificationRule::scheduleCode($code))throw new \InvalidArgumentException('schedule_composition_invalid');
            $parameters=NotificationRule::scheduleParameters($code);
            if($ordinal<1||!array_key_exists($ordinal,$parameters))throw new \InvalidArgumentException('schedule_composition_invalid');
            if(($row['parameter_b']??null)!==null||($row['parameter_c']??null)!==null||($row['parameter_d']??null)!==null)throw new \InvalidArgumentException('schedule_composition_invalid');
            if(isset($codes[$code][$ordinal]))throw new \InvalidArgumentException('schedule_composition_invalid');
            $codes[$code][$ordinal]=self::canonicalParameter($parameters[$ordinal],(string)($row['parameter_a']??''));
        }
        // One anchor, at most one of each optional code, exactly one expiry — and every ordinal present.
        foreach($codes as $code=>$values){
            $expected=array_keys(NotificationRule::scheduleParameters($code));
            $actual=array_keys($values);
            sort($expected);sort($actual);
            if($expected!==$actual)throw new \InvalidArgumentException('schedule_composition_invalid');
        }
        $anchorCode=$tier==='F'?NotificationRule::SCHEDULE_SLOTS['anchor_F']:NotificationRule::SCHEDULE_SLOTS['anchor_P'];
        $oppositeAnchor=$tier==='F'?NotificationRule::SCHEDULE_SLOTS['anchor_P']:NotificationRule::SCHEDULE_SLOTS['anchor_F'];
        if(isset($codes[$oppositeAnchor])||!isset($codes[$anchorCode]))throw new \InvalidArgumentException('schedule_composition_invalid');
        if(!isset($codes['expiry']))throw new \InvalidArgumentException('schedule_expiry_missing');
        $composition=array(
            'tier'=>$tier,
            'anchor'=>array('code'=>$anchorCode)+($anchorCode==='lead_time'?array('lead_time_minutes'=>$codes['lead_time'][1]):array()),
            'fixed_local_time'=>isset($codes['fixed_local_time'])?array('local_time'=>$codes['fixed_local_time'][1],'timezone_basis'=>$codes['fixed_local_time'][2]):null,
            'send_window'=>isset($codes['send_window'])?array(
                'start'=>$codes['send_window'][1],'end'=>$codes['send_window'][2],
                'weekday_mask'=>$codes['send_window'][3],'timezone_basis'=>$codes['send_window'][4],
            ):null,
            'deferral'=>isset($codes['deferral'])?array('defer_ceiling_minutes'=>$codes['deferral'][1],'max_deferrals'=>$codes['deferral'][2]):null,
            'coalesce'=>isset($codes['coalesce'])?array('coalesce_window_minutes'=>$codes['coalesce'][1]):null,
            'expiry'=>array('expiry_minutes'=>$codes['expiry'][1]),
        );
        if($composition['send_window']!==null){
            $start=NotificationSupport::timeOfDaySeconds($composition['send_window']['start']);
            $end=NotificationSupport::timeOfDaySeconds($composition['send_window']['end']);
            if($start===null||$end===null||$start>=$end)throw new \InvalidArgumentException('schedule_composition_invalid');
        }
        if($composition['deferral']!==null){
            $product=$composition['deferral']['defer_ceiling_minutes']*$composition['deferral']['max_deferrals'];
            if($product>NotificationRule::DEFERRAL_PRODUCT_MAX)throw new \InvalidArgumentException('schedule_composition_invalid');
        }
        $composition['timezone_basis']=self::sharedBasis($composition);
        return $composition;
    }
    /** The single basis every timezone-sensitive rule names, or null when the version names none. */
    private static function sharedBasis(array $composition):?string{
        $bases=array();
        foreach(NotificationRule::TIMEZONE_SENSITIVE_CODES as $code){
            $rule=$composition[$code]??null;
            if($rule!==null)$bases[]=(string)$rule['timezone_basis'];
        }
        if($bases===array())return null;
        if(count(array_unique($bases))!==1)throw new \InvalidArgumentException('schedule_timezone_basis_conflict');
        return $bases[0];
    }
    /** One canonical parameter value, validated against its declared kind and admissible range. */
    private static function canonicalParameter(array $spec,string $value){
        [$name,$kind,$minimum,$maximum]=$spec;
        if($kind==='basis'){if(!NotificationRule::timezoneBasis($value))throw new \InvalidArgumentException('schedule_composition_invalid');return $value;}
        if($kind==='time'){if(!NotificationSupport::canonicalTime($value))throw new \InvalidArgumentException('schedule_composition_invalid');return $value;}
        $number=NotificationSupport::canonicalUnsigned($value);
        if($number===null)throw new \InvalidArgumentException('schedule_composition_invalid');
        if($number<$minimum||$number>$maximum)throw new \InvalidArgumentException('schedule_composition_invalid');
        return $number;
    }
    /** The composition serialised as the rule rows the frozen digest covers (ordered by kind, code, ordinal). */
    public static function compositionRows(array $composition):array{
        $rows=array();
        $anchor=$composition['anchor'];
        $rows[]=array('rule_kind'=>'schedule','rule_code'=>$anchor['code'],'ordinal'=>1,'parameter_a'=>$anchor['code']==='lead_time'?(string)$anchor['lead_time_minutes']:null);
        if($composition['fixed_local_time']!==null){
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'fixed_local_time','ordinal'=>1,'parameter_a'=>$composition['fixed_local_time']['local_time']);
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'fixed_local_time','ordinal'=>2,'parameter_a'=>$composition['fixed_local_time']['timezone_basis']);
        }
        if($composition['send_window']!==null){
            $window=$composition['send_window'];
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'send_window','ordinal'=>1,'parameter_a'=>$window['start']);
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'send_window','ordinal'=>2,'parameter_a'=>$window['end']);
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'send_window','ordinal'=>3,'parameter_a'=>(string)$window['weekday_mask']);
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'send_window','ordinal'=>4,'parameter_a'=>$window['timezone_basis']);
        }
        if($composition['deferral']!==null){
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'deferral','ordinal'=>1,'parameter_a'=>(string)$composition['deferral']['defer_ceiling_minutes']);
            $rows[]=array('rule_kind'=>'schedule','rule_code'=>'deferral','ordinal'=>2,'parameter_a'=>(string)$composition['deferral']['max_deferrals']);
        }
        if($composition['coalesce']!==null)$rows[]=array('rule_kind'=>'schedule','rule_code'=>'coalesce','ordinal'=>1,'parameter_a'=>(string)$composition['coalesce']['coalesce_window_minutes']);
        $rows[]=array('rule_kind'=>'schedule','rule_code'=>'expiry','ordinal'=>1,'parameter_a'=>(string)$composition['expiry']['expiry_minutes']);
        return $rows;
    }
    /**
     * §6.3 step 1 — the anchor, from the persisted observation instant (tier P) or the persisted
     * tier-F announced instant (tier F). A tier-F anchor is always strictly earlier than that instant.
     */
    public static function anchor(array $composition,string $observedAt,?string $subjectInstant):string{
        if($composition['anchor']['code']==='immediate')return $observedAt;
        if($subjectInstant===null)throw new \InvalidArgumentException('tier_f_instant_unavailable');
        $anchor=NotificationSupport::addSeconds($subjectInstant,-((int)$composition['anchor']['lead_time_minutes'])*60);
        if($anchor===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        return $anchor;
    }
    /**
     * §6.3 steps 2–4 — local placement, half-open send window and the frozen base instant.
     *
     * `$timezone` is the version's single resolved zone, persisted per notification at observation. An
     * unresolvable basis never reaches here: observation closes terminally with
     * `schedule_timezone_unresolved` before any instant is derived.
     */
    public static function base(array $composition,string $anchorAt,string $timezone):string{
        $placed=self::place($composition,$anchorAt,$timezone);
        return self::window($composition,$placed,$timezone);
    }
    /** §6.3 step 2: the earliest instant at or after the anchor whose local wall clock is `local_time`. */
    private static function place(array $composition,string $anchorAt,string $timezone):string{
        if($composition['fixed_local_time']===null)return $anchorAt;
        if($timezone==='')throw new \InvalidArgumentException('schedule_timezone_unresolved');
        $wanted=$composition['fixed_local_time']['local_time'];
        $anchorSeconds=NotificationSupport::seconds($anchorAt);
        if($anchorSeconds===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        for($offset=0;$offset<=3;$offset++){
            $local=NotificationSupport::utcToLocal($timezone,$anchorAt);
            if($local===null)throw new \InvalidArgumentException('schedule_timezone_unresolved');
            $date=gmdate('Y-m-d',strtotime($local['date'].' UTC +'.$offset.' days'));
            $candidate=NotificationSupport::localToUtc($timezone,$date,$wanted);
            if($candidate===null)throw new \InvalidArgumentException('schedule_timezone_unresolved');
            if(NotificationSupport::seconds($candidate)>=$anchorSeconds)return $candidate;
        }
        throw new \InvalidArgumentException('schedule_composition_invalid');
    }
    /** §6.3 step 3: the earliest instant at or after placement inside a permitted window, bounded to 14 days. */
    private static function window(array $composition,string $placedAt,string $timezone):string{
        if($composition['send_window']===null)return $placedAt;
        if($timezone==='')throw new \InvalidArgumentException('schedule_timezone_unresolved');
        $rule=$composition['send_window'];
        $placedSeconds=NotificationSupport::seconds($placedAt);
        if($placedSeconds===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        for($offset=0;$offset<=NotificationRule::SEND_WINDOW_SEARCH_DAYS;$offset++){
            $local=NotificationSupport::utcToLocal($timezone,$placedAt);
            if($local===null)throw new \InvalidArgumentException('schedule_timezone_unresolved');
            $instant=NotificationSupport::instant($placedSeconds+$offset*NotificationSupport::SECONDS_PER_DAY);
            if($instant===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
            $day=NotificationSupport::utcToLocal($timezone,$instant);
            if($day===null)throw new \InvalidArgumentException('schedule_timezone_unresolved');
            if((((int)$rule['weekday_mask'])&(1<<($day['weekday']-1)))===0)continue;
            $start=NotificationSupport::localToUtc($timezone,$day['date'],$rule['start']);
            $end=NotificationSupport::localToUtc($timezone,$day['date'],$rule['end']);
            if($start===null||$end===null)throw new \InvalidArgumentException('schedule_timezone_unresolved');
            if(NotificationSupport::seconds($end)<=NotificationSupport::seconds($start))continue;
            $inside=NotificationSupport::later($placedAt,$start);
            if(NotificationSupport::seconds($inside)<NotificationSupport::seconds($end))return $inside;
        }
        throw new \InvalidArgumentException('schedule_composition_invalid');
    }
    /**
     * The whole §6.3 derivation. Inputs are persisted values only; the returned representation is what
     * the notification and its outbox mirror both carry.
     *
     * @throws \InvalidArgumentException `schedule_derivation_divergence`, `schedule_composition_invalid`,
     *         `eligibility_expired` or `tier_f_instant_unavailable`.
     */
    public static function derive(array $composition,array $inputs):array{
        $observedAt=(string)($inputs['observed_at']??'');
        if(NotificationSupport::seconds($observedAt)===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        $subjectInstant=($inputs['subject_instant']??null)===null?null:(string)$inputs['subject_instant'];
        // §6.2.4: the subject instant is a persisted column too, so a non-null value is judged by the same
        // declared domain as the observation instant rather than only by the arithmetic that consumes it.
        if($subjectInstant!==null&&NotificationSupport::seconds($subjectInstant)===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        $timezone=(string)($inputs['timezone']??'');
        $deferralCount=(int)($inputs['deferral_count']??0);
        if($deferralCount<0)throw new \InvalidArgumentException('schedule_derivation_divergence');
        if($composition['deferral']===null&&$deferralCount!==0)throw new \InvalidArgumentException('schedule_derivation_divergence');
        if($composition['deferral']!==null&&$deferralCount>(int)$composition['deferral']['max_deferrals'])throw new \InvalidArgumentException('schedule_derivation_divergence');
        if($composition['timezone_basis']===null&&$timezone!=='')throw new \InvalidArgumentException('schedule_derivation_divergence');
        if($composition['timezone_basis']!==null&&$timezone==='')throw new \InvalidArgumentException('schedule_derivation_divergence');
        $anchorAt=self::anchor($composition,$observedAt,$subjectInstant);
        $baseAt=self::base($composition,$anchorAt,$timezone);
        $scheduledFor=$baseAt;
        if($composition['deferral']!==null&&$deferralCount>0){
            $scheduledFor=NotificationSupport::addSeconds($baseAt,$deferralCount*(int)$composition['deferral']['defer_ceiling_minutes']*60);
            if($scheduledFor===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        }
        $expiresAt=self::expiryFor($composition,$baseAt,$subjectInstant);
        $result=array(
            'schedule_anchor_at'=>$anchorAt,
            'derivation_base_at'=>$baseAt,
            'scheduled_for'=>$scheduledFor,
            'expires_at'=>$expiresAt,
            'timezone'=>$timezone,
            'deferral_count'=>$deferralCount,
            'coalesce_bucket'=>NotificationSupport::coalesceBucket($anchorAt,$composition['coalesce']!==null?(int)$composition['coalesce']['coalesce_window_minutes']:null),
        );
        if($composition['tier']==='F'){
            // §6.3(d): the strict postcondition is applied to the final, post-deferral result, never to the
            // base alone, and equality is failure rather than "just in time".
            if($subjectInstant===null)throw new \InvalidArgumentException('tier_f_instant_unavailable');
            if(NotificationSupport::seconds($scheduledFor)>=NotificationSupport::seconds($subjectInstant))throw new \InvalidArgumentException('eligibility_expired');
        }
        return $result;
    }
    /** §6.3 step 6: the base-anchored expiry, capped at the announced instant for a tier-F intent. */
    private static function expiryFor(array $composition,string $baseAt,?string $subjectInstant):string{
        $expiry=NotificationSupport::addSeconds($baseAt,(int)$composition['expiry']['expiry_minutes']*60);
        if($expiry===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        if($composition['tier']==='F'){
            if($subjectInstant===null)throw new \InvalidArgumentException('tier_f_instant_unavailable');
            return NotificationSupport::earlier($subjectInstant,$expiry);
        }
        return $expiry;
    }
    /**
     * §6.3(e) — one bounded deferral: increment the persisted count by exactly one and re-derive
     * `scheduled_for` from the frozen base rather than shifting the previous instant. The refusal is
     * terminal rather than a deferral into an undispatchable state.
     *
     * @throws \InvalidArgumentException `schedule_derivation_divergence`, `retry_window_exhausted`
     *         (the moved instant crossed the frozen window), `eligibility_expired` or
     *         `lead_time_insufficient`.
     */
    public static function defer(array $composition,array $persisted,?string $subjectInstant,?int $eligibilityLeadMinutes):array{
        if($composition['deferral']===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        if($subjectInstant!==null&&NotificationSupport::seconds($subjectInstant)===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        $count=(int)$persisted['deferral_count']+1;
        if($count>(int)$composition['deferral']['max_deferrals'])throw new \InvalidArgumentException('schedule_derivation_divergence');
        $moved=NotificationSupport::addSeconds((string)$persisted['derivation_base_at'],$count*(int)$composition['deferral']['defer_ceiling_minutes']*60);
        if($moved===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        $expiresAt=(string)$persisted['expires_at'];
        // Both sides of the window comparison are parsed rather than assumed, so a refused instant can
        // never be read as a zero second count and silently pass (or fail) the gate.
        $movedSeconds=NotificationSupport::seconds($moved);
        if($movedSeconds===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        // The window is anchored to the base, so a deferral that reaches or crosses it is exhaustion by
        // window rather than a message delivered outside its agreed window.
        if($expiresAt!==''){
            $expiresSeconds=NotificationSupport::seconds($expiresAt);
            if($expiresSeconds===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
            if($movedSeconds>=$expiresSeconds)throw new \InvalidArgumentException('retry_window_exhausted');
        }
        if($composition['tier']==='F'){
            if($subjectInstant===null)throw new \InvalidArgumentException('tier_f_instant_unavailable');
            $subjectSeconds=NotificationSupport::seconds($subjectInstant);
            if($subjectSeconds===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
            if($movedSeconds>=$subjectSeconds)throw new \InvalidArgumentException('eligibility_expired');
            if($eligibilityLeadMinutes!==null){
                $remaining=$subjectSeconds-$movedSeconds;
                if($remaining<$eligibilityLeadMinutes*60)throw new \InvalidArgumentException('lead_time_insufficient');
            }
        }
        return array('scheduled_for'=>$moved,'deferral_count'=>$count);
    }
    /**
     * §6.3(f) — re-derive from the persisted inputs and prove the result reproduces exactly. This is the
     * verifier's and the dispatch path's own check; a disagreement is a divergence, never a reschedule.
     */
    public static function verify(array $composition,array $persisted,?string $subjectInstant):void{
        $expected=self::derive($composition,array(
            'observed_at'=>(string)($persisted['observed_at']??''),
            'subject_instant'=>$subjectInstant,
            'timezone'=>(string)($persisted['timezone']??''),
            'deferral_count'=>(int)($persisted['deferral_count']??0),
        ));
        foreach(array('schedule_anchor_at','scheduled_for','expires_at','timezone','deferral_count','coalesce_bucket') as $key){
            $stored=$persisted[$key]??null;
            if((string)$stored!==(string)$expected[$key])throw new \InvalidArgumentException('schedule_derivation_divergence');
        }
    }
}
