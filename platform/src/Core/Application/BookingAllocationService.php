<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Sequential offers. The delivery remains the sole confirmation anchor;
 * automatic history lives in the allocation ledger.
 */
final class BookingAllocationService {
    public const RESPONSE_SECONDS=86400;
    public const ESCALATION_SECONDS=259200;
    // A response must finish before the lesson begins; leave an hour for
    // confirmation and provider projection after the complete response window.
    public const PROJECTION_BUFFER_SECONDS=3600;
    private CanonicalTeacherOccupancyReadService $occupancy;
    public function __construct(
        private ?BookingAllocationRepository $repo=null,
        private ?BookingRequestMatchAssessmentRepository $matches=null,
        private ?BookingTeacherDeliveryRepository $deliveries=null,
        private ?TeacherAvailabilityService $availability=null
    ){
        $this->repo??=new BookingAllocationRepository();
        $this->matches??=new BookingRequestMatchAssessmentRepository();
        $this->deliveries??=new BookingTeacherDeliveryRepository();
        $this->availability??=new TeacherAvailabilityService();
        $this->occupancy=new CanonicalTeacherOccupancyReadService();
    }

    /** Called inside the submission transaction, which already owns the new request. */
    public function startWithinTransaction(int $requestId,int $courseId,array $times,string $now):?array{
        if($this->repo->delivery($requestId))throw new \RuntimeException('Allocation already started');
        $candidates=$this->candidates($courseId,$times,$now);
        if(!$candidates){$this->repo->referral($requestId,'no_viable_teacher',$now);return null;}
        $last=$this->repo->cursor($courseId,$now);
        $chosen=$this->next($candidates,$last,array());
        $this->repo->advanceCursor($courseId,$chosen['teacher_id'],$now);
        $deliveryId=$this->deliveries->create(array('uid'=>Identifier::uid(),'booking_request_id'=>$requestId,'requested_time_id'=>$chosen['requested_time_id'],'teacher_id'=>$chosen['teacher_id'],'state'=>'pending_request','match_strength'=>$chosen['match_strength'],'created_at'=>$now,'updated_at'=>$now));
        $deadline=self::addSeconds($now,self::RESPONSE_SECONDS);
        $this->repo->addOffer($requestId,$deliveryId,$chosen,1,$now,$deadline);
        return array('delivery_id'=>$deliveryId,'teacher_id'=>$chosen['teacher_id'],'requested_time_id'=>$chosen['requested_time_id'],'state'=>'pending_request','match_strength'=>$chosen['match_strength'],'deadline_at'=>$deadline);
    }

    /** The caller owns the current delivery row lock and transaction. */
    public function advanceLocked(object $delivery,string $outcome,string $now):?array{
        if(!in_array($outcome,array('declined','expired'),true))throw new \InvalidArgumentException('Invalid offer outcome');
        $requestId=(int)$delivery->booking_request_id;
        $offer=$this->repo->offer($requestId,true);
        if(!$offer||(int)$offer->delivery_id!==(int)$delivery->id||(int)$offer->teacher_id!==(int)$delivery->teacher_id||$delivery->state!=='pending_request')throw new \InvalidArgumentException('Booking offer is no longer pending');
        if($outcome==='expired'&&(string)$offer->deadline_at>$now)throw new \InvalidArgumentException('Booking offer has not expired');
        $this->repo->transitionOffer((int)$offer->id,$outcome,$now,$outcome);
        $request=$this->repo->request($requestId);
        if(!$request||$request->lifecycle_status!=='submitted'||$request->resolution_state!=='unresolved'||$request->privacy_erased_at!==null)return null;
        $history=$this->repo->history($requestId);
        $offered=array_map(static fn(object $row):int=>(int)$row->teacher_id,$history);
        $candidates=$this->candidates((int)$request->selected_intro_course_id,$this->repo->times($requestId),$now);
        $next=$this->next($candidates,(int)$offer->teacher_id,$offered);
        if(!$next){
            $this->deliveries->transitionForAllocation((int)$delivery->id,'pending_request',$outcome,$now);
            $this->repo->referral($requestId,'candidates_exhausted',$now);
            return null;
        }
        $deliveryId=$this->repo->rotateDelivery($delivery,$next,$now);
        $deadline=self::addSeconds($now,self::RESPONSE_SECONDS);
        $this->repo->addOffer($requestId,$deliveryId,$next,(int)$offer->offer_sequence+1,$now,$deadline);
        return array('delivery_id'=>$deliveryId,'teacher_id'=>$next['teacher_id'],'requested_time_id'=>$next['requested_time_id'],'state'=>'pending_request','match_strength'=>$next['match_strength'],'deadline_at'=>$deadline);
    }

