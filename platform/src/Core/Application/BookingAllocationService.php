<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Booking\TeacherBookingPriorityService;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Sequential offers. The delivery remains the sole confirmation anchor;
 * automatic history lives in the allocation ledger.
 */
final class BookingAllocationService {
    /** The 24-hour response window is the ceiling for one offer. */
    public const RESPONSE_SECONDS=BookingLeadTimePolicy::RESPONSE_WINDOW_SECONDS;
    /** No new offer is made inside T−12h; the request escalates. */
    public const TERMINAL_REFERRAL_REASON='terminal_offer_window_closed';
    /** A prioritized Teacher always receives the first offer, for at most eight hours. */
    public const PRIORITY_RESPONSE_SECONDS=28800;
    /** The silent, still-viable sole Teacher keeps the same pending offer. */
    public const HOLD_ALERT_REASON='sole_candidate_unanswered';
    // A response must finish before the lesson begins; leave an hour for
    // confirmation and provider projection after the complete response window.
    public const PROJECTION_BUFFER_SECONDS=BookingLeadTimePolicy::PROJECTION_BUFFER_SECONDS;
    /**
     * A request that crossed the minimum notice before it could be (re)offered.
     *
     * Distinct from `no_viable_teacher`/`candidates_exhausted`, which both mean
     * every eligible Teacher was actually considered. Here no Teacher could have
     * been asked, and no Teacher decision can change the outcome, so the referral
     * is retryable: the Student chooses later times and allocation succeeds.
     */
    public const LEAD_TIME_REFERRAL_REASON='requested_time_inside_lead_time';
    private BookingTeacherOccupancyReadService $occupancy;
    private BookingTeacherWorkloadService $workload;
    private TeacherBookingPriorityService $priorities;
    public function __construct(
        private ?BookingAllocationRepository $repo=null,
        private ?BookingRequestMatchAssessmentRepository $matches=null,
        private ?BookingTeacherDeliveryRepository $deliveries=null,
        private ?TeacherAvailabilityService $availability=null,
        ?BookingTeacherWorkloadService $workload=null,
        ?TeacherBookingPriorityService $priorities=null
    ){
        $this->repo??=new BookingAllocationRepository();
        $this->matches??=new BookingRequestMatchAssessmentRepository();
        $this->deliveries??=new BookingTeacherDeliveryRepository();
        $this->availability??=new TeacherAvailabilityService();
        $this->occupancy=new BookingTeacherOccupancyReadService();
        $this->workload=$workload??new BookingTeacherWorkloadService();
        $this->priorities=$priorities??new TeacherBookingPriorityService();
    }

