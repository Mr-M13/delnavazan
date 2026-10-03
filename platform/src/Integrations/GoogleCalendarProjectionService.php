<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\{IntegrationSecretService,ProviderIntegrationService,ProviderIntegrationRule};
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;

/**
 * Dispatch one committed pending Calendar projection under an authorised operator principal.
 *
 * Network I/O happens only after the pending mapping already exists. The deterministic Google event
 * id makes an ambiguous POST recoverable. Canonical acknowledgement still belongs exclusively to
 * ProviderIntegrationService.
 */
final class GoogleCalendarProjectionService {
    public function __construct(
        private ?ProviderIntegrationRepository $repository=null,
        private ?IntegrationSecretService $secrets=null,
        private ?GoogleCalendarMeetAdapter $adapter=null,
        private ?GoogleCalendarProjectionTransport $transport=null
    ){
        $this->repository??=new ProviderIntegrationRepository();
        $this->secrets??=new IntegrationSecretService();
        $this->adapter??=new GoogleCalendarMeetAdapter();
        $this->transport??=new GoogleCalendarProjectionTransport();
    }

    public function dispatch(int $mappingId,string $commandKey):array{
        if(!current_user_can(ProviderIntegrationService::MANAGE_CAPABILITY))throw new \RuntimeException('Unauthorized');
        $mapping=$this->repository->calendarMapping($mappingId,false);
        if(!$mapping||(string)$mapping->provider_code!=='google_calendar'||(string)$mapping->projection_state!=='pending')throw new \InvalidArgumentException('pending_google_calendar_projection_required');
        $connection=$this->repository->connection((int)$mapping->connection_id,false);
        if(!$connection||(string)$connection->connection_state!=='connected')throw new \InvalidArgumentException('provider_connection_unusable');
        $credential=$this->repository->credential((int)$connection->id,false);
        if(!$credential)throw new \InvalidArgumentException('provider_credential_required');
        if($credential->valid_until_utc!==null&&strtotime((string)$credential->valid_until_utc.' UTC')<=time()+60)throw new \InvalidArgumentException('provider_credential_refresh_required');
        $material=$this->secrets->open((int)$connection->id,'google_calendar',$credential);
        $translation=$this->adapter->project(array(
            'provider_code'=>'google_calendar','operation'=>'project',
            'lesson_id'=>(int)$mapping->lesson_id,'schedule_version_id'=>(int)$mapping->schedule_version_id,
            'starts_at_utc'=>(string)$mapping->starts_at_utc,'ends_at_utc'=>(string)$mapping->ends_at_utc,
            'schedule_timezone'=>(string)$mapping->schedule_timezone,'local_wall_date'=>(string)$mapping->local_wall_date,
            'local_wall_time'=>(string)$mapping->local_wall_time,'teacher_subject_reference'=>null,
            'projection_reference'=>'mapping:'.$mappingId,
        ));
        $request=$translation['provider_request']??null;
        if(!is_array($request))throw new \RuntimeException('provider_projection_translation_unavailable');
        $provider=$this->transport->dispatch($request,$material);
        $now=gmdate('Y-m-d H:i:s');
        $authority=new ProviderIntegrationService(null,null,new GoogleOAuthTransport(),$this->adapter,$this->adapter);
        $result=$authority->acknowledgeCalendarProjection($mappingId,array(
            'provider_object_reference'=>(string)$provider['provider_object_reference'],
            'join_uri_reference'=>(string)($provider['join_uri_reference']??''),
            'evidence_channel'=>'authenticated_platform',
            'evidence_reference'=>'google-calendar-dispatch:'.$mappingId,
            'evidence_at'=>$now,
        ),$commandKey);
        $result['conference_pending']=!empty($provider['conference_pending']);
        return $result;
    }
}
