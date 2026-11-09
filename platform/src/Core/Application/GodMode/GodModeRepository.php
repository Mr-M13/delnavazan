<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

use Delnavazan\Platform\Core\Support\Identifier;

/** Persistence for the dedicated test aggregate. There is no canonical Lesson writer here. */
final class GodModeRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function createSession(array $data):int{
        global $wpdb;
        $now=gmdate('Y-m-d H:i:s');
        $uid=Identifier::uid();
        $reference='GMT-'.$uid;
        $row=array_merge(array(
            'uid'=>$uid,'reference_code'=>$reference,'scenario_code'=>'direct_class_booking',
            'mode'=>GodModeRule::MODE_DIRECT,'status'=>GodModeRule::STATUS_SCHEDULED,
            'activity_type'=>GodModeRule::ACTIVITY_TYPE,'result'=>GodModeRule::RESULT_NOT_TESTED,
            'title'=>'God Mode test class','description'=>null,
            'recording_requested'=>0,'recording_state'=>'not_requested','meet_state'=>'not_requested',
            'classroom_id'=>null,'conflict_acknowledged'=>0,'conflict_summary'=>null,'notification_plan'=>'none',
            'workflow_state'=>null,'current_stage'=>GodModeRule::STAGE_BOOKING_CREATED,'failure_stage'=>null,
            'last_error_code'=>null,'retention_days'=>(int)GodModeSettings::get('retention_days',30),
            'purge_eligible_at'=>null,'archived_at'=>null,'completed_at'=>null,
            'created_at'=>$now,'updated_at'=>$now,'updated_by'=>(int)($data['created_by']??0),
        ),$data);
        if($wpdb->insert($this->p.'god_mode_sessions',$row)===false)throw new \RuntimeException('god_mode_session_persistence_failed');
        return (int)$wpdb->insert_id;
    }

    public function session(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}god_mode_sessions WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function sessionByReference(string $reference):?object{
        return $this->row("SELECT * FROM {$this->p}god_mode_sessions WHERE reference_code=%s",trim($reference));
    }
    public function updateSession(int $id,array $data,array $where=array()):void{
        global $wpdb;$data['updated_at']=gmdate('Y-m-d H:i:s');$data['updated_by']=(int)get_current_user_id();
        if($wpdb->update($this->p.'god_mode_sessions',$data,array_merge(array('id'=>$id),$where))===false)throw new \RuntimeException('god_mode_session_update_failed');
    }
    public function deleteSession(int $id):void{
        global $wpdb;
        foreach(array('god_mode_session_events','god_mode_notification_tests') as $table)$wpdb->delete($this->p.$table,array('session_id'=>$id));
        $wpdb->delete($this->p.'god_mode_scenario_runs',array('session_id'=>$id));
        if($wpdb->delete($this->p.'god_mode_sessions',array('id'=>$id))===false)throw new \RuntimeException('god_mode_session_delete_failed');
    }

    /** @return list<object> */
    public function sessions(array $filters=array(),int $limit=100,int $offset=0):array{
        global $wpdb;$where=array("s.activity_type='test'");$args=array();
        foreach(array('teacher_id','status','scenario_code','mode','result') as $field){
            if(isset($filters[$field])&&$filters[$field]!==''&&$filters[$field]!==null){$where[]="s.{$field}=%s";$args[]=(string)$filters[$field];}
        }
        if(!empty($filters['from_utc'])){$where[]='s.starts_at_utc>=%s';$args[]=(string)$filters['from_utc'];}
        if(!empty($filters['to_utc'])){$where[]='s.starts_at_utc<%s';$args[]=(string)$filters['to_utc'];}
        if(!empty($filters['search'])){
            $like='%'.(function_exists('esc_sql')?esc_sql((string)$filters['search']):(string)$filters['search']).'%';
            $where[]='(s.reference_code LIKE %s OR s.title LIKE %s OR s.scenario_code LIKE %s)';
            $args[]=$like;$args[]=$like;$args[]=$like;
        }
        $order=match((string)($filters['sort']??'')){
            'oldest'=>'s.starts_at_utc ASC,s.id ASC',
            'teacher'=>'t.display_name ASC,s.starts_at_utc DESC',
            'result'=>'s.result ASC,s.starts_at_utc DESC',
            default=>'s.starts_at_utc DESC,s.id DESC',
        };
        $sql="SELECT s.*,t.display_name teacher_name,t.persian_name teacher_name_fa,t.email teacher_email,i.name_fa instrument_name_fa,i.name_en instrument_name_en,c.name_fa course_name_fa,c.name_en course_name_en
              FROM {$this->p}god_mode_sessions s
              LEFT JOIN {$this->p}teachers t ON t.id=s.teacher_id
              LEFT JOIN {$this->p}instruments i ON i.id=s.instrument_id
              LEFT JOIN {$this->p}courses c ON c.id=s.course_id
              WHERE ".implode(' AND ',$where).' ORDER BY '.$order.' LIMIT %d OFFSET %d';
        $args[]=max(1,min(500,$limit));$args[]=max(0,$offset);
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$args));
        if($wpdb->last_error!=='')throw new \RuntimeException('god_mode_history_unavailable');
        return is_array($rows)?$rows:array();
    }

    public function countSessions(array $filters=array()):int{
        global $wpdb;$where=array("activity_type='test'");$args=array();
        foreach(array('teacher_id','status','scenario_code','mode','result') as $field)if(isset($filters[$field])&&$filters[$field]!==''&&$filters[$field]!==null){$where[]="{$field}=%s";$args[]=(string)$filters[$field];}
        $sql="SELECT COUNT(*) FROM {$this->p}god_mode_sessions WHERE ".implode(' AND ',$where);
        return (int)($args?$wpdb->get_var($wpdb->prepare($sql,...$args)):$wpdb->get_var($sql));
    }

    public function appendEvent(int $sessionId,string $stage,string $state,array $evidence=array(),?string $correlationId=null,?int $providerStatus=null,?string $providerErrorClass=null,?string $errorCode=null,?string $errorDetail=null,bool $retryable=false):int{
        global $wpdb;
        $stage=in_array($stage,GodModeRule::STAGES,true)?$stage:GodModeRule::STAGE_BOOKING_CREATED;
        $state=in_array($state,GodModeRule::EVENT_STATES,true)?$state:GodModeRule::EVENT_UNKNOWN;
        $now=gmdate('Y-m-d H:i:s');
        $payload=array(
            'uid'=>Identifier::uid(),'session_id'=>$sessionId,'stage'=>$stage,'state'=>$state,
            'correlation_id'=>$correlationId!==null?substr($correlationId,0,64):null,
            'evidence_json'=>$evidence?self::safeJson($evidence):null,
            'provider_status'=>$providerStatus,'provider_error_class'=>$providerErrorClass!==null?substr($providerErrorClass,0,64):null,
            'error_code'=>$errorCode!==null?substr($errorCode,0,64):null,
            'error_detail'=>$errorDetail!==null?substr((string)GodModeAudit::redact($errorDetail),0,255):null,
            'retryable'=>$retryable?1:0,'occurred_at'=>$now,'created_at'=>$now,'created_by'=>(int)get_current_user_id(),
        );
        if($wpdb->insert($this->p.'god_mode_session_events',$payload)===false)throw new \RuntimeException('god_mode_event_persistence_failed');
        return (int)$wpdb->insert_id;
    }

    /** @return list<object> */
    public function events(int $sessionId):array{
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}god_mode_session_events WHERE session_id=%d ORDER BY id ASC",$sessionId));
        return is_array($rows)?$rows:array();
    }

    public function startScenarioRun(string $code,?int $sessionId,int $actor):int{
        global $wpdb;$now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($this->p.'god_mode_scenario_runs',array('uid'=>Identifier::uid(),'scenario_code'=>$code,'session_id'=>$sessionId,'state'=>GodModeRule::RESULT_RUNNING,'started_at'=>$now,'created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now));
        if($wpdb->last_error!=='')throw new \RuntimeException('god_mode_scenario_run_failed');
        return (int)$wpdb->insert_id;
    }
    public function finishScenarioRun(int $id,string $state,string $summary,array $evidence,?string $failedStage=null):void{
        global $wpdb;$state=in_array($state,GodModeRule::RESULTS,true)?$state:GodModeRule::RESULT_FAILED;
        $wpdb->update($this->p.'god_mode_scenario_runs',array('state'=>$state,'result_summary'=>substr($summary,0,2000),'evidence_json'=>self::safeJson($evidence),'failed_stage'=>$failedStage,'finished_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$id));
    }
    /** @return list<object> */
    public function scenarioRuns(int $limit=100):array{
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}god_mode_scenario_runs ORDER BY started_at DESC,id DESC LIMIT %d",max(1,min(500,$limit))));
        return is_array($rows)?$rows:array();
    }
    public function scenarioRun(int $id):?object{return $this->row("SELECT * FROM {$this->p}god_mode_scenario_runs WHERE id=%d",$id);}

    public function recordNotification(int $sessionId,?int $runId,string $channel,string $recipientKind,string $recipient,string $template,string $state,?string $provider=null,?string $outcome=null,?string $failureClass=null,bool $fallbackUsed=false,bool $fallbackVerified=false):int{
        global $wpdb;$now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($this->p.'god_mode_notification_tests',array(
            'uid'=>Identifier::uid(),'session_id'=>$sessionId>0?$sessionId:null,'scenario_run_id'=>$runId,
            'channel'=>$channel,'recipient_kind'=>$recipientKind,'recipient_digest'=>hash('sha256','god-mode-recipient:'.strtolower(trim($recipient))),
            'template_key'=>$template,'state'=>$state,'provider_key'=>$provider,'outcome_code'=>$outcome,
            'failure_class'=>$failureClass,'fallback_used'=>$fallbackUsed?1:0,'fallback_condition_verified'=>$fallbackVerified?1:0,
            'provider_reference_digest'=>null,'attempted_at'=>$now,'created_at'=>$now,'created_by'=>(int)get_current_user_id(),
        ));
        return (int)$wpdb->insert_id;
    }
    /** @return list<object> */
    public function notificationTests(?int $sessionId=null,int $limit=100):array{
        global $wpdb;
        $sql=$sessionId!==null
            ?$wpdb->prepare("SELECT * FROM {$this->p}god_mode_notification_tests WHERE session_id=%d ORDER BY id DESC LIMIT %d",$sessionId,max(1,min(500,$limit)))
            :$wpdb->prepare("SELECT * FROM {$this->p}god_mode_notification_tests ORDER BY id DESC LIMIT %d",max(1,min(500,$limit)));
        $rows=$wpdb->get_results($sql);return is_array($rows)?$rows:array();
    }

    public function recordCleanupReceipt(int $sessionId,string $action,string $classification,array $counts,array $retained,int $actor):void{
        global $wpdb;$now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($this->p.'god_mode_cleanup_receipts',array('uid'=>Identifier::uid(),'session_id'=>$sessionId,'action'=>$action,'classification'=>$classification,'affected_counts_json'=>self::safeJson($counts),'retained_evidence_json'=>self::safeJson($retained),'executed_at'=>$now,'executed_by'=>$actor,'created_at'=>$now));
    }
    /** @return list<object> */
    public function cleanupReceipts(int $limit=100):array{
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}god_mode_cleanup_receipts ORDER BY id DESC LIMIT %d",max(1,min(500,$limit))));return is_array($rows)?$rows:array();
    }

    /** @return list<object> */
    public function teacherTestSessions(int $teacherId,?string $fromUtc=null,int $limit=50):array{
        global $wpdb;
        $from=$fromUtc?:gmdate('Y-m-d H:i:s');
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT s.*,i.name_fa instrument_name_fa,i.name_en instrument_name_en,c.name_fa course_name_fa,c.name_en course_name_en
             FROM {$this->p}god_mode_sessions s
             LEFT JOIN {$this->p}instruments i ON i.id=s.instrument_id
             LEFT JOIN {$this->p}courses c ON c.id=s.course_id
             WHERE s.teacher_id=%d AND s.activity_type='test' AND s.status IN ('scheduled','in_progress','completed')
             AND s.starts_at_utc>=%s ORDER BY s.starts_at_utc ASC LIMIT %d",
            $teacherId,$from,max(1,min(100,$limit))
        ));
        return is_array($rows)?$rows:array();
    }

    /** @return list<object> */
    public function archivedSessions(int $limit=200):array{
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}god_mode_sessions WHERE activity_type='test' AND status='archived' ORDER BY id ASC LIMIT %d",max(1,min(500,$limit))));
        return is_array($rows)?$rows:array();
    }

    /** @return list<object> */
    public function staleSessions(string $cutoffUtc,int $limit=100):array{
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}god_mode_sessions WHERE activity_type='test' AND status IN ('completed','cancelled','failed') AND ends_at_utc<%s ORDER BY ends_at_utc ASC LIMIT %d",
            $cutoffUtc,max(1,min(200,$limit))
        ));
        return is_array($rows)?$rows:array();
    }

    public function pendingProviderOperations(int $sessionId):int{
        $session=$this->session($sessionId);if(!$session)return 0;
        return in_array((string)$session->meet_state,array('pending','provisioning'),true)?1:0;
    }

    public function testerStudentId():int{
        global $wpdb;$id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}students WHERE reference_code=%s LIMIT 1",'GOD-MODE-TESTER'));
        if($id>0)return $id;
        $now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($this->p.'students',array(
            'uid'=>Identifier::uid(),'reference_code'=>'GOD-MODE-TESTER','status'=>'active',
            'first_name'=>'Super','last_name'=>'Tester','display_name'=>'Super Tester (TEST)',
            'email'=>'god-mode-tester@delnavazan.invalid','timezone'=>'UTC','locale'=>'en_US','calendar_preference'=>'gregorian',
            'created_at'=>$now,'updated_at'=>$now,'created_by'=>0,'updated_by'=>0,
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('god_mode_tester_profile_failed');
        return (int)$wpdb->insert_id;
    }

    private function row(string $sql,mixed ...$args):?object{
        global $wpdb;$row=$args?$wpdb->get_row($wpdb->prepare($sql,...$args)):$wpdb->get_row($sql);
        return $row?:null;
    }
    private static function safeJson(array $value):string{
        $json=wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return is_string($json)?$json:'{}';
    }
}
