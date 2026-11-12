<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/**
 * Dependency-aware, read-only inventory of the designated account's previous student records.
 *
 * It never deletes or mutates a row. Financial and audit-bearing records are explicitly marked as
 * retained evidence so a future approved cleanup cannot silently remove them.
 */
final class GodModeInventoryService {
    public function __construct(private ?GodModeRepository $repository=null){$this->repository??=new GodModeRepository();}

    /** @return array<string,mixed> */
    public function inventoryForUser(int $userId):array{
        GodModeAuthority::require();
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $user=get_userdata($userId);
        $email=$user&&is_object($user)?strtolower(trim((string)($user->user_email??''))):'';
        $studentIds=$this->ids($p.'students','id',"wordpress_user_id=%d",array($userId));
        $linked=$this->ids($p.'student_principal_links','student_id',"wordpress_user_id=%d",array($userId));
        $studentIds=array_values(array_unique(array_merge($studentIds,$linked)));
        if($email!=='')$studentIds=array_values(array_unique(array_merge($studentIds,$this->ids($p.'students','id','LOWER(email)=%s',array($email)))));
        $bookingIds=$this->ids($p.'booking_requests','id','student_id IN ('. $this->in($studentIds) .')',$studentIds);
        if($email!==''){
            $bookingIds=array_values(array_unique(array_merge($bookingIds,$this->ids($p.'booking_request_contact_snapshots','booking_request_id','LOWER(email)=%s',array($email)))));
        }
        $enrolmentIds=$this->ids($p.'enrolments','id','student_id IN ('.$this->in($studentIds).')',$studentIds);
        $termIds=$this->ids($p.'terms','id','enrolment_id IN ('.$this->in($enrolmentIds).')',$enrolmentIds);
        $lessonIds=$this->ids($p.'lessons','id','student_id IN ('.$this->in($studentIds).')',$studentIds);
        $attendanceCaseIds=$this->ids($p.'canonical_attendance_cases','id','student_id IN ('.$this->in($studentIds).')',$studentIds);
        $items=array();
        $add=static function(string $table,string $label,array $ids,string $classification,string $retention)use(&$items):void{
            $items[]=array('table'=>$table,'label'=>$label,'count'=>count($ids),'sample_ids'=>array_slice($ids,0,50),'classification'=>$classification,'retention'=>$retention);
        };
        $add($p.'students','Student profiles',$studentIds,'genuine_identity','preserve_wordpress_identity_and_profile');
        $add($p.'booking_requests','Introductory class requests',$bookingIds,'genuine_student_activity','retain_audit_then_archive');
        $add($p.'booking_request_confirmations','Booking confirmations',$this->ids($p.'booking_request_confirmations','id','booking_request_id IN ('.$this->in($bookingIds).')',$bookingIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'booking_request_teacher_deliveries','Teacher assignments',$this->ids($p.'booking_request_teacher_deliveries','id','booking_request_id IN ('.$this->in($bookingIds).')',$bookingIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'booking_request_requested_times','Introductory requested times',$this->ids($p.'booking_request_requested_times','id','booking_request_id IN ('.$this->in($bookingIds).')',$bookingIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'enrolments','Enrolments',$enrolmentIds,'genuine_student_activity','retain_financial_lineage');
        $add($p.'terms','Term allocations',$termIds,'genuine_student_activity','retain_financial_lineage');
        $add($p.'teacher_assignments','Teacher assignments',$this->ids($p.'teacher_assignments','id','enrolment_id IN ('.$this->in($enrolmentIds).')',$enrolmentIds),'genuine_student_activity','retain_financial_lineage');
        $add($p.'lessons','Classes and lessons',$lessonIds,'genuine_student_activity','retain_financial_lineage');
        $add($p.'lesson_schedule_versions','Legacy class schedules',$this->ids($p.'lesson_schedule_versions','id','lesson_id IN ('.$this->in($lessonIds).')',$lessonIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'canonical_lesson_schedule_versions','Canonical class schedules',$this->ids($p.'canonical_lesson_schedule_versions','id','lesson_id IN ('.$this->in($lessonIds).')',$lessonIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'canonical_attendance_cases','Attendance cases',$attendanceCaseIds,'genuine_student_activity','retain_audit_then_archive');
        $add($p.'canonical_attendance_evidence','Attendance evidence',$this->ids($p.'canonical_attendance_evidence','id','case_id IN ('.$this->in($attendanceCaseIds).')',$attendanceCaseIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'canonical_lesson_delivery_outcomes','Class completion outcomes',$this->ids($p.'canonical_lesson_delivery_outcomes','id','lesson_id IN ('.$this->in($lessonIds).')',$lessonIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'finance_lesson_snapshots','Finance lesson snapshots',$this->ids($p.'finance_lesson_snapshots','id','lesson_id IN ('.$this->in($lessonIds).')',$lessonIds),'financial_evidence','immutable_retain');
        $add($p.'intro_payment_obligations','Introductory payment obligations',$this->ids($p.'intro_payment_obligations','id','student_id IN ('.$this->in($studentIds).')',$studentIds),'financial_evidence','immutable_retain');
        $add($p.'intro_checkout_sessions','Introductory checkout sessions',$this->ids($p.'intro_checkout_sessions','id','student_id IN ('.$this->in($studentIds).')',$studentIds),'financial_evidence','immutable_retain');
        $add($p.'commercial_offers','Commercial offers',$this->ids($p.'commercial_offers','id','student_id IN ('.$this->in($studentIds).')',$studentIds),'financial_evidence','immutable_retain');
        $add($p.'commercial_purchases','Commercial purchases',$this->ids($p.'commercial_purchases','id','beneficiary_student_id IN ('.$this->in($studentIds).')',$studentIds),'financial_evidence','immutable_retain');
        $add($p.'commercial_entitlements','Commercial entitlements',$this->ids($p.'commercial_entitlements','id','beneficiary_student_id IN ('.$this->in($studentIds).')',$studentIds),'financial_evidence','immutable_retain');
        $add($p.'portal_notifications','Portal notifications',$this->ids($p.'portal_notifications','id',"(wp_user_id=%d OR (audience='student' AND recipient_id IN (".$this->in($studentIds).")))",array_merge(array($userId),$studentIds)),'operational','archive_or_expire');
        $add($p.'post_intro_preparations','Post-intro preparations',$this->ids($p.'post_intro_preparations','id','student_id IN ('.$this->in($studentIds).')',$studentIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'post_intro_feedback','Post-intro feedback',$this->ids($p.'post_intro_feedback','id','student_id IN ('.$this->in($studentIds).')',$studentIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'canonical_continuation_cases','Continuation decisions',$this->ids($p.'canonical_continuation_cases','id','student_id IN ('.$this->in($studentIds).')',$studentIds),'genuine_student_activity','retain_audit_then_archive');
        $add($p.'recording_decisions','Recording decisions',$this->ids($p.'recording_decisions','id',"subject_type='student' AND subject_id IN (".$this->in($studentIds).")",$studentIds),'consent_evidence','immutable_retain');
        $items=array_values(array_filter($items,static fn(array $item):bool=>$item['count']>0));
        $total=0;$financial=0;$legal=0;foreach($items as $item){$total+=$item['count'];if($item['classification']==='financial_evidence')$financial+=$item['count'];if(in_array($item['retention'],array('immutable_retain','retain_financial_lineage','preserve_wordpress_identity_and_profile'),true))$legal+=$item['count'];}
        return array(
            'user_id'=>$userId,'email_reference'=>$email!==''?'[verified account]':'',
            'generated_at'=>gmdate('Y-m-d H:i:s'),
            'student_ids'=>$studentIds,
            'items'=>$items,
            'totals'=>array('records'=>$total,'financial_evidence'=>$financial,'retained_or_legal'=>$legal),
            'destructive_action_executed'=>false,
            'notes'=>array(
                'The WordPress account and administrator access are never part of this inventory.',
                'Financial evidence and consent evidence are marked immutable and must not be deleted.',
                'This report is read-only. A separately approved cleanup is required before any deletion.',
            ),
            'test_session_count'=>$this->repository->countSessions(),
        );
    }

    /** @return array<string,mixed> */
    public function writeArchive(int $userId):array{
        GodModeAuthority::require();
        $inventory=$this->inventoryForUser($userId);
        $uploads=wp_upload_dir();
        $base=is_array($uploads)&&!empty($uploads['basedir'])?(string)$uploads['basedir']:'';
        if($base==='')throw new \RuntimeException('god_mode_archive_directory_unavailable');
        $dir=$base.'/dzn-god-mode-archive';
        if(!is_dir($dir)&&!wp_mkdir_p($dir))throw new \RuntimeException('god_mode_archive_directory_unavailable');
        if(!file_exists($dir.'/index.php'))@file_put_contents($dir.'/index.php',"<?php\n// Silence is golden.\n");
        if(!file_exists($dir.'/.htaccess'))@file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n");
        $name='god-mode-inventory-'.gmdate('Ymd-His').'-'.substr(hash('sha256',(string)$userId.gmdate('c')),0,8).'.json';
        $path=$dir.'/'.$name;
        $json=wp_json_encode($inventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||file_put_contents($path,$json,LOCK_EX)===false)throw new \RuntimeException('god_mode_archive_write_failed');
        @chmod($path,0600);
        GodModeAudit::record($userId,'legacy_inventory_archive','wordpress_user',$userId,'recoverable_inventory_snapshot',$name);
        return array('path'=>$path,'name'=>$name,'counts'=>$inventory['totals'],'destructive_action_executed'=>false);
    }

    /** @return list<int> */
    private function ids(string $table,string $column,string $where,array $args):array{
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return array();
        if($args===array())return array();
        $sql="SELECT {$column} FROM {$table} WHERE {$where}";
        $rows=$wpdb->get_col($wpdb->prepare($sql,...$args));
        if($wpdb->last_error!=='')throw new \RuntimeException('god_mode_inventory_query_failed');
        return array_values(array_unique(array_map('intval',(array)$rows)));
    }
    private function in(array $ids):string{
        if($ids===array())return '0';
        return implode(',',array_map('intval',$ids));
    }
}
