<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository,ProviderIntegrationRepository};

/**
 * Fail-closed shape and aggregate validation for the Phase 2A.2-V integration storage.
 *
 * The validator never repairs, never coalesces and never guesses: a malformed connection, credential,
 * mapping, ingest event, conflict receipt or command row is refused. Provider projection additionally
 * composes the existing Phase-M/N/O validators, so an integration reference can only ever be recorded
 * against a canonical Lesson whose scheduling aggregate is exactly what Phase N owns.
 */
final class ProviderIntegrationValidator {
    public static function connectionShape(object $connection):bool{
        if((int)($connection->id??0)<1)return false;
        if(!in_array((string)($connection->provider_code??''),ProviderIntegrationRule::PROVIDER_CODES,true))return false;
        if((int)($connection->teacher_id??0)<1)return false;
        if(!in_array((string)($connection->connection_state??''),ProviderIntegrationRule::CONNECTION_STATES,true))return false;
        if((int)($connection->connection_version??0)<1||(int)($connection->lifecycle_sequence??0)<1)return false;
        if(!in_array((string)($connection->identity_state??''),ProviderIntegrationRule::MAPPING_STATES,true))return false;
        $active=(string)($connection->connection_state??'')==='connected';
        if($active&&(int)($connection->active_slot??0)!==1)return false;
        if(!$active&&$connection->active_slot!==null)return false;
        if($active&&!ProviderIntegrationRule::digest($connection->identity_digest??null))return false;
        if($active&&(string)($connection->scope_snapshot??'')==='')return false;
        return true;
    }

    public static function credentialShape(object $credential):bool{
        if((int)($credential->id??0)<1||(int)($credential->connection_id??0)<1)return false;
        if(!in_array((string)($credential->state??''),ProviderIntegrationRule::CREDENTIAL_STATES,true))return false;
        if((string)($credential->key_version??'')===''||(string)($credential->cipher_version??'')==='')return false;
        return self::sealedShape($credential->nonce??null,$credential->ciphertext??null);
    }

    /** A ciphertext column may never be a readable credential; it must be non-empty base64 material. */
    public static function sealedShape(mixed $nonce,mixed $ciphertext):bool{
        foreach(array($nonce,$ciphertext) as $value){
            if(!is_string($value)||strlen($value)<8||base64_decode($value,true)===false)return false;
        }
        return true;
    }

    public static function mappingShape(object $mapping,string $purpose):bool{
        if(!in_array($purpose,ProviderIntegrationRule::PURPOSES,true))return false;
        if((int)($mapping->id??0)<1)return false;
        if(!in_array((string)($mapping->provider_code??''),ProviderIntegrationRule::PROVIDER_CODES,true))return false;
        $state=(string)($mapping->mapping_state??$mapping->projection_state??'');
        if(!in_array($state,ProviderIntegrationRule::MAPPING_STATES,true))return false;
        if((int)($mapping->mapping_version??0)<1)return false;
        if($state==='verified'&&(int)($mapping->active_slot??0)!==1)return false;
        if($state!=='verified'&&$mapping->active_slot!==null)return false;
        if($purpose==='connection_identity')return ProviderIntegrationRule::digest($mapping->subject_digest??null)&&(int)($mapping->teacher_id??0)>0;
        if((int)($mapping->lesson_id??0)<1||(int)($mapping->schedule_version_id??0)<1)return false;
        if($purpose==='calendar_event')return ProviderIntegrationRule::digest($mapping->event_digest??null);
        return ProviderIntegrationRule::digest($mapping->conference_digest??null);
    }

    public static function ingestEventShape(object $event):bool{
        if((int)($event->id??0)<1)return false;
        if(!in_array((string)($event->provider_code??''),ProviderIntegrationRule::EVIDENCE_PROVIDER_CODES,true))return false;
        if(!ProviderIntegrationRule::digest($event->provider_event_key_digest??null))return false;
        if(!ProviderIntegrationRule::digest($event->event_fact_digest??null))return false;
        if(!ProviderIntegrationRule::digest($event->provider_account_digest??null))return false;
        // The receipt records the trusted transport that authenticated the delivery and the digest of
        // its proof: a receipt without provenance could not have been authenticated by anyone.
        if((string)($event->transport??'')==='')return false;
        if(!ProviderIntegrationRule::digest($event->proof_reference_digest??null))return false;
        if((int)($event->event_sequence??0)<1)return false;
        if(!in_array((string)($event->processing_state??''),ProviderIntegrationRule::INGEST_STATES,true))return false;
        if(!ProviderIntegrationRule::utc($event->occurred_at??null)||!ProviderIntegrationRule::utc($event->received_at??null))return false;
        $occurred=(string)$event->occurred_at;$received=(string)$event->received_at;
        // Provider time and local time are separate facts: a provider instant may never be in the future
        // of the local receipt, and the local receipt may never precede the provider instant.
        return $occurred<=$received;
    }

