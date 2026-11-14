<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * One current delivery is the confirmation anchor. This ledger retains every
 * automatic offer without changing its identity after confirmation starts.
 * All mutating methods run inside the caller's InnoDB transaction.
 */
final class BookingAllocationRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Allocation transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Allocation transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function ensureAndLockTeacherRoot(int $teacherId,string $now,int $actor):void{(new CanonicalLessonScheduleRepository())->ensureAndLockTeacherRoot($teacherId,$now,$actor);}
    public function request(int $id,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_requests WHERE id=%d".($lock?' FOR UPDATE':''),$id))?:null;}
    public function requestIdForReference(string $reference):?int{global $wpdb;$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}booking_requests WHERE reference_code=%s",$reference));return $id===null?null:(int)$id;}
    public function times(int $requestId):array{global $wpdb;return (array)$wpdb->get_results($wpdb->prepare("SELECT id,sequence_number,local_date,local_start_time,timezone,starts_at_utc,occupied_ends_at_utc FROM {$this->p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number,id",$requestId));}
    public function delivery(int $requestId,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_request_teacher_deliveries WHERE booking_request_id=%d".($lock?' FOR UPDATE':''),$requestId))?:null;}
    public function offer(int $requestId,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_allocation_offers WHERE booking_request_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$requestId))?:null;}
    public function offerForTeacher(int $requestId,int $teacherId,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_allocation_offers WHERE booking_request_id=%d AND teacher_id=%d".($lock?' FOR UPDATE':''),$requestId,$teacherId))?:null;}
    public function history(int $requestId):array{global $wpdb;return (array)$wpdb->get_results($wpdb->prepare("SELECT o.offer_sequence,o.teacher_id,o.requested_time_id,o.state,o.offered_at,o.deadline_at,o.responded_at,o.reason_code,COALESCE(NULLIF(t.display_name,''),NULLIF(t.persian_name,''),t.reference_code) teacher_name FROM {$this->p}booking_allocation_offers o INNER JOIN {$this->p}teachers t ON t.id=o.teacher_id WHERE o.booking_request_id=%d ORDER BY o.offer_sequence",$requestId));}
    public function cursor(int $courseId,string $now):int{
        global $wpdb;
        if($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->p}booking_allocation_cursors (course_id,last_teacher_id,updated_at) VALUES (%d,0,%s)",$courseId,$now))===false)throw new \RuntimeException('Allocation cursor unavailable');
        $row=$wpdb->get_row($wpdb->prepare("SELECT last_teacher_id FROM {$this->p}booking_allocation_cursors WHERE course_id=%d FOR UPDATE",$courseId));
        if(!$row)throw new \RuntimeException('Allocation cursor unavailable');
        return (int)$row->last_teacher_id;
    }
    public function advanceCursor(int $courseId,int $teacherId,string $now):void{global $wpdb;if($wpdb->update($this->p.'booking_allocation_cursors',array('last_teacher_id'=>$teacherId,'updated_at'=>$now),array('course_id'=>$courseId))===false)throw new \RuntimeException('Allocation cursor changed');}
    public function addOffer(int $requestId,int $deliveryId,array $candidate,int $sequence,string $now,string $deadline):void{
        global $wpdb;
        if($wpdb->insert($this->p.'booking_allocation_offers',array('booking_request_id'=>$requestId,'delivery_id'=>$deliveryId,'teacher_id'=>$candidate['teacher_id'],'requested_time_id'=>$candidate['requested_time_id'],'offer_sequence'=>$sequence,'state'=>'pending','active_slot'=>1,'match_strength'=>$candidate['match_strength'],'offered_at'=>$now,'deadline_at'=>$deadline,'responded_at'=>null,'reason_code'=>null))===false)throw new \RuntimeException('Allocation offer persistence failed');
        $this->enqueueOfferNotification((int)$wpdb->insert_id,$now);
    }
    public function transitionOffer(int $offerId,string $outcome,string $now,string $reason):void{
        global $wpdb;
        if($wpdb->update($this->p.'booking_allocation_offers',array('state'=>$outcome,'active_slot'=>null,'responded_at'=>$now,'reason_code'=>$reason),array('id'=>$offerId,'state'=>'pending','active_slot'=>1))!==1)throw new \RuntimeException('Allocation offer changed concurrently');
    }
    public function rotateDelivery(object $delivery,array $candidate,string $now):int{
        global $wpdb;
        $changed=$wpdb->update($this->p.'booking_request_teacher_deliveries',array('uid'=>Identifier::uid(),'teacher_id'=>$candidate['teacher_id'],'requested_time_id'=>$candidate['requested_time_id'],'match_strength'=>$candidate['match_strength'],'state'=>'pending_request','created_at'=>$now,'updated_at'=>$now,'responded_at'=>null),array('id'=>(int)$delivery->id,'state'=>(string)$delivery->state));
        if($changed!==1)throw new \RuntimeException('Allocation delivery changed concurrently');
        return (int)$delivery->id;
    }
    public function referral(int $requestId,string $reason,string $now):void{
        global $wpdb;
        $this->alert($requestId,'referral',$reason,'normal',$now);
        $case=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}coordination_cases WHERE booking_request_id=%d",$requestId));
        if(!$case){
            if($wpdb->insert($this->p.'coordination_cases',array('uid'=>Identifier::uid(),'reference_code'=>null,'booking_request_id'=>$requestId,'state'=>'open','state_reason_code'=>$reason,'version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>null,'updated_by'=>null,'closed_at'=>null,'closed_by'=>null))===false)throw new \RuntimeException('Allocation coordination referral failed');
            $case=(int)$wpdb->insert_id;
            if($wpdb->update($this->p.'coordination_cases',array('reference_code'=>Identifier::reference('CCS',(int)$case)),array('id'=>(int)$case))!==1)throw new \RuntimeException('Allocation coordination reference failed');
        }
    }
    public function alert(int $requestId,string $kind,string $reason,string $priority,string $now):void{
        global $wpdb;
        $sql=$wpdb->prepare("INSERT INTO {$this->p}booking_allocation_alerts (booking_request_id,kind,reason_code,priority,state,created_at,resolved_at) VALUES (%d,%s,%s,%s,'open',%s,NULL) ON DUPLICATE KEY UPDATE notified_at=IF(state='resolved',NULL,notified_at),created_at=IF(state='resolved',VALUES(created_at),created_at),reason_code=VALUES(reason_code),priority=VALUES(priority),resolved_at=NULL,state='open'",$requestId,$kind,$reason,$priority,$now);
        if($wpdb->query($sql)===false)throw new \RuntimeException('Allocation alert unavailable');
    }
    public function resolveAlerts(int $requestId,string $now):void{global $wpdb;if($wpdb->query($wpdb->prepare("UPDATE {$this->p}booking_allocation_alerts SET state='resolved',resolved_at=%s WHERE booking_request_id=%d AND state='open'",$now,$requestId))===false)throw new \RuntimeException('Allocation alert resolution failed');}
    public function unnotifiedAlerts(int $limit=25):array{global $wpdb;$limit=max(1,min(50,$limit));return (array)$wpdb->get_results("SELECT id,booking_request_id,kind,reason_code FROM {$this->p}booking_allocation_alerts WHERE state='open' AND notified_at IS NULL ORDER BY id LIMIT {$limit}");}
    public function markAlertNotified(int $id,string $now):void{global $wpdb;if($wpdb->update($this->p.'booking_allocation_alerts',array('notified_at'=>$now),array('id'=>$id,'state'=>'open','notified_at'=>null))===false)throw new \RuntimeException('Allocation alert notice marker failed');}
    public function unnotifiedExpirations(int $limit=25):array{global $wpdb;$limit=max(1,min(50,$limit));return (array)$wpdb->get_results("SELECT id,teacher_id FROM {$this->p}booking_allocation_offers WHERE state='expired' AND expiry_notified_at IS NULL ORDER BY id LIMIT {$limit}");}
    public function markExpiryNotified(int $id,string $now):void{global $wpdb;if($wpdb->update($this->p.'booking_allocation_offers',array('expiry_notified_at'=>$now),array('id'=>$id,'state'=>'expired','expiry_notified_at'=>null))===false)throw new \RuntimeException('Allocation expiry notice marker failed');}
    public function dueRequests(string $now,int $limit=25):array{global $wpdb;return (array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT r.id FROM {$this->p}booking_requests r LEFT JOIN {$this->p}booking_allocation_offers o ON o.booking_request_id=r.id AND o.active_slot=1 WHERE r.lifecycle_status='submitted' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL AND r.selected_intro_course_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$this->p}booking_request_confirmations c WHERE c.booking_request_id=r.id AND c.state<>'failed') AND (o.deadline_at<=%s OR (r.created_at<=DATE_SUB(%s, INTERVAL 72 HOUR) AND (o.id IS NOT NULL OR EXISTS (SELECT 1 FROM {$this->p}booking_allocation_alerts a0 WHERE a0.booking_request_id=r.id AND a0.kind='referral')) AND NOT EXISTS (SELECT 1 FROM {$this->p}booking_allocation_alerts a WHERE a.booking_request_id=r.id AND a.kind='escalation'))) ORDER BY r.id LIMIT %d",$now,$now,$limit));}
    public function confirmationExists(int $requestId):bool{global $wpdb;return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1",$requestId));}
    public function historicalOffer(int $requestId,object $delivery,string $deadline):void{
        global $wpdb;
        if($wpdb->insert($this->p.'booking_allocation_offers',array('booking_request_id'=>$requestId,'delivery_id'=>(int)$delivery->id,'teacher_id'=>(int)$delivery->teacher_id,'requested_time_id'=>(int)$delivery->requested_time_id,'offer_sequence'=>1,'state'=>'pending','active_slot'=>1,'match_strength'=>(string)$delivery->match_strength,'offered_at'=>(string)$delivery->created_at,'deadline_at'=>$deadline,'responded_at'=>null,'reason_code'=>'legacy_recovery'))===false)throw new \RuntimeException('Historical offer recovery failed');
        $this->enqueueOfferNotification((int)$wpdb->insert_id,(string)$delivery->created_at);
    }
    public function alerts(int $requestId):array{global $wpdb;return (array)$wpdb->get_results($wpdb->prepare("SELECT kind,reason_code,priority,state,created_at,resolved_at FROM {$this->p}booking_allocation_alerts WHERE booking_request_id=%d ORDER BY id",$requestId));}
    private function enqueueOfferNotification(int $offerId,string $now):void{global $wpdb;if($wpdb->insert($this->p.'booking_allocation_notifications',array('offer_id'=>$offerId,'state'=>'queued','attempt_count'=>0,'next_attempt_at'=>$now,'lease_expires_at'=>null,'last_error_code'=>null,'handed_off_at'=>null,'created_at'=>$now,'updated_at'=>$now))===false)throw new \RuntimeException('Allocation notification persistence failed');}
    public function dueNotifications(string $now,int $limit=20):array{global $wpdb;return (array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->p}booking_allocation_notifications WHERE (state IN ('queued','retryable') AND next_attempt_at<=%s) OR (state='sending' AND lease_expires_at<=%s) ORDER BY id LIMIT %d",$now,$now,$limit));}
    public function notification(int $id,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT n.*,o.booking_request_id,o.teacher_id,o.requested_time_id,o.state offer_state,o.deadline_at,o.active_slot,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,s.full_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.persian_name,''),t.reference_code) teacher_name,COALESCE(NULLIF(c.name_fa,''),c.name_en) course_name,rt.starts_at_utc,p.timezone teacher_timezone,l.wordpress_user_id FROM {$this->p}booking_allocation_notifications n INNER JOIN {$this->p}booking_allocation_offers o ON o.id=n.offer_id INNER JOIN {$this->p}booking_requests r ON r.id=o.booking_request_id INNER JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1 INNER JOIN {$this->p}teachers t ON t.id=o.teacher_id LEFT JOIN {$this->p}courses c ON c.id=r.selected_intro_course_id INNER JOIN {$this->p}booking_request_requested_times rt ON rt.id=o.requested_time_id LEFT JOIN {$this->p}teacher_availability_profiles p ON p.teacher_id=o.teacher_id LEFT JOIN {$this->p}teacher_principal_links l ON l.teacher_id=o.teacher_id AND l.status='active' AND l.revoked_at IS NULL WHERE n.id=%d".($lock?' FOR UPDATE':''),$id))?:null;}
    public function updateNotification(int $id,string $from,array $data):void{global $wpdb;if($wpdb->update($this->p.'booking_allocation_notifications',$data,array('id'=>$id,'state'=>$from))!==1)throw new \RuntimeException('Allocation notification changed concurrently');}
    /** Called while the privacy owner holds the request row lock. */
    public function erase(int $requestId):void{
        global $wpdb;
        if($wpdb->query($wpdb->prepare("DELETE n FROM {$this->p}booking_allocation_notifications n INNER JOIN {$this->p}booking_allocation_offers o ON o.id=n.offer_id WHERE o.booking_request_id=%d",$requestId))===false)throw new \RuntimeException('Allocation notification erasure failed');
        foreach(array('booking_allocation_offers','booking_allocation_alerts') as $table)if($wpdb->delete($this->p.$table,array('booking_request_id'=>$requestId))===false)throw new \RuntimeException('Allocation privacy erasure failed');
    }
    public function queueRows():array{
        global $wpdb;
        return (array)$wpdb->get_results("SELECT r.id,r.reference_code,r.created_at,o.deadline_at,o.state offer_state,COALESCE(NULLIF(t.display_name,''),NULLIF(t.persian_name,''),t.reference_code) teacher_name,ref.reason_code referral_reason,esc.created_at escalation_at FROM {$this->p}booking_requests r LEFT JOIN {$this->p}booking_allocation_offers o ON o.booking_request_id=r.id AND o.active_slot=1 LEFT JOIN {$this->p}teachers t ON t.id=o.teacher_id LEFT JOIN {$this->p}booking_allocation_alerts ref ON ref.booking_request_id=r.id AND ref.kind='referral' AND ref.state='open' LEFT JOIN {$this->p}booking_allocation_alerts esc ON esc.booking_request_id=r.id AND esc.kind='escalation' AND esc.state='open' WHERE r.lifecycle_status='submitted' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL ORDER BY (esc.id IS NOT NULL) DESC,r.created_at ASC LIMIT 100");
    }
}
