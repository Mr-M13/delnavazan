<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Transactional persistence for the bounded Student account-claim invitation aggregate. */
final class StudentAccountClaimInvitationRepository {
    private string $prefix;
    public function __construct(){global $wpdb;$this->prefix=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Claim invitation transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Claim invitation transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function studentForUpdate(int $studentId):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}students WHERE id=%d FOR UPDATE",$studentId));}
    public function invitationForStudentForUpdate(int $studentId):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}student_account_claim_invitations WHERE student_id=%d FOR UPDATE",$studentId));}
    public function generationForCommandForUpdate(string $commandDigest):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}student_account_claim_generations WHERE command_digest=%s FOR UPDATE",$commandDigest));}
    public function insertInvitation(array $data):int{global $wpdb;if($wpdb->insert($this->prefix.'student_account_claim_invitations',$data)===false)throw new \RuntimeException('Claim invitation persistence failed');return(int)$wpdb->insert_id;}
    public function insertGeneration(array $data):int{global $wpdb;if($wpdb->insert($this->prefix.'student_account_claim_generations',$data)===false)throw new \RuntimeException('Claim invitation generation persistence failed');return(int)$wpdb->insert_id;}
    public function updateInvitation(int $id,array $data):void{global $wpdb;if($wpdb->update($this->prefix.'student_account_claim_invitations',$data,array('id'=>$id))===false)throw new \RuntimeException('Claim invitation update failed');}
    public function supersedeActiveGenerations(int $invitationId,string $reason,string $now,int $actor):int{global $wpdb;$n=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}student_account_claim_generations SET state='superseded',active_slot=NULL,superseded_at=%s,replacement_reason_code=%s,updated_at=%s,updated_by=%d WHERE invitation_id=%d AND state='active' AND active_slot=1",$now,$reason,$now,$actor,$invitationId));if($n===false)throw new \RuntimeException('Claim invitation replacement failed');return$n;}
    public function nextEventSequence(int $invitationId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$this->prefix}student_account_claim_events WHERE invitation_id=%d",$invitationId));}
    public function insertEvent(array $data):void{global $wpdb;if($wpdb->insert($this->prefix.'student_account_claim_events',$data)===false)throw new \RuntimeException('Claim invitation audit persistence failed');}
}