    /** Called inside the submission transaction, which already owns the new request. */
    public function startWithinTransaction(int $requestId,int $courseId,array $times,string $now):?array{
        if($this->repo->delivery($requestId))throw new \RuntimeException('Allocation already started');
        $this->lockEligibleTeacherRoots($courseId,$now);
        $candidates=$this->candidates($courseId,$times,$now);
        if(!$candidates){$this->repo->referral($requestId,$this->emptyCandidatesReason($times,$now,true,'no_viable_teacher'),$now);return null;}
        $timeSetVersion=$this->repo->currentTimeSetVersion($requestId);
        $offered=$this->repo->stickyDeclinedTeacherIds($requestId,$timeSetVersion);
        // A priority offer is not a round-robin turn: it does not move the cursor.
        $priority=$this->prioritizedOffer($candidates,$courseId,$times,$now,$offered);
        if($priority!==null)return $this->startOffer($requestId,$priority['candidate'],$priority['deadline'],$now,$timeSetVersion);
        $last=$this->repo->cursor($courseId,$now);
        $chosen=$this->next($candidates,$last,$offered,$now);
        if(!$chosen){$this->repo->referral($requestId,'candidates_exhausted',$now);return null;}
        $deadline=$this->deadlineFor($this->startForTimeId((int)$chosen['requested_time_id'],$times),$now,count($this->availablePool($candidates,$offered)));
        if($deadline===null){$this->repo->referral($requestId,self::TERMINAL_REFERRAL_REASON,$now);return null;}
        $this->repo->advanceCursor($courseId,$chosen['teacher_id'],$now);
        return $this->startOffer($requestId,$chosen,$deadline,$now,$timeSetVersion);
    }
    private function startOffer(int $requestId,array $candidate,string $deadline,string $now,int $timeSetVersion):array{
        $deliveryId=$this->deliveries->create(array('uid'=>Identifier::uid(),'booking_request_id'=>$requestId,'requested_time_id'=>$candidate['requested_time_id'],'teacher_id'=>$candidate['teacher_id'],'state'=>'pending_request','match_strength'=>$candidate['match_strength'],'created_at'=>$now,'updated_at'=>$now));
        $this->repo->addOffer($requestId,$deliveryId,$candidate,1,$now,$deadline,$timeSetVersion);
        return array('delivery_id'=>$deliveryId,'teacher_id'=>$candidate['teacher_id'],'requested_time_id'=>$candidate['requested_time_id'],'state'=>'pending_request','match_strength'=>$candidate['match_strength'],'deadline_at'=>$deadline);
    }
    /**
     * The best prioritized candidate for this request, if any.
     *
     * Priority never bypasses a guard: the candidate must already have passed
     * eligibility, availability, occupancy and continuation checks. If the
     * priority Teacher is not in that set, the normal channel runs untouched.
     *
     * @return array{candidate:array,deadline:string}|null
     */
    private function prioritizedOffer(array $candidates,int $courseId,array $times,string $now,array $offered):?array{
        if(!$candidates)return null;
        $ids=$this->priorities->prioritizedTeachers(array_keys($candidates),$courseId,$now);
        if(!$ids)return null;
        $pool=array();
        foreach($ids as $id)if(isset($candidates[(int)$id])&&!in_array((int)$id,$offered,true))$pool[(int)$id]=$candidates[(int)$id];
        if(!$pool)return null;
        $candidate=$this->best($pool,$now);
        if(!$candidate)return null;
        $deadline=$this->priorityDeadlineFor($this->startForTimeId((int)$candidate['requested_time_id'],$times),$now);
        if($deadline===null)return null;
        return array('candidate'=>$candidate,'deadline'=>$deadline);
    }
    /** Priority ordering: match strength, weighted workload, then Teacher id. No cursor. */
    private function best(array $pool,string $now):?array{
        if(!$pool)return null;
        $workload=$this->workload->scores(array_keys($pool),$now);
        uasort($pool,function(array $a,array $b)use($workload):int{
            $strengthA=$a['match_strength']==='strong'?0:1;
            $strengthB=$b['match_strength']==='strong'?0:1;
            if($strengthA!==$strengthB)return $strengthA<=>$strengthB;
            $loadA=(float)($workload[$a['teacher_id']]['weighted']??0.0);
            $loadB=(float)($workload[$b['teacher_id']]['weighted']??0.0);
            if($loadA!=$loadB)return $loadA<=>$loadB;
            return $a['teacher_id']<=>$b['teacher_id'];
        });
        return reset($pool);
    }
    /** Eight hours, capped by the same terminal stop as every new offer. */
    private function priorityDeadlineFor(string $startUtc,string $now):?string{
        $window=$this->deadlineFor($startUtc,$now,0);
        if($window===null)return null;
        $at=strtotime($now.' UTC');
        $deadline=min($at+self::PRIORITY_RESPONSE_SECONDS,strtotime($window.' UTC'));
        return $deadline>$at?gmdate('Y-m-d H:i:s',$deadline):null;
    }

