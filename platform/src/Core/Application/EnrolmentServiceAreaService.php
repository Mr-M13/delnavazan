<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\EnrolmentRepository;

/**
 * The explicit Enrolment service-area configuration authority.
 *
 * It writes only the one canonical `enrolments.service_area_key` fact. It never derives an area
 * from a Student's country, a Teacher's timezone, a browser, or the largest bonus, and it never
 * rewrites a committed finance snapshot. Existing Enrolments may remain blank and require review.
 */
final class EnrolmentServiceAreaService {
    private const CAPABILITY='dzn_manage_enrolments';

    public function __construct(private ?EnrolmentRepository $enrolments=null){
        $this->enrolments??=new EnrolmentRepository();
    }

    /** @return array{enrolment_id:int,previous:?string,service_area_key:?string,changed:bool} */
    public function set(int $enrolmentId, mixed $serviceAreaKey): array {
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        if($enrolmentId<1)throw new \InvalidArgumentException('enrolment_required');
        $key=GoldenHourRule::serviceAreaKey($serviceAreaKey);
        $actor=get_current_user_id();
        if($actor<1)throw new \RuntimeException('Service-area actor unavailable');
        $now=gmdate('Y-m-d H:i:s');
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try{
            $row=$wpdb->get_row($wpdb->prepare("SELECT id,service_area_key,record_model,archived_at FROM {$wpdb->prefix}dzn_enrolments WHERE id=%d FOR UPDATE",$enrolmentId));
            if(!$row||$row->archived_at!==null)throw new \InvalidArgumentException('enrolment_not_live');
            $prior=$row->service_area_key===null||$row->service_area_key===''?null:(string)$row->service_area_key;
            $changed=!hash_equals((string)($prior??''),(string)($key??''));
            if($changed){
                $this->enrolments->setServiceArea($enrolmentId,$key,$now,$actor);
                $audit=$wpdb->insert($wpdb->prefix.'dzn_platform_audit_events',array(
                    'aggregate_type'=>'enrolments','aggregate_id'=>$enrolmentId,'event_type'=>'service_area_changed',
                    'actor_type'=>'user','actor_id'=>$actor,'reason_code'=>null,
                    'safe_detail'=>substr('previous='.($prior??'').';new='.($key??''),0,190),
                    'idempotency_key'=>hash('sha256','enrolment_service_area:'.$enrolmentId.':'.$now.':'.($key??'')),
                    'occurred_at'=>$now,
                ));
                if($audit===false)throw new \RuntimeException('Service-area audit evidence could not be recorded');
            }
            $wpdb->query('COMMIT');
            return array('enrolment_id'=>$enrolmentId,'previous'=>$prior,'service_area_key'=>$key,'changed'=>$changed);
        }catch(\Throwable $error){
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}
