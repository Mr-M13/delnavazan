<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Port\ProviderEventNormalizer;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Provider-event ingestion into the Phase-P evidence seam (Phase 2A.2-V, V-D8/V-D9).
 *
 * The integration layer is a trusted evidence *source*, never an evidence owner. It accepts only a
 * delivery envelope that a named trusted transport has already authenticated, translates that exact
 * body to provider-neutral facts, deduplicates on the provider event key against the complete
 * immutable context, records a durable conflict receipt for any changed context, and then hands the
 * facts to `CanonicalAttendanceIntakeService::ingestProviderEvidence`. It performs no provider
 * cryptography itself and never guesses that a delivery is genuine.
 *
 * It never resolves a participant identity, never asserts verification, never writes Phase-O outcome
 * storage, never settles attendance and never completes or cancels a Lesson. A provider conference or
 * a calendar event existing is an integration reference, not attendance evidence.
 *
 * The receipt is immutable and append-only: it records only that one exact authenticated delivery was
 * received, and it is written before the Phase-P handoff so an interruption always leaves evidence.
 * Admission is a separate appended outcome, written only after the handoff succeeded, so a receipt can
 * never be read — or returned to a retrying caller — as admitted when Phase P never saw the fact.
 *
 * Every decision about one provider event key is taken under the same provider-scoped lock: it is
 * acquired before the committed head is read, and it is held until the deciding transaction has ended.
 * Two deliveries that name different Lessons lock different canonical chains and can therefore only
 * meet on that shared lock, so a new receipt takes the next `event_sequence` without colliding on the
 * unique `(provider_code,event_sequence)` index — and a contender that loses the race re-reads the
 * committed winner and leaves a durable conflict receipt for its changed context instead of being
 * merely rejected by the unique `provider_event` index.
 */
final class ProviderEventIngestService {
    public function __construct(
        private ?ProviderIntegrationRepository $repository=null,
        private ?ProviderEventNormalizer $normalizer=null,
        private ?CanonicalAttendanceIntakeService $intake=null
    ){
        $this->repository??=new ProviderIntegrationRepository();
        if($this->normalizer===null)throw new \RuntimeException('Provider event normaliser must be supplied by the Integrations module');
        $this->intake??=new CanonicalAttendanceIntakeService();
    }