    /** The caller owns the current delivery row lock and transaction. */
    public function advanceLocked(object $delivery,string $outcome,string $now):?array{
        if(!in_array($outcome,array('declined','expired'),true))throw new \InvalidArgumentException('Invalid offer outcome');
        $requestId=(int)$delivery->booking_request_id;
        $offer=$this->repo->offer($requestId,true);
        if(!$offer||(int)$offer->delivery_id!==(int)$delivery->id||(int)$offer->teacher_id!==(int)$delivery->teacher_id||$delivery->state!=='pending_request')throw new \InvalidArgumentException('Booking offer is no longer pending');
        if($outcome==='expired'&&(string)$offer->deadline_at>$now)throw new \InvalidArgumentException('Booking offer has not expired');
        $request=$this->repo->request($requestId);
        if(!$request||$request->lifecycle_status!=='submitted'||$request->resolution_state!=='unresolved'||$request->privacy_erased_at!==null){
            $this->repo->transitionOffer((int)$offer->id,$outcome,$now,$outcome);
            return null;
        }
        $timeSetVersion=$this->repo->currentTimeSetVersion($requestId);
        $history=$this->repo->historyForVersion($requestId,$timeSetVersion);
        $offered=array_map(static fn(object $row):int=>(int)$row->teacher_id,$history);
        $offered=array_values(array_unique(array_merge($offered,$this->repo->stickyDeclinedTeacherIds($requestId,$timeSetVersion))));
        $times=$this->repo->times($requestId);
        $this->lockEligibleTeacherRoots((int)$request->selected_intro_course_id,$now);
        $candidates=$this->candidates((int)$request->selected_intro_course_id,$times,$now,true,(int)$requestId);
        // The hold question is "can this Teacher still accept?", not "could a new
        // offer be made?". Inside the maximum-window lead no new offer is possible,
        // but the current Teacher may still be perfectly viable until T−1h.
        $viable=$this->candidates((int)$request->selected_intro_course_id,$times,$now,false,(int)$requestId);
        $pool=$this->availablePool($candidates,$offered);
        $next=$this->next($candidates,(int)$offer->teacher_id,$offered,$now);
        $requestStart=$this->earliestStart($times);
        $terminal=$requestStart!==null&&self::addSeconds($requestStart,-BookingLeadTimePolicy::PROJECTION_BUFFER_SECONDS)<=$now;

        $nextDeadline=$next?$this->deadlineFor($this->startForTimeId((int)$next['requested_time_id'],$times),$now,count($pool)):null;
        if($next&&$nextDeadline!==null){
            $this->repo->transitionOffer((int)$offer->id,$outcome,$now,$outcome);
            $deliveryId=$this->repo->rotateDelivery($delivery,$next,$now);
            $this->repo->addOffer($requestId,$deliveryId,$next,(int)$offer->offer_sequence+1,$now,$nextDeadline,$timeSetVersion);
            return array('delivery_id'=>$deliveryId,'teacher_id'=>$next['teacher_id'],'requested_time_id'=>$next['requested_time_id'],'state'=>'pending_request','match_strength'=>$next['match_strength'],'deadline_at'=>$nextDeadline);
        }

        // Sole-teacher hold: an expiry (never a decline) of the current Teacher, who
        // is still viable, when no next offer can be made, keeps the same pending
        // offer alive. The hold is not a new offer, so it may extend past T−12h up
        // to T−1h.
        if($outcome==='expired'&&!$terminal&&isset($viable[(int)$offer->teacher_id])){
            $holdDeadline=$this->deadlineFor($this->startForTimeId((int)$offer->requested_time_id,$times),$now,1,false);
            if($holdDeadline!==null&&$holdDeadline>(string)$offer->deadline_at){
                $this->repo->extendOffer((int)$offer->id,$holdDeadline,$now);
                $this->repo->alert($requestId,'hold',self::HOLD_ALERT_REASON,'high',$now);
                $this->repo->queueBookingNotice((int)$delivery->id,(int)$offer->id,'hold_teacher',$now);
                return null;
            }
        }

        $this->repo->transitionOffer((int)$offer->id,$outcome,$now,$outcome);
        $this->deliveries->transitionForAllocation((int)$delivery->id,'pending_request',$outcome,$now);
        if($terminal){
            $this->repo->queueBookingNotice((int)$delivery->id,(int)$offer->id,'terminal',$now);
            $this->repo->referral($requestId,self::TERMINAL_REFERRAL_REASON,$now);
            return null;
        }
        $reason=isset($viable[(int)$offer->teacher_id])?'candidates_exhausted':'teacher_became_unavailable';
        $this->repo->referral($requestId,$this->emptyCandidatesReason($times,$now,true,$reason),$now);
        return null;
    }

