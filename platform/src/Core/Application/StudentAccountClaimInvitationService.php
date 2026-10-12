<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\StudentAccountClaimInvitationRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Administrative issuance only. This service does not establish a principal link; its public boundary
 * composes the generation with #220, keeping the plaintext only in the trusted in-process snapshot path.
 */
final class StudentAccountClaimInvitationService {
    public function __construct(private ?StudentAccountClaimInvitationRepository $records=null){$this->records??=new StudentAccountClaimInvitationRepository();}
    public function issue(int $studentId,int $ttl=604800,?string $command=null):array{
        // The public authority is deliberately composed with #220.  A direct generation commit would
        // recreate the lost-secret crash window this service replaced.
        return (new StudentAccountClaimNotificationService($this->records,$this))->issue($studentId,$ttl,$command);
    }
    /**
     * The claim aggregate half of the atomic notification issuance.  The caller owns the one shared
     * connection and must commit or roll it back with the outbox and encrypted snapshot.
     *
     * The returned secret is deliberately process-local.  No repository method receives it.
     */
    public function issueInTransaction(int $studentId,int $ttl,?string $command,int $actor):array{
        if($actor<1)throw new \InvalidArgumentException('Authenticated issuer required');
        if($studentId<1)throw new \InvalidArgumentException('Canonical Student required');
        $ttl=max(300,min($ttl,2592000));$command=$this->command($command??'student-claim-issue:'.$studentId.':'.Identifier::uid());$commandDigest=hash('sha256',$command);$now=current_time('mysql',true);
            $duplicate=$this->records->generationForCommandForUpdate($commandDigest);
            if($duplicate){if((int)$duplicate->student_id!==$studentId)throw new \InvalidArgumentException('Claim issuance command conflicts with another Student');return array('idempotent'=>true,'invitation_id'=>(int)$duplicate->invitation_id,'generation_id'=>(int)$duplicate->id,'status'=>(string)$duplicate->state);}
            $student=$this->records->studentForUpdate($studentId);
            if(!$student||$student->archived_at!==null||$student->status!=='active'||trim((string)$student->uid)===''||trim((string)$student->reference_code)==='')throw new \InvalidArgumentException('Student is not eligible for account claim');
            $invitation=$this->records->invitationForStudentForUpdate($studentId);
            if($invitation&&$invitation->state==='claimed')throw new \InvalidArgumentException('Student already has a claimed account invitation');
            if(!$invitation){$invitationId=$this->records->insertInvitation(array('uid'=>Identifier::uid(),'student_id'=>$studentId,'state'=>'active','current_generation'=>0,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor));$invitation=$this->records->invitationForStudentForUpdate($studentId);}else{$invitationId=(int)$invitation->id;$this->records->supersedeActiveGenerations($invitationId,'replacement_issued',$now,$actor);}
            $number=(int)$invitation->current_generation+1;$secret=bin2hex(random_bytes(32));$digest=hash_hmac('sha256',$secret,wp_salt('auth'));
            $generationId=$this->records->insertGeneration(array('uid'=>Identifier::uid(),'invitation_id'=>$invitationId,'generation_number'=>$number,'state'=>'active','active_slot'=>1,'token_digest'=>$digest,'command_digest'=>$commandDigest,'issued_at'=>$now,'issued_by'=>$actor,'expires_at'=>gmdate('Y-m-d H:i:s',time()+$ttl),'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor));
            $this->records->updateInvitation($invitationId,array('state'=>'active','current_generation'=>$number,'updated_at'=>$now,'updated_by'=>$actor,'revoked_at'=>null,'revoked_by'=>null,'revocation_reason_code'=>null));
            $this->event($invitationId,$generationId,'issued',null,'active',null,$actor,$now);
            return array('idempotent'=>false,'invitation_id'=>$invitationId,'generation_id'=>$generationId,'expires_at'=>gmdate('Y-m-d H:i:s',time()+$ttl),'secret'=>$secret);
    }
    private function event(int $invitationId,int $generationId,string $type,?string $from,string $to,?string $reason,int $actor,string $now):void{$this->records->insertEvent(array('uid'=>Identifier::uid(),'invitation_id'=>$invitationId,'generation_id'=>$generationId,'event_sequence'=>$this->records->nextEventSequence($invitationId),'event_type'=>$type,'from_state'=>$from,'to_state'=>$to,'reason_code'=>$reason,'principal_user_id'=>null,'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));}
    private function command(string $command):string{$command=trim($command);if($command===''||strlen($command)>191)throw new \InvalidArgumentException('Invalid issuance command');return $command;}
}