    /**
     * Ingest one authenticated provider delivery.
     *
     * A raw delivery is refused before anything is read from it. An exact duplicate converges on the
     * recorded receipt and outcome, re-attempting the Phase-P handoff when no admission was ever
     * recorded; a materially different context is durably refused as a conflict, and the original
     * receipt and its history are never overwritten.
     */
    public function ingest(array $delivery,string $key):array{
        $this->requireCapability();
        $actor=$this->actor();
        // A raw provider delivery is never evidence. Only an envelope a named trusted transport has
        // already authenticated — naming the transport, the exact body it validated, the authenticated
        // instant and the transport's own proof reference — reaches the normaliser, and the normaliser
        // is handed that envelope and nothing else, so a header, a channel token or a bare body can
        // never be promoted into attendance evidence.
        $envelope=ProviderIntegrationRule::deliveryEnvelope($delivery);
        $providerCode=$envelope['provider_code'];
        if(!$this->normalizer->verify($delivery))throw new \InvalidArgumentException('provider_event_unverified');
        $facts=$this->normalizer->normalise($envelope);
        $facts['provider_code']=$providerCode;
        $eventKey=trim((string)($facts['provider_event_key']??''));
        if($eventKey==='')throw new \InvalidArgumentException('Provider event key required');
        $eventKeyDigest=ProviderIntegrationIdempotency::providerEventKey($eventKey);
        // The occurrence binding comes from the authenticated body whenever it names one: a
        // caller-supplied hint may never move an authenticated delivery onto a different occurrence.
        $lessonId=$this->occurrence((string)($facts['lesson_id']??''),(string)($delivery['lesson_id']??''));
        $scheduleVersionId=$this->occurrence((string)($facts['schedule_version_id']??''),(string)($delivery['schedule_version_id']??''));
        if($lessonId<1||$scheduleVersionId<1)throw new \InvalidArgumentException('exact_occurrence_required');
        $context=$this->contextFacts($facts,$lessonId,$scheduleVersionId);
        $eventFactDigest=ProviderIntegrationIdempotency::payload($context);
        $digest=ProviderIntegrationIdempotency::key($key);
        $now=gmdate('Y-m-d H:i:s');
        $eventId=0;$duplicate=false;$sequenceLocked=false;
        $this->repository->begin();
        try{
            $this->repository->lockLessonRoots($lessonId);
            $existing=$this->repository->ingestEvent($providerCode,$eventKeyDigest,true);
            if(!$existing){
                // One provider event key is decided under one provider-scoped lock, taken before the
                // committed head is read. Two deliveries of the *same* key can name different Lessons,
                // so they lock different canonical chains and meet for the first time here: the loser
                // re-reads the winner below and, because its context materially differs, leaves a
                // durable conflict receipt for it instead of colliding on the unique `provider_event`
                // index. The lock is held until this transaction has ended, so it also serialises the
                // provider-scoped `event_sequence` taken inside.
                $this->repository->lockProviderEventSequence($providerCode);
                $sequenceLocked=true;
                $existing=$this->repository->ingestEvent($providerCode,$eventKeyDigest,true);
            }
            if($existing){
                if(!hash_equals((string)$existing->event_fact_digest,$eventFactDigest)){
                    $kind=$this->conflictKind($existing,$context,$lessonId,$scheduleVersionId);
                    $conflictId=$this->recordConflict($existing,$providerCode,$eventKeyDigest,$kind,$eventFactDigest,$lessonId,$scheduleVersionId,(string)$facts['observed_at'],$now,$actor);
                    $this->repository->commit();
                    return $this->conflictResult((int)$existing->id,$conflictId,$kind);
                }
                // Exact duplicate. The receipt must still satisfy the aggregate contract, and it is
                // only ever reported as admitted when an admitted handoff outcome already exists for
                // it; otherwise the handoff is (re)attempted below, so an interrupted or failed
                // request can never be answered with an admission that never happened.
                if(!ProviderIntegrationValidator::ingestEventShape($existing))throw new \RuntimeException('provider_event_receipt_corrupt');
                $eventId=(int)$existing->id;
                $recorded=$this->repository->latestIngestOutcome($eventId,true);
                if($recorded&&!ProviderIntegrationValidator::ingestOutcomeShape($recorded))throw new \RuntimeException('provider_event_outcome_corrupt');
                if($recorded&&(string)$recorded->outcome==='admitted'){
                    $this->repository->commit();
                    return array('ingest_event_id'=>$eventId,'processing_state'=>'admitted','conflict'=>false,'idempotent'=>true,'operation'=>'ingest_provider_event');
                }
                $this->repository->commit();
                $duplicate=true;
            }else{
                // The provider-scoped sequence lock taken above is still held, so a delivery for a
                // different Lesson can never take the same `event_sequence` while this receipt is
                // still uncommitted.
                $eventId=$this->repository->insertIngestEvent(array(
                    'uid'=>Identifier::uid(),'connection_id'=>isset($delivery['connection_id'])?(int)$delivery['connection_id']:null,
                    'provider_code'=>$providerCode,'provider_event_key_digest'=>$eventKeyDigest,'event_fact_digest'=>$eventFactDigest,
                    'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,
                    'participant_role'=>(string)$context['participant_role'],'provider_account_digest'=>(string)$context['provider_account_digest'],
                    'event_sequence'=>$this->repository->maxEventSequence($providerCode)+1,
                    'join_at_utc'=>$context['join_at_utc'],'leave_at_utc'=>$context['leave_at_utc'],
                    'occurred_at'=>(string)$context['observed_at'],'received_at'=>$now,
                    // The receipt records only that this exact delivery was received from this exact
                    // authenticating transport. It is committed as `received` and never mutated again:
                    // admission is a separate appended outcome written only after the Phase-P handoff.
                    'processing_state'=>'received','reason_code'=>null,'conflicting_fact_digest'=>null,
                    'transport'=>$envelope['transport'],'proof_reference_digest'=>$envelope['proof_reference_digest'],
                    'created_at'=>$now,'created_by'=>$actor,
                ));
                // Deterministic gated boundary: the ingest receipt is written and the integration
                // transaction is still open. Phase P is never called with an integration row held.
                do_action('dzn_phase_2a2v_after_ingest_write',$eventId);
                $this->repository->commit();
            }
        }catch(\Throwable$e){
            $this->repository->rollback();
            // Durable guard. The unique `provider_event` index still adjudicates the key if the
            // provider-scoped lock could not be taken, so a delivery that loses that index race owes
            // the same durable conflict receipt as the contender that read the committed winner: a
            // materially changed context is recorded, never merely rejected.
            $winner=$this->repository->duplicate($e)==='provider_event'?$this->repository->ingestEvent($providerCode,$eventKeyDigest):null;
            if(!$winner)throw $e;
            if(!hash_equals((string)$winner->event_fact_digest,$eventFactDigest))
                return $this->conflictReceipt($winner,$providerCode,$eventKeyDigest,$eventFactDigest,$context,$lessonId,$scheduleVersionId,(string)$facts['observed_at'],$actor);
            $eventId=(int)$winner->id;$duplicate=true;
        }finally{
            // The lock is not transactional, so it is always released once the receipt transaction — and
            // the read of the committed head that decides the next sequence — is finished.
            if($sequenceLocked)$this->repository->releaseProviderEventSequence($providerCode);
        }
        // Phase P owns intake, identity resolution, assessment and any canonical consequence. The
        // integration layer supplies facts only, and its own transaction is already closed so no
        // integration row is held across the Phase-P transaction. The receipt deliberately exists
        // before this handoff: an interruption here leaves durable evidence and the next request
        // repeats the handoff (Phase P is idempotent on the provider event key) instead of reporting
        // an admission that never reached Phase P.
        $intakeInput=array(
            'provider_code'=>$providerCode,
            'provider_account_key'=>(string)$facts['provider_account_key'],
            'provider_event_key'=>$eventKey,
            'provider_payload_key'=>(string)($facts['provider_payload_key']??$eventKey),
            'participant_role'=>(string)$facts['participant_role'],
            'observed_at'=>(string)$facts['observed_at'],
            'join_at_utc'=>$facts['join_at_utc']??null,
            'leave_at_utc'=>$facts['leave_at_utc']??null,
            'provenance_reference'=>(string)($facts['provenance_reference']??$eventKey),
            'evidence_reference'=>(string)($facts['evidence_reference']??$eventKey),
        );
        try{
            $result=$this->intake->ingestProviderEvidence($lessonId,$scheduleVersionId,$intakeInput,$key);
        }catch(\Throwable$e){
            // A refusal is appended only when this receipt does not already carry an admission. Two
            // concurrent retries of the same receipt may both reach the handoff (Phase P is idempotent
            // on the provider event key), so a contender that finds the effective outcome already
            // recorded re-reads it and converges instead of reporting a failure for a delivery that did
            // reach Phase P.
            if($this->recordOutcome($eventId,$providerCode,$eventKeyDigest,'refused',$this->refusalCode($e),null,$actor)<1)return $this->convergedAdmission($eventId);
            throw $e;
        }
        // Only a successful Phase-P handoff appends the durable admission, and the admission records
        // the exact Phase-P result it was written for. Allocation is serialised on the parent receipt,
        // so a concurrent retry can never collide on the attempt number: it either appends its own
        // attempt or converges on the one recorded by the winner, and never surfaces a driver failure.
        $resultDigest=ProviderIntegrationIdempotency::payload(array(
            'case_id'=>(int)($result['case_id']??0),'evidence_id'=>(int)($result['evidence_id']??0),
            'created'=>isset($result['created'])?(bool)$result['created']:null,
        ));
        if($this->recordOutcome($eventId,$providerCode,$eventKeyDigest,'admitted',null,$resultDigest,$actor)<1)return $this->convergedAdmission($eventId);
        return array('ingest_event_id'=>$eventId,'processing_state'=>'admitted','conflict'=>false,'idempotent'=>$duplicate,'intake'=>$result,'operation'=>'ingest_provider_event');
    }

