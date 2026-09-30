<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CoordinationCaseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProposalRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProposalAcceptanceRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Records only immutable accepted_pending_conditions evidence; it has no final-acceptance or conversion authority. */
final class ProposalAcceptanceService {
    private const CAPABILITY='dzn_record_booking_request_provisional_acceptance';
    private const CHANNELS=array('email_reference','message_reference','phone','in_person','other_reference');
    public function __construct(private ?BookingRequestRepository $requests=null,private ?CoordinationCaseRepository $cases=null,private ?ProposalRepository $proposals=null,private ?ProposalAcceptanceRepository $events=null){$this->requests??=new BookingRequestRepository();$this->cases??=new CoordinationCaseRepository();$this->proposals??=new ProposalRepository();$this->events??=new ProposalAcceptanceRepository();}
    public function record(string $familyUid,string $optionUid,int $versionNumber,string $prospectiveSubjectRef,string $channel,string $evidenceAt,string $key):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');$actor=get_current_user_id();if($actor<1)throw new \RuntimeException('Acceptance actor is unavailable');
        foreach(array($familyUid,$optionUid) as $uid)if(!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$uid))throw new \InvalidArgumentException('Exact Proposal UID required');
        if($versionNumber<1||!preg_match('/^[A-Za-z0-9:_-]{3,128}$/D',$prospectiveSubjectRef))throw new \InvalidArgumentException('Exact provisional acceptance target required');
        if(!in_array($channel,self::CHANNELS,true))throw new \InvalidArgumentException('Closed evidence channel required');
        if(!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$evidenceAt))throw new \InvalidArgumentException('Evidence timestamp required');
        $keyDigest=AcceptanceIdempotency::keyDigest($key);$payload=AcceptanceIdempotency::payloadDigest(array('operation'=>'provisional','family_uid'=>$familyUid,'option_uid'=>$optionUid,'version_number'=>$versionNumber,'prospective_subject_ref'=>$prospectiveSubjectRef,'evidence_channel'=>$channel,'evidence_at'=>$evidenceAt,'accepting_subject_state'=>'authority_unresolved'));
        if($replay=$this->events->eventForCommand($keyDigest)){if(!hash_equals((string)$replay->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');return $this->response($replay,false,true);}
        $now=gmdate('Y-m-d H:i:s');$this->requests->begin();try{
            $version=$this->proposals->exact($familyUid,$optionUid,$versionNumber);if(!$version)throw new \InvalidArgumentException('Exact Proposal Version not found');
            $request=$this->cases->requestForUpdate((int)$version->booking_request_id);if(!$request||$request->privacy_erased_at!==null||$request->resolution_state==='privacy_erased')throw new \InvalidArgumentException('Booking Request is unavailable');
            $case=$this->cases->caseForUpdate((int)$version->coordination_case_id);if(!$case||(int)$case->booking_request_id!==(int)$request->id)throw new \RuntimeException('Coordination Case ancestry is inconsistent');
            $family=$this->proposals->familyForCaseForUpdate((int)$case->id);if(!$family||(string)$family->uid!==$familyUid||(int)$family->booking_request_id!==(int)$request->id)throw new \RuntimeException('Proposal Family ancestry is inconsistent');
            $option=$this->proposals->optionForFamilyCandidateForUpdate((int)$family->id,(int)$version->candidate_id);if(!$option||(string)$option->uid!==$optionUid)throw new \RuntimeException('Proposal Option ancestry is inconsistent');
            $locked=$this->proposals->currentVersionForOption($option);if(!$locked||(int)$locked->id!==(int)$version->id||(int)$locked->version_number!==$versionNumber)throw new \RuntimeException('Proposal Version is stale');
            if(!hash_equals((string)$locked->prospective_subject_ref,$prospectiveSubjectRef))throw new \InvalidArgumentException('Prospective subject reference does not match Proposal Version');
            do_action('dzn_phase_2a2e_acceptance_locks_held');
            if($replay=$this->events->eventForCommandForUpdate($keyDigest)){if(!hash_equals((string)$replay->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');$this->requests->commit();return $this->response($replay,false,true);}
            $data=array('uid'=>Identifier::uid(),'reference_code'=>null,'booking_request_id'=>(int)$request->id,'coordination_case_id'=>(int)$case->id,'proposal_family_id'=>(int)$family->id,'proposal_option_id'=>(int)$option->id,'proposal_version_id'=>(int)$locked->id,'family_uid'=>$familyUid,'option_uid'=>$optionUid,'version_uid'=>(string)$locked->uid,'version_number'=>$versionNumber,'version_fingerprint'=>(string)$locked->version_fingerprint,'event_kind'=>'accepted_pending_conditions','prospective_subject_ref'=>$prospectiveSubjectRef,'accepting_subject_state'=>'authority_unresolved','evidence_channel'=>$channel,'evidence_reference'=>hash_hmac('sha256','acceptance-evidence:'.Identifier::uid(),wp_salt('dzn_provisional_acceptance_evidence')),'evidence_at'=>$evidenceAt,'recorded_at'=>$now,'recorded_by'=>$actor,'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payload,'created_at'=>$now,'created_by'=>$actor);
            try{$id=$this->events->insert($data);$this->events->assignReference($id,Identifier::reference('PAE',$id));$event=$this->events->eventForCommandForUpdate($keyDigest);if(!$event)throw new \RuntimeException('Provisional acceptance persistence verification failed');$this->requests->commit();return $this->response($event,true,false);}catch(\Throwable $e){if(!$this->events->isDuplicate($e))throw $e;$event=$this->events->eventForCommandForUpdate($keyDigest);if(!$event)throw $e;if(!hash_equals((string)$event->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');$this->requests->commit();return $this->response($event,false,true);}
        }catch(\Throwable $e){$this->requests->rollback();throw $e;}
    }
    private function response(object $event,bool $created,bool $idempotent):array{return array('event_id'=>(int)$event->id,'event_uid'=>(string)$event->uid,'event_kind'=>'accepted_pending_conditions','created'=>$created,'idempotent'=>$idempotent);}
}
