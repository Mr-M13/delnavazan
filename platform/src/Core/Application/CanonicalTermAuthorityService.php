<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalTermAuthorityRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Explicit, atomic canonical Term creation and lifecycle authority. */
final class CanonicalTermAuthorityService {
    private const CAPABILITY = 'dzn_manage_canonical_terms';
    /**
     * The single canonical source of the Term session allocation and of the Term change allowance.
     * Every consumer (commercial funding, Lesson issuance) reads these values or the values recorded
     * on the Term itself; no other literal may restate them.
     */
    public const SESSION_ALLOCATION = 12;
    public const REPLACEMENT_ALLOWANCE = 2;
    private const CHANNELS = array('staff_record', 'authenticated_platform', 'document_reference');
    private const TRANSITIONS = array('activate'=>array('authorised','current'),'close'=>array('current','closed'),'cancel_authorised'=>array('authorised','cancelled'),'cancel_current'=>array('current','cancelled'));

    public function __construct(private ?CanonicalTermAuthorityRepository $repository = null) { $this->repository ??= new CanonicalTermAuthorityRepository(); }

    /**
     * Create one canonical Term.
     *
     * `$withinCallerTransaction` lets another authority that already owns a transaction (Phase
     * 2A.2-R1 commercial Term binding) create the Term inside it, so the Term, the immutable origin
     * event and the caller's own binding commit together. The caller then owns commit, rollback and
     * its own durable command evidence; this authority still performs every validation and the
     * complete aggregate check.
     */
    public function create(int $enrolmentId, ?int $expectedLatestTermId, ?string $expectedLatestState, array $evidence, string $idempotencyKey, ?bool $withinCallerTransaction = null, ?string $capability = null): array {
        $this->requireCapability($capability); $actor=$this->actor();
        if ($enrolmentId < 1) throw new \InvalidArgumentException('Enrolment identity required');
        if (($expectedLatestTermId === null) !== ($expectedLatestState === null)) throw new \InvalidArgumentException('Expected aggregate position is incomplete');
        if ($expectedLatestState !== null && !in_array($expectedLatestState,array('closed','cancelled'),true)) throw new \InvalidArgumentException('Expected latest terminal state required');
        // Bind this invocation to what was committed before it can wait for the
        // canonical Enrolment lock. A terminal transition completed while this
        // command waits must not make an otherwise stale successor valid.
        $observedPosition=$this->aggregatePosition($this->repository->termsForEnrolment($enrolmentId,false));
        $proof=$this->evidence('canonical_term_created',$evidence);
        $facts=array('expected_latest_term_id'=>$expectedLatestTermId,'expected_latest_state'=>$expectedLatestState)+$proof['payload'];
        return $this->execute('create',$enrolmentId,$facts,$idempotencyKey,function(string$key,string$payload)use($enrolmentId,$expectedLatestTermId,$expectedLatestState,$observedPosition,$proof,$facts,$actor):array{
            $parent=$this->repository->enrolment($enrolmentId,true);
            $terms=$this->repository->termsForEnrolment($enrolmentId,true);foreach($terms as$t)$this->repository->events((int)$t->id,true);
            do_action('dzn_phase_2a2l_term_locks_held','create',$enrolmentId);
            $this->validAggregate($enrolmentId,$terms);
            if($winner=$this->repository->commandForDigest($key))return $this->replay($winner,$payload,'create',$enrolmentId,$facts);
            $this->validParent($parent);
            if($observedPosition!==$this->aggregatePosition($terms))throw new \InvalidArgumentException('stale_term_aggregate');
            $latest=$terms?end($terms):null;
            if(!$this->expectedPosition($latest,$expectedLatestTermId,$expectedLatestState)){
                if($latest&&$this->createdIntentMatches($latest,$expectedLatestTermId,$expectedLatestState,$proof))return $this->converge($key,$payload,'create',$enrolmentId,$latest,$actor,$expectedLatestTermId,$expectedLatestState);
                throw new \InvalidArgumentException('stale_term_aggregate');
            }
            foreach($terms as$t)if(in_array((string)$t->lifecycle_state,array('authorised','current'),true))throw new \InvalidArgumentException('applicable_term_exists');
            $now=gmdate('Y-m-d H:i:s');$sequence=count($terms)+1;
            $id=$this->repository->insertTerm(array('uid'=>Identifier::uid(),'reference_code'=>null,'enrolment_id'=>$enrolmentId,'sequence_number'=>$sequence,'status'=>'canonical','lesson_allocation'=>self::SESSION_ALLOCATION,'replacement_allowance'=>self::REPLACEMENT_ALLOWANCE,'starts_at'=>null,'ends_at'=>null,'activated_at'=>null,'completed_at'=>null,'payment_state'=>'not_applicable','created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,'archived_at'=>null,'archived_by'=>null,'record_model'=>'canonical_enrolment_term_v1','lifecycle_state'=>'authorised','applicable_slot'=>1));
            $this->repository->assignReference($id,Identifier::reference('DZN-TRM-',$id));do_action('dzn_phase_2a2l_after_term_insert','create');
            $this->repository->insertEvent($this->event($id,1,null,'authorised','canonical_term_created',$proof,$now,$actor));do_action('dzn_phase_2a2l_after_event_insert','create');
            $this->recordCommand($key,$payload,'create',$enrolmentId,$id,$expectedLatestTermId,$expectedLatestState,'authorised',$now,$actor);do_action('dzn_phase_2a2l_after_command_insert','create');
            $this->validAggregate($enrolmentId,$this->repository->termsForEnrolment($enrolmentId,false));
            return $this->result($id,'create',true,false,false);
        },$withinCallerTransaction);
    }

    public function activate(int $termId,string $expectedState,array $evidence,string $key):array{return $this->transition('activate',$termId,$expectedState,$evidence,$key);}
    public function close(int $termId,string $expectedState,array $evidence,string $key):array{return $this->transition('close',$termId,$expectedState,$evidence,$key);}
    public function cancel(int $termId,string $expectedState,array $evidence,string $key):array{return $this->transition('cancel',$termId,$expectedState,$evidence,$key);}

    private function transition(string$operation,int$termId,string$expectedState,array$evidence,string$idempotencyKey):array{
        $this->requireCapability();$actor=$this->actor();$hint=$this->repository->term($termId,false);
        if(!$hint||($hint->record_model??null)!=='canonical_enrolment_term_v1')throw new \InvalidArgumentException('canonical_term_required');
        $pairKey=$operation==='cancel'?'cancel_'.$expectedState:$operation;$pair=self::TRANSITIONS[$pairKey]??null;
        if(!$pair||$pair[0]!==$expectedState)throw new \InvalidArgumentException('transition_not_allowed');
        $proof=$this->evidence('canonical_term_'.$operation.'d',$evidence);$facts=array('term_id'=>$termId,'expected_state'=>$expectedState,'target_state'=>$pair[1])+$proof['payload'];$enrolmentId=(int)$hint->enrolment_id;
        return $this->execute($operation,$enrolmentId,$facts,$idempotencyKey,function(string$key,string$payload)use($operation,$termId,$expectedState,$pair,$proof,$facts,$actor,$enrolmentId):array{
            $parent=$this->repository->enrolment($enrolmentId,true);
            $terms=$this->repository->termsForEnrolment($enrolmentId,true);foreach($terms as$t)$this->repository->events((int)$t->id,true);
            do_action('dzn_phase_2a2l_term_locks_held',$operation,$enrolmentId);$this->validAggregate($enrolmentId,$terms);
            if($winner=$this->repository->commandForDigest($key))return $this->replay($winner,$payload,$operation,$enrolmentId,$facts);
            $this->validParent($parent);
            $term=null;foreach($terms as$t)if((int)$t->id===$termId)$term=$t;if(!$term)throw new \InvalidArgumentException('canonical_term_required');
            if((string)$term->lifecycle_state!==$expectedState){
                if((string)$term->lifecycle_state===$pair[1]&&$this->transitionIntentMatches($term,$expectedState,$pair[1],$proof))return $this->converge($key,$payload,$operation,$enrolmentId,$term,$actor,$termId,$expectedState);
                throw new \InvalidArgumentException('stale_term_state');
            }
            if(in_array($pair[1],array('closed','cancelled'),true))(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->assertTermClosable($termId);
            if(in_array($pair[1],array('closed','cancelled'),true)&&(new \Delnavazan\Platform\Core\Infrastructure\Repository\TermRepository())->hasAuthorisedCanonicalLessons($termId))throw new \InvalidArgumentException('authorised_canonical_lesson_exists');
            if(in_array($pair[1],array('closed','cancelled'),true)&&(new \Delnavazan\Platform\Core\Application\CanonicalLessonScheduleGuard())->activeFutureExists('term',$termId,gmdate('Y-m-d H:i:s')))throw new \InvalidArgumentException('active_future_schedule_exists');
            $now=gmdate('Y-m-d H:i:s');$this->repository->transition($term,$expectedState,$pair[1],$now,$actor);do_action('dzn_phase_2a2l_after_term_mutation',$operation);
            $sequence=count($this->repository->events($termId,false))+1;$this->repository->insertEvent($this->event($termId,$sequence,$expectedState,$pair[1],'canonical_term_'.$operation.'d',$proof,$now,$actor));do_action('dzn_phase_2a2l_after_event_insert',$operation);
            $this->recordCommand($key,$payload,$operation,$enrolmentId,$termId,$termId,$expectedState,$pair[1],$now,$actor);do_action('dzn_phase_2a2l_after_command_insert',$operation);
            $this->validAggregate($enrolmentId,$this->repository->termsForEnrolment($enrolmentId,false));
            return $this->result($termId,$operation,false,false,false);
        });
    }

    private function execute(string$operation,int$enrolmentId,array$facts,string$rawKey,callable$work,?bool $withinCallerTransaction=null):array{
        $key=CanonicalTermIdempotency::keyDigest($rawKey);$payload=CanonicalTermIdempotency::payloadDigest(array('domain'=>'canonical_term_v1','operation'=>$operation,'enrolment_id'=>$enrolmentId,'facts'=>$facts));
        if($withinCallerTransaction===true)return $work($key,$payload);
        $this->repository->begin();try{$result=$work($key,$payload);$this->repository->commit();return$result;}catch(\Throwable$e){$this->repository->rollback();$constraint=$this->repository->duplicateConstraint($e);if($constraint==='command_key_digest'&&($winner=$this->repository->commandForDigest($key)))return$this->replay($winner,$payload,$operation,$enrolmentId,$facts);throw$e;}
    }
    private function replay(object$c,string$payload,string$operation,int$enrolmentId,array$facts):array{
        if(!hash_equals((string)$c->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        $expectedId=$operation==='create'?($facts['expected_latest_term_id']??null):(int)($facts['term_id']??0);$expectedState=$operation==='create'?($facts['expected_latest_state']??null):(string)($facts['expected_state']??'');$resultState=$operation==='create'?'authorised':(string)($facts['target_state']??'');
        if($c->command_domain!=='canonical_term_v1'||$c->operation!==$operation||(int)$c->enrolment_id!==$enrolmentId||($c->expected_term_id===null?null:(int)$c->expected_term_id)!==$expectedId||($c->expected_from_state===null?null:(string)$c->expected_from_state)!==$expectedState||(string)$c->result_state!==$resultState)throw new \RuntimeException('Contaminated canonical Term command');
        $term=$this->repository->term((int)$c->result_term_id,false);if(!$term||(int)$term->enrolment_id!==$enrolmentId)throw new \RuntimeException('Contaminated canonical Term result');
        $proof=array('reason'=>(string)$facts['reason_code'],'channel'=>(string)$facts['evidence_channel'],'digest'=>(string)$facts['evidence_reference_digest'],'at'=>(string)$facts['evidence_at']);$complete=$operation==='create'?$this->createdIntentMatches($term,$expectedId,$expectedState,$proof):$this->transitionIntentMatches($term,$expectedState,$resultState,$proof);
        if(!$complete)throw new \RuntimeException('Contaminated canonical Term result');$this->validAggregate($enrolmentId,$this->repository->termsForEnrolment($enrolmentId,false));return$this->result((int)$term->id,$operation,false,true,false);
    }
    private function converge(string$key,string$payload,string$operation,int$enrolmentId,object$term,int$actor,?int$expectedTermId,?string$expected):array{$now=gmdate('Y-m-d H:i:s');$this->recordCommand($key,$payload,$operation,$enrolmentId,(int)$term->id,$expectedTermId,$expected,(string)$term->lifecycle_state,$now,$actor);return$this->result((int)$term->id,$operation,false,false,true);}
    private function validParent(?object$p):void{if(!$p||($p->record_model??null)!=='canonical_student_course_v1'||($p->status??null)!=='canonical'||!in_array((string)($p->lifecycle_state??''),array('authorised','current','paused'),true)||(int)($p->applicable_slot??0)!==1||(int)($p->accepted_service_arrangement_id??0)<1||$p->archived_at!==null)throw new \InvalidArgumentException('enrolment_not_applicable');}
    private function validAggregate(int$id,array$terms):void{$assessment=(new TermApplicabilityAssessment())->inspectForAuthority($id);if($terms&&$assessment['classification']===TermApplicabilityAssessment::DATA_INTEGRITY_CONFLICT)throw new \InvalidArgumentException('data_integrity_conflict');if(!$terms&&$assessment['classification']!==TermApplicabilityAssessment::NONE)throw new \InvalidArgumentException('data_integrity_conflict');}
    private function expectedPosition(?object$latest,?int$id,?string$state):bool{return(!$latest&&$id===null&&$state===null)||($latest&&$id===(int)$latest->id&&$state===(string)$latest->lifecycle_state&&in_array($state,array('closed','cancelled'),true));}
    private function aggregatePosition(array$terms):array{$latest=$terms?end($terms):null;return array('count'=>count($terms),'latest_id'=>$latest?(int)$latest->id:null,'latest_sequence'=>$latest?(int)$latest->sequence_number:null,'latest_state'=>$latest?(string)($latest->lifecycle_state??''):null,'latest_model'=>$latest?(string)($latest->record_model??''):null);}
    private function createdIntentMatches(object$t,?int$expectedId,?string$expectedState,array$p):bool{$events=$this->repository->events((int)$t->id,false);$origin=$events[0]??null;$prior=(int)$t->sequence_number===1?null:$this->repository->termsForEnrolment((int)$t->enrolment_id,false)[(int)$t->sequence_number-2]??null;return$origin&&$origin->reason_code==='canonical_term_created'&&$this->matchingEvidence($origin,$p)&&(($expectedId===null&&(int)$t->sequence_number===1)||($prior&&$expectedId===(int)$prior->id&&$expectedState===(string)$prior->lifecycle_state));}
    private function transitionIntentMatches(object$t,string$from,string$to,array$p):bool{foreach($this->repository->events((int)$t->id,false)as$event)if((string)$event->from_state===$from&&(string)$event->to_state===$to&&$this->matchingEvidence($event,$p))return true;return false;}
    private function evidence(string$reason,array$e):array{$channel=(string)($e['evidence_channel']??'');if(!in_array($channel,self::CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');$now=gmdate('Y-m-d H:i:s');$at=(string)($e['evidence_at']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$at)||strtotime($at.' UTC')===false||$at>$now)throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');$digest=CanonicalTermIdempotency::evidenceDigest((string)($e['evidence_reference']??''));return array('reason'=>$reason,'channel'=>$channel,'digest'=>$digest,'at'=>$at,'payload'=>array('reason_code'=>$reason,'evidence_channel'=>$channel,'evidence_reference_digest'=>$digest,'evidence_at'=>$at));}
    private function matchingEvidence(object$event,array$p):bool{return$event->reason_code===$p['reason']&&$event->evidence_channel===$p['channel']&&hash_equals((string)$event->evidence_reference_digest,$p['digest'])&&$event->occurred_at===$p['at'];}
    private function event(int$id,int$sequence,?string$from,string$to,string$reason,array$p,string$now,int$actor):array{return array('uid'=>Identifier::uid(),'term_id'=>$id,'event_sequence'=>$sequence,'from_state'=>$from,'to_state'=>$to,'reason_code'=>$reason,'evidence_channel'=>$p['channel'],'evidence_reference_digest'=>$p['digest'],'occurred_at'=>$p['at'],'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor);}
    private function recordCommand(string$key,string$payload,string$operation,int$enrolmentId,int$resultTermId,?int$expectedTermId,?string$expected,string$result,string$now,int$actor):void{$this->repository->insertCommand(array('uid'=>Identifier::uid(),'command_domain'=>'canonical_term_v1','operation'=>$operation,'command_key_digest'=>$key,'command_payload_digest'=>$payload,'enrolment_id'=>$enrolmentId,'expected_term_id'=>$expectedTermId,'expected_from_state'=>$expected,'result_term_id'=>$resultTermId,'result_state'=>$result,'created_at'=>$now,'created_by'=>$actor));}
    private function requireCapability(?string $capability=null):void{if(!current_user_can($capability??self::CAPABILITY))throw new \RuntimeException('Unauthorized');}
    private function actor():int{$id=get_current_user_id();if($id<1)throw new \RuntimeException('Canonical Term actor is unavailable');return$id;}
    private function result(int$id,string$operation,bool$created,bool$idempotent,bool$already):array{return array('term_id'=>$id,'operation'=>$operation,'created'=>$created,'idempotent'=>$idempotent,'already_applied'=>$already);}
}