    /**
     * Converge on the effective outcome a concurrent retry already recorded for this receipt.
     *
     * A contender whose own append did not become the recorded outcome — the effective attempt was
     * already taken, or an admission already existed — re-reads the durable outcome instead of
     * surfacing a duplicate-key failure. Only a recorded admission may be reported as an admission; a
     * receipt with no readable admission can never be answered as one.
     */
    private function convergedAdmission(int $eventId):array{
        $recorded=$this->repository->latestIngestOutcome($eventId);
        if(!$recorded||(string)$recorded->outcome!=='admitted')throw new \RuntimeException('provider_event_outcome_unavailable');
        return array('ingest_event_id'=>$eventId,'processing_state'=>'admitted','conflict'=>false,'idempotent'=>true,'operation'=>'ingest_provider_event');
    }

    /**
     * Resolve the occurrence binding of one delivery.
     *
     * An authenticated body that names an occurrence wins over a caller-supplied hint, and a delivery
     * whose two bindings disagree is refused: a caller may never re-point an authenticated provider
     * event at a different Lesson or schedule version.
     */
    private function occurrence(mixed $authenticated,mixed $claimed):int{
        $fromBody=$authenticated===null?'':trim((string)$authenticated);
        $fromHint=$claimed===null?'':trim((string)$claimed);
        if($fromBody!==''&&(!ctype_digit($fromBody)||(int)$fromBody<1))throw new \InvalidArgumentException('provider_event_context_mismatch');
        if($fromHint!==''&&(!ctype_digit($fromHint)||(int)$fromHint<1))throw new \InvalidArgumentException('exact_occurrence_required');
        if($fromBody!==''&&$fromHint!==''&&$fromBody!==$fromHint)throw new \InvalidArgumentException('provider_event_context_mismatch');
        return $fromBody!==''?(int)$fromBody:(int)$fromHint;
    }

