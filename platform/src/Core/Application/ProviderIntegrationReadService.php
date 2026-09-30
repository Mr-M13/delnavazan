<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;

/**
 * Capability-gated, object-level-protected reads for the Phase 2A.2-V integration surface.
 *
 * A read is never a decryption oracle and never an authorisation: an administrator reads the whole
 * integration surface, a Teacher reads only their own connection, and any read whose exact Core
 * Teacher or Lesson does not belong to the caller is refused rather than filtered. Every returned row
 * has already failed closed if its stored shape is malformed, and a credential row is reported only
 * as a redacted shape — never as material.
 */
final class ProviderIntegrationReadService {
    public function __construct(private ?ProviderIntegrationRepository $repository=null){
        $this->repository??=new ProviderIntegrationRepository();
    }

    /** @return array<string,mixed> */
    public function connection(int $connectionId):array{
        $connection=$this->repository->connection($connectionId);
        if(!$connection)throw new \InvalidArgumentException('provider_connection_required');
        $this->authorizeTeacher((int)$connection->teacher_id);
        if(!ProviderIntegrationValidator::connectionShape($connection))throw new \RuntimeException('provider_connection_malformed');
        $credential=$this->repository->credential($connectionId,false);
        if($credential&&!ProviderIntegrationValidator::credentialShape($credential))throw new \RuntimeException('provider_credential_malformed');
        return array(
            'connection_id'=>(int)$connection->id,'provider_code'=>(string)$connection->provider_code,
            'teacher_id'=>(int)$connection->teacher_id,'connection_state'=>(string)$connection->connection_state,
            'identity_state'=>(string)$connection->identity_state,'scope_snapshot'=>(string)$connection->scope_snapshot,
            'connected_at'=>$connection->connected_at,'disconnected_at'=>$connection->disconnected_at,'revoked_at'=>$connection->revoked_at,
            'credential'=>$credential?IntegrationSecretService::redacted($credential):null,
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function connections(string $providerCode,int $teacherId):array{
        ProviderIntegrationRule::providerCode($providerCode);
        $this->authorizeTeacher($teacherId);
        $rows=array();
        foreach($this->repository->connections($providerCode,$teacherId) as $connection){
            if(!ProviderIntegrationValidator::connectionShape($connection))throw new \RuntimeException('provider_connection_malformed');
            $rows[]=array('connection_id'=>(int)$connection->id,'provider_code'=>(string)$connection->provider_code,'connection_state'=>(string)$connection->connection_state,'lifecycle_sequence'=>(int)$connection->lifecycle_sequence,'connected_at'=>$connection->connected_at,'disconnected_at'=>$connection->disconnected_at,'revoked_at'=>$connection->revoked_at);
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function lessonIntegrations(int $lessonId):array{
        $this->authorizeLesson($lessonId);
        $calendar=array();$meeting=array();
        foreach($this->repository->calendarMappings($lessonId) as $mapping){
            if(!ProviderIntegrationValidator::mappingShape($mapping,'calendar_event'))throw new \RuntimeException('integration_mapping_malformed');
            $calendar[]=array('mapping_id'=>(int)$mapping->id,'provider_code'=>(string)$mapping->provider_code,'schedule_version_id'=>(int)$mapping->schedule_version_id,'projection_state'=>(string)$mapping->projection_state,'starts_at_utc'=>$mapping->starts_at_utc,'ends_at_utc'=>$mapping->ends_at_utc);
        }
        foreach($this->repository->meetingMappings($lessonId) as $mapping){
            if(!ProviderIntegrationValidator::mappingShape($mapping,'meeting_conference'))throw new \RuntimeException('integration_mapping_malformed');
            $meeting[]=array('mapping_id'=>(int)$mapping->id,'provider_code'=>(string)$mapping->provider_code,'schedule_version_id'=>(int)$mapping->schedule_version_id,'projection_state'=>(string)$mapping->projection_state,'starts_at_utc'=>$mapping->starts_at_utc,'ends_at_utc'=>$mapping->ends_at_utc);
        }
        return array('lesson_id'=>$lessonId,'calendar_events'=>$calendar,'meetings'=>$meeting);
    }

    /** @return array<int,array<string,mixed>> */
    public function events(int $lessonId):array{
        $this->authorizeLesson($lessonId);
        $rows=array();
        foreach($this->repository->ingestEvents($lessonId) as $event){
            if(!ProviderIntegrationValidator::ingestEventShape($event))throw new \RuntimeException('provider_event_receipt_corrupt');
            $rows[]=array('ingest_event_id'=>(int)$event->id,'provider_code'=>(string)$event->provider_code,'participant_role'=>(string)$event->participant_role,'processing_state'=>$this->effectiveState($event),'received_state'=>(string)$event->processing_state,'occurred_at'=>$event->occurred_at,'received_at'=>$event->received_at);
        }
        return $rows;
    }

    /**
     * The effective outcome of one receipt: the immutable receipt only ever records that the delivery
     * was received, and every later handoff outcome is an appended row. A receipt therefore reports
     * `received` until an outcome exists, and can never be read as admitted in advance of its handoff.
     */
    private function effectiveState(object $event):string{
        $outcome=$this->repository->latestIngestOutcome((int)$event->id);
        if(!$outcome)return (string)$event->processing_state;
        if(!ProviderIntegrationValidator::ingestOutcomeShape($outcome))throw new \RuntimeException('provider_event_outcome_corrupt');
        return (string)$outcome->outcome;
    }

    /** @return array<int,array<string,mixed>> */
    public function conflicts(int $lessonId):array{
        $this->authorizeLesson($lessonId);
        $rows=array();
        foreach($this->repository->conflicts($lessonId) as $conflict){
            if(!ProviderIntegrationValidator::conflictShape($conflict))throw new \RuntimeException('provider_conflict_receipt_corrupt');
            $rows[]=array('conflict_id'=>(int)$conflict->id,'conflict_kind'=>(string)$conflict->conflict_kind,'lesson_id'=>$conflict->lesson_id===null?null:(int)$conflict->lesson_id,'observed_at'=>$conflict->observed_at);
        }
        return $rows;
    }

    /**
     * Object-level authorisation against the exact Core Teacher.
     *
     * The integration-management capability covers every Teacher. Otherwise the caller must both hold
     * the view capability and resolve to exactly that Teacher through the Phase-J principal link: being
     * linked to a Teacher never by itself grants a read.
     */
    private function authorizeTeacher(int $teacherId):void{
        if($teacherId<1)throw new \InvalidArgumentException('canonical_teacher_required');
        if(current_user_can(ProviderIntegrationService::MANAGE_CAPABILITY))return;
        if(current_user_can(ProviderIntegrationService::VIEW_CAPABILITY)&&$this->isOwnTeacher($teacherId))return;
        throw new \RuntimeException('Unauthorized');
    }

    /** Object-level authorisation against the exact Core Lesson and its owning Teacher. */
    private function authorizeLesson(int $lessonId):void{
        global $wpdb;
        if($lessonId<1)throw new \InvalidArgumentException('canonical_lesson_required');
        $lesson=$wpdb->get_row($wpdb->prepare("SELECT id,teacher_id FROM {$wpdb->prefix}dzn_lessons WHERE id=%d",$lessonId));
        if(!$lesson)throw new \InvalidArgumentException('canonical_lesson_required');
        $this->authorizeTeacher((int)$lesson->teacher_id);
    }

    private function isOwnTeacher(int $teacherId):bool{
        $userId=get_current_user_id();
        if($userId<1)return false;
        $teacher=$this->repository->teacherForPrincipalUser($userId,false);
        return $teacher&&(int)$teacher->id===$teacherId;
    }
}
