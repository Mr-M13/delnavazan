<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence for Phase 2A.2-F. Evidence references are opaque identifiers, never contact data or documents. */
final class StudentIdentityAuthorityRepository {
    private string $prefix;
    public function __construct(){global $wpdb;$this->prefix=$wpdb->prefix.'dzn_';}
    public function requestForUpdate(int $id):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}booking_requests WHERE id=%d FOR UPDATE",$id));}
    public function studentForUpdate(int $id):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}students WHERE id=%d FOR UPDATE",$id));}
    public function principalById(int $id):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}student_principal_links WHERE id=%d",$id));}
    public function grantById(int $id):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}student_acceptance_authority_grants WHERE id=%d",$id));}
    public function lockWordpressUsers(array $ids):array{global $wpdb;$ids=$this->ids($ids);if(!$ids)return array();$marks=implode(',',array_fill(0,count($ids),'%d'));return $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->users} FORCE INDEX (PRIMARY) WHERE ID IN ({$marks}) ORDER BY ID ASC FOR UPDATE",...$ids));}
    /** Caller owns Student and WordPress-user locks; callers revalidate authority from these concretely locked rows. */
    public function lockPrincipalsForAuthority(int $studentId,array $userIds):array{
        $ids=$this->discoverAuthorityIds('student_principal_links','wordpress_user_id',$studentId,$userIds);
        $rows=$this->lockAuthorityRows('student_principal_links',$ids);
        $this->revalidateAuthorityDiscovery($rows,'wordpress_user_id',$studentId,$userIds);
        return$rows;
    }
    /** Caller owns Student and WordPress-user locks; callers revalidate authority from these concretely locked rows. */
    public function lockGrantsForAuthority(int $studentId,array $userIds):array{
        $ids=$this->discoverAuthorityIds('student_acceptance_authority_grants','acting_wordpress_user_id',$studentId,$userIds);
        $rows=$this->lockAuthorityRows('student_acceptance_authority_grants',$ids);
        $this->revalidateAuthorityDiscovery($rows,'acting_wordpress_user_id',$studentId,$userIds);
        return$rows;
    }
    public function wordpressUserForUpdate(int $id):?object{$rows=$this->lockWordpressUsers(array($id));return$rows[0]??null;}
    public function currentResolutionForUpdate(int $requestId):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT e.* FROM {$this->prefix}booking_requests r LEFT JOIN {$this->prefix}booking_request_identity_resolution_events e ON e.id=r.current_identity_resolution_id WHERE r.id=%d FOR UPDATE",$requestId));}
    public function insertResolution(array $data):int{global $wpdb;if(false===$wpdb->insert($this->prefix.'booking_request_identity_resolution_events',$data))throw new \RuntimeException('Identity resolution persistence failed');return(int)$wpdb->insert_id;}
    public function setResolutionProjection(int $requestId,int $eventId,?int $studentId,int $actor,string $now):void{global $wpdb;$sql=$studentId===null?$wpdb->prepare("UPDATE {$this->prefix}booking_requests SET current_identity_resolution_id=%d,student_id=NULL,updated_at=%s,updated_by=%d WHERE id=%d AND lifecycle_status='submitted' AND resolution_state='unresolved' AND privacy_erased_at IS NULL",$eventId,$now,$actor,$requestId):$wpdb->prepare("UPDATE {$this->prefix}booking_requests SET current_identity_resolution_id=%d,student_id=%d,updated_at=%s,updated_by=%d WHERE id=%d AND lifecycle_status='submitted' AND resolution_state='unresolved' AND privacy_erased_at IS NULL",$eventId,$studentId,$now,$actor,$requestId);$changed=$wpdb->query($sql);if($changed!==1)throw new \InvalidArgumentException('Booking Request identity is no longer resolvable');}
    public function nextResolutionSequence(int $requestId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(resolution_sequence),0) FROM {$this->prefix}booking_request_identity_resolution_events WHERE booking_request_id=%d",$requestId));}
    public function createStudent(array $data):int{global $wpdb;if(false===$wpdb->insert($this->prefix.'students',$data))throw new \RuntimeException('Student creation failed');return(int)$wpdb->insert_id;}
    public function currentCapacityForUpdate(int $studentId):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT c.* FROM {$this->prefix}students s LEFT JOIN {$this->prefix}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id WHERE s.id=%d FOR UPDATE",$studentId));}
    public function resolutionByIdForUpdate(int $id):?object{global $wpdb;if($id<1)return null;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}booking_request_identity_resolution_events WHERE id=%d FOR UPDATE",$id));}
    public function capacityByIdForUpdate(int $id):?object{global $wpdb;if($id<1)return null;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}student_acceptance_capacity_classifications WHERE id=%d FOR UPDATE",$id));}
    public function insertCapacity(array $data):int{global $wpdb;if(false===$wpdb->insert($this->prefix.'student_acceptance_capacity_classifications',$data))throw new \RuntimeException('Capacity classification persistence failed');return(int)$wpdb->insert_id;}
    public function setCapacityProjection(int $studentId,int $classificationId,int $actor,string $now):void{global $wpdb;$changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}students SET current_acceptance_capacity_classification_id=%d,updated_at=%s,updated_by=%d WHERE id=%d AND archived_at IS NULL AND status='active'",$classificationId,$now,$actor,$studentId));if($changed!==1)throw new \InvalidArgumentException('Student is not active');}
    public function nextPrincipalSequence(int $studentId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(link_sequence),0) FROM {$this->prefix}student_principal_links WHERE student_id=%d",$studentId));}
    public function insertPrincipal(array $data):int{global $wpdb;if(false===$wpdb->insert($this->prefix.'student_principal_links',$data))throw new \RuntimeException('Student principal persistence failed');return(int)$wpdb->insert_id;}
    public function revokePrincipal(int $id,int $version,int $actor,string $reason,string $now):void{global $wpdb;$changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}student_principal_links SET status='revoked',active_slot=NULL,version=version+1,revoked_at=%s,revoked_by=%d,reason_code=%s,updated_at=%s,updated_by=%d WHERE id=%d AND status='active' AND active_slot=1 AND version=%d",$now,$actor,$reason,$now,$actor,$id,$version));if($changed!==1)throw new \InvalidArgumentException('Student principal link is no longer current');}
    public function supersedePrincipal(int $id,int $version,int $actor,string $reason,string $now):void{global $wpdb;$changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}student_principal_links SET status='superseded',active_slot=NULL,version=version+1,superseded_at=%s,superseded_by=%d,reason_code=%s,updated_at=%s,updated_by=%d WHERE id=%d AND status='active' AND active_slot=1 AND version=%d",$now,$actor,$reason,$now,$actor,$id,$version));if($changed!==1)throw new \InvalidArgumentException('Student principal link is no longer current');}
    public function setSupersedingPrincipal(int $id,int $nextId):void{global $wpdb;if($wpdb->update($this->prefix.'student_principal_links',array('superseded_by_link_id'=>$nextId),array('id'=>$id,'status'=>'superseded'))!==1)throw new \RuntimeException('Student principal supersession lineage failed');}
    public function expireElapsedGrants(array $rows,int $actor,string $now):void{global $wpdb;foreach($rows as$row)if($this->isElapsedGrant($row,$now)){$changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}student_acceptance_authority_grants SET state='expired',active_slot=NULL,version=version+1,reason_code='effective_interval_elapsed',updated_at=%s,updated_by=%d WHERE id=%d AND state='active' AND active_slot=1 AND version=%d",$now,$actor,(int)$row->id,(int)$row->version));if($changed!==1)throw new \InvalidArgumentException('Acceptance authority grant is no longer current');$row->state='expired';$row->active_slot=null;$row->version=(int)$row->version+1;}}
    public function isElapsedGrant(object $row,string $now):bool{return$row->state==='active'&&(int)$row->active_slot===1&&$row->effective_until!==null&&(string)$row->effective_until<=$now;}
    public function insertGrant(array $data):int{global $wpdb;if(false===$wpdb->insert($this->prefix.'student_acceptance_authority_grants',$data))throw new \RuntimeException('Acceptance authority persistence failed');return(int)$wpdb->insert_id;}
    public function revokeGrant(int $id,int $version,int $actor,string $reason,string $now):void{global $wpdb;$changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}student_acceptance_authority_grants SET state='revoked',active_slot=NULL,version=version+1,revoked_at=%s,revoked_by=%d,reason_code=%s,updated_at=%s,updated_by=%d WHERE id=%d AND state='active' AND active_slot=1 AND version=%d",$now,$actor,$reason,$now,$actor,$id,$version));if($changed!==1)throw new \InvalidArgumentException('Acceptance authority grant is no longer current');}
    public function markGrantSuperseded(int $id,int $version,int $actor,string $reason,string $now):void{global $wpdb;$changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}student_acceptance_authority_grants SET state='superseded',active_slot=NULL,version=version+1,superseded_at=%s,reason_code=%s,updated_at=%s,updated_by=%d WHERE id=%d AND state='active' AND active_slot=1 AND version=%d",$now,$reason,$now,$actor,$id,$version));if($changed!==1)throw new \InvalidArgumentException('Acceptance authority grant is no longer current');}
    public function setSupersedingGrant(int $id,int $nextId):void{global $wpdb;if($wpdb->update($this->prefix.'student_acceptance_authority_grants',array('superseded_by_grant_id'=>$nextId),array('id'=>$id,'state'=>'superseded'))!==1)throw new \RuntimeException('Acceptance authority supersession persistence failed');}
    public function privacyResolutionConsistent(object $request):bool{if($request->student_id===null&&$request->current_identity_resolution_id===null)return true;if($request->current_identity_resolution_id===null)return false;global $wpdb;if($request->student_id===null)return(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->prefix}booking_request_identity_resolution_events WHERE id=%d AND booking_request_id=%d AND student_id IS NULL AND outcome IN ('unresolved','ambiguous','rejected')",$request->current_identity_resolution_id,$request->id));return(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->prefix}booking_request_identity_resolution_events WHERE id=%d AND booking_request_id=%d AND student_id=%d AND outcome='resolved'",$request->current_identity_resolution_id,$request->id,$request->student_id));}
    public function eligibilityRows(int $requestId,int $userId,string $now):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT r.id,r.student_id,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,r.current_identity_resolution_id,e.outcome,e.student_id AS event_student_id,s.status AS student_status,s.archived_at,s.current_acceptance_capacity_classification_id,c.classification,p.id AS principal_id,g.id AS guardian_grant_id FROM {$this->prefix}booking_requests r LEFT JOIN {$this->prefix}booking_request_identity_resolution_events e ON e.id=r.current_identity_resolution_id LEFT JOIN {$this->prefix}students s ON s.id=r.student_id LEFT JOIN {$this->prefix}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id LEFT JOIN {$this->prefix}student_principal_links p ON p.student_id=s.id AND p.wordpress_user_id=%d AND p.status='active' AND p.active_slot=1 LEFT JOIN {$this->prefix}student_acceptance_authority_grants g ON g.student_id=s.id AND g.acting_wordpress_user_id=%d AND g.authority_type='guardian_representative' AND g.authority_scope='service_acceptance' AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s) WHERE r.id=%d",$userId,$userId,$now,$now,$requestId));}
    public function rows(string $table,int $limit=100):array{global $wpdb;$allowed=array('booking_request_identity_resolution_events','student_acceptance_capacity_classifications','student_principal_links','student_acceptance_authority_grants');if(!in_array($table,$allowed,true))return array();return $wpdb->get_results("SELECT * FROM {$this->prefix}{$table} ORDER BY id DESC LIMIT ".max(1,min($limit,100)));}
    private function discoverAuthorityIds(string $table,string $userColumn,int $studentId,array $userIds):array{
        global $wpdb;
        $userIds=$this->ids($userIds);
        $args=array_merge(array($studentId),$userIds);
        $user=$userIds?' OR '.$userColumn.' IN ('.implode(',',array_fill(0,count($userIds),'%d')).')':'';
        return$this->ids($wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->prefix}{$table} WHERE student_id=%d{$user} ORDER BY id ASC",...$args))?:array());
    }
    private function lockAuthorityRows(string $table,array $ids):array{
        global $wpdb;
        $ids=$this->ids($ids);
        if(!$ids)return array();
        $marks=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}{$table} WHERE id IN ({$marks}) ORDER BY id ASC FOR UPDATE",...$ids))?:array();
        $locked=$this->ids(array_map(static fn(object $row):int=>(int)$row->id,$rows));
        if($locked!==$ids)throw new \RuntimeException('Authority collection changed concurrently');
        return$rows;
    }
    private function revalidateAuthorityDiscovery(array $rows,string $userColumn,int $studentId,array $userIds):void{
        $userIds=$this->ids($userIds);
        foreach($rows as$row)if((int)$row->student_id!==$studentId&&!in_array((int)$row->{$userColumn},$userIds,true))throw new \RuntimeException('Authority collection changed concurrently');
    }
    private function ids(array $ids):array{$ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));sort($ids,SORT_NUMERIC);return$ids;}
}