    /**
     * The complete immutable context of one provider event.
     *
     * Every field that could change the meaning of the event is part of the digest, so a changed
     * payload, a moved occurrence, a different participant or a shifted interval can never converge.
     */
    private function contextFacts(array $facts,int $lessonId,int $scheduleVersionId):array{
        $account=trim((string)($facts['provider_account_key']??''));
        $role=(string)($facts['participant_role']??'');
        $observed=(string)($facts['observed_at']??'');
        if($account==='')throw new \InvalidArgumentException('Provider account identity required');
        if(!in_array($role,array('teacher','student'),true))throw new \InvalidArgumentException('Controlled participant role required');
        if(!ProviderIntegrationRule::utc($observed))throw new \InvalidArgumentException('Valid UTC observed time required');
        $join=$facts['join_at_utc']??null;$leave=$facts['leave_at_utc']??null;
        foreach(array($join,$leave) as $instant)if($instant!==null&&!ProviderIntegrationRule::utc($instant))throw new \InvalidArgumentException('Valid UTC join instant required');
        return array(
            'provider_code'=>ProviderIntegrationRule::evidenceProviderCode((string)$facts['provider_code']),
            'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'participant_role'=>$role,
            'provider_account_digest'=>ProviderIntegrationIdempotency::evidence($account),
            'provider_payload_digest'=>ProviderIntegrationIdempotency::providerPayload((string)($facts['provider_payload_key']??$facts['provider_event_key'])),
            'observed_at'=>$observed,'join_at_utc'=>$join,'leave_at_utc'=>$leave,
        );
    }

    /** Classify the divergence so the receipt is actionable without ever being a silent overwrite. */
    private function conflictKind(object $existing,array $context,int $lessonId,int $scheduleVersionId):string{
        if((int)$existing->lesson_id!==$lessonId)return 'cross_lesson';
        if((int)$existing->schedule_version_id!==$scheduleVersionId)return 'cross_schedule_version';
        if((string)$existing->participant_role!==(string)$context['participant_role'])return 'cross_participant';
        if(!hash_equals((string)$existing->provider_account_digest,(string)$context['provider_account_digest']))return 'cross_participant';
        if((string)$existing->join_at_utc!==(string)($context['join_at_utc']??'')||(string)$existing->leave_at_utc!==(string)($context['leave_at_utc']??''))return 'cross_interval';
        return 'changed_payload';
    }

