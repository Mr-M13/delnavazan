<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\GodMode\{GodModeAudit,GodModeAuthority,GodModeBookingService,GodModeInventoryService,GodModeNotificationService,GodModeReadService,GodModeRepository,GodModeRule,GodModeScenarioLibrary,GodModeScenarioRunner,GodModeSessionService,GodModeSettings};

/** One consolidated, English-only God Mode administrator destination. */
final class GodModeController {
    public const MENU_SLUG='dzn-god-mode';
    public const ACTION='dzn_god_mode_action';
    public const EXPORT_ACTION='dzn_god_mode_export';
    public const JOIN_ACTION='dzn_god_mode_join';

    public static function register():void{
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'handlePost'));
        add_action('admin_post_'.self::EXPORT_ACTION,array(__CLASS__,'exportDiagnostic'));
        add_action('admin_post_'.self::JOIN_ACTION,array(__CLASS__,'joinTestClass'));
    }

    public static function render():void{
        GodModeAuthority::require();
        $tab=sanitize_key((string)($_GET['tab']??'overview'));
        if(!in_array($tab,array('overview','book','scenarios','history','inspector','settings'),true))$tab='overview';
        $read=new GodModeReadService();
        $view=new \Delnavazan\Platform\Admin\View\GodModeView($read);
        $url=plugins_url('src/Admin/Assets/god-mode.css',DZN_PLATFORM_FILE);
        $path=DZN_PLATFORM_DIR.'src/Admin/Assets/god-mode.css';
        wp_enqueue_style('dzn-god-mode',$url,array('dzn-admin'),file_exists($path)?(string)filemtime($path):DZN_PLATFORM_VERSION);
        $view->render($tab,self::flash(),self::context());
    }

    /** @return array<string,mixed> */
    public static function context():array{
        $read=new GodModeReadService();
        $sessionId=(int)($_GET['session']??0);
        $filters=array(
            'teacher_id'=>isset($_GET['teacher_id'])?(int)$_GET['teacher_id']:0,
            'status'=>isset($_GET['status'])?sanitize_key(wp_unslash((string)$_GET['status'])):'',
            'scenario_code'=>isset($_GET['scenario'])?sanitize_key(wp_unslash((string)$_GET['scenario'])):'',
            'mode'=>isset($_GET['mode'])?sanitize_key(wp_unslash((string)$_GET['mode'])):'',
            'result'=>isset($_GET['result'])?sanitize_key(wp_unslash((string)$_GET['result'])):'',
            'from_utc'=>isset($_GET['from'])?self::utcDate((string)$_GET['from']):'',
            'to_utc'=>isset($_GET['to'])?self::utcDate((string)$_GET['to'],true):'',
            'search'=>isset($_GET['q'])?sanitize_text_field(wp_unslash((string)$_GET['q'])):'',
            'sort'=>isset($_GET['sort'])?sanitize_key(wp_unslash((string)$_GET['sort'])):'',
        );
        $filters=array_filter($filters,static fn($value):bool=>$value!==''&&$value!==0&&$value!==null);
        $inspector=null;
        if($sessionId>0){try{$inspector=$read->inspector($sessionId);}catch(\Throwable $error){$inspector=null;}}
        return array(
            'metrics'=>$read->metrics(),'recent'=>$read->recentActivity(20),'options'=>(new GodModeBookingService())->bookingOptions(),
            'scenarios'=>GodModeScenarioLibrary::all(),'scenario_runs'=>$read->scenarioRuns(100),
            'history'=>$read->history($filters,100,0),'history_count'=>$read->historyCount($filters),'filters'=>$filters,
            'teachers'=>$read->teachers(),'inspector'=>$inspector,'session_id'=>$sessionId,
            'settings'=>GodModeSettings::all(),'identity'=>GodModeAuthority::context(GodModeAuthority::designatedUserId()),
            'inventory'=>get_transient('dzn_god_mode_inventory_'.get_current_user_id()) ?: null,'scenario_library'=>GodModeScenarioLibrary::all(),
            'now'=>gmdate('Y-m-d H:i:s'),'timezones'=>timezone_identifiers_list(),
        );
    }

    public static function handlePost():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        $actor=GodModeAuthority::require();
        check_admin_referer(self::ACTION);
        $operation=sanitize_key((string)wp_unslash($_POST['operation']??''));
        try{
            GodModeAudit::record($actor,'god_mode_action','admin_action',null,$operation!==''?$operation:'unknown');
            $redirect=self::dispatch($operation,$actor);
        }catch(\Throwable $error){
            $message=self::safeMessage($error->getMessage());
            self::setFlash('error',self::humanizeError($message));
            $redirect=self::tabForOperation($operation);
            GodModeAudit::record($actor,'god_mode_action_failed','admin_action',null,$operation!==''?$operation:'unknown',$message);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /** @return string */
    private static function dispatch(string $operation,int $actor):string{
        $booking=new GodModeBookingService();
        $sessions=new GodModeSessionService();
        $repo=new GodModeRepository();
        switch($operation){
            case 'book_test':
                $input=self::bookingInput();
                $created=$booking->create($input);
                $id=(int)($created['id']??0);
                $session=$repo->session($id);
                $plan=(string)($input['notification_behavior']??'none');
                if($id>0&&$plan!=='none'){
                    try{
                        $notifier=new GodModeNotificationService($repo);
                        $notifier->sendTest($id,'portal','owner');
                        if(in_array($plan,array('email','all'),true))$notifier->sendTest($id,'email','owner');
                        if($plan==='all'){
                            $notifier->sendTest($id,'whatsapp','owner');
                            $notifier->sendTest($id,'sms','owner');
                        }
                    }catch(\Throwable $notificationError){
                        $repo->appendEvent($id,GodModeRule::STAGE_INVITATION_SENT,GodModeRule::EVENT_FAILED,array('reason'=>'booking_notification_plan_failed'),'notification:'.$id,null,null,'god_mode_notification_plan_failed',$notificationError->getMessage(),true);
                    }
                }
                self::setFlash('success','Test class created. ID '.($session->reference_code??('GMT-'.$id)).'. Meet state: '.($session->meet_state??'unknown').'. No financial obligation was created.');
                return add_query_arg(array('page'=>self::MENU_SLUG,'tab'=>'inspector','session'=>$id),admin_url('admin.php'));
            case 'check_conflicts':
            case 'preview_test':
                $result=$operation==='preview_test'?$booking->preview(self::bookingInput()):$booking->checkConflicts(self::bookingInput());
                self::setFlash('info','Conflict check completed. Genuine conflicts: '.(int)$result['real_conflicts'].'. Test conflicts: '.(int)$result['test_conflicts'].'. '.$result['summary'].'. Test classes never block genuine availability.');
                return add_query_arg(array('page'=>self::MENU_SLUG,'tab'=>'book'),admin_url('admin.php'));
            case 'cancel_test':
                $sessionId=(int)($_POST['session_id']??0);
                $booking->cancel($sessionId,'operator_cancelled');
                return self::successRedirect('Test session cancelled and provider event retracted where available.',array('tab'=>'inspector','session'=>$sessionId));
            case 'archive_test':
                $sessions->archive((int)($_POST['session_id']??0));
                return self::successRedirect('Test session archived. It remains in history and audit evidence is retained.',array('tab'=>'history'));
            case 'delete_test':
                $sessions->deleteEligible((int)($_POST['session_id']??0),true);
                return self::successRedirect('Eligible test data was permanently removed and its provider event retracted where available. Audit records were retained.',array('tab'=>'history'));
            case 'duplicate_test':
                $id=$booking->duplicate((int)($_POST['session_id']??0));
                return self::successRedirect('Test session duplicated.',array('tab'=>'inspector','session'=>$id));
            case 'reschedule_test':
                $sessionId=(int)($_POST['session_id']??0);
                $booking->reschedule($sessionId,array_merge(self::bookingInput(),array('conflict_acknowledged'=>!empty($_POST['conflict_acknowledged']))));
                return self::successRedirect('Test session rescheduled.',array('tab'=>'inspector','session'=>$sessionId));
            case 'retry_stage':
                $sessionId=(int)($_POST['session_id']??0);
                $stage=sanitize_key((string)wp_unslash($_POST['stage']??GodModeRule::STAGE_MEET_PROVISIONED));
                $result=$sessions->retry($sessionId,$stage);
                return self::successRedirect('Retry executed for '.$stage.'. Result: '.($result['state']??'attempted').'.',array('tab'=>'inspector','session'=>$sessionId));
            case 'verify_recording':
                $sessionId=(int)($_POST['session_id']??0);
                $result=$sessions->verifyRecording($sessionId);
                return self::successRedirect('Recording verification: '.($result['state']??'unknown').'. '.($result['detail']??''),array('tab'=>'inspector','session'=>$sessionId));
            case 'map_participant':
                $sessionId=(int)($_POST['session_id']??0);
                $digest=sanitize_text_field(wp_unslash((string)($_POST['participant_digest']??'')));
                $type=sanitize_key((string)wp_unslash($_POST['participant_type']??'unknown'));
                $role=sanitize_key((string)wp_unslash($_POST['role']??''));
                $result=(new GodModeSessionService($repo))->mapParticipant($sessionId,$digest,$type,$role);
                return self::successRedirect('Participant identity mapped to '.$result['role'].'. Re-run evidence verification to confirm attendance.',array('tab'=>'inspector','session'=>$sessionId));
            case 'verify_attendance_evidence':
                $sessionId=(int)($_POST['session_id']??0);
                $result=(new GodModeSessionService($repo))->verifyAttendance($sessionId);
                return self::successRedirect('Participant evidence: '.($result['state']??'unknown').'. '.($result['detail']??''),array('tab'=>'inspector','session'=>$sessionId));
            case 'confirm_attendance':
                $sessionId=(int)($_POST['session_id']??0);
                $sessions->confirmAttendance($sessionId,!empty($_POST['attended']),(string)wp_unslash($_POST['note']??''));
                return self::successRedirect('Attendance confirmed for test session. No finance snapshot or teacher earning was created.',array('tab'=>'inspector','session'=>$sessionId));
            case 'cancel_booking':
                $sessionId=(int)($_POST['session_id']??0);
                $booking->cancel($sessionId,'operator_cancelled');
                return self::successRedirect('Test session cancelled and provider event retracted where available.',array('tab'=>'inspector','session'=>$sessionId));
            case 'run_scenario':
                $code=sanitize_key((string)wp_unslash($_POST['scenario_code']??''));
                $runner=new GodModeScenarioRunner($repo);
                $result=$runner->run($code,self::scenarioInput());
                self::setFlash($result['state']===GodModeRule::RESULT_FAILED?'error':($result['state']===GodModeRule::RESULT_PASSED?'success':'info'),'Scenario '.$code.': '.$result['summary']);
                return add_query_arg(array('page'=>self::MENU_SLUG,'tab'=>!empty($result['session_id'])?'inspector':'scenarios','session'=>(int)($result['session_id']??0)),admin_url('admin.php'));
            case 'notification_test':
                $sessionId=(int)($_POST['session_id']??0);
                $channel=sanitize_key((string)wp_unslash($_POST['channel']??'portal'));
                $result=(new GodModeNotificationService($repo))->sendTest($sessionId,$channel,sanitize_key((string)wp_unslash($_POST['recipient_kind']??'owner')));
                self::setFlash($result['state']==='failed'?'error':'info','Notification test '.$channel.': '.$result['state'].' via '.($result['provider']??'unknown').'. Delivery is not confirmed by transport hand-off.');
                return add_query_arg(array('page'=>self::MENU_SLUG,'tab'=>$sessionId>0?'inspector':'settings','session'=>$sessionId),admin_url('admin.php'));
            case 'bulk_action':
                $bulk=sanitize_key((string)wp_unslash($_POST['bulk']??''));
                $ids=array_values(array_filter(array_map('intval',(array)($_POST['selected']??array())),static fn(int $id):bool=>$id>0));
                $done=0;$refused=0;
                foreach($ids as $id){
                    try{
                        if($bulk==='archive'){$sessions->archive($id);$done++;}
                        elseif($bulk==='delete'){$sessions->deleteEligible($id,true);$done++;}
                        else break;
                    }catch(\Throwable $error){$refused++;}
                }
                self::setFlash($refused>0?'info':'success','Bulk '.$bulk.' completed: '.$done.' completed, '.$refused.' refused by safety checks.');
                return self::tabForOperation('history');
            case 'cleanup_by_age':
                if(!GodModeSettings::get('automatic_cleanup_eligibility',false))throw new \RuntimeException('Enable automatic cleanup eligibility in Settings before running an age-based lifecycle pass.');
                $days=max(1,(int)GodModeSettings::get('retention_days',30));
                $cutoff=gmdate('Y-m-d H:i:s',time()-($days*86400));
                $archived=0;
                foreach($repo->staleSessions($cutoff,200) as $stale){
                    try{$sessions->archive((int)$stale->id);$archived++;}catch(\Throwable $error){}
                }
                self::setFlash('success','Age-based lifecycle pass archived '.$archived.' completed/cancelled test sessions older than '.$days.' days. Nothing was permanently deleted.');
                return self::tabForOperation('settings');
            case 'cleanup_all_eligible':
                $done=0;$refused=0;
                foreach($repo->archivedSessions(500) as $archived){
                    try{$sessions->deleteEligible((int)$archived->id,true);$done++;}catch(\Throwable $error){$refused++;}
                }
                self::setFlash('success','Permanent cleanup pass completed: '.$done.' archived test records removed, '.$refused.' refused by retention/evidence safety checks. Audit records were retained.');
                return self::tabForOperation('settings');
            case 'legacy_inventory':
                $inventory=(new GodModeInventoryService($repo))->inventoryForUser(GodModeAuthority::designatedUserId());
                self::setFlash('info','Read-only inventory completed: '.(int)$inventory['totals']['records'].' related records, '.(int)$inventory['totals']['financial_evidence'].' financial evidence records, '.(int)$inventory['totals']['retained_or_legal'].' retained/legal records. No records were deleted.');
                set_transient('dzn_god_mode_inventory_'.get_current_user_id(),$inventory,120);
                return self::tabForOperation('settings');
            case 'legacy_archive':
                $result=(new GodModeInventoryService($repo))->writeArchive(GodModeAuthority::designatedUserId());
                self::setFlash('success','Recoverable inventory snapshot written: '.$result['name'].'. No records were deleted.');
                return self::tabForOperation('settings');
            case 'save_settings':
                $settings=GodModeSettings::save($actor,array(
                    'default_timezone'=>(string)wp_unslash($_POST['default_timezone']??'UTC'),
                    'default_duration_minutes'=>(int)($_POST['default_duration_minutes']??30),
                    'default_mode'=>sanitize_key((string)wp_unslash($_POST['default_mode']??'direct')),
                    'default_notification_behavior'=>sanitize_key((string)wp_unslash($_POST['default_notification_behavior']??'portal')),
                    'approved_recipients'=>(string)wp_unslash($_POST['approved_recipients']??''),
                    'recording_default'=>!empty($_POST['recording_default']),
                    'retention_days'=>(int)($_POST['retention_days']??30),
                    'automatic_cleanup_eligibility'=>!empty($_POST['automatic_cleanup_eligibility']),
                    'conflict_warning_enabled'=>!empty($_POST['conflict_warning_enabled']),
                    'diagnostic_logging'=>!empty($_POST['diagnostic_logging']),
                ));
                self::setFlash('success','Settings saved and audited. Default timezone: '.$settings['default_timezone'].'.');
                return self::tabForOperation('settings');
            case 'revoke_authority':
                GodModeAuthority::revoke($actor,'owner_requested_revocation');
                wp_safe_redirect(admin_url());
                exit;
            default:
                throw new \InvalidArgumentException('Unsupported God Mode action');
        }
    }

    public static function joinTestClass():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        GodModeAuthority::require();
        $sessionId=(int)($_POST['session_id']??0);
        check_admin_referer(self::JOIN_ACTION.'_'.$sessionId);
        try{
            $session=(new GodModeRepository())->session($sessionId);
            if(!$session||(string)$session->activity_type!=='test')throw new \RuntimeException('god_mode_test_session_required');
            $target=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->joinUriForTestSession($sessionId);
            GodModeAudit::record(GodModeAuthority::require(),'test_session_join','test_session',$sessionId,'administrator_join');
            nocache_headers();header('Referrer-Policy: no-referrer');
            wp_redirect($target,302,'Delnavazan');exit;
        }catch(\Throwable $error){
            self::setFlash('error','The test classroom is not ready or the join reference is unavailable.');
            wp_safe_redirect(add_query_arg(array('page'=>self::MENU_SLUG,'tab'=>'inspector','session'=>$sessionId),admin_url('admin.php')));exit;
        }
    }

    public static function exportDiagnostic():never{
        GodModeAuthority::require();
        $sessionId=(int)($_GET['session']??0);
        check_admin_referer('dzn_god_mode_export_'.$sessionId);
        try{$payload=(new GodModeReadService())->diagnosticExport($sessionId);}
        catch(\Throwable $error){wp_die('Diagnostic export unavailable.','Delnavazan',array('response'=>404));}
        GodModeAudit::record(GodModeAuthority::require(),'diagnostic_export','test_session',$sessionId,'sanitized_export');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="god-mode-diagnostic-'.$sessionId.'.json"');
        echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @return array<string,mixed> */
    private static function bookingInput():array{
        return array(
            'teacher_id'=>(int)($_POST['teacher_id']??0),'instrument_id'=>(int)($_POST['instrument_id']??0),
            'course_id'=>(int)($_POST['course_id']??0),'mode'=>sanitize_key((string)wp_unslash($_POST['mode']??'direct')),
            'local_date'=>sanitize_text_field(wp_unslash((string)($_POST['local_date']??''))),
            'local_time'=>sanitize_text_field(wp_unslash((string)($_POST['local_time']??''))),
            'timezone'=>sanitize_text_field(wp_unslash((string)($_POST['timezone']??'UTC'))),
            'duration_minutes'=>(int)($_POST['duration_minutes']??30),
            'recording'=>!empty($_POST['recording']),
            'notification_behavior'=>sanitize_key((string)wp_unslash($_POST['notification_behavior']??'portal')),
            'title'=>sanitize_text_field(wp_unslash((string)($_POST['title']??'God Mode test class'))),
            'description'=>sanitize_textarea_field(wp_unslash((string)($_POST['description']??''))),
            'scenario_code'=>sanitize_key((string)wp_unslash($_POST['scenario_code']??'direct_class_booking')),
            'conflict_acknowledged'=>!empty($_POST['conflict_acknowledged']),
        );
    }
    /** @return array<string,mixed> */
    private static function scenarioInput():array{
        return array(
            'teacher_id'=>(int)($_POST['teacher_id']??0),'instrument_id'=>(int)($_POST['instrument_id']??0),
            'timezone'=>sanitize_text_field(wp_unslash((string)($_POST['timezone']??'UTC'))),
            'local_date'=>sanitize_text_field(wp_unslash((string)($_POST['local_date']??''))),
            'local_time'=>sanitize_text_field(wp_unslash((string)($_POST['local_time']??''))),
            'duration_minutes'=>(int)($_POST['duration_minutes']??30),
            'conflict_acknowledged'=>!empty($_POST['conflict_acknowledged']),
            'session_id'=>(int)($_POST['session_id']??0),'recipient_kind'=>sanitize_key((string)wp_unslash($_POST['recipient_kind']??'owner')),
        );
    }

    private static function successRedirect(string $message,array $query):string{
        self::setFlash('success',$message);
        return add_query_arg(array_merge(array('page'=>self::MENU_SLUG),$query),admin_url('admin.php'));
    }
    private static function tabForOperation(string $operation):string{
        $tab=match(true){
            in_array($operation,array('save_settings','legacy_inventory','legacy_archive','notification_test'),true)=>'settings',
            in_array($operation,array('run_scenario'),true)=>'scenarios',
            in_array($operation,array('check_conflicts','preview_test','book_test'),true)=>'book',
            in_array($operation,array('cancel_test','archive_test','delete_test','bulk_action'),true)=>'history',
            default=>'inspector',
        };
        return add_query_arg(array('page'=>self::MENU_SLUG,'tab'=>$tab),admin_url('admin.php'));
    }
    private static function setFlash(string $type,string $message):void{
        set_transient('dzn_god_mode_flash_'.get_current_user_id(),array('type'=>$type,'message'=>substr($message,0,1500)),60);
    }
    /** @return array{type:string,message:string}|null */
    private static function flash():?array{
        $key='dzn_god_mode_flash_'.get_current_user_id();
        $flash=get_transient($key);
        if(is_array($flash))delete_transient($key);
        return is_array($flash)?$flash:null;
    }
    private static function safeMessage(string $message):string{
        $message=preg_replace('/[^A-Za-z0-9 _:;.,!()\\[\\]-]/','',$message)??'';
        return trim($message)!==''?substr($message,0,500):'god_mode_action_failed';
    }
    private static function humanizeError(string $message):string{
        return match(true){
            str_starts_with($message,'god_mode_conflict_acknowledgement_required')=>'This test conflicts with an existing class. Confirm the conflict warning explicitly before booking. Genuine classes are never modified.',
            str_starts_with($message,'god_mode_cleanup_refused')=>'Cleanup refused: only archived test activity with no pending provider operation and no retained recording is eligible.',
            $message==='god_mode_forbidden'=>'God Mode authority is required.',
            default=>'Action failed: '.$message,
        };
    }
    private static function utcDate(string $date,bool $endOfDay=false):string{
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))return '';
        return $date.($endOfDay?' 23:59:59':' 00:00:00');
    }
}
