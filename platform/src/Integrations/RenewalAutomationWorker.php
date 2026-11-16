<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\RenewalAutomationNotificationService;

/**
 * Renewal-automation scheduler for the three clock-driven notices.
 *
 * Finds renewal cycles whose announced instant falls inside the bounded lead window and produces the
 * matching Student notice through RenewalAutomationNotificationService. Idempotency lives in the
 * producer: the portal inbox row is the durable per-cycle + event record, so a repeated worker run
 * never duplicates a notice. The worker never changes a cycle, collection intent, obligation or
 * settlement decision; its reads are SELECT-only and it is bounded (one finite batch per event).
 *
 *   - `renewal.automatic_upcoming.student`        — a pending automatic cycle announces its charge
 *     inside the lead window before `automatic_charge_at`;
 *   - `renewal.guarantee_deadline_approaching.student` — a guarantee-protected cycle warns inside the
 *     lead window before `guarantee_deadline_at`;
 *   - `renewal.manual_payment_required.student`   — a payment-required manual cycle requests payment
 *     inside the lead window before `guarantee_deadline_at`.
 */
final class RenewalAutomationWorker {
    public const HOOK='dzn_renewal_automation_dispatch';
    private const BATCH=50;
    /** Two days before the announced automatic charge. */
    private const AUTOMATIC_UPCOMING_LEAD_SECONDS=172800;
    /** Three days before the guarantee deadline. */
    private const GUARANTEE_DEADLINE_LEAD_SECONDS=259200;
    /** Three days before the manual-payment deadline. */
    private const MANUAL_PAYMENT_LEAD_SECONDS=259200;

    public static function register():void{
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    public static function ensureScheduled():void{
        if(!self::schedulingEnabled())return;
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+300,'dzn_two_minutes',self::HOOK);
    }

    /** The three clock-driven events and their lead seconds before the announced instant. */
    public static function clockDrivenEvents():array{
        return array(
            RenewalAutomationNotificationService::RENEWAL_AUTOMATIC_UPCOMING_EVENT=>self::AUTOMATIC_UPCOMING_LEAD_SECONDS,
            RenewalAutomationNotificationService::RENEWAL_GUARANTEE_DEADLINE_EVENT=>self::GUARANTEE_DEADLINE_LEAD_SECONDS,
            RenewalAutomationNotificationService::RENEWAL_MANUAL_PAYMENT_REQUIRED_EVENT=>self::MANUAL_PAYMENT_LEAD_SECONDS,
        );
    }

    /** Lead seconds before the announced instant for one clock-driven event, or 0 when it is not clock-driven. */
    public static function leadSeconds(string $eventKey):int{
        return self::clockDrivenEvents()[$eventKey]??0;
    }

    /** The `(now, now + lead]` due window, as UTC strings, for one clock-driven event. */
    public static function dueWindow(string $eventKey,int $now):array{
        return array('lower'=>gmdate('Y-m-d H:i:s',$now),'upper'=>gmdate('Y-m-d H:i:s',$now+self::leadSeconds($eventKey)));
    }

    /** Whether an announced UTC instant falls inside the lead window (strictly after `now`, at or before the upper bound). */
    public static function isDue(string $instant,int $now,string $eventKey):bool{
        $lead=self::leadSeconds($eventKey);
        if($lead<1)return false;
        $ts=strtotime(trim($instant).' UTC');
        if($ts===false)return false;
        return $ts>$now&&$ts<=$now+$lead;
    }

    /** @return array{examined:int,queued:int,skipped:int,errors:int} */
    public static function run(?int $now=null):array{
        $result=array('examined'=>0,'queued'=>0,'skipped'=>0,'errors'=>0);
        global $wpdb;
        if(!is_object($wpdb))return $result;
        $now??=time();
        $service=new RenewalAutomationNotificationService();
        foreach(self::clockDrivenEvents() as $eventKey=>$lead){
            $window=self::dueWindow($eventKey,$now);
            $rows=self::dueCycles($wpdb,$eventKey,$window['lower'],$window['upper']);
            if(!is_array($rows))continue;
            foreach($rows as $row){
                $result['examined']++;
                $cycleId=(int)($row->cycle_id??0);
                try{
                    $outcome=$service->dispatch($eventKey,$cycleId);
                    if(!empty($outcome['produced']))$result['queued']++;
                    else $result['skipped']++;
                }catch(\Throwable $error){
                    $result['errors']++;
                    do_action('dzn_renewal_automation_failed',$eventKey,$cycleId,get_class($error),(string)$error->getMessage());
                }
            }
        }
        return $result;
    }

    /** Bounded SELECT of the cycles due for one clock-driven event inside `(lower, upper]`. */
    private static function dueCycles(object $wpdb,string $eventKey,string $lower,string $upper):?array{
        $p=$wpdb->prefix.'dzn_';
        $sql=match($eventKey){
            RenewalAutomationNotificationService::RENEWAL_AUTOMATIC_UPCOMING_EVENT
                =>"SELECT c.id cycle_id FROM {$p}renewal_cycles c WHERE c.state='pending' AND c.collection_mode='automatic' AND c.automatic_charge_at IS NOT NULL AND c.automatic_charge_at>%s AND c.automatic_charge_at<=%s ORDER BY c.id ASC LIMIT %d",
            RenewalAutomationNotificationService::RENEWAL_GUARANTEE_DEADLINE_EVENT
                =>"SELECT c.id cycle_id FROM {$p}renewal_cycles c WHERE c.state='guarantee_protected' AND c.guarantee_deadline_at IS NOT NULL AND c.guarantee_deadline_at>%s AND c.guarantee_deadline_at<=%s ORDER BY c.id ASC LIMIT %d",
            RenewalAutomationNotificationService::RENEWAL_MANUAL_PAYMENT_REQUIRED_EVENT
                =>"SELECT c.id cycle_id FROM {$p}renewal_cycles c WHERE c.state='payment_required' AND c.collection_mode='manual' AND c.guarantee_deadline_at IS NOT NULL AND c.guarantee_deadline_at>%s AND c.guarantee_deadline_at<=%s ORDER BY c.id ASC LIMIT %d",
            default=>null,
        };
        if($sql===null)return null;
        return $wpdb->get_results($wpdb->prepare($sql,$lower,$upper,self::BATCH));
    }

    private static function schedulingEnabled():bool{
        if(!function_exists('wp_get_environment_type')||!function_exists('apply_filters'))return false;
        $default=in_array(wp_get_environment_type(),array('staging','production'),true);
        return (bool)apply_filters('dzn_renewal_automation_scheduling_enabled',$default);
    }
}
