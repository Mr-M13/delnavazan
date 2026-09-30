<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * §9 — S's one bounded, deterministic retry derivation.
 *
 * The jitter is keyed, never sampled, and the base back-off is the declared bounded recurrence — the sole
 * canonical semantics, never the closed form. The two exhaustion gates are read in one fixed order, the
 * ceiling first and the window second, so the reachable state in which both fail has exactly one outcome.
 * This class reads no clock: the only time input is the closure instant persisted on the closing attempt.
 */
final class NotificationRetry {
    /**
     * Validate one version's `rule_kind = 'retry'` rows against the canonical encoding and the
     * narrow-only partial order, then return the effective policy.
     *
     * The partial order is real: the ordering is the coordinatewise order on the five declared
     * parameters, the approved class baseline is its maximum element, and each parameter's admissible
     * interval is bounded above by that baseline, so a version can only narrow the baseline and never
     * exceed it on any axis. A version that registers no retry rows takes the baseline itself.
     *
     * @throws \InvalidArgumentException `retry_policy_invalid`.
     */
    public static function validatePolicy(array $rows):array{
        if($rows===array())return NotificationRule::retryBaseline();
        $values=array();
        $codes=array();
        foreach($rows as $row){
            $code=(string)($row['rule_code']??'');
            $codes[$code]=true;
            if($code!==NotificationRule::RETRY_CODE)throw new \InvalidArgumentException('retry_policy_invalid');
            $ordinal=(int)($row['ordinal']??0);
            $parameters=NotificationRule::retryParameters();
            if($ordinal<1||!array_key_exists($ordinal,$parameters))throw new \InvalidArgumentException('retry_policy_invalid');
            if(($row['parameter_b']??null)!==null||($row['parameter_c']??null)!==null||($row['parameter_d']??null)!==null)throw new \InvalidArgumentException('retry_policy_invalid');
            if(isset($values[$ordinal]))throw new \InvalidArgumentException('retry_policy_invalid');
            $number=NotificationSupport::canonicalUnsigned((string)($row['parameter_a']??''));
            if($number===null)throw new \InvalidArgumentException('retry_policy_invalid');
            $values[$ordinal]=$number;
        }
        if(count($codes)!==1)throw new \InvalidArgumentException('retry_policy_invalid');
        $expected=array_keys(NotificationRule::retryParameters());
        $actual=array_keys($values);
        sort($expected);sort($actual);
        if($expected!==$actual)throw new \InvalidArgumentException('retry_policy_invalid');
        $policy=array();
        foreach(NotificationRule::retryParameters() as $ordinal=>$spec){
            [$name,$minimum,$maximum,$baseline]=$spec;
            $value=$values[$ordinal];
            if($value<$minimum||$value>$maximum||$value>$baseline)throw new \InvalidArgumentException('retry_policy_invalid');
            $policy[$name]=$value;
        }
        if($policy['retry_max_backoff_seconds']<$policy[NotificationRule::RETRY_MAX_BACKOFF_FLOOR_PARAMETER])throw new \InvalidArgumentException('retry_policy_invalid');
        return $policy;
    }
    /** The canonical rule rows a policy serialises to, for the frozen rule-set digest. */
    public static function policyRows(array $policy):array{
        $rows=array();
        foreach(NotificationRule::retryParameters() as $ordinal=>$spec){
            [$name]=$spec;
            $rows[]=array('rule_kind'=>'retry','rule_code'=>NotificationRule::RETRY_CODE,'ordinal'=>$ordinal,'parameter_a'=>(string)$policy[$name]);
        }
        return $rows;
    }
    /** §9: the bounded recurrence — the sole canonical semantics, overflow-safe in 64-bit integers. */
    public static function baseBackoff(int $attemptSequence,array $policy):int{
        if($attemptSequence<1)throw new \InvalidArgumentException('retry_policy_invalid');
        $backoff=min((int)$policy['retry_max_backoff_seconds'],(int)$policy['retry_initial_backoff_seconds']);
        for($step=2;$step<=$attemptSequence;$step++){
            $backoff=min(
                (int)$policy['retry_max_backoff_seconds'],
                intdiv($backoff*(int)$policy['retry_backoff_multiplier_bp'],10000)
            );
        }
        return $backoff;
    }
    /** §9: the realised additive jitter of one attempt, from immutable identity and frozen parameters. */
    public static function appliedJitter(int $attemptSequence,array $policy,string $notificationKeyDigest,int $workflowVersion):int{
        $span=(int)$policy['retry_jitter_bp'];
        if($span<0)throw new \InvalidArgumentException('retry_policy_invalid');
        return NotificationSupport::jitterEntropy($notificationKeyDigest,$workflowVersion,$attemptSequence)%($span+1);
    }
    /**
     * §9 — decide one non-terminal closure.
     *
     * Returns `exhaustion` = `ceiling` / `window` / null, and, for a closure that actually re-arms, the
     * deterministic quadruple to persist. The ceiling gate is evaluated first, so a closure at
     * `attempt_sequence = retry_max_attempts` is ceiling exhaustion whatever its clamp would have done,
     * and the window gate applies only to a closure with an attempt remaining. A NULL `expires_at` omits
     * the expiry clamp term and every expiry-window check.
     *
     * @throws \InvalidArgumentException `retry_schedule_divergence` when a derived instant leaves the
     *         stored `datetime` domain.
     */
    public static function closure(array $policy,array $closure):array{
        $attemptSequence=(int)$closure['attempt_sequence'];
        $finishedAt=(string)$closure['finished_at'];
        if(NotificationSupport::seconds($finishedAt)===null)throw new \InvalidArgumentException('retry_schedule_divergence');
        $expiresAt=$closure['expires_at']??null;
        $expiresAt=$expiresAt===null||trim((string)$expiresAt)===''?null:(string)$expiresAt;
        $maxAttempts=(int)$policy['retry_max_attempts'];
        if($attemptSequence>=$maxAttempts){
            return array('exhaustion'=>'ceiling','re_arm'=>false,'applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
        }
        $baseBackoff=self::baseBackoff($attemptSequence,$policy);
        $appliedJitter=self::appliedJitter($attemptSequence,$policy,(string)$closure['notification_key_digest'],(int)$closure['workflow_version']);
        $jittered=$baseBackoff+intdiv($baseBackoff*$appliedJitter,10000);
        $clamped=min($jittered,(int)$policy['retry_max_backoff_seconds']);
        $nextAvailable=NotificationSupport::addSeconds($finishedAt,$clamped);
        if($nextAvailable===null)throw new \InvalidArgumentException('retry_schedule_divergence');
        if($expiresAt!==null){
            if(NotificationSupport::seconds($expiresAt)===null)throw new \InvalidArgumentException('retry_schedule_divergence');
            $nextAvailable=NotificationSupport::earlier($nextAvailable,$expiresAt);
        }
        $usable=NotificationSupport::seconds($nextAvailable)>NotificationSupport::seconds($finishedAt)
            &&($expiresAt===null||NotificationSupport::seconds($nextAvailable)<NotificationSupport::seconds($expiresAt));
        if(!$usable){
            return array('exhaustion'=>'window','re_arm'=>false,'applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
        }
        return array(
            'exhaustion'=>null,'re_arm'=>true,
            'applied_jitter_bp'=>$appliedJitter,'base_backoff_seconds'=>$baseBackoff,
            'backoff_seconds'=>$clamped,'next_available_at'=>$nextAvailable,
        );
    }
    /**
     * Replay/recovery convergence: recompute the schedule from the persisted inputs under the same
     * expiry branch and require the persisted quadruple to reproduce exactly. A divergence is reported,
     * never silently rescheduled.
     */
    public static function verifyPersisted(array $policy,array $closure,array $persisted):void{
        $expected=self::closure($policy,$closure);
        if(($expected['re_arm']??false)!==true)throw new \InvalidArgumentException('retry_schedule_divergence');
        foreach(array('applied_jitter_bp','base_backoff_seconds','backoff_seconds','next_available_at') as $key){
            $stored=$persisted[$key]??null;
            if($stored===null||(string)$stored!==(string)$expected[$key])throw new \InvalidArgumentException('retry_schedule_divergence');
        }
    }
    /** The attempt ceiling of one policy, exposed so callers never re-derive the accounting rule. */
    public static function maxAttempts(array $policy):int{return (int)$policy['retry_max_attempts'];}
    /**
     * §6.6/§9 — the single mapping of an exhaustion gate to its closed reason code.
     *
     * Both exhaustion callers (the port-reported/defer closure path and lease-expiry recovery) read this
     * one mapping, so ceiling exhaustion always closes `failed` with `retry_exhausted` and window
     * exhaustion always closes `expired` with `retry_window_exhausted`, whatever path reached the gate.
     * A closure that re-arms (or a terminal class) maps to null: it has no exhaustion code.
     */
    public static function exhaustionReasonCode(array $closure):?string{
        return match($closure['exhaustion']??null){
            'ceiling'=>NotificationRule::CEILING_EXHAUSTION_CODE,
            'window'=>NotificationRule::WINDOW_EXHAUSTION_CODE,
            default=>null,
        };
    }
}