    public function assertPending(object $delivery,string $now):void{
        $offer=$this->repo->offer((int)$delivery->booking_request_id,true);
        if(!$offer||(int)$offer->delivery_id!==(int)$delivery->id||(int)$offer->teacher_id!==(int)$delivery->teacher_id||(int)$offer->requested_time_id!==(int)$delivery->requested_time_id||(string)$offer->deadline_at<=$now)throw new \InvalidArgumentException('Booking offer expired or reassigned');
    }
    /**
     * Revalidate one exact Student preference.
     *
     * The originally offered time keeps strict availability rules. A different
     * explicitly selected preference, or an unmatched-request claim, may proceed
     * outside declared availability and reports that override to the caller.
     */
    public function assertAcceptable(object $delivery,string $now,?int $requestedTimeId=null,bool $allowOutsideAvailability=false):bool{
        $this->assertPending($delivery,$now);
        $requestedTimeId??=(int)$delivery->requested_time_id;
        $times=array_values(array_filter($this->repo->times((int)$delivery->booking_request_id),static fn(object $time):bool=>(int)$time->id===$requestedTimeId));
        if(!$times)throw new \InvalidArgumentException('Choose one of the requested times');
        $candidates=$this->candidates((int)$delivery->selected_intro_course_id,$times,$now,false,(int)$delivery->booking_request_id,false,$allowOutsideAvailability);
        $candidate=$candidates[(int)$delivery->teacher_id]??null;
        if(!$candidate||(int)$candidate['requested_time_id']!==$requestedTimeId)throw new \InvalidArgumentException('Booking time is no longer available');
        return !empty($candidate['availability_overridden']);
    }
    /**
     * A Teacher's acceptance has been committed to the delivery.
     *
     * A free introduction goes straight to provider projection, so it is already
     * a committed winner and retires every overlapping pending offer to the same
     * Teacher. A paid introduction stops at `awaiting_payment`; it has not
     * secured the slot yet (Option B), so it must not retire other offers. The
     * payment path calls `assertPaidSlotAvailable()` and, once it has committed
     * this confirmation, `retireOverlappingOffers()`.
     */
    public function acceptedLocked(object $delivery,string $now,bool $slotSecured=true):void{
        $offer=$this->repo->offer((int)$delivery->booking_request_id,true);
        if(!$offer||(int)$offer->teacher_id!==(int)$delivery->teacher_id)throw new \RuntimeException('Booking offer changed');
        if($slotSecured)$this->retireCompetingOffers($delivery,$now);
        $this->repo->transitionOffer((int)$offer->id,'accepted',$now,'teacher_accepted');
        $this->repo->resolveAlerts((int)$delivery->booking_request_id,$now);
    }
    public function administratorAcceptedLocked(object $delivery,string $now,bool $slotSecured=true):void{
        $offer=$this->repo->offer((int)$delivery->booking_request_id,true);
        if($slotSecured)$this->retireCompetingOffers($delivery,$now);
        if($offer)$this->repo->transitionOffer((int)$offer->id,'superseded',$now,'administrator_agreement');
        $this->repo->resolveAlerts((int)$delivery->booking_request_id,$now);
    }
    /**
     * Verify that a settled paid acceptance can still own its slot.
     *
     * The caller owns the transaction. The Student scheduling root is taken
     * before the Teacher root, matching every other booking write; the
     * confirmation row is re-locked by the caller only after these roots are
     * held, so no acceptance path can invert the order.
     *
     * The check uses committed capacity only: canonical schedules plus committed
     * introductory confirmations. Pending offers and `awaiting_payment`
     * confirmations deliberately do not count, because an unpaid acceptance
     * reserves nothing. Nothing is retired here: the caller must prove its own
     * confirmation is still `awaiting_payment` before calling
     * `retireOverlappingOffers()`.
     *
     * @return array{starts_at_utc:string,occupied_ends_at_utc:string}|null null when the slot is already taken
     */
    public function assertPaidSlotAvailable(int $requestId,int $teacherId,int $requestedTimeId,int $studentId,string $startsAtUtc,string $occupiedEndUtc,string $now):?array{
        $start=trim($startsAtUtc);$end=trim($occupiedEndUtc);
        if($requestedTimeId>0){
            $time=$this->repo->requestedTime($requestedTimeId);
            if(!$time||(int)$time->booking_request_id!==$requestId)throw new \RuntimeException('Booking requested time is unavailable');
            $start=(string)$time->starts_at_utc;$end=(string)$time->occupied_ends_at_utc;
        }
        if($start===''||$end===''||$end<=$start)throw new \RuntimeException('Booking requested time is unavailable');
        $actor=function_exists('get_current_user_id')?max(0,(int)get_current_user_id()):0;
        $this->repo->ensureAndLockStudentRoot($studentId,$now,$actor);
        $this->repo->ensureAndLockTeacherRoot($teacherId,$now,$actor);
        if((new CanonicalTeacherOccupancyReadService())->overlapping($teacherId,$start,$end,0,$requestId))return null;
        return array('starts_at_utc'=>$start,'occupied_ends_at_utc'=>$end);
    }
    /** A committed paid winner makes every overlapping pending offer impossible. */
    public function retireOverlappingOffers(int $requestId,int $teacherId,string $start,string $end,string $now):void{
        if($start===''||$end===''||$end<=$start)return;
        $this->retireCompetingOffers((object)array('id'=>0,'booking_request_id'=>$requestId,'teacher_id'=>$teacherId,'starts_at_utc'=>$start,'occupied_ends_at_utc'=>$end),$now);
    }
    /** A committed winner makes every overlapping pending offer to this Teacher impossible. */
    private function retireCompetingOffers(object $delivery,string $now):void{
        $start=(string)($delivery->starts_at_utc??'');
        $end=(string)($delivery->occupied_ends_at_utc??'');
        if($start===''||$end===''||$end<=$start)return;
        (new BookingOfferRetirementService($this->repo))->retireCompeting((int)$delivery->teacher_id,$start,$end,(int)$delivery->booking_request_id,$now);
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
            $start=$this->earliestStart($this->repo->times($requestId));
            if($start!==null&&self::addSeconds($start,-BookingLeadTimePolicy::TERMINAL_ESCALATION_SECONDS)<=$now&&!$this->repo->hasAlert($requestId,'escalation')){
                $this->repo->alert($requestId,'escalation','unresolved_at_terminal_threshold','high',$now);
            }
            $this->repo->commit();
        }catch(\Throwable $error){
            $contention=BookingContention::capture($error);
            $this->repo->rollback();
            throw $contention??$error;
        }
    }
    /** Read-only, privacy-safe preview for an operator before any historical mutation. */
    public function recoveryPreview(int $requestId,string $now):array{
        $request=$this->repo->request($requestId);
        if(!$request)return array('request_id'=>$requestId,'action'=>'missing');
        $delivery=$this->repo->delivery($requestId);
        $offer=$this->repo->offer($requestId);
        $confirmed=$this->repo->confirmationExists($requestId);
        $eligible=$request->lifecycle_status==='submitted'&&$request->resolution_state==='unresolved'&&$request->privacy_erased_at===null&&!$confirmed;
        $times=$this->repo->times($requestId);
        $candidates=$eligible?$this->candidates((int)$request->selected_intro_course_id,$times,$now):array();
        $action=!$eligible?'preserve_existing_lifecycle':($offer?'already_allocated':($delivery&&$delivery->state==='pending_request'?'reconcile_legacy_offer':($delivery?'manual_review':($candidates?'start_offer':'refer_administrator'))));
        $start=$this->earliestStart($times);
        return array('request_id'=>$requestId,'reference'=>(string)($request->reference_code??''),'submitted_at'=>(string)$request->created_at,'action'=>$action,'existing_delivery_state'=>$delivery?(string)$delivery->state:null,'active_offer'=>$offer!==null,'confirmation_exists'=>$confirmed,'viable_teacher_count'=>count($candidates),'terminal_threshold_reached'=>$start!==null&&self::addSeconds($start,-BookingLeadTimePolicy::TERMINAL_ESCALATION_SECONDS)<=$now,'terminal_expiry_reached'=>$start!==null&&self::addSeconds($start,-BookingLeadTimePolicy::PROJECTION_BUFFER_SECONDS)<=$now);
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
                $start=$this->earliestStart($this->repo->times($requestId));
                $deadline=$start!==null?$this->deadlineFor($start,(string)$delivery->created_at,max(0,(int)$preview['viable_teacher_count'])):null;
                if($deadline===null){$this->repo->referral($requestId,self::TERMINAL_REFERRAL_REASON,$now);}
                else{
                    $this->repo->historicalOffer($requestId,$delivery,$deadline,$this->repo->currentTimeSetVersion($requestId));
                    if($deadline<=$now)$this->advanceLocked($delivery,'expired',$now);
                }
            }else{
                $this->startWithinTransaction($requestId,(int)$request->selected_intro_course_id,$this->repo->times($requestId),$now);
            }
            $this->repo->commit();return $preview+array('applied'=>true);
        }catch(\Throwable $error){
            $contention=BookingContention::capture($error);
            $this->repo->rollback();
            throw $contention??$error;
        }
    }
    public static function addSeconds(string $utc,int $seconds):string{
        return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->modify(($seconds>=0?'+':'').$seconds.' seconds')->format('Y-m-d H:i:s');
    }
    /**
     * The single offer-window authority.
     *
     * The budget is everything between now and the terminal stop (T−12h). It is
     * split across the Teachers still in the pool plus this offer, clamped to
     * [4h, 24h], then capped so confirmation and projection finish before T−1h.
     *
     * A new offer is impossible when the minimum four-hour window would already
     * cross the terminal stop: this is what makes a 26h/3 request produce three
     * four-hour windows and then stop, instead of inventing a fifth hour of
     * budget. Callers may disable the terminal gate only for a sole-Teacher hold,
     * which extends the same offer (not a new offer) up to T−1h.
     *
     * @param int $remainingPool Candidates still eligible for an offer, including the one being offered.
     */
    public static function deadlineFor(string $startUtc,string $now,int $remainingPool,bool $enforceTerminalStop=true):?string{
        $start=strtotime($startUtc.' UTC');$at=strtotime($now.' UTC');
        if($start===false||$at===false||$start<=$at)return null;
        $terminalStop=$start-BookingLeadTimePolicy::TERMINAL_ESCALATION_SECONDS;
        if($enforceTerminalStop&&$at+BookingLeadTimePolicy::MIN_WINDOW_SECONDS>$terminalStop)return null;
        $budget=max(0,$terminalStop-$at);
        $pool=max(0,$remainingPool);
        $window=(int)floor($budget/($pool+1));
        $window=max(BookingLeadTimePolicy::MIN_WINDOW_SECONDS,min(BookingLeadTimePolicy::RESPONSE_WINDOW_SECONDS,$window));
        $deadline=min($at+$window,$start-BookingLeadTimePolicy::PROJECTION_BUFFER_SECONDS);
        return $deadline<=$at?null:gmdate('Y-m-d H:i:s',$deadline);
    }
    /** @return array<int,array{teacher_id:int,requested_time_id:int,match_strength:string,sequence:int}> */
    private function candidates(int $courseId,array $times,string $now,bool $fullResponseWindow=true,int $excludeBookingRequestId=0,bool $includePendingOffers=true,bool $allowOutsideAvailability=false):array{
        if($courseId<1)return array();
        $teachers=$this->matches->eligibleTeachers($courseId,$now);
        $result=array();
        foreach($teachers as $teacher)foreach($times as $time){
            $start=(string)(is_array($time)?$time['starts_at_utc']:$time->starts_at_utc);
            $end=(string)(is_array($time)?$time['occupied_ends_at_utc']:$time->occupied_ends_at_utc);
            // One boundary authority for the allocation decision: a new offer uses
            // the time-budgeted offer window (4h before T−12h), while an acceptance
            // recheck uses the projection buffer only. The public day grid keeps
            // the maximum-window lead for new submissions.
            if($fullResponseWindow?!BookingLeadTimePolicy::isStartOfferable($start,$now):!BookingLeadTimePolicy::isStartAllowed($start,$now,false))continue;
            $teacherId=(int)$teacher->teacher_id;
            $coverage=$this->coverage($teacherId,$start,$end,$this->availability->instrumentIdForCourse($courseId));
            $outside=$coverage===null;
            // Hard scheduling constraints (Teacher occupancy and continuation holds) are never
            // overridden. Only the Teacher's declared availability may be bypassed explicitly.
            if(($outside&&!$allowOutsideAvailability)||$this->occupancy->overlapping($teacherId,$start,$end,0,$excludeBookingRequestId,$includePendingOffers))continue;
            try{CanonicalContinuationCapacityAuthority::assertNoActiveHold($teacherId,$start,$end);}
            catch(\InvalidArgumentException $error){if($error->getMessage()==='teacher_slot_conflict')continue;throw $error;}
            $candidate=array('teacher_id'=>$teacherId,'requested_time_id'=>(int)(is_array($time)?$time['id']:$time->id),'sequence'=>(int)(is_array($time)?($time['sequence']??$time['sequence_number']):$time->sequence_number),'match_strength'=>$coverage==='preferred'&&(string)$teacher->accepting_state==='accepting'?'strong':'possible','availability_overridden'=>$outside);
            $prior=$result[$teacherId]??null;
            if(!$prior||array($candidate['match_strength']==='strong'?0:1,$candidate['sequence'])<array($prior['match_strength']==='strong'?0:1,$prior['sequence']))$result[$teacherId]=$candidate;
        }
        ksort($result,SORT_NUMERIC);
        return $result;
    }
    /** Candidates not yet offered this request, including the one about to be chosen. */
    private function availablePool(array $candidates,array $offered):array{
        $pool=array();
        foreach($candidates as $id=>$candidate)if(!in_array((int)$id,$offered,true))$pool[(int)$id]=$candidate;
        return $pool;
    }
    private function startForTimeId(int $timeId,array $times):string{
        foreach($times as $time){
            $id=(int)(is_array($time)?($time['id']??0):($time->id??0));
            if($id===$timeId)return (string)(is_array($time)?($time['starts_at_utc']??''):($time->starts_at_utc??''));
        }
        return '';
    }
    /** The earliest requested start bounds the request-level terminal thresholds. */
    private function earliestStart(array $times):?string{
        $starts=array();
        foreach($times as $time){
            $start=(string)(is_array($time)?($time['starts_at_utc']??''):($time->starts_at_utc??''));
            if($start!=='')$starts[]=$start;
        }
        if(!$starts)return null;
        sort($starts);
        return $starts[0];
    }
    /**
     * Approved allocation order:
     *
     *  1. eligibility — already enforced by candidates(), never re-litigated here;
     *  2. stronger match — `strong` before `possible`;
     *  3. lowest weighted cross-instrument workload;
     *  4. deterministic round-robin — the next Teacher id after the Course cursor,
     *     wrapping once, with the Teacher id as the final total order.
     *
     * Teachers already offered this request are excluded first, so a decline or
     * expiry always moves to a different Teacher. The sort is total and stable, so
     * the same inputs always select the same Teacher.
     */
    private function next(array $candidates,int $last,array $offered,string $now):?array{        $pool=array();
        foreach($candidates as $id=>$candidate)if(!in_array($id,$offered,true))$pool[$id]=$candidate;
        if(!$pool)return null;
        $workload=$this->workload->scores(array_keys($pool),$now);
        uasort($pool,function(array $a,array $b)use($workload,$last):int{
            $strengthA=$a['match_strength']==='strong'?0:1;
            $strengthB=$b['match_strength']==='strong'?0:1;
            if($strengthA!==$strengthB)return $strengthA<=>$strengthB;
            $loadA=(float)($workload[$a['teacher_id']]['weighted']??0.0);
            $loadB=(float)($workload[$b['teacher_id']]['weighted']??0.0);
            if($loadA!=$loadB)return $loadA<=>$loadB;
            $turnA=$a['teacher_id']>$last?0:1;
            $turnB=$b['teacher_id']>$last?0:1;
            if($turnA!==$turnB)return $turnA<=>$turnB;
            return $a['teacher_id']<=>$b['teacher_id'];
        });
        return reset($pool);
    }
    private function coverage(int $teacherId,string $start,string $end,?int $instrumentId=null):?string{
        $cursor=$start;$state='preferred';
        foreach($this->availability->effective($teacherId,$start,$end,$instrumentId) as $segment){
            if($segment['starts_at_utc']>$cursor||!in_array($segment['state'],array('preferred','requestable'),true))return null;
            if($segment['state']==='requestable')$state='requestable';
            if($segment['ends_at_utc']>$cursor)$cursor=min($end,$segment['ends_at_utc']);
            if($cursor===$end)return $state;
        }
        return null;
    }
    /**
     * Explain an empty candidate set without inventing a Teacher decision.
     *
     * `no_viable_teacher` and `candidates_exhausted` both state that every
     * eligible Teacher was considered and none could take the time. A request
     * that has crossed the minimum notice is different: no Teacher could have
     * been asked, because its requested starts are no longer requestable at this
     * instant. Reporting that as "no Teacher" sends the Student and the
     * coordinator looking for a Teacher who cannot exist. The crossed-boundary
     * case therefore gets its own retryable reason: choose later times and the
     * request can be allocated normally.
     *
     * Precedence is deliberate. When every requested start is inside the window,
     * no Teacher decision can change the outcome, so the lead-time reason wins
     * even if the eligible set was also empty. The predicate is the shared policy
     * one, evaluated at the same instant as the candidate filter above.
     */
    private function emptyCandidatesReason(array $times,string $now,bool $fullResponseWindow,string $fallback):string{
        $starts=array();
        foreach($times as $time)$starts[]=(string)(is_array($time)?$time['starts_at_utc']:$time->starts_at_utc);
        $inside=$fullResponseWindow?BookingLeadTimePolicy::allStartsInsideOfferWindow($starts,$now):BookingLeadTimePolicy::allStartsInsideMinimumNotice($starts,$now,false);
        return $inside?self::LEAD_TIME_REFERRAL_REASON:$fallback;
    }
    /** Lock in a global Teacher-id order before allocating across instruments. */
    private function lockEligibleTeacherRoots(int $courseId,string $now):void{
        $ids=array();
        foreach($this->matches->eligibleTeachers($courseId,$now) as $teacher)$ids[(int)$teacher->teacher_id]=true;
        $ids=array_keys($ids);sort($ids,SORT_NUMERIC);
        $actor=function_exists('get_current_user_id')?max(0,(int)get_current_user_id()):0;
        foreach($ids as $teacherId)if($teacherId>0)$this->repo->ensureAndLockTeacherRoot($teacherId,$now,$actor);
    }
}
