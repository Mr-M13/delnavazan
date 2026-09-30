<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Durable provider-neutral participant identity authority (Phase 2A.2-P correction P-1).
 *
 * This service is the ONLY authority allowed to decide which canonical Teacher or Student one
 * provider account belongs to. Intake never accepts caller-asserted resolution: an authenticated
 * provider account digest is looked up here, and only a single `verified` mapping may contribute
 * qualifying attendance. Multiple verified provider accounts may map to the same canonical
 * participant (devices/accounts are unioned); an account bound to several canonical participants is
 * ambiguous and never qualifies; an unverified, revoked or missing mapping never qualifies.
 *
 * Nothing here is delivery truth: no mapping creates a canonical outcome, obligation or replacement.
 */
final class CanonicalAttendanceIdentityService {
    public const CAPABILITY='dzn_manage_canonical_attendance_identity';
    private const OPERATIONS=array('record_participant_mapping','revoke_participant_mapping');
    public function __construct(private ?CanonicalAttendanceRepository $repository=null){
        $this->repository??=new CanonicalAttendanceRepository();
    }

    /** Record or refresh one provider account → canonical participant binding. */
    public function record(array $input,string $key):array{
        $this->requireCapability();
        $providerCode=(string)($input['provider_code']??'');
        if(!in_array($providerCode,CanonicalAttendanceIntakeService::PROVIDER_CODES,true))throw new \InvalidArgumentException('Controlled provider code required');
        $role=(string)($input['participant_role']??'');
        if(!in_array($role,CanonicalAttendanceValidator::PARTICIPANT_ROLES,true))throw new \InvalidArgumentException('Controlled participant role required');
        $participantId=(int)($input['participant_id']??0);
        if($participantId<1)throw new \InvalidArgumentException('Canonical participant ID required');
        $state=(string)($input['state']??'verified');
        if(!in_array($state,array('verified','unverified'),true))throw new \InvalidArgumentException('Controlled mapping state required');
        $account=trim((string)($input['provider_account_key']??''));
        if($account==='')throw new \InvalidArgumentException('Provider account identity required');
        $facts=array(
            'domain'=>'canonical_attendance_v1','operation'=>'record_participant_mapping',
            'provider_code'=>$providerCode,'provider_account_digest'=>CanonicalAttendanceIdempotency::evidence($account),
            'participant_role'=>$role,'participant_id'=>$participantId,'state'=>$state,
        );
        $payload=CanonicalAttendanceIdempotency::payload($facts);
        $digest=CanonicalAttendanceIdempotency::key($key);
        $actor=$this->actor();
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){ $replay=$this->replay($winner,$payload,'record_participant_mapping'); $this->repository->commit(); return $replay; }
            $this->assertParticipant($role,$participantId);
            $now=gmdate('Y-m-d H:i:s');
            $existing=$this->repository->mappingsForAccount($providerCode,$facts['provider_account_digest'],$role,true);
            $id=null;
            foreach($existing as$row)if((int)$row->participant_id===$participantId)$id=(int)$row->id;
            $row=array(
                'provider_code'=>$providerCode,'provider_account_digest'=>$facts['provider_account_digest'],
                'participant_role'=>$role,'participant_id'=>$participantId,'state'=>$state,
                'provenance_digest'=>CanonicalAttendanceIdempotency::evidence((string)($input['provenance_reference']??('mapping-'.$providerCode.'-'.$role.'-'.$participantId))),
                'evidence_reference_digest'=>CanonicalAttendanceIdempotency::evidence((string)($input['evidence_reference']??('mapping-authority-'.$providerCode.'-'.$role.'-'.$participantId))),
                'verified_at'=>$now,'revoked_at'=>null,
                'recorded_at'=>$now,'recorded_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            );
            if($id!==null){
                $current=$this->repository->mapping($id,true);
                $this->repository->updateMappingState($id,(int)$current->mapping_version,$state,null,$now,$actor);
            }else{
                $row['uid']=Identifier::uid();$row['mapping_version']=1;
                $id=$this->repository->insertMapping($row);
            }
            $this->insertCommand($digest,$payload,'record_participant_mapping',$facts,$id,$state,$now,$actor);
            $this->repository->commit();
            return array('mapping_id'=>$id,'provider_code'=>$providerCode,'participant_role'=>$role,'participant_id'=>$participantId,'state'=>$state,'created'=>true,'operation'=>'record_participant_mapping');
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'record_participant_mapping');
            throw $e;
        }
    }

    /** Revoke one binding so it can never again contribute qualifying attendance. */
    public function revoke(int $mappingId,array $input,string $key):array{
        $this->requireCapability();
        $facts=array('domain'=>'canonical_attendance_v1','operation'=>'revoke_participant_mapping','mapping_id'=>$mappingId);
        $payload=CanonicalAttendanceIdempotency::payload($facts);
        $digest=CanonicalAttendanceIdempotency::key($key);
        $actor=$this->actor();
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){ $replay=$this->replay($winner,$payload,'revoke_participant_mapping'); $this->repository->commit(); return $replay; }
            $mapping=$this->repository->mapping($mappingId,true);
            if(!$mapping)throw new \InvalidArgumentException('canonical_attendance_mapping_required');
            $now=gmdate('Y-m-d H:i:s');
            $this->repository->updateMappingState($mappingId,(int)$mapping->mapping_version,'revoked',$now,$now,$actor);
            $this->insertCommand($digest,$payload,'revoke_participant_mapping',$facts,$mappingId,'revoked',$now,$actor);
            $this->repository->commit();
            return array('mapping_id'=>$mappingId,'state'=>'revoked','operation'=>'revoke_participant_mapping');
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'revoke_participant_mapping');
            throw $e;
        }
    }

    /**
     * Resolve one provider account + role to an authoritative canonical participant.
     *
     * @param array<int,object> $mappings rows for this exact provider account and role
     * @return array{state:string,participant_id:?int,mapping_id:?int,reason:?string}
     */
    public static function resolve(array $mappings):array{
        $verified=array();$mappingIds=array();$state='unverified';
        foreach($mappings as$row){
            $rowState=(string)($row->state??'');
            if($rowState==='verified'){
                $verified[(int)$row->participant_id]=true;
                $mappingIds[(int)$row->participant_id][]=(int)$row->id;
            }elseif($rowState==='revoked'){
                $state='revoked';
            }
        }
        if(!$mappings)return array('state'=>'unknown','participant_id'=>null,'mapping_id'=>null,'reason'=>'provider_identity_unmapped');
        if(count($verified)>1)return array('state'=>'ambiguous','participant_id'=>null,'mapping_id'=>null,'reason'=>'participant_ambiguous');
        if(!$verified)return array('state'=>'unknown','participant_id'=>null,'mapping_id'=>null,'reason'=>$state==='revoked'?'participant_identity_revoked':'participant_identity_unverified');
        $participantId=array_key_first($verified);
        return array('state'=>'resolved','participant_id'=>(int)$participantId,'mapping_id'=>(int)($mappingIds[$participantId][0]??0),'reason'=>null);
    }

    /** Resolve and additionally prove the account belongs to the expected canonical participant. */
    public static function resolveExpected(array $mappings,string $role,int $expectedParticipantId):array{
        $resolved=self::resolve($mappings);
        if($resolved['state']!=='resolved')return $resolved;
        if($role!==''&&!in_array($role,CanonicalAttendanceValidator::PARTICIPANT_ROLES,true))return array('state'=>'ambiguous','participant_id'=>null,'mapping_id'=>null,'reason'=>'participant_ambiguous');
        if($resolved['participant_id']!==$expectedParticipantId)return array('state'=>'mismatch','participant_id'=>$resolved['participant_id'],'mapping_id'=>$resolved['mapping_id'],'reason'=>'participant_identity_mismatch');
        return $resolved;
    }

    private function assertParticipant(string $role,int $participantId):void{
        global $wpdb;
        $table=$role==='teacher'?'teachers':'students';
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_{$table} WHERE id=%d",$participantId))!==1)throw new \InvalidArgumentException('Canonical participant identity required');
    }

    private function insertCommand(string $digest,string $payload,string $operation,array $facts,int $subjectId,string $state,string $now,int $actor):int{
        return $this->repository->insertCommand(array(
            'uid'=>Identifier::uid(),'command_domain'=>'canonical_attendance_v1','operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,
            'case_id'=>null,'lesson_id'=>null,'schedule_version_id'=>null,'subject_id'=>$subjectId,
            'expected_case_version'=>null,'result_evidence_id'=>null,'result_decision_id'=>null,
            'result_state'=>$state,'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    private function replay(object $command,string $payload,string $operation):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        if((string)$command->command_domain!=='canonical_attendance_v1'||(string)$command->operation!==$operation)throw new \RuntimeException('Contaminated canonical attendance command');
        return array('mapping_id'=>$command->subject_id===null?null:(int)$command->subject_id,'state'=>(string)$command->result_state,'operation'=>$operation,'idempotent'=>true);
    }

    private function requireCapability():void{if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');}
    private function actor():int{$id=get_current_user_id();if($id<1)throw new \RuntimeException('Canonical attendance actor unavailable');return$id;}
}