    public function assertPending(object $delivery,string $now):void{
        $offer=$this->repo->offer((int)$delivery->booking_request_id,true);
        if(!$offer||(int)$offer->delivery_id!==(int)$delivery->id||(int)$offer->teacher_id!==(int)$delivery->teacher_id||(int)$offer->requested_time_id!==(int)$delivery->requested_time_id||(string)$offer->deadline_at<=$now)throw new \InvalidArgumentException('Booking offer expired or reassigned');
    }
    /** Revalidate the exact offered time, not merely another preference. */
    public function assertAcceptable(object $delivery,string $now):void{
        $this->assertPending($delivery,$now);
        $times=array_values(array_filter($this->repo->times((int)$delivery->booking_request_id),static fn(object $time):bool=>(int)$time->id===(int)$delivery->requested_time_id));
        $candidates=$this->candidates((int)$delivery->selected_intro_course_id,$times,$now,false);
        if(!isset($candidates[(int)$delivery->teacher_id])||(int)$candidates[(int)$delivery->teacher_id]['requested_time_id']!==(int)$delivery->requested_time_id)throw new \InvalidArgumentException('Booking time is no longer available');
    }
    public function acceptedLocked(object $delivery,string $now):void{
        $offer=$this->repo->offer((int)$delivery->booking_request_id,true);
        if(!$offer||(int)$offer->teacher_id!==(int)$delivery->teacher_id)throw new \RuntimeException('Booking offer changed');
        $this->repo->transitionOffer((int)$offer->id,'accepted',$now,'teacher_accepted');
        $this->repo->resolveAlerts((int)$delivery->booking_request_id,$now);
    }
    public function administratorAcceptedLocked(object $delivery,string $now):void{
        $offer=$this->repo->offer((int)$delivery->booking_request_id,true);
        if($offer)$this->repo->transitionOffer((int)$offer->id,'superseded',$now,'administrator_agreement');
        $this->repo->resolveAlerts((int)$delivery->booking_request_id,$now);
    }
    public function process(int $requestId,string $now):void{
        $this->repo->begin();
        try{
            // The Teacher response path locks this delivery first. Use the same
            // order so a deadline and an acceptance cannot each own half the
            // aggregate while waiting for the other half.
            $delivery=$this->repo->delivery($requestId,true);
            $request=$this->repo->request($requestId,true);
            if(!$request||$request->lifecycle_status!=='submitted'||$request->resolution_state!=='unresolved'||$request->privacy_erased_at!==null||$this->repo->confirmationExists($requestId)){$this->repo->commit();return;}
            $offer=$this->repo->offer($requestId,true);
            if($offer&&$delivery&&$delivery->state==='pending_request'&&(string)$offer->deadline_at<=$now)$this->advanceLocked($delivery,'expired',$now);
            if((string)$request->created_at<=self::addSeconds($now,-self::ESCALATION_SECONDS)){
                $this->repo->alert($requestId,'escalation','unaccepted_after_72h','high',$now);
                $this->repo->referral($requestId,'unaccepted_after_72h',$now);
            }
            $this->repo->commit();
        }catch(\Throwable $error){$this->repo->rollback();throw $error;}
    }
    /** Read-only, privacy-safe preview for an operator before any historical mutation. */
    public function recoveryPreview(int $requestId,string $now):array{
        $request=$this->repo->request($requestId);
        if(!$request)return array('request_id'=>$requestId,'action'=>'missing');
        $delivery=$this->repo->delivery($requestId);
        $offer=$this->repo->offer($requestId);
        $confirmed=$this->repo->confirmationExists($requestId);
        $eligible=$request->lifecycle_status==='submitted'&&$request->resolution_state==='unresolved'&&$request->privacy_erased_at===null&&!$confirmed;
        $candidates=$eligible?$this->candidates((int)$request->selected_intro_course_id,$this->repo->times($requestId),$now):array();
        $action=!$eligible?'preserve_existing_lifecycle':($offer?'already_allocated':($delivery&&$delivery->state==='pending_request'?'reconcile_legacy_offer':($delivery?'manual_review':($candidates?'start_offer':'refer_administrator'))));
        return array('request_id'=>$requestId,'reference'=>(string)($request->reference_code??''),'submitted_at'=>(string)$request->created_at,'action'=>$action,'existing_delivery_state'=>$delivery?(string)$delivery->state:null,'active_offer'=>$offer!==null,'confirmation_exists'=>$confirmed,'viable_teacher_count'=>count($candidates),'past_72_hours'=>(string)$request->created_at<=self::addSeconds($now,-self::ESCALATION_SECONDS));
    }
    /** Only an explicit, audited operator action can turn a legacy request into offers. */
    public function recover(int $requestId,string $now):array{
        if(!current_user_can('dzn_manage_booking_request_coordination'))throw new \RuntimeException('Unauthorized');
        $this->repo->begin();
        try{
            $request=$this->repo->request($requestId,true);
            $preview=$this->recoveryPreview($requestId,$now);
            if(!$request||$preview['action']==='preserve_existing_lifecycle'||$preview['action']==='already_allocated'||$preview['action']==='missing'){$this->repo->commit();return $preview;}
            if($preview['action']==='manual_review'){$this->repo->referral($requestId,'legacy_delivery_requires_review',$now);}
            elseif($preview['action']==='reconcile_legacy_offer'){
                $delivery=$this->repo->delivery($requestId,true);
                $deadline=self::addSeconds((string)$delivery->created_at,self::RESPONSE_SECONDS);
                $this->repo->historicalOffer($requestId,$delivery,$deadline);
                if($deadline<=$now)$this->advanceLocked($delivery,'expired',$now);
            }else{
                $this->startWithinTransaction($requestId,(int)$request->selected_intro_course_id,$this->repo->times($requestId),$now);
            }
            if((string)$request->created_at<=self::addSeconds($now,-self::ESCALATION_SECONDS)){
                $this->repo->alert($requestId,'escalation','unaccepted_after_72h','high',$now);
                $this->repo->referral($requestId,'unaccepted_after_72h',$now);
            }
            $this->repo->commit();return $preview+array('applied'=>true);
        }catch(\Throwable $error){$this->repo->rollback();throw $error;}
    }
    public static function addSeconds(string $utc,int $seconds):string{
        return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->modify(($seconds>=0?'+':'').$seconds.' seconds')->format('Y-m-d H:i:s');
    }
    /** @return array<int,array{teacher_id:int,requested_time_id:int,match_strength:string,sequence:int}> */
    private function candidates(int $courseId,array $times,string $now,bool $fullResponseWindow=true):array{
        if($courseId<1)return array();
        $teachers=$this->matches->eligibleTeachers($courseId,$now);
        $minStart=self::addSeconds($now,($fullResponseWindow?self::RESPONSE_SECONDS:0)+self::PROJECTION_BUFFER_SECONDS);
        $result=array();
        foreach($teachers as $teacher)foreach($times as $time){
            $start=(string)(is_array($time)?$time['starts_at_utc']:$time->starts_at_utc);
            $end=(string)(is_array($time)?$time['occupied_ends_at_utc']:$time->occupied_ends_at_utc);
            if($start<=$minStart)continue;
            $teacherId=(int)$teacher->teacher_id;
            $coverage=$this->coverage($teacherId,$start,$end);
            if($coverage===null||$this->occupancy->overlapping($teacherId,$start,$end))continue;
            try{CanonicalContinuationCapacityAuthority::assertNoActiveHold($teacherId,$start,$end);}
            catch(\InvalidArgumentException $error){if($error->getMessage()==='teacher_slot_conflict')continue;throw $error;}
            $candidate=array('teacher_id'=>$teacherId,'requested_time_id'=>(int)(is_array($time)?$time['id']:$time->id),'sequence'=>(int)(is_array($time)?($time['sequence']??$time['sequence_number']):$time->sequence_number),'match_strength'=>$coverage==='preferred'&&(string)$teacher->accepting_state==='accepting'?'strong':'possible');
            $prior=$result[$teacherId]??null;
            if(!$prior||array($candidate['match_strength']==='strong'?0:1,$candidate['sequence'])<array($prior['match_strength']==='strong'?0:1,$prior['sequence']))$result[$teacherId]=$candidate;
        }
        ksort($result,SORT_NUMERIC);
        return $result;
    }
    private function next(array $candidates,int $last,array $offered):?array{
        foreach(array_keys($candidates) as $id)if($id>$last&&!in_array($id,$offered,true))return $candidates[$id];
        foreach(array_keys($candidates) as $id)if(!in_array($id,$offered,true))return $candidates[$id];
        return null;
    }
    private function coverage(int $teacherId,string $start,string $end):?string{
        $cursor=$start;$state='preferred';
        foreach($this->availability->effective($teacherId,$start,$end) as $segment){
            if($segment['starts_at_utc']>$cursor||!in_array($segment['state'],array('preferred','requestable'),true))return null;
            if($segment['state']==='requestable')$state='requestable';
            if($segment['ends_at_utc']>$cursor)$cursor=min($end,$segment['ends_at_utc']);
            if($cursor===$end)return $state;
        }
        return null;
    }
}
