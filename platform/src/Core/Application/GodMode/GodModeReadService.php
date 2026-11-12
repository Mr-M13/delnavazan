<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/** Read models for the consolidated, English-only God Mode dashboard. */
final class GodModeReadService {
    public function __construct(private ?GodModeRepository $repository=null){$this->repository??=new GodModeRepository();}

    /** @return array<string,int> */
    public function metrics():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $one=static function(string $sql)use($wpdb):int{return (int)$wpdb->get_var($sql);};
        $runs=$wpdb->get_results("SELECT state,COUNT(*) total FROM {$p}god_mode_scenario_runs GROUP BY state");
        $by=array();foreach((array)$runs as $row)$by[(string)$row->state]=(int)$row->total;
        return array(
            'total_sessions'=>$one("SELECT COUNT(*) FROM {$p}god_mode_sessions WHERE activity_type='test'"),
            'active_sessions'=>$one("SELECT COUNT(*) FROM {$p}god_mode_sessions WHERE activity_type='test' AND status IN ('scheduled','in_progress')"),
            'completed_tests'=>$one("SELECT COUNT(*) FROM {$p}god_mode_sessions WHERE activity_type='test' AND status='completed'"),
            'passed_scenarios'=>(int)($by[GodModeRule::RESULT_PASSED]??0),
            'failed_scenarios'=>(int)($by[GodModeRule::RESULT_FAILED]??0),
            'attention_scenarios'=>(int)(($by[GodModeRule::RESULT_WARNING]??0)+($by[GodModeRule::RESULT_AWAITING_MANUAL]??0)),
            'meet_failures'=>$one("SELECT COUNT(*) FROM {$p}god_mode_sessions WHERE activity_type='test' AND meet_state='failed'"),
            'recording_failures'=>$one("SELECT COUNT(*) FROM {$p}god_mode_sessions WHERE activity_type='test' AND recording_state='failed'"),
            'attention_tests'=>$one("SELECT COUNT(*) FROM {$p}god_mode_sessions WHERE activity_type='test' AND result IN ('failed','warning','awaiting_manual_verification')"),
        );
    }

    /** @return list<object> */
    public function recentActivity(int $limit=20):array{
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare(
            "SELECT e.*,s.reference_code,s.scenario_code,s.status session_status FROM {$wpdb->prefix}dzn_god_mode_session_events e INNER JOIN {$wpdb->prefix}dzn_god_mode_sessions s ON s.id=e.session_id WHERE s.activity_type='test' ORDER BY e.id DESC LIMIT %d",
            max(1,min(100,$limit))
        ));
        return is_array($rows)?$rows:array();
    }

    public function session(int $id):?object{return $this->repository->session($id);}
    public function sessionByReference(string $reference):?object{return $this->repository->sessionByReference($reference);}

    /** @return array<string,mixed> */
    public function inspector(int $sessionId):array{
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        $events=$this->repository->events($sessionId);
        $timeline=array();
        foreach($events as $event){
            $timeline[]=array(
                'id'=>(int)$event->id,
                'stage'=>(string)$event->stage,
                'stage_label'=>$this->stageLabel((string)$event->stage),
                'state'=>(string)$event->state,
                'state_label'=>GodModeRule::timelineStateLabel((string)$event->state),
                'occurred_at'=>(string)$event->occurred_at,
                'correlation_id'=>(string)($event->correlation_id??''),
                'evidence'=>$this->json((string)($event->evidence_json??'')),
                'provider_status'=>$event->provider_status!==null?(int)$event->provider_status:null,
                'error_code'=>(string)($event->error_code??''),
                'error_detail'=>(string)($event->error_detail??''),
                'retryable'=>(bool)$event->retryable,
                'retry_stage'=>(bool)$event->retryable?(string)$event->stage:null,
            );
        }
        $runs=$this->repository->scenarioRuns(200);
        $runs=array_values(array_filter($runs,static fn(object $run):bool=>(int)($run->session_id??0)===$sessionId));
        $attendanceEvidence=array();
        foreach($timeline as $event){
            if((string)$event['stage']===GodModeRule::STAGE_ATTENDANCE_CONFIRMED&&is_array($event['evidence']))$attendanceEvidence=$event['evidence'];
        }
        return array(
            'session'=>(array)$session,
            'timeline'=>$timeline,
            'attendance_evidence'=>$attendanceEvidence,
            'notification_tests'=>$this->repository->notificationTests($sessionId),
            'scenario_runs'=>$runs,
            'activity_type'=>GodModeRule::ACTIVITY_TYPE,
            'billing_impact'=>0,
            'recording_requested'=>(bool)$session->recording_requested,
            'recording_confirmed'=>(string)$session->recording_state==='verified',
        );
    }

    /** @return array<string,mixed> */
    public function diagnosticExport(int $sessionId):array{
        $inspector=$this->inspector($sessionId);
        $session=$inspector['session'];
        foreach(array('description') as $field)unset($session[$field]);
        return array(
            'format'=>'dzn-god-mode-diagnostic-export-v1',
            'generated_at'=>gmdate('Y-m-d H:i:s'),
            'activity_type'=>'test',
            'session'=>$session,
            'timeline'=>$inspector['timeline'],
            'notification_tests'=>array_map(static function(object $row):array{
                return array('channel'=>(string)$row->channel,'recipient_kind'=>(string)$row->recipient_kind,'recipient_digest'=>(string)$row->recipient_digest,'template'=>(string)$row->template_key,'state'=>(string)$row->state,'provider'=>(string)($row->provider_key??''),'outcome'=>(string)($row->outcome_code??''),'failure_class'=>(string)($row->failure_class??''),'fallback_used'=>(bool)$row->fallback_used,'fallback_condition_verified'=>(bool)$row->fallback_condition_verified,'attempted_at'=>(string)$row->attempted_at);
            },$inspector['notification_tests']),
            'scenario_runs'=>array_map(static function(object $row):array{
                return array('code'=>(string)$row->scenario_code,'state'=>(string)$row->state,'summary'=>(string)($row->result_summary??''),'failed_stage'=>(string)($row->failed_stage??''),'started_at'=>(string)$row->started_at,'finished_at'=>(string)($row->finished_at??''));
            },$inspector['scenario_runs']),
            'exclusions'=>array('access_tokens','credentials','webhook_secrets','private_recording_content','raw_recipient_addresses'),
        );
    }

    /** @return list<object> */
    public function history(array $filters,int $limit=100,int $offset=0):array{return $this->repository->sessions($filters,$limit,$offset);}
    public function historyCount(array $filters):int{return $this->repository->countSessions($filters);}

    /** @return list<object> */
    public function teachers():array{
        global $wpdb;$rows=$wpdb->get_results("SELECT id,COALESCE(NULLIF(display_name,''),NULLIF(persian_name,''),english_name) name FROM {$wpdb->prefix}dzn_teachers WHERE archived_at IS NULL ORDER BY name ASC");
        return is_array($rows)?$rows:array();
    }

    public function stageLabel(string $stage):string{
        return match($stage){
            GodModeRule::STAGE_BOOKING_CREATED=>'Booking created',
            GodModeRule::STAGE_TEACHER_ASSIGNED=>'Teacher assigned',
            GodModeRule::STAGE_TEACHER_ACCEPTED=>'Teacher accepted',
            GodModeRule::STAGE_CALENDAR_EVENT=>'Calendar event created',
            GodModeRule::STAGE_MEET_PROVISIONED=>'Google Meet provisioned',
            GodModeRule::STAGE_INVITATION_SENT=>'Invitation sent',
            GodModeRule::STAGE_TEACHER_JOINED=>'Teacher joined',
            GodModeRule::STAGE_RECORDING_STARTED=>'Recording started / verified',
            GodModeRule::STAGE_ATTENDANCE_CONFIRMED=>'Attendance confirmed',
            GodModeRule::STAGE_CLASS_COMPLETED=>'Class completed',
            GodModeRule::STAGE_CLEANUP=>'Cleanup',
            default=>'Unknown stage',
        };
    }

    /** @return array<string,mixed> */
    private function json(string $json):array{
        $data=json_decode($json,true);
        return is_array($data)?$data:array();
    }
}