    /**
     * One append-only handoff outcome for a provider-event receipt.
     *
     * Admission is only ever recorded by an outcome row that exists after a successful Phase-P
     * handoff, so a receipt with no admitted outcome can never be reported as admitted.
     */
    public static function ingestOutcomeShape(object $outcome):bool{
        if((int)($outcome->id??0)<1||(int)($outcome->provider_ingest_event_id??0)<1)return false;
        if(!in_array((string)($outcome->provider_code??''),ProviderIntegrationRule::EVIDENCE_PROVIDER_CODES,true))return false;
        if(!ProviderIntegrationRule::digest($outcome->provider_event_key_digest??null))return false;
        $state=(string)($outcome->outcome??'');
        if(!in_array($state,ProviderIntegrationRule::INGEST_OUTCOMES,true))return false;
        if((int)($outcome->handoff_attempt??0)<1)return false;
        if(!ProviderIntegrationRule::utc($outcome->recorded_at??null))return false;
        if($state==='refused')return trim((string)($outcome->reason_code??''))!=='';
        return ProviderIntegrationRule::digest($outcome->intake_result_digest??null);
    }

    public static function conflictShape(object $conflict):bool{
        return (int)($conflict->id??0)>0
            &&in_array((string)($conflict->provider_code??''),ProviderIntegrationRule::EVIDENCE_PROVIDER_CODES,true)
            &&ProviderIntegrationRule::digest($conflict->provider_event_key_digest??null)
            &&ProviderIntegrationRule::digest($conflict->conflicting_fact_digest??null)
            &&in_array((string)($conflict->conflict_kind??''),ProviderIntegrationRule::CONFLICT_KINDS,true);
    }

    public static function commandShape(object $command,string $operation,string $payloadDigest):bool{
        if((string)($command->command_domain??'')!==ProviderIntegrationRule::COMMAND_DOMAIN)return false;
        if((string)($command->operation??'')!==$operation)return false;
        if(!in_array($operation,ProviderIntegrationRule::OPERATIONS,true))return false;
        if(!ProviderIntegrationRule::digest($command->command_key_digest??null))return false;
        if(!ProviderIntegrationRule::digest($command->command_payload_digest??null))return false;
        return hash_equals((string)$command->command_payload_digest,$payloadDigest);
    }

    /**
     * The exact applicable canonical schedule version of one canonical Lesson.
     *
     * Composes the Phase-N schedule validator, so a projection can only ever mirror a schedule version
     * the canonical authority already recognises as valid and applicable.
     *
     * @return array{applicable:bool,reason:?string,facts:?array}
     */
    public static function projectionApplicable(int $lessonId,int $scheduleVersionId,?CanonicalLessonScheduleRepository $schedules=null,?CanonicalLessonAuthorityRepository $lessons=null,bool $lock=false):array{
        $schedules??=new CanonicalLessonScheduleRepository();
        $lessons??=new CanonicalLessonAuthorityRepository();
        if($lessonId<1||$scheduleVersionId<1)return array('applicable'=>false,'reason'=>'canonical_schedule_version_required','facts'=>null);
        if(!CanonicalLessonScheduleValidator::validForLesson($lessonId,$schedules,$lessons,$lock))return array('applicable'=>false,'reason'=>'canonical_schedule_aggregate_invalid','facts'=>null);
        $applicable=$schedules->applicableVersion($lessonId,$lock);
        if(!$applicable||(int)$applicable->id!==$scheduleVersionId)return array('applicable'=>false,'reason'=>'stale_schedule_version','facts'=>null);
        try{
            $facts=ProviderIntegrationRule::scheduleProjectionFacts($applicable);
        }catch(\InvalidArgumentException){
            return array('applicable'=>false,'reason'=>'canonical_schedule_version_unusable','facts'=>null);
        }
        return array('applicable'=>true,'reason'=>null,'facts'=>$facts);
    }

    /**
     * Whole-aggregate validity for one integration reference on one Lesson occurrence.
     *
     * Phase M/N/O remain the only owners: the Lesson lifecycle, the schedule aggregate and the
     * delivery/attendance aggregate must all validate before an integration mapping may be recorded.
     */
    public static function occurrenceAggregateValid(int $lessonId,?ProviderIntegrationRepository $integration=null,?CanonicalLessonScheduleRepository $schedules=null,?CanonicalLessonAuthorityRepository $lessons=null,bool $lock=false):bool{
        $schedules??=new CanonicalLessonScheduleRepository();
        $lessons??=new CanonicalLessonAuthorityRepository();
        if(!CanonicalLessonScheduleValidator::validForLesson($lessonId,$schedules,$lessons,$lock))return false;
        return CanonicalLessonDeliveryValidator::validForLesson($lessonId,null,$schedules,$lessons,$lock);
    }
}