    /**
     * The single durable conflict receipt a materially changed context is owed.
     *
     * Exactly one row may exist for one provider event key and one changed context digest, so the
     * insert converges on the recorded row when a concurrent contender carrying the identical changed
     * context has already written it — the conflict is recorded either way, and the caller is never
     * answered with a driver failure in its place.
     */
    private function recordConflict(object $existing,string $providerCode,string $eventKeyDigest,string $kind,string $conflictingDigest,int $lessonId,int $scheduleVersionId,string $observedAt,string $now,int $actor):int{
        if($found=$this->repository->conflict($providerCode,$eventKeyDigest,$conflictingDigest))return(int)$found->id;
        try{
            return $this->repository->insertConflict(array(
                'uid'=>Identifier::uid(),'provider_ingest_event_id'=>(int)$existing->id,'provider_code'=>$providerCode,
                'provider_event_key_digest'=>$eventKeyDigest,'conflict_kind'=>$kind,'conflicting_fact_digest'=>$conflictingDigest,
                'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,
                'observed_at'=>ProviderIntegrationRule::utc($observedAt)?$observedAt:$now,
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
        }catch(\Throwable$e){
            if($this->repository->duplicate($e)==='conflict_identity')
                if($found=$this->repository->conflict($providerCode,$eventKeyDigest,$conflictingDigest))return(int)$found->id;
            throw $e;
        }
    }

    /**
     * Record the durable conflict receipt for a delivery that lost the race for its provider event key.
     *
     * The receipt row is immutable and is never overwritten, so the divergence is appended in its own
     * transaction: the winner's receipt stays exactly as it was committed and the loser leaves the
     * conflict evidence the contract requires for every materially changed context.
     */
    private function conflictReceipt(object $existing,string $providerCode,string $eventKeyDigest,string $conflictingDigest,array $context,int $lessonId,int $scheduleVersionId,string $observedAt,int $actor):array{
        $now=gmdate('Y-m-d H:i:s');
        $kind=$this->conflictKind($existing,$context,$lessonId,$scheduleVersionId);
        $this->repository->begin();
        try{
            $conflictId=$this->recordConflict($existing,$providerCode,$eventKeyDigest,$kind,$conflictingDigest,$lessonId,$scheduleVersionId,$observedAt,$now,$actor);
            $this->repository->commit();
        }catch(\Throwable$e){
            $this->repository->rollback();
            throw $e;
        }
        return $this->conflictResult((int)$existing->id,$conflictId,$kind);
    }

    /** The reported outcome of a durably recorded conflict: the original receipt is named, never changed. */
    private function conflictResult(int $eventId,int $conflictId,string $kind):array{
        return array('ingest_event_id'=>$eventId,'conflict_id'=>$conflictId,'conflict_kind'=>$kind,'processing_state'=>'conflicted','conflict'=>true,'idempotent'=>false,'operation'=>'ingest_provider_event');
    }

    /**
     * Append one handoff outcome for a receipt; the immutable receipt table is never updated.
     *
     * Every attempt appends its own outcome, so an interrupted attempt is followed by a new row rather
     * than by a mutation of the recorded history, and the latest row is always the effective outcome.
     * The attempt number is allocated by the repository inside a transaction that locks the parent
     * receipt, so concurrent retries of one receipt serialise instead of colliding; a `0` result means
     * the effective outcome was already recorded by a concurrent retry.
     */
    private function recordOutcome(int $eventId,string $providerCode,string $eventKeyDigest,string $outcome,?string $reason,?string $resultDigest,int $actor):int{
        $now=gmdate('Y-m-d H:i:s');
        return $this->repository->insertIngestOutcome($eventId,array(
            'uid'=>Identifier::uid(),'provider_code'=>$providerCode,
            'provider_event_key_digest'=>$eventKeyDigest,'outcome'=>$outcome,'reason_code'=>$reason,
            'intake_result_digest'=>$resultDigest,'recorded_at'=>$now,'recorded_by'=>$actor,
            'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    private function refusalCode(\Throwable $e):string{
        $message=strtolower($e->getMessage());
        if(str_contains($message,'unauthorized'))return 'capability_refused';
        if(str_contains($message,'conflict'))return 'evidence_conflict';
        if(str_contains($message,'identity'))return 'participant_identity_unresolved';
        return 'evidence_refused';
    }

    private function requireCapability():void{
        if(!current_user_can(ProviderIntegrationService::INGEST_CAPABILITY))throw new \RuntimeException('Unauthorized');
    }

    private function actor():int{
        $id=get_current_user_id();
        if($id<1)throw new \RuntimeException('Integration actor unavailable');
        return $id;
    }
}
