<?php
namespace Delnavazan\Platform\Portals;

final class PortalPublicActionService {
    private function uid():string { return substr(hash('sha256',wp_generate_uuid4()),0,26); }
    private function digest(string $value):string { return hash('sha256',$value); }
    private function requestDigest():string { return hash_hmac('sha256','portal_public_request_v1|'.wp_json_encode($_REQUEST),wp_salt('dzn_portal_request_fingerprint')); }
    private function confirmationSignature(string $handle,string $nonce,object $row):string { return hash_hmac('sha256','portal_absence_confirmation_v1|'.$handle.'|'.$nonce.'|'.$row->id.'|'.$row->generation.'|'.$row->expires_at,wp_salt('dzn_portal_confirmation')); }
    private function assertConfirmation(string $handle,object $row,string $confirmation):void { if(!preg_match('/^[a-f0-9]{128}$/D',$confirmation))throw new \InvalidArgumentException('portal_confirmation_invalid');$nonce=substr($confirmation,0,PortalRule::CONFIRMATION_TOKEN_BYTES*2);$signature=substr($confirmation,-64);if(!hash_equals($this->confirmationSignature($handle,$nonce,$row),$signature))throw new \InvalidArgumentException('portal_confirmation_invalid'); }
    private function subject(object $row):PublicCapabilityReadSubject { return new PublicCapabilityReadSubject((int)$row->id,PortalRule::ABSENCE,(int)$row->lesson_id,(int)$row->schedule_version_id,(int)$row->generation,(int)$row->subject_student_id,(string)$row->expires_at); }
    /** §5.2/§7.3 — the declared public surface that carries each public purpose's refusal evidence. */
    private function declaredSurface(string $purpose):string { return $purpose===PortalRule::JOIN?'portal_public_join':'portal_public_absence'; }
    /**
     * W-D18/§15.1–15.2: every capability write and every redemption of a Lesson takes that Lesson's
     * `portal_lesson_capability_roots` row first and re-reads the capability under that lock, so the
     * refusal evidence, the redemption claim, the durable delegation lease and the recorded outcome
     * are each appended inside the one declared serialisation root.
     */
    private function underLessonRoot(object $capability):object { global $wpdb;$p=$wpdb->prefix.'dzn_';if(!$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}portal_lesson_capability_roots WHERE lesson_id=%d FOR UPDATE",(int)$capability->lesson_id)))throw new \RuntimeException('portal_action_evidence_persistence_failed');$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}portal_public_capabilities WHERE id=%d FOR UPDATE",(int)$capability->id));if(!$row)throw new \InvalidArgumentException('portal_capability_unknown');return$row; }
    private function nextSequence(object $capability):int { global $wpdb;$p=$wpdb->prefix.'dzn_';return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(action_sequence),0)+1 FROM {$p}portal_public_action_events WHERE capability_id=%d FOR UPDATE",(int)$capability->id)); }
    private function commit():void { global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('portal_action_evidence_persistence_failed'); }

    /**
     * §15.6 — one public-action business refusal commits exactly its refusal evidence: the refused
     * `portal_public_action_events` row **and** its digest-only `portal_access_denials` row, appended
     * in the §15.2 order inside the one root-serialized transaction.  Any evidence write, or the
     * commit itself, that fails rolls both rows back — the controller writes no denial of its own, so
     * refusal evidence is never split across two transactions.
     *
     * A malformed or unknown handle has no capability parent, but is still refusal evidence.  A
     * refusal whose handle resolves to a capability is a capability write: it starts the transaction,
     * takes that Lesson's root, re-reads the capability under the lock, and only then appends.
     */
    public function recordRefusal(string $purpose,string $handle,string $reason):void {
        if(!in_array($purpose,PortalRule::PURPOSES,true))throw new \InvalidArgumentException('portal_vocabulary_member_not_allowed');
        $reason=in_array($reason,PortalRule::EXCEPTION_REASON_CODES,true)?$reason:'portal_upstream_aggregate_invalid';
        global $wpdb;$p=$wpdb->prefix.'dzn_';$hint=null;
        if(preg_match('/^[a-f0-9]{64}$/D',$handle))$hint=$wpdb->get_row($wpdb->prepare("SELECT id,lesson_id FROM {$p}portal_public_capabilities WHERE handle_digest=%s LIMIT 1",hash_hmac('sha256',$handle,wp_salt('dzn_portal_capability_public'))));
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('portal_action_evidence_persistence_failed');
        try{
            $capability=$hint?$this->underLessonRoot($hint):null;
            $sequence=$capability?$this->nextSequence($capability):1;
            $now=gmdate('Y-m-d H:i:s');
            if(false===$wpdb->insert($p.'portal_public_action_events',array('uid'=>$this->uid(),'capability_id'=>$capability?(int)$capability->id:null,'lesson_id'=>$capability?(int)$capability->lesson_id:null,'purpose'=>$purpose,'action_sequence'=>$sequence,'action_state'=>'refused','resolved_student_id'=>$capability&&$capability->subject_student_id!==null?(int)$capability->subject_student_id:null,'redemption_key_digest'=>hash_hmac('sha256','portal_public_refusal_v1|'.wp_generate_uuid4(),wp_salt('dzn_portal_capability_public')),'outcome_reason_code'=>$reason,'request_fingerprint_digest'=>$this->requestDigest(),'occurred_at'=>$now,'created_at'=>$now,'created_by'=>null)))throw new \RuntimeException('portal_action_evidence_persistence_failed');
            if(false===$wpdb->insert($p.'portal_access_denials',array('uid'=>$this->uid(),'surface'=>$this->declaredSurface($purpose),'capability_id'=>$capability?(int)$capability->id:null,'lesson_id'=>$capability?(int)$capability->lesson_id:null,'target_kind'=>'capability','reason_code'=>$reason,'request_fingerprint_digest'=>$this->requestDigest(),'occurred_at'=>$now,'created_at'=>$now)))throw new \RuntimeException('portal_action_evidence_persistence_failed');
            $this->commit();
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    public function renderAbsenceConfirmation(string $handle,string $token):string {
        // GET is deliberately non-mutating.  The GET is a representation request only: it verifies the immutable capability
        // binding but does not lock or append an action row; the POST creates the durable
        // confirmation receipt atomically with the consume claim.
        $row=(new PortalCapabilityService())->verify($handle,$token,PortalRule::ABSENCE); $nonce=bin2hex(random_bytes(PortalRule::CONFIRMATION_TOKEN_BYTES));$confirmation=$nonce.$this->confirmationSignature($handle,$nonce,$row);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,noarchive"><title>Confirm absence</title></head><body><main><h1>Confirm absence</h1><p>Reference: <span>'.esc_html((string)$row->uid).'</span></p><form method="post" action="'.esc_attr(home_url('/wp-json/delnavazan-platform/v1/portal/absence/confirm')).'"><input type="hidden" name="handle" value="'.esc_attr($handle).'"><input type="hidden" name="token" value="'.esc_attr($token).'"><input type="hidden" name="confirmation" value="'.esc_attr($confirmation).'"><button type="submit">Confirm absence</button></form></main></body></html>';
    }

    public function confirmAbsence(string $handle,string $token,string $confirmation):array {
        if(!is_string($confirmation)||$confirmation==='')throw new \InvalidArgumentException('portal_confirmation_required');
        $digest=$this->digest($confirmation);$claim=null;
        for($attempt=0;$attempt<max(1,(int)PortalRule::DELEGATION_WAIT_ATTEMPTS);$attempt++){
            if($attempt>0)usleep(1000*max(1,(int)PortalRule::DELEGATION_WAIT_MILLISECONDS));
            $claim=$this->claimDelegation($handle,$token,$confirmation,$digest);
            if((string)$claim['decision']!=='held')break;
        }
        if((string)$claim['decision']==='terminal')return $claim['result'];
        if((string)$claim['decision']==='refused')throw new PortalPublicActionRefusalRecorded((string)$claim['reason']);
        if((string)$claim['decision']==='held')throw new \InvalidArgumentException('portal_absence_submission_pending');
        $row=$claim['capability'];
        // §15.6 — only a declared owner refusal is recorded as the refused outcome, with the owner's own
        // reason; an owner persistence, corruption or infrastructure failure propagates unchanged and is
        // never converted into a durable refusal.  The claim and lease committed before the delegation
        // stay durable, so the §15.8 bound and an exact replay converge the abandoned claim.
        try{$result=PortalOwnerPorts::attendance()->submitCapabilityClaim($this->subject($row),$confirmation);$state='submitted';$reason=null;}catch(\Throwable $e){$refusal=PortalRule::refusalReason($e);if($refusal===null)throw $e;$result=array('capability_id'=>(int)$row->id,'lesson_id'=>(int)$row->lesson_id);$state='refused';$reason=$refusal;}
        return $this->recordOutcome($row,$digest,$confirmation,$state,$reason,$result);
    }

    /**
     * §9.7/§15.3 — the redemption claim, the consume command and the durable single-delegator lease
     * commit in one Phase-W transaction under the Lesson root; the delegation itself runs after that
     * commit and outside it (W-D19).  The lease is keyed to the confirmation, so exactly one
     * delegator holds it and an exact replay never delegates a second time.
     */
    private function claimDelegation(string $handle,string $token,string $confirmation,string $digest):array {
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('portal_action_evidence_persistence_failed');
        try{
            $hint=$wpdb->get_row($wpdb->prepare("SELECT id,lesson_id FROM {$p}portal_public_capabilities WHERE handle_digest=%s LIMIT 1",hash_hmac('sha256',$handle,wp_salt('dzn_portal_capability_public'))));if(!$hint)throw new \InvalidArgumentException('portal_capability_unknown');
            $row=$this->underLessonRoot($hint);
            if((string)$row->state==='consumed'){
                $row=(new PortalCapabilityService())->verifyConsumed($handle,$token,PortalRule::ABSENCE,(int)$row->id);
                $this->assertConfirmation($handle,$row,$confirmation);
                $bound=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}portal_public_action_events WHERE id=%d AND capability_id=%d AND confirmation_digest=%s AND action_state='confirmed_submitting' FOR UPDATE",(int)$row->consumed_action_event_id,(int)$row->id,$digest));
                if(!$bound)throw new \InvalidArgumentException('portal_capability_consumed');
                $claimed=true;
            }else{
                $row=(new PortalCapabilityService())->verify($handle,$token,PortalRule::ABSENCE);
                PortalOwnerPorts::attendance()->assertCapabilityClaimAdmissible($this->subject($row));
                $this->assertConfirmation($handle,$row,$confirmation);
                $claimed=false;
            }
            // §9.7/§15.3 — the recorded terminal outcome is re-read under the root before delegation.
            $terminal=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}portal_public_action_events WHERE capability_id=%d AND confirmation_digest=%s AND action_state IN ('submitted','refused') ORDER BY id DESC LIMIT 1 FOR UPDATE",(int)$row->id,$digest));
            if($terminal){
                $outcome=(string)$terminal->action_state;$recorded=$outcome==='refused'?(string)($terminal->outcome_reason_code?:'portal_upstream_aggregate_invalid'):null;
                $this->commit();
                if($outcome==='refused')return array('decision'=>'refused','reason'=>$recorded,'capability'=>$row);
                return array('decision'=>'terminal','capability'=>$row,'result'=>array('capability_id'=>(int)$row->id,'lesson_id'=>(int)$row->lesson_id,'attribution'=>'public_capability_on_behalf','state'=>'submitted','replayed'=>true));
            }
            // §15.3/§15.8 — a live lease belongs to another delegator; an expired lease is the
            // abandoned claim bound and this exact replay takes it over.
            $lease=$wpdb->get_row($wpdb->prepare("SELECT id,occurred_at FROM {$p}portal_public_action_events WHERE capability_id=%d AND confirmation_digest=%s AND action_state='delegating' ORDER BY id DESC LIMIT 1 FOR UPDATE",(int)$row->id,$digest));
            if($lease&&strtotime((string)$lease->occurred_at.' UTC')>time()-(int)PortalRule::DELEGATION_LEASE_SECONDS){$this->commit();return array('decision'=>'held','capability'=>$row);}
            $now=gmdate('Y-m-d H:i:s');
            if(!$claimed){
                $sequence=$this->nextSequence($row);
                if(false===$wpdb->insert($p.'portal_public_action_events',array('uid'=>$this->uid(),'capability_id'=>(int)$row->id,'lesson_id'=>(int)$row->lesson_id,'purpose'=>PortalRule::ABSENCE,'action_sequence'=>$sequence,'action_state'=>'confirmed_submitting','resolved_student_id'=>(int)$row->subject_student_id,'confirmation_digest'=>$digest,'redemption_key_digest'=>$digest,'request_fingerprint_digest'=>$this->requestDigest(),'occurred_at'=>$now,'created_at'=>$now,'created_by'=>null)))throw new \RuntimeException('portal_action_evidence_persistence_failed');
                $claimId=(int)$wpdb->insert_id;
                $eventSequence=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$p}portal_public_capability_events WHERE lesson_id=%d FOR UPDATE",(int)$row->lesson_id));
                if(false===$wpdb->insert($p.'portal_public_capability_events',array('uid'=>$this->uid(),'lesson_id'=>(int)$row->lesson_id,'capability_id'=>(int)$row->id,'event_sequence'=>$eventSequence,'event_type'=>'consumed','purpose'=>PortalRule::ABSENCE,'generation'=>(int)$row->generation,'reason_code'=>'portal_confirmation_required','evidence_channel'=>'public_confirmation','evidence_reference_digest'=>$this->digest($confirmation),'recorded_at'=>$now,'created_at'=>$now,'created_by'=>0)))throw new \RuntimeException('portal_capability_persistence_failed');
                if(false===$wpdb->insert($p.'portal_public_capability_commands',array('uid'=>$this->uid(),'command_domain'=>'portal_capability','operation'=>'consume','command_key_digest'=>hash_hmac('sha256',$confirmation,wp_salt('dzn_portal_capability_public')),'command_payload_digest'=>$this->digest($confirmation.'|consume'),'lesson_id'=>(int)$row->lesson_id,'purpose'=>PortalRule::ABSENCE,'expected_capability_id'=>(int)$row->id,'expected_generation'=>(int)$row->generation,'expected_state'=>'active','reason_code'=>'portal_confirmation_required','result_capability_id'=>(int)$row->id,'result_state'=>'consumed','created_at'=>$now,'created_by'=>0)))throw new \RuntimeException('portal_capability_persistence_failed');
                if($wpdb->query($wpdb->prepare("UPDATE {$p}portal_public_capabilities SET state='consumed',active_slot=NULL,consumed_at=%s,consumed_action_event_id=%d WHERE id=%d AND state='active' AND active_slot=1",$now,$claimId,$row->id))!==1)throw new \RuntimeException('portal_capability_generation_conflict');
            }
            $epoch=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}portal_public_action_events WHERE capability_id=%d AND confirmation_digest=%s AND action_state='delegating'",(int)$row->id,$digest))+1;
            $leaseSequence=$this->nextSequence($row);
            if(false===$wpdb->insert($p.'portal_public_action_events',array('uid'=>$this->uid(),'capability_id'=>(int)$row->id,'lesson_id'=>(int)$row->lesson_id,'purpose'=>PortalRule::ABSENCE,'action_sequence'=>$leaseSequence,'action_state'=>'delegating','resolved_student_id'=>(int)$row->subject_student_id,'confirmation_digest'=>$digest,'redemption_key_digest'=>$this->digest($confirmation.'|delegating|'.$epoch),'request_fingerprint_digest'=>$this->requestDigest(),'occurred_at'=>$now,'created_at'=>$now,'created_by'=>null)))throw new \RuntimeException('portal_action_evidence_persistence_failed');
            $this->commit();
            return array('decision'=>'delegating','capability'=>$row);
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    /**
     * §9.7/§15.3–15.5 — the outcome row is a capability write, so it takes the Lesson root first and
     * re-reads the capability under that lock.  Terminal recording is idempotent: an outcome recorded
     * by another delegator is returned instead of appended, so an exact replay never fails the unique
     * redemption key and never reports a persistence failure for an already-recorded outcome.
     * §15.6 — a refused outcome commits its refusal evidence together: the refused outcome row and its
     * digest-only denial row are appended in the same transaction, so a failure of either rolls both
     * back and a converging replay re-reads the recorded pair instead of appending a second denial.
     */
    private function recordOutcome(object $capability,string $digest,string $confirmation,string $state,?string $reason,array $result):array {
        global $wpdb;$p=$wpdb->prefix.'dzn_';$converged=false;
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('portal_action_evidence_persistence_failed');
        try{
            $capability=$this->underLessonRoot($capability);
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}portal_public_action_events WHERE capability_id=%d AND confirmation_digest=%s AND action_state IN ('submitted','refused') ORDER BY id DESC LIMIT 1 FOR UPDATE",(int)$capability->id,$digest));
            if($existing){
                $state=(string)$existing->action_state;$reason=$state==='refused'?(string)($existing->outcome_reason_code?:'portal_upstream_aggregate_invalid'):null;$converged=true;
            }else{
                $now=gmdate('Y-m-d H:i:s');$sequence=$this->nextSequence($capability);
                if(false===$wpdb->insert($p.'portal_public_action_events',array('uid'=>$this->uid(),'capability_id'=>(int)$capability->id,'lesson_id'=>(int)$capability->lesson_id,'purpose'=>PortalRule::ABSENCE,'action_sequence'=>$sequence,'action_state'=>$state,'resolved_student_id'=>(int)$capability->subject_student_id,'confirmation_digest'=>$digest,'redemption_key_digest'=>$this->digest($confirmation.'|'.$state),'handoff_target'=>$state==='submitted'?'canonical_attendance_evidence':null,'handoff_reference_id'=>$state==='submitted'?(int)($result['evidence_id']??0):null,'outcome_reason_code'=>$reason,'request_fingerprint_digest'=>$this->requestDigest(),'occurred_at'=>$now,'created_at'=>$now,'created_by'=>null)))throw new \RuntimeException('portal_action_evidence_persistence_failed');
                if($state==='refused'&&false===$wpdb->insert($p.'portal_access_denials',array('uid'=>$this->uid(),'surface'=>$this->declaredSurface((string)$capability->purpose),'capability_id'=>(int)$capability->id,'lesson_id'=>(int)$capability->lesson_id,'target_kind'=>'capability','reason_code'=>$reason,'request_fingerprint_digest'=>$this->requestDigest(),'occurred_at'=>$now,'created_at'=>$now)))throw new \RuntimeException('portal_action_evidence_persistence_failed');
            }
            $this->commit();
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
        if($state==='refused')throw new PortalPublicActionRefusalRecorded((string)$reason);
        return array_merge($result,array('capability_id'=>(int)$capability->id,'attribution'=>'public_capability_on_behalf','state'=>$state),$converged?array('replayed'=>true):array());
    }
}
