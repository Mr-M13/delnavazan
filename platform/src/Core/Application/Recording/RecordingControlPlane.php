<?php
namespace Delnavazan\Platform\Core\Application\Recording;

use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;
use Delnavazan\Platform\Core\Application\RecordingAuthorisationGate;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * WordPress recording control plane.
 *
 * This is the only code allowed to decide that a lesson may be captured. It resolves canonical
 * facts itself, calls RecordingAuthorisationGate::assertCaptureAuthorised(), persists the exact
 * evidence, and writes a durable provider command. It never starts a provider capture in a web
 * request and never receives provider Egress credentials.
 */
final class RecordingControlPlane {
    public const COMMAND_TYPES=array('start','stop','reconcile','transfer','transfer_retry','archive_verification','source_deletion','withdrawal_disposition');
    public const CALLBACK_AUDIENCE='wordpress-recording-callback';
    public const COMMAND_AUDIENCE='recording-service-command';
    public const PULL_AUDIENCE='recording-service-command-pull';
    public const DELETION_AUDIENCE='recording-deletion-authorisation';
    public const DELETION_RESPONSE_AUDIENCE='recording-deletion-authorisation-response';

    private RecordingAuthorisationGate $gate;
    private RecordingMediaEvidencePolicy $mediaPolicy;
    private ?int $now;

    public function __construct(?RecordingAuthorisationGate $gate=null,?RecordingMediaEvidencePolicy $mediaPolicy=null,?int $now=null){
        $this->gate=$gate??new RecordingAuthorisationGate();
        $this->mediaPolicy=$mediaPolicy??new RecordingMediaEvidencePolicy();
        $this->now=$now;
    }

