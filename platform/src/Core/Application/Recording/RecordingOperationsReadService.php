<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Read-only administration projection. It never writes a consent decision, provider state or
 * arbitrary filesystem path and never exposes a raw token or service secret.
 */
final class RecordingOperationsReadService {
    public const CAPABILITY='dzn_manage_recording_operations';
    public const VIEW_CAPABILITY='dzn_view_recording_operations';
    public const RETRY_CAPABILITY='dzn_retry_recording_commands';
    public const RECONCILE_CAPABILITY='dzn_reconcile_recordings';
    public const LEGAL_HOLD_CAPABILITY='dzn_manage_recording_legal_hold';

    public function counts():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results("SELECT lifecycle_state,COUNT(*) total FROM {$p}recordings GROUP BY lifecycle_state",OBJECT);
        $counts=array_fill_keys(RecordingLifecycle::STATES,0);
        foreach($rows as $row)$counts[(string)$row->lifecycle_state]=(int)$row->total;
        $counts['consent_blocked']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}consent_recording_authorisations WHERE outcome='denied' AND evaluated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
        $counts['storage_warning']=0;
        $health=RecordingFeatureFlags::serviceHealth();
        if(($health['storage_free_percent']??100)<10||($health['storage_free_bytes']??PHP_INT_MAX)<5368709120)$counts['storage_warning']=1;
        $counts['unavailable']=$counts[RecordingLifecycle::FAILED];
        $counts['awaiting_transfer']=$counts[RecordingLifecycle::AWAITING_ARCHIVE]+$counts[RecordingLifecycle::TRANSFER_RETRY_PENDING];
        return $counts;
    }

    public function recordings(int $limit=100,string $state=''):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$limit=max(1,min(500,$limit));
        $where='';$args=array();
        if($state!==''&&in_array($state,RecordingLifecycle::STATES,true)){$where=' WHERE r.lifecycle_state=%s';$args[]=$state;}
        $sql="SELECT r.*,l.uid lesson_uid,l.reference_code lesson_reference,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,
            (SELECT COUNT(*) FROM {$p}recording_commands c WHERE c.recording_id=r.id AND c.state IN ('pending','leased')) pending_commands,
            (SELECT COUNT(*) FROM {$p}recording_commands c WHERE c.recording_id=r.id AND c.state IN ('failed','dead')) failed_commands
            FROM {$p}recordings r LEFT JOIN {$p}lessons l ON l.id=r.lesson_id LEFT JOIN {$p}teachers t ON t.id=r.teacher_id LEFT JOIN {$p}students s ON s.id=r.student_id".$where." ORDER BY r.id DESC LIMIT %d";
        $args[]=$limit;
        return (array)$wpdb->get_results($wpdb->prepare($sql,$args),ARRAY_A);
    }

    public function commands(int $limit=100):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$limit=max(1,min(500,$limit));
        return (array)$wpdb->get_results($wpdb->prepare("SELECT c.*,r.uid recording_uid FROM {$p}recording_commands c INNER JOIN {$p}recordings r ON r.id=c.recording_id ORDER BY c.id DESC LIMIT %d",$limit),ARRAY_A);
    }

    public function health():array{
        $health=RecordingFeatureFlags::serviceHealth();
        $now=time();
        return array(
            'received_at'=>(int)($health['received_at']??0),'fresh'=>(int)($health['received_at']??0)>=$now-180,
            'ready'=>($health['ready']??false)===true,
            'storage_free_bytes'=>(int)($health['storage_free_bytes']??0),
            'storage_free_percent'=>(float)($health['storage_free_percent']??0),
            'archive_mount_identity'=>(string)($health['archive_mount_identity']??''),
            'archive_available'=>($health['archive_available']??false)===true,
            'archive_free_bytes'=>(int)($health['archive_free_bytes']??0),
            'tailscale_connected'=>($health['tailscale_connected']??false)===true,
            'egress_cpu_percent'=>(float)($health['egress_cpu_percent']??0),
            'egress_memory_bytes'=>(int)($health['egress_memory_bytes']??0),
            'oldest_queue_age_seconds'=>(int)($health['oldest_queue_age_seconds']??0),
        );
    }
}
