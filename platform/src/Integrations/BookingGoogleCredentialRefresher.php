<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderIntegrationService;

/** Rotates an expired booking credential through the encrypted provider authority. */
final class BookingGoogleCredentialRefresher {
    public function refresh(int $connectionId,int $confirmationId,int $attempt):bool{
        $adapter=new GoogleCalendarMeetAdapter();
        $service=new ProviderIntegrationService(null,null,new GoogleOAuthTransport(),$adapter,$adapter);
        $result=$service->refreshConnection($connectionId,array(
            'evidence_channel'=>'authenticated_platform',
            'evidence_reference'=>'booking-confirmation-refresh:'.$confirmationId.':'.$attempt,
            'evidence_at'=>gmdate('Y-m-d H:i:s'),
        ),'booking-confirmation-refresh:'.$confirmationId.':'.$attempt);
        return (string)($result['connection_state']??'')==='connected';
    }
}