    /**
     * Create the sole pre-capture authority record for a canonical lesson.
     *
     * @return array{recording_id:int,recording_uid:string,state:string,content_version:int,idempotent:bool,command_id:int}
     */
    public function requestRecording(int $lessonId,string $idempotencyKey,int $actor,string $channel='admin'):array{
        $this->assertKey($idempotencyKey);
        if(!RecordingFeatureFlags::captureEnabledForLesson($lessonId))throw new \RuntimeException('recording_capture_disabled');
        $health=$this->assertReady();
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $lesson=$this->canonicalLesson($lessonId,$p);
        $this->assertParticipantAssignment($lesson,$p);
        // Re-runs the canonical consent/policy/guardian evidence. Never trust a prior projection.
        $authorisation=$this->gate->assertCaptureAuthorised($lessonId,RecordingAuthorisationGate::PROVIDER_LIVEKIT,ConsentPurposeRegistry::STUDENT_RECORDING,$actor);
        $evidenceId=(int)($authorisation['evidence_id']??0);
        if($evidenceId<1)throw new \RuntimeException('recording_authorisation_evidence_missing');
        $evidence=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_recording_authorisations WHERE id=%d AND lesson_id=%d AND outcome='authorised' LIMIT 1",$evidenceId,$lessonId));
        if(!$evidence)throw new \RuntimeException('recording_authorisation_evidence_missing');
        $evidenceDigest=(string)$evidence->evidence_digest;
        if(preg_match('/^[a-f0-9]{64}$/D',$evidenceDigest)!==1)throw new \RuntimeException('recording_authorisation_digest_invalid');
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE lesson_id=%d AND schedule_version_id=%d ORDER BY id DESC LIMIT 1",$lessonId,(int)$lesson->schedule_version_id));
        if($existing){
            return array('recording_id'=>(int)$existing->id,'recording_uid'=>(string)$existing->uid,'state'=>(string)$existing->lifecycle_state,'content_version'=>(int)$existing->content_version,'idempotent'=>true,'command_id'=>0);
        }
        $recordingUid=Identifier::uid();
        $teacherUid=(string)$wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}teachers WHERE id=%d AND status='active' AND archived_at IS NULL",(int)$lesson->teacher_id));
        $studentUid=(string)$wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}students WHERE id=%d AND status='active' AND archived_at IS NULL",(int)$lesson->student_id));
        if($teacherUid===''||$studentUid==='')throw new \RuntimeException('recording_participant_snapshot_unavailable');
        $notice=$this->noticeReference($authorisation);
        $wpdb->query('START TRANSACTION');
        try{
            $inserted=$wpdb->insert($p.'recordings',array(
                'uid'=>$recordingUid,'lesson_id'=>$lessonId,'schedule_version_id'=>(int)$lesson->schedule_version_id,
                'schedule_version_uid'=>(string)$lesson->schedule_uid,'teacher_id'=>(int)$lesson->teacher_id,
                'student_id'=>(int)$lesson->student_id,'teacher_uid'=>$teacherUid,'student_uid'=>$studentUid,
                'enrolment_id'=>(int)($lesson->enrolment_id??0)?:null,'term_id'=>(int)($lesson->term_id??0)?:null,
                'authorisation_evidence_id'=>$evidenceId,'authorisation_digest'=>$evidenceDigest,
                'policy_reference'=>substr('recording_notice:'.(string)$lesson->schedule_uid.':'.$notice,0,191),
                'notice_reference'=>substr($notice,0,191),'provider'=>RecordingAuthorisationGate::PROVIDER_LIVEKIT,
                'content_version'=>1,'lifecycle_state'=>RecordingLifecycle::RECORDING_REQUESTED,
                'media_format'=>null,'legal_hold_state'=>'none','disposition_state'=>'none',
                'authoritative_location'=>'vps','version'=>1,'created_at'=>$now,'updated_at'=>$now,
            ));
            if($inserted===false)throw new \RuntimeException('recording_persistence_failed');
            $recordingId=(int)$wpdb->insert_id;
            $this->appendEvent($recordingId,1,'recording_authorised','',RecordingLifecycle::AUTHORISED,$this->eventKey('authorised|'.$recordingUid.'|'.$evidenceId),array('evidence_id'=>$evidenceId,'evidence_digest'=>$evidenceDigest),$actor,'wordpress',$channel,'authorised',$now);
            $this->appendEvent($recordingId,1,'request_start',RecordingLifecycle::AUTHORISED,RecordingLifecycle::RECORDING_REQUESTED,$this->eventKey('start|'.$recordingUid.'|'.$idempotencyKey),array('idempotency_key'=>$idempotencyKey),$actor,'wordpress',$channel,'capture_requested',$now);
            $command=$this->enqueueCommandInternal($recordingId,1,'start',$this->commandKey('start|'.$recordingUid),array(
                'recording_uid'=>$recordingUid,'content_version'=>1,'lesson_id'=>$lessonId,
                'lesson_uid'=>(string)$lesson->lesson_uid,'schedule_version_id'=>(int)$lesson->schedule_version_id,
                'schedule_version_uid'=>(string)$lesson->schedule_uid,'provider'=>RecordingAuthorisationGate::PROVIDER_LIVEKIT,
                'authorisation_evidence_id'=>$evidenceId,'authorisation_digest'=>$evidenceDigest,
                'room_name'=>'dzn-rec-'.substr(hash_hmac('sha256','recording-room|'.(string)$lesson->lesson_uid.'|'.(string)$lesson->schedule_uid,RecordingFeatureFlags::serviceSecret()),0,40),
                'teacher_id'=>(int)$lesson->teacher_id,'teacher_uid'=>$teacherUid,
                'student_id'=>(int)$lesson->student_id,'student_uid'=>$studentUid,
                'starts_at_utc'=>(string)$lesson->starts_at_utc,'ends_at_utc'=>(string)$lesson->ends_at_utc,
                'scheduled_duration_seconds'=>(int)$lesson->duration_minutes*60,
                'capture_mode'=>'room_composite','layout'=>'dzn-two-person','watermark_required'=>true,
                'health_identity'=>(string)($health['archive_mount_identity']??''),
            ),RecordingLifecycle::RECORDING_REQUESTED,$actor,$now);
            $wpdb->query('COMMIT');
            return array('recording_id'=>$recordingId,'recording_uid'=>$recordingUid,'state'=>RecordingLifecycle::RECORDING_REQUESTED,'content_version'=>1,'idempotent'=>false,'command_id'=>(int)$command['command_id']);
        }catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
    }

    /**
     * Signed status callback from the OVH recorder. Every accepted, delayed, refused and duplicate
     * event is appended. A callback can never invent a state the lifecycle did not reach.
     *
     * @param array{payload?:mixed,meta?:mixed,signature?:mixed} $envelope
     */
    public function handleCallback(array $envelope):array{
        $verified=RecordingSignature::verify($envelope,RecordingFeatureFlags::serviceSecret(),self::CALLBACK_AUDIENCE,$this->now());
        $payload=$verified['payload'];
        $recordingUid=(string)($payload['recording_uid']??'');
        if(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D',$recordingUid)!==1)throw new \RuntimeException('recording_callback_identity_invalid');
        $idempotency=(string)($payload['idempotency_key']??'');
        $this->assertKey($idempotency);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $recording=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE uid=%s LIMIT 1",$recordingUid));
        if(!$recording)throw new \RuntimeException('recording_not_found');
        $recordingId=(int)$recording->id;
        $type=(string)($payload['event']??'');
        $contentVersion=(int)($payload['content_version']??0);
        if($contentVersion!==(int)$recording->content_version)throw new \RuntimeException('recording_content_version_mismatch');
        $digest=RecordingSignature::payloadDigest($payload);
        $source=(string)($payload['source']??'recorder');
        if($type==='health'){
            update_option(RecordingFeatureFlags::SERVICE_HEALTH_OPTION,array(
                'received_at'=>$this->now(),'ready'=>($payload['ready']??false)===true,
                'storage_free_bytes'=>(int)($payload['storage_free_bytes']??0),
                'storage_free_percent'=>(float)($payload['storage_free_percent']??0),
                'archive_mount_identity'=>(string)($payload['archive_mount_identity']??''),
                'archive_available'=>($payload['archive_available']??false)===true,
                'archive_free_bytes'=>(int)($payload['archive_free_bytes']??0),
                'tailscale_connected'=>($payload['tailscale_connected']??false)===true,
                'secure_transport'=>(string)($payload['secure_transport']??'tailscale'),
                'secure_transport_connected'=>($payload['secure_transport_connected']??($payload['tailscale_connected']??false))===true,
                'egress_cpu_percent'=>(float)($payload['egress_cpu_percent']??0),
                'egress_memory_bytes'=>(int)($payload['egress_memory_bytes']??0),
                'oldest_queue_age_seconds'=>(int)($payload['oldest_queue_age_seconds']??0),
                'host_load_per_cpu'=>(float)($payload['host_load_per_cpu']??0),
                'host_memory_available_bytes'=>(int)($payload['host_memory_available_bytes']??0),
                'emergency_stop_active'=>($payload['emergency_stop_active']??false)===true,
                'queue_states'=>is_array($payload['queue_states']??null)?$payload['queue_states']:array(),
            ),false);
            return array('accepted'=>true,'applied'=>false,'reason'=>'health_recorded');
        }
        if($type==='command_completed'){
            $this->completeCommandByKey((string)($payload['command_idempotency_key']??''),(string)($payload['result_code']??'completed'),$recordingId);
            return array('accepted'=>true,'applied'=>false,'reason'=>'command_completed');
        }
        if($type==='egress_started'){
            $egressId=(string)($payload['provider_egress_id']??'');
            if($egressId==='')throw new \RuntimeException('recording_egress_id_missing');
            $wpdb->update($p.'recordings',array('provider_egress_id'=>substr($egressId,0,191),'updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$recordingId));
            return $this->applyTransition($recordingId,$payload,$type,'egress_started',RecordingLifecycle::RECORDING_REQUESTED,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),'egress_started');
        }
        if($type==='egress_completed'){
            return $this->applyTransition($recordingId,$payload,$type,'egress_completed',RecordingLifecycle::RECORDING_ACTIVE,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),'egress_completed');
        }
        if($type==='media_verified'){
            if((string)$recording->disposition_state!=='none'){
                return $this->applyTransition($recordingId,$payload,'withdrawal_quarantine','quarantine',RecordingLifecycle::RECORDING_FINALISING,$idempotency,$digest,$source,array('reason'=>'withdrawal_disposition'),$this->actorFrom($payload),'withdrawal_disposition');
            }
            $verdict=$this->mediaPolicy->evaluate((array)($payload['media']??array()),(int)($payload['scheduled_duration_seconds']??0));
            if(!$verdict['verified']){
                return $this->applyTransition($recordingId,$payload,'media_verification_failed','quarantine',RecordingLifecycle::RECORDING_FINALISING,$idempotency,$digest,$source,array('reason'=>$verdict['reason']),$this->actorFrom($payload),$verdict['reason']);
            }
            $media=(array)$payload['media'];
            $wpdb->update($p.'recordings',array(
                'media_format'=>'mp4','video_codec'=>'h264','audio_codec'=>'aac',
                'duration_ms'=>(int)$media['duration_ms'],'width'=>(int)($media['width']??0),
                'height'=>(int)($media['height']??0),'source_bytes'=>(int)$media['source_bytes'],
                'source_sha256'=>strtolower((string)$media['sha256']),'updated_at'=>gmdate('Y-m-d H:i:s'),
            ),array('id'=>$recordingId));
            return $this->applyTransition($recordingId,$payload,'media_verified','media_verified',RecordingLifecycle::RECORDING_FINALISING,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),'media_verified');
        }
        if($type==='transfer_queued'){
            return $this->applyTransition($recordingId,$payload,$type,'transfer_queued',RecordingLifecycle::RECORDING_VERIFIED,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),'transfer_queued');
        }
        if($type==='transfer_started'){
            if((string)$recording->lifecycle_state===RecordingLifecycle::RECORDING_VERIFIED){
                $queued=$this->applyTransition($recordingId,$payload,'transfer_queued_auto','transfer_queued',RecordingLifecycle::RECORDING_VERIFIED,$this->eventKey('transfer-queued-auto|'.$recordingUid.'|'.$idempotency),RecordingSignature::payloadDigest(array('auto_queued'=>$recordingUid)),$source,array('reason'=>'transfer_started_without_queue'),0,'transfer_queued');
                if(!($queued['applied']??false)&&!($queued['idempotent']??false))return $queued;
                $recording=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d LIMIT 1",$recordingId));
            }
            $expectedStart=in_array((string)$recording->lifecycle_state,array(RecordingLifecycle::AWAITING_ARCHIVE,RecordingLifecycle::TRANSFER_RETRY_PENDING),true)?(string)$recording->lifecycle_state:RecordingLifecycle::AWAITING_ARCHIVE;
            return $this->applyTransition($recordingId,$payload,$type,'transfer_started',$expectedStart,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),'transfer_started');
        }
        if($type==='transfer_verified'){
            return $this->applyTransition($recordingId,$payload,$type,'transfer_verified',RecordingLifecycle::TRANSFERRING,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),'transfer_verified');
        }
        if($type==='transfer_failed'){
            return $this->applyTransition($recordingId,$payload,$type,'transfer_failed',RecordingLifecycle::TRANSFERRING,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),(string)($payload['failure_class']??'transfer_failed'));
        }
        if($type==='egress_failed'){
            $current=(string)$recording->lifecycle_state;
            $expected=in_array($current,array(RecordingLifecycle::RECORDING_REQUESTED,RecordingLifecycle::RECORDING_ACTIVE,RecordingLifecycle::RECORDING_FINALISING),true)?$current:RecordingLifecycle::RECORDING_ACTIVE;
            return $this->applyTransition($recordingId,$payload,$type,'fail',$expected,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),(string)($payload['failure_class']??'egress_failed'));
        }
        if($type==='deletion_failed'){
            $this->appendEvent($recordingId,(int)$recording->content_version,'deletion_failed',(string)$recording->lifecycle_state,(string)$recording->lifecycle_state,$this->eventKey('deletion-failed|'.$recordingUid.'|'.$idempotency),array('failure_class'=>(string)($payload['failure_class']??'')),0,'recorder',$source,'deletion_retry_pending',$this->now());
            return array('accepted'=>true,'applied'=>false,'reason'=>'deletion_retry_pending');
        }
        if($type==='archive_confirmed')return $this->confirmArchive($recording,$payload,$idempotency,$digest,$source);
        if($type==='archive_mismatch'){
            return $this->applyTransition($recordingId,$payload,'archive_mismatch','archive_mismatch',RecordingLifecycle::ARCHIVE_VERIFICATION,$idempotency,$digest,$source,$payload,$this->actorFrom($payload),'checksum_mismatch');
        }
        if($type==='source_deleted')return $this->confirmSourceDeletion($recording,$payload,$idempotency,$digest,$source);
        if($type==='storage_warning'){
            $this->appendEvent($recordingId,(int)$recording->content_version,'storage_warning',(string)$recording->lifecycle_state,(string)$recording->lifecycle_state,$this->eventKey('storage-warning|'.$recordingUid.'|'.$idempotency),array('warning'=>(string)($payload['warning']??'')),0,'recorder',$source,'storage_warning',$this->now());
            return array('accepted'=>true,'applied'=>false,'reason'=>'storage_warning_recorded');
        }
        $this->appendEvent($recordingId,(int)$recording->content_version,'callback_refused',(string)$recording->lifecycle_state,(string)$recording->lifecycle_state,$this->eventKey('refused|'.$recordingUid.'|'.$idempotency),array('event'=>$type),0,'recorder',$source,'callback_event_unsupported',$this->now());
        throw new \RuntimeException('recording_callback_event_unsupported');
    }

    /** Withdrawal is server-resolved; it never accepts a browser lesson, principal or path. */
    public function withdrawForSubject(string $subjectType,int $subjectId,int $actor,string $reason='withdrawal'):array{
        if(!in_array($subjectType,array('teacher','student'),true))throw new \InvalidArgumentException('recording_subject_type_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $column=$subjectType==='teacher'?'teacher_id':'student_id';
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT id FROM {$p}recordings WHERE {$column}=%d AND lifecycle_state NOT IN (%s) ORDER BY id",$subjectId,RecordingLifecycle::DELETION_REQUIRED));
        $out=array();foreach($rows as $row)$out[]=$this->withdrawRecording((int)$row->id,$actor,$reason);
        return array('subject_type'=>$subjectType,'subject_id'=>$subjectId,'recordings'=>$out);
    }

    /** Withdrawal is server-resolved; it never accepts a browser lesson, principal or path. */
    public function withdrawForLesson(int $lessonId,int $actor,string $reason='withdrawal'):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recordings WHERE lesson_id=%d AND lifecycle_state NOT IN (%s) ORDER BY id",$lessonId,RecordingLifecycle::DELETION_REQUIRED));
        $out=array();
        foreach($rows as $recording)$out[]=$this->withdrawRecording((int)$recording->id,$actor,$reason);
        return array('lesson_id'=>$lessonId,'recordings'=>$out);
    }

    public function withdrawRecording(int $recordingId,int $actor,string $reason='withdrawal'):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $recording=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d LIMIT 1",$recordingId));
        if(!$recording)throw new \RuntimeException('recording_not_found');
        $state=(string)$recording->lifecycle_state;
        $key=$this->eventKey('withdrawal|'.$recordingId.'|'.$reason);
        $wpdb->update($p.'recordings',array('disposition_state'=>'withdrawal','quarantine_reason_code'=>substr($reason,0,64),'updated_at'=>$now),array('id'=>$recordingId));
        $commands=array();
        if(in_array($state,array(RecordingLifecycle::RECORDING_REQUESTED,RecordingLifecycle::RECORDING_ACTIVE),true)){
            $commands[]=$this->enqueueCommandInternal($recordingId,(int)$recording->content_version,'stop',$this->commandKey('stop|'.$recordingId.'|'.$reason),array('recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version,'reason'=>$reason),$state,$actor,$now);
        }
        if(in_array($state,array(RecordingLifecycle::ARCHIVED,RecordingLifecycle::VPS_DELETION_PENDING,RecordingLifecycle::ARCHIVED_VPS_DELETED),true)){
            $commands[]=$this->enqueueCommandInternal($recordingId,(int)$recording->content_version,'withdrawal_disposition',$this->commandKey('withdrawal-disposition|'.$recordingId.'|'.$reason),array('recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version,'reason'=>$reason,'delete_archive'=>true),$state,$actor,$now);
        }
        $result=$this->applyTransition($recordingId,array('reason'=>$reason),'withdrawal_stop','withdrawal_stop',$state,$key,RecordingSignature::payloadDigest(array('withdrawal'=>$reason,'recording'=>$recordingId)),'wordpress',array('recording_uid'=>(string)$recording->uid,'reason'=>$reason),$actor,'withdrawal',true);
        return array_merge($result,array('commands'=>$commands));
    }

    public function setLegalHold(int $recordingId,bool $active,int $actor,string $reason):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $recording=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d LIMIT 1",$recordingId));
        if(!$recording)throw new \RuntimeException('recording_not_found');
        if($wpdb->update($p.'recordings',array('legal_hold_state'=>$active?'active':'none','updated_at'=>$now),array('id'=>$recordingId))===false)throw new \RuntimeException('recording_legal_hold_failed');
        $this->appendEvent($recordingId,(int)$recording->content_version,$active?'legal_hold_applied':'legal_hold_released',(string)$recording->lifecycle_state,(string)$recording->lifecycle_state,$this->eventKey('legal-hold|'.$recordingId.'|'.($active?'1':'0').'|'.$reason),array('reason'=>$reason),$actor,'wordpress','admin',$reason,$now);
        if($active){
            $wpdb->query($wpdb->prepare("UPDATE {$p}recording_commands SET state='cancelled',updated_at=%s,version=version+1 WHERE recording_id=%d AND command_type='source_deletion' AND state IN ('pending','leased')",$now,$recordingId));
        }else{
            $confirmation=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_archive_confirmations WHERE recording_id=%d AND content_version=%d",$recordingId,(int)$recording->content_version));
            if($confirmation>0)$this->enqueueCommandInternal($recordingId,(int)$recording->content_version,'source_deletion',$this->commandKey('source-deletion-release|'.$recordingId.'|'.(int)$recording->content_version.'|'.$now),array('recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version),RecordingLifecycle::VPS_DELETION_PENDING,$actor,$now);
        }
        $this->enqueueCommandInternal($recordingId,(int)$recording->content_version,'reconcile',$this->commandKey('legal-hold|'.$recordingId.'|'.($active?'1':'0')),array(
            'recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version,
            'legal_hold_state'=>$active?'active':'none',
        ),null,$actor,$now);
        return array('recording_id'=>$recordingId,'legal_hold_state'=>$active?'active':'none');
    }

    /** Queue a server-side stop at lesson end. No browser input participates. */
    public function requestStop(int $recordingId,int $actor,string $reason='lesson_end'):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $recording=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d LIMIT 1",$recordingId));
        if(!$recording)throw new \RuntimeException('recording_not_found');
        if(!in_array((string)$recording->lifecycle_state,array(RecordingLifecycle::RECORDING_REQUESTED,RecordingLifecycle::RECORDING_ACTIVE),true))return array('command_id'=>0,'state'=>(string)$recording->lifecycle_state,'idempotent'=>true);
        return $this->enqueueCommandInternal($recordingId,(int)$recording->content_version,'stop',$this->commandKey('stop|'.$recordingId.'|'.$reason),array('recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version,'reason'=>$reason),$recording->lifecycle_state,$actor,$this->now());
    }

    /**
     * Repository-wide capture kill switch. It is durable in the options table, blocks every new
     * capture request and queues a stop for every recording that can still be active.
     */
    public function engageEmergencyStop(int $actor,string $reason='classroom_performance'):array{
        $now=$this->now();
        $state=array('engaged'=>true,'reason'=>$reason,'actor'=>$actor,'at'=>$now);
        update_option(RecordingFeatureFlags::EMERGENCY_STOP_OPTION,$state,false);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $ids=(array)$wpdb->get_col("SELECT id FROM {$p}recordings WHERE lifecycle_state IN ('recording_requested','recording_active') ORDER BY id");
        $stopped=array();
        foreach($ids as $id){try{$this->requestStop((int)$id,$actor,'emergency_stop');$stopped[]=(int)$id;}catch(\Throwable $error){}}
        return $state+array('stopped'=>$stopped);
    }

    /** Release the kill switch. Existing stops remain queued; new capture resumes only through the normal gates. */
    public function releaseEmergencyStop(int $actor):array{
        $state=array('engaged'=>false,'reason'=>'released','actor'=>$actor,'at'=>$this->now());
        update_option(RecordingFeatureFlags::EMERGENCY_STOP_OPTION,$state,false);
        return $state;
    }

    public function emergencyStopStatus():array{return RecordingFeatureFlags::emergencyStop();}

    /**
     * Normal global capture switch. Disabling it refuses new capture immediately and queues a stop
     * for every recording that can still be active. Enabling it only restores the normal gates.
     */
    public function setGlobalCapture(bool $enabled,int $actor,string $reason='administrator_setting'):array{
        update_option(RecordingFeatureFlags::GLOBAL_CAPTURE_OPTION,$enabled?'1':'0',false);
        $stopped=array();
        if(!$enabled){
            global $wpdb;$p=$wpdb->prefix.'dzn_';
            $ids=(array)$wpdb->get_col("SELECT id FROM {$p}recordings WHERE lifecycle_state IN ('recording_requested','recording_active') ORDER BY id");
            foreach($ids as $id){try{$this->requestStop((int)$id,$actor,'global_capture_disabled');$stopped[]=(int)$id;}catch(\Throwable $error){}}
        }
        return array('enabled'=>$enabled,'stopped'=>$stopped,'reason'=>$reason);
    }

    /** Stop the current recording for a lesson when LiveKit reports the room has finished. */
    public function requestStopForLesson(int $lessonId,int $actor,string $reason='room_finished'):array{
        if($lessonId<1)return array('command_id'=>0,'state'=>'none','idempotent'=>true);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}recordings WHERE lesson_id=%d AND lifecycle_state IN ('recording_requested','recording_active') ORDER BY id DESC LIMIT 1",$lessonId));
        if($id<1)return array('command_id'=>0,'state'=>'none','idempotent'=>true);
        return $this->requestStop($id,$actor,$reason);
    }

    public function reconcile(int $recordingId,int $actor):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $recording=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d LIMIT 1",$recordingId));
        if(!$recording)throw new \RuntimeException('recording_not_found');
        $command=$this->enqueueCommandInternal($recordingId,(int)$recording->content_version,'reconcile',$this->commandKey('reconcile|'.$recordingId.'|'.gmdate('YmdHi')),array('recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version),null,$actor,$this->now());
        return $command;
    }

    public function retryCommand(int $commandId,int $actor):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $command=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_commands WHERE id=%d LIMIT 1",$commandId));
        if(!$command)throw new \RuntimeException('recording_command_not_found');
        if(!in_array((string)$command->state,array('failed','expired','dead'),true))throw new \RuntimeException('recording_command_not_retryable');
        if($wpdb->update($p.'recording_commands',array('state'=>'pending','available_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'updated_at'=>$now,'version'=>(int)$command->version+1),array('id'=>$commandId))===false)throw new \RuntimeException('recording_command_retry_failed');
        return array('command_id'=>$commandId,'state'=>'pending');
    }

    /**
     * Lease a bounded batch to the authenticated recorder. The service signs the command payload
     * with its own key; WordPress retains the outbox and can reconcile if a callback is lost.
     */
    public function claimCommands(int $limit=20):array{
        $limit=max(1,min(100,$limit));global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $wpdb->query('START TRANSACTION');
        try{
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recording_commands WHERE state='pending' AND available_at<=%s ORDER BY id LIMIT %d FOR UPDATE SKIP LOCKED",gmdate('Y-m-d H:i:s',$now),$limit));
            $out=array();
            foreach($rows as $row){
                $token=bin2hex(random_bytes(16));
                $wpdb->update($p.'recording_commands',array('state'=>'leased','lease_token_digest'=>hash('sha256',$token),'lease_expires_at'=>gmdate('Y-m-d H:i:s',$now+180),'attempts'=>(int)$row->attempts+1,'updated_at'=>gmdate('Y-m-d H:i:s',$now),'version'=>(int)$row->version+1),array('id'=>(int)$row->id));
                $payload=json_decode((string)$row->payload_json,true);
                if(!is_array($payload))throw new \RuntimeException('recording_command_payload_invalid');
                $envelope=RecordingSignature::sign(array(
                    'command_id'=>(int)$row->id,'command_type'=>(string)$row->command_type,
                    'recording_id'=>(int)$row->recording_id,'recording_uid'=>(string)($payload['recording_uid']??''),
                    'content_version'=>(int)$row->content_version,'expected_state'=>$row->expected_state===null?null:(string)$row->expected_state,
                    'idempotency_key'=>(string)$row->idempotency_key,'payload_digest'=>(string)$row->payload_digest,
                    'lease_token'=>$token,'payload'=>$payload,
                ),RecordingFeatureFlags::serviceSecret(),RecordingFeatureFlags::serviceKeyId(),self::COMMAND_AUDIENCE,$now,300);
                $out[]=$envelope;
            }
            $wpdb->query('COMMIT');
            return $out;
        }catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
    }

    public function recordPlaybackEvent(int $recordingId,int $contentVersion,string $principalType,int $principalId,string $eventType,string $reason,string $jti,?int $rangeStart=null,?int $rangeEnd=null,?int $bytesSent=null,string $source='wordpress'):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $jtiDigest=preg_match('/^[a-f0-9]{64}$/D',$jti)===1?$jti:hash('sha256',$jti);
        if($recordingId<1){
            $wpdb->insert($p.'recording_playback_access',array('uid'=>Identifier::uid(),'recording_id'=>0,'content_version'=>max(1,$contentVersion),'lesson_id'=>0,'principal_type'=>substr($principalType,0,16),'principal_id'=>max(0,$principalId),'grant_jti_digest'=>$jtiDigest,'event_type'=>substr($eventType,0,16),'reason_code'=>substr($reason,0,64),'source'=>substr($source,0,24),'range_start'=>$rangeStart,'range_end'=>$rangeEnd,'bytes_sent'=>$bytesSent,'occurred_at'=>$now,'created_at'=>$now));
            if($wpdb->last_error!=='')throw new \RuntimeException('recording_playback_audit_failed');
            return;
        }
        $key=hash('sha256','playback|'.$recordingId.'|'.$contentVersion.'|'.$jtiDigest.'|'.$eventType);
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}recording_playback_access (uid,recording_id,content_version,lesson_id,principal_type,principal_id,grant_jti_digest,event_type,reason_code,source,range_start,range_end,bytes_sent,occurred_at,created_at) SELECT %s,r.id,%d,r.lesson_id,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s FROM {$p}recordings r WHERE r.id=%d",Identifier::uid(),$contentVersion,$principalType,$principalId,$jtiDigest,$eventType,substr($reason,0,64),$source,$rangeStart,$rangeEnd,$bytesSent,$now,$now,$recordingId));
        if($wpdb->last_error!=='')throw new \RuntimeException('recording_playback_audit_failed');
    }

    /**
     * Fresh, signed deletion authorisation. The recorder must obtain this after archive confirmation
     * and before unlinking the VPS source. A legal hold, missing confirmation, digest mismatch or a
     * non-deletion state returns an explicit refusal; WordPress being unavailable fails the worker.
     *
     * @param array{payload?:mixed,meta?:mixed,signature?:mixed} $envelope
     */
    public function deletionAuthorisation(array $envelope):array{
        $verified=RecordingSignature::verify($envelope,RecordingFeatureFlags::serviceSecret(),self::DELETION_AUDIENCE,$this->now());
        $request=$verified['payload'];
        $recordingUid=(string)($request['recording_uid']??'');
        $contentVersion=(int)($request['content_version']??0);
        $destinationSha=strtolower((string)($request['destination_sha256']??''));
        $recording=$this->recordingByUid($recordingUid);
        $reason='authorised';
        if(!$recording)$reason='recording_not_found';
        elseif((int)$recording->content_version!==$contentVersion)$reason='content_version_mismatch';
        elseif((string)$recording->legal_hold_state==='active')$reason='legal_hold_active';
        elseif(!in_array((string)$recording->lifecycle_state,array(RecordingLifecycle::ARCHIVED,RecordingLifecycle::VPS_DELETION_PENDING,RecordingLifecycle::ARCHIVED_VPS_DELETED,RecordingLifecycle::DELETION_REQUIRED),true))$reason='recording_not_deletable';
        $confirmation=$recording?$this->confirmationFor((int)$recording->id,$contentVersion):null;
        if($reason==='authorised'){
            if(!$confirmation)$reason='archive_confirmation_missing';
            elseif(!hash_equals((string)$confirmation->destination_sha256,$destinationSha))$reason='archive_digest_mismatch';
            elseif((string)$recording->disposition_state==='quarantined'&&(string)$recording->lifecycle_state!==RecordingLifecycle::DELETION_REQUIRED)$reason='recording_quarantined';
        }
        $payload=array(
            'authorised'=>$reason==='authorised','reason'=>$reason,'recording_uid'=>$recordingUid,
            'content_version'=>$contentVersion,'destination_sha256'=>$destinationSha,
            'legal_hold_state'=>$recording?(string)$recording->legal_hold_state:'unknown',
            'confirmation_digest'=>$confirmation?(string)$confirmation->destination_sha256:'',
            'issued_at'=>$this->now(),
        );
        $this->appendEvent((int)($recording->id??0),$contentVersion,$reason==='authorised'?'deletion_authorised':'deletion_refused',(string)($recording->lifecycle_state??''),(string)($recording->lifecycle_state??''),$this->eventKey('deletion-auth|'.$recordingUid.'|'.$contentVersion.'|'.$reason.'|'.$destinationSha),array('reason'=>$reason),0,'wordpress','deletion_authorisation',$reason,$this->now());
        return RecordingSignature::sign($payload,RecordingFeatureFlags::serviceSecret(),RecordingFeatureFlags::serviceKeyId(),self::DELETION_RESPONSE_AUDIENCE,$this->now(),120);
    }

    private function confirmationFor(int $recordingId,int $contentVersion):?object{
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_recording_archive_confirmations WHERE recording_id=%d AND content_version=%d LIMIT 1",$recordingId,$contentVersion));
    }

    public function recordingByUid(string $uid):?object{
        global $wpdb;
        if(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D',$uid)!==1)return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_recordings WHERE uid=%s LIMIT 1",$uid));
    }

    private function confirmArchive(object $recording,array $payload,string $idempotency,string $digest,string $source):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $confirmation=(array)($payload['confirmation']??array());
        foreach(array('recording_uid','content_version','source_bytes','destination_bytes','source_sha256','destination_sha256','archive_node_identity','destination_object_key','durable_commit_receipt','confirmation_signature','confirmation_time') as $key){
            if(!array_key_exists($key,$confirmation))throw new \RuntimeException('recording_archive_confirmation_incomplete');
        }
        if((string)$confirmation['recording_uid']!==(string)$recording->uid)throw new \RuntimeException('recording_archive_confirmation_identity_mismatch');
        if((int)$confirmation['content_version']!==(int)$recording->content_version)throw new \RuntimeException('recording_archive_confirmation_version_mismatch');
        $sourceBytes=(int)$confirmation['source_bytes'];$destinationBytes=(int)$confirmation['destination_bytes'];
        $sourceSha=strtolower((string)$confirmation['source_sha256']);$destinationSha=strtolower((string)$confirmation['destination_sha256']);
        if(preg_match('/^[a-f0-9]{64}$/D',$sourceSha)!==1||preg_match('/^[a-f0-9]{64}$/D',$destinationSha)!==1||preg_match('/^[a-f0-9]{64}$/D',strtolower((string)$confirmation['confirmation_signature']))!==1)throw new \RuntimeException('recording_archive_confirmation_digest_invalid');
        $signedEvidence=array(
            'recording_uid'=>(string)$confirmation['recording_uid'],'content_version'=>(int)$confirmation['content_version'],
            'source_bytes'=>$sourceBytes,'destination_bytes'=>$destinationBytes,'source_sha256'=>$sourceSha,'destination_sha256'=>$destinationSha,
            'archive_node_identity'=>(string)$confirmation['archive_node_identity'],'destination_object_key'=>(string)$confirmation['destination_object_key'],
            'durable_commit_receipt'=>(string)$confirmation['durable_commit_receipt'],'confirmation_time'=>(string)$confirmation['confirmation_time'],
        );
        $expectedSignature=hash_hmac('sha256',RecordingFeatureFlags::archiveConfirmationKeyId().'|'.RecordingSignature::canonicalJson($signedEvidence),RecordingFeatureFlags::archiveConfirmationSecret());
        if(!hash_equals($expectedSignature,strtolower((string)$confirmation['confirmation_signature'])))throw new \RuntimeException('recording_archive_confirmation_signature_invalid');
        if((int)$recording->source_bytes>0&&(int)$recording->source_bytes!==$sourceBytes)return $this->applyTransition((int)$recording->id,$payload,'archive_mismatch','archive_mismatch',RecordingLifecycle::ARCHIVE_VERIFICATION,$idempotency,$digest,$source,array('reason'=>'source_bytes_mismatch'),0,'source_bytes_mismatch');
        if((string)$recording->source_sha256!==''&&!hash_equals((string)$recording->source_sha256,$sourceSha))return $this->applyTransition((int)$recording->id,$payload,'archive_mismatch','archive_mismatch',RecordingLifecycle::ARCHIVE_VERIFICATION,$idempotency,$digest,$source,array('reason'=>'source_digest_mismatch'),0,'source_digest_mismatch');
        if($sourceBytes!==$destinationBytes||!hash_equals($sourceSha,$destinationSha))return $this->applyTransition((int)$recording->id,$payload,'archive_mismatch','archive_mismatch',RecordingLifecycle::ARCHIVE_VERIFICATION,$idempotency,$digest,$source,array('reason'=>'destination_mismatch'),0,'destination_mismatch');
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}recording_archive_confirmations WHERE recording_id=%d AND content_version=%d LIMIT 1",(int)$recording->id,(int)$recording->content_version));
        if($existing<1){
            $wpdb->query('START TRANSACTION');
            try{
                $inserted=$wpdb->insert($p.'recording_archive_confirmations',array(
                    'uid'=>Identifier::uid(),'recording_id'=>(int)$recording->id,'content_version'=>(int)$recording->content_version,
                    'source_bytes'=>$sourceBytes,'destination_bytes'=>$destinationBytes,'source_sha256'=>$sourceSha,'destination_sha256'=>$destinationSha,
                    'archive_node_identity'=>substr((string)$confirmation['archive_node_identity'],0,191),
                    'destination_object_key'=>substr((string)$confirmation['destination_object_key'],0,512),
                    'durable_commit_receipt'=>substr((string)$confirmation['durable_commit_receipt'],0,191),
                    'confirmation_signature'=>substr(strtolower((string)$confirmation['confirmation_signature']),0,512),
                    'confirmation_time'=>(string)$confirmation['confirmation_time'],'received_at'=>gmdate('Y-m-d H:i:s',$now),'created_at'=>gmdate('Y-m-d H:i:s',$now),
                ));
                if($inserted===false)throw new \RuntimeException('recording_archive_confirmation_failed');
                $wpdb->update($p.'recordings',array('archive_object_key'=>substr((string)$confirmation['destination_object_key'],0,512),'archive_node_identity'=>substr((string)$confirmation['archive_node_identity'],0,191),'updated_at'=>gmdate('Y-m-d H:i:s',$now)),array('id'=>(int)$recording->id));
                $wpdb->query('COMMIT');
            }catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
        }
        $result=$this->applyTransition((int)$recording->id,$payload,'archive_confirmed','archive_confirmed',RecordingLifecycle::ARCHIVE_VERIFICATION,$idempotency,$digest,$source,array('destination_object_key'=>(string)$confirmation['destination_object_key']),0,'archive_confirmed');
        if(($result['applied']??false)===true){
            $this->enqueueCommandInternal((int)$recording->id,(int)$recording->content_version,'source_deletion',$this->commandKey('source-deletion|'.(int)$recording->id.'|'.(int)$recording->content_version),array(
                'recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version,
                'expected_source_bytes'=>$sourceBytes,'expected_source_sha256'=>$sourceSha,
                'archive_object_key'=>(string)$confirmation['destination_object_key'],
                'archive_node_identity'=>(string)$confirmation['archive_node_identity'],
            ),RecordingLifecycle::ARCHIVED,0,$now);
            $this->applyTransition((int)$recording->id,array('reason'=>'after_archive_confirmation'),'deletion_queued','deletion_queued',RecordingLifecycle::ARCHIVED,$this->eventKey('deletion-queued|'.(int)$recording->id.'|'.(int)$recording->content_version),RecordingSignature::payloadDigest(array('deletion'=>$recording->id)),$source,array(),0,'deletion_queued',true);
        }
        return $result;
    }

    private function confirmSourceDeletion(object $recording,array $payload,string $idempotency,string $digest,string $source):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $confirmation=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_archive_confirmations WHERE recording_id=%d AND content_version=%d",(int)$recording->id,(int)$recording->content_version));
        if($confirmation<1)throw new \RuntimeException('recording_archive_confirmation_required');
        if((string)$recording->legal_hold_state==='active')throw new \RuntimeException('recording_legal_hold_active');
        $deletedBytes=(int)($payload['deleted_source_bytes']??0);$deletedSha=strtolower((string)($payload['deleted_source_sha256']??''));
        if($deletedBytes<1||!hash_equals((string)$recording->source_sha256,$deletedSha)||(int)$recording->source_bytes!==$deletedBytes)return $this->applyTransition((int)$recording->id,$payload,'deletion_refused','require_deletion',(string)$recording->lifecycle_state,$idempotency,$digest,$source,array('reason'=>'source_identity_mismatch'),0,'source_identity_mismatch');
        $expected=in_array((string)$recording->lifecycle_state,array(RecordingLifecycle::VPS_DELETION_PENDING,RecordingLifecycle::DELETION_REQUIRED),true)?(string)$recording->lifecycle_state:RecordingLifecycle::VPS_DELETION_PENDING;
        $result=$this->applyTransition((int)$recording->id,$payload,'source_deleted','source_deleted',$expected,$idempotency,$digest,$source,$payload,0,'source_deleted');
        if(($payload['archive_deleted']??false)===true&&($result['applied']??false)===true)$wpdb->update($p.'recordings',array('authoritative_location'=>'none'),array('id'=>(int)$recording->id));
        return $result;
    }

    private function applyTransition(int $recordingId,array $payload,string $eventType,string $event,string $expectedState,string $idempotency,string $digest,string $source,array $evidence,int $actor,string $reason,bool $force=false):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=$this->now();
        $wpdb->query('START TRANSACTION');
        try{
            $recording=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d FOR UPDATE",$recordingId));
            if(!$recording)throw new \RuntimeException('recording_not_found');
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_events WHERE idempotency_key=%s LIMIT 1",$idempotency));
            if($existing){
                if(hash_equals((string)$existing->payload_digest,$digest)){
                    $wpdb->query('COMMIT');
                    return array('accepted'=>true,'applied'=>false,'idempotent'=>true,'reason'=>'duplicate_replay','state'=>(string)$recording->lifecycle_state);
                }
                $conflictKey=$this->eventKey('idempotency-conflict|'.$idempotency.'|'.$digest);
                $this->appendEvent($recordingId,(int)$recording->content_version,'idempotency_conflict',(string)$recording->lifecycle_state,(string)$recording->lifecycle_state,$conflictKey,array('expected_digest'=>(string)$existing->payload_digest,'received_digest'=>$digest),$actor,$source,'idempotency_payload_conflict',$now);
                $wpdb->query('COMMIT');
                throw new \RuntimeException('recording_idempotency_conflict');
            }
            $current=(string)$recording->lifecycle_state;$version=(int)$recording->version;
            // Legal hold blocks deletion, not capture or playback.
            if($event==='source_deleted'&&(string)$recording->legal_hold_state==='active'){
                $this->appendEvent($recordingId,(int)$recording->content_version,'deletion_refused',$current,$current,$this->eventKey('legal-hold-delete|'.$recordingId.'|'.$idempotency),array('reason'=>'legal_hold'),$actor,$source,'legal_hold_active',$now);
                $wpdb->query('COMMIT');
                throw new \RuntimeException('recording_legal_hold_active');
            }
            $result=RecordingLifecycle::apply($current,$expectedState,$version,0,$event);
            if(!$result['ok']){
                // An event that arrives before its required prior state is durable evidence, not a state jump.
                $order=array_flip(RecordingLifecycle::STATES);
                $expectedOrder=$order[$expectedState]??-1;$currentOrder=$order[$current]??-1;
                $known=($order[$expectedState]??null)!==null;
                $delay=$known&&$expectedOrder>$currentOrder;
                $this->appendEvent($recordingId,(int)$recording->content_version,$delay?'provider_out_of_order':'transition_refused',$current,$current,$this->eventKey(($delay?'out-of-order|':'refused|').$recordingId.'|'.$idempotency),array('expected_state'=>$expectedState,'event'=>$event,'reason'=>$result['reason']),$actor,$source,$result['reason'],$now);
                if($delay)$this->enqueueCommandInternal($recordingId,(int)$recording->content_version,'reconcile',$this->commandKey('reconcile-delayed|'.$recordingId.'|'.$idempotency),array('recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version),null,0,$now);
                $wpdb->query('COMMIT');
                if($delay)return array('accepted'=>true,'applied'=>false,'reason'=>'out_of_order','state'=>$current);
                throw new \RuntimeException('recording_transition_refused:'.$result['reason']);
            }
            if($result['idempotent']){
                $this->appendEvent($recordingId,(int)$recording->content_version,$event,$current,$current,$this->eventKey('duplicate|'.$recordingId.'|'.$idempotency),$evidence,$actor,$source,$reason,$now);
                $wpdb->query('COMMIT');
                return array('accepted'=>true,'applied'=>false,'idempotent'=>true,'reason'=>'already_applied','state'=>$current);
            }
            $updates=array('lifecycle_state'=>$result['state'],'version'=>$result['version'],'updated_at'=>gmdate('Y-m-d H:i:s',$now));
            if($event==='egress_started')$updates['capture_started_at']=gmdate('Y-m-d H:i:s',$now);
            if($event==='egress_completed')$updates['finalised_at']=gmdate('Y-m-d H:i:s',$now);
            if($event==='media_verified')$updates['verified_at']=gmdate('Y-m-d H:i:s',$now);
            if($event==='archive_confirmed'){$updates['archived_at']=gmdate('Y-m-d H:i:s',$now);$updates['authoritative_location']='mbox';}
            if($event==='source_deleted'){$updates['vps_deleted_at']=gmdate('Y-m-d H:i:s',$now);$updates['authoritative_location']='mbox';}
            if($event==='withdrawal_stop'){$updates['disposition_state']=$result['state']===RecordingLifecycle::QUARANTINED?'quarantined':($result['state']===RecordingLifecycle::DELETION_REQUIRED?'deletion_required':'withdrawal');}
            if($event==='quarantine')$updates['disposition_state']='quarantined';
            if($event==='invalidate')$updates['failure_reason_code']='schedule_superseded';
            if($event==='fail'&&$reason!=='')$updates['failure_reason_code']=substr($reason,0,64);
            if($event==='archive_mismatch'){$updates['disposition_state']='quarantined';$updates['quarantine_reason_code']='archive_checksum_mismatch';}
            if($wpdb->update($p.'recordings',$updates,array('id'=>$recordingId,'version'=>$version))!==1)throw new \RuntimeException('recording_version_conflict');
            $this->appendEvent($recordingId,(int)$recording->content_version,$eventType,$current,$result['state'],$idempotency,$evidence,$actor,$source,$reason,$now);
            $wpdb->query('COMMIT');
            return array('accepted'=>true,'applied'=>true,'idempotent'=>false,'reason'=>$reason,'state'=>$result['state'],'version'=>$result['version']);
        }catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
    }

    private function appendEvent(int $recordingId,int $contentVersion,string $eventType,string $expectedState,string $resultingState,string $idempotencyKey,array $evidence,int $actor,string $source,string $reason,string $occurredAt):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s',$this->now());
        $digest=RecordingSignature::payloadDigest($evidence);
        $inserted=$wpdb->insert($p.'recording_events',array(
            'uid'=>Identifier::uid(),'recording_id'=>$recordingId,'content_version'=>$contentVersion,
            'event_type'=>$eventType,'expected_state'=>$expectedState!==''?$expectedState:null,'resulting_state'=>$resultingState,
            'idempotency_key'=>$idempotencyKey,'payload_digest'=>$digest,'actor_type'=>$actor>0?'user':'system',
            'actor_id'=>$actor>0?$actor:null,'source'=>substr($source,0,24),'reason_code'=>substr($reason,0,64),
            'evidence_json'=>substr(RecordingSignature::canonicalJson($evidence),0,20000),
            'occurred_at'=>$occurredAt,'received_at'=>$now,'created_at'=>$now,
        ));
        if($inserted===false){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT payload_digest FROM {$p}recording_events WHERE idempotency_key=%s LIMIT 1",$idempotencyKey));
            if($existing&&hash_equals((string)$existing->payload_digest,$digest))return;
            throw new \RuntimeException('recording_event_persistence_failed');
        }
    }

    private function enqueueCommandInternal(int $recordingId,int $contentVersion,string $type,string $key,array $payload,?string $expectedState,int $actor,int $now):array{
        if(!in_array($type,self::COMMAND_TYPES,true))throw new \InvalidArgumentException('recording_command_type_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $payloadDigest=RecordingSignature::payloadDigest($payload);
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_commands WHERE idempotency_key=%s LIMIT 1",$key));
        if($existing){
            if(hash_equals((string)$existing->payload_digest,$payloadDigest))return array('command_id'=>(int)$existing->id,'idempotent'=>true);
            $this->appendEvent($recordingId,$contentVersion,'command_conflict',(string)$existing->state,(string)$existing->state,$this->eventKey('command-conflict|'.$key.'|'.$payloadDigest),array('type'=>$type),$actor,'wordpress','command_payload_conflict',$now);
            throw new \RuntimeException('recording_command_idempotency_conflict');
        }
        $inserted=$wpdb->insert($p.'recording_commands',array(
            'uid'=>Identifier::uid(),'recording_id'=>$recordingId,'content_version'=>$contentVersion,'command_type'=>$type,
            'idempotency_key'=>$key,'payload_digest'=>$payloadDigest,'payload_json'=>RecordingSignature::canonicalJson($payload),
            'expected_state'=>$expectedState,'state'=>'pending','attempts'=>0,'max_attempts'=>8,
            'available_at'=>gmdate('Y-m-d H:i:s',$now),'created_at'=>gmdate('Y-m-d H:i:s',$now),'updated_at'=>gmdate('Y-m-d H:i:s',$now),'version'=>1,
        ));
        if($inserted===false)throw new \RuntimeException('recording_command_persistence_failed');
        return array('command_id'=>(int)$wpdb->insert_id,'idempotent'=>false);
    }

    private function completeCommandByKey(string $key,string $resultCode,int $recordingId):void{
        if($key==='')return;global $wpdb;$p=$wpdb->prefix.'dzn_';
        $wpdb->update($p.'recording_commands',array('state'=>'completed','result_code'=>substr($resultCode,0,64),'completed_at'=>gmdate('Y-m-d H:i:s',$this->now()),'updated_at'=>gmdate('Y-m-d H:i:s',$this->now())),array('idempotency_key'=>$key));
    }

    private function canonicalLesson(int $lessonId,string $p):object{
        global $wpdb;
        $lesson=$wpdb->get_row($wpdb->prepare("SELECT l.id,l.uid lesson_uid,l.teacher_id,l.student_id,l.enrolment_id,l.term_id,l.record_model,l.lifecycle_state,l.current_schedule_version_id,sv.id schedule_version_id,sv.uid schedule_uid,sv.teacher_id schedule_teacher_id,sv.starts_at_utc,sv.ends_at_utc,sv.duration_minutes,sv.superseded_by_version_id FROM {$p}lessons l INNER JOIN {$p}canonical_lesson_schedule_versions sv ON sv.id=l.current_schedule_version_id AND sv.lesson_id=l.id AND sv.applicable_slot=1 AND sv.superseded_by_version_id IS NULL WHERE l.id=%d AND l.archived_at IS NULL LIMIT 1",$lessonId));
        if(!$lesson)throw new \RuntimeException('recording_lesson_schedule_unavailable');
        if((string)$lesson->record_model!=='canonical_term_lesson_v1')throw new \RuntimeException('recording_lesson_not_canonical');
        if((string)$lesson->lifecycle_state!=='authorised')throw new \RuntimeException('recording_lesson_not_authorised');
        return $lesson;
    }

    private function assertParticipantAssignment(object $lesson,string $p):void{
        global $wpdb;
        if((int)$lesson->schedule_teacher_id!==(int)$lesson->teacher_id)throw new \RuntimeException('recording_teacher_replacement_detected');
        $teacher=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teachers WHERE id=%d AND status='active' AND archived_at IS NULL",(int)$lesson->teacher_id));
        $student=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}students WHERE id=%d AND status='active' AND archived_at IS NULL",(int)$lesson->student_id));
        if($teacher!==1||$student!==1)throw new \RuntimeException('recording_participant_assignment_invalid');
    }

    private function assertReady():array{
        $health=RecordingFeatureFlags::freshServiceHealth($this->now());
        if(($health['secure_transport_connected']??($health['tailscale_connected']??false))!==true)throw new \RuntimeException('recording_secure_transport_unavailable');
        if(trim((string)($health['archive_mount_identity']??''))==='')throw new \RuntimeException('recording_archive_mount_unverified');
        if((int)($health['storage_free_bytes']??0)<104857600)throw new \RuntimeException('recording_storage_below_threshold');
        if((float)($health['storage_free_percent']??0)<5.0)throw new \RuntimeException('recording_storage_percent_below_threshold');
        return $health;
    }

    private function noticeReference(array $authorisation):string{
        $decisions=(array)($authorisation['decisions']??array());
        $parts=array();
        foreach(array('teacher_recording','student_recording','guardian_recording') as $key){
            $decision=$decisions[$key]??null;
            if(is_array($decision)&&isset($decision['row'])){
                $parts[]=$key.':'.(string)$decision['row']->notice_uid.':v'.(int)$decision['row']->notice_version_number;
            }
        }
        return substr(implode(';',$parts),0,191);
    }

    private function assertKey(string $key):void{
        if(preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$key)!==1)throw new \InvalidArgumentException('recording_idempotency_key_invalid');
    }

    private function eventKey(string $value):string{return hash('sha256','dzn-recording-event|'.$value);}
    private function commandKey(string $value):string{return hash('sha256','dzn-recording-command|'.$value);}
    private function actorFrom(array $payload):int{return max(0,(int)($payload['actor_id']??0));}
    private function now():int{return $this->now??(function_exists('time')?(int)time():0);}
}
