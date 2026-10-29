<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderIntegrationService;

/**
 * Refresh one provider connection's renewable credential through the existing integration authority.
 *
 * The default path uses the real Google OAuth transport and the configured projection-worker
 * principal. A callable seam keeps the refresh policy testable without network access.
 */
final class ProviderCredentialRefresher {
    /** @var callable(int,string):bool */
    private $refresh;
    public function __construct(?callable $refresh=null){
        $this->refresh=$refresh??static function(int $connectionId,string $reference):bool{
            $adapter=new GoogleCalendarMeetAdapter();
            $service=new ProviderIntegrationService(null,null,new GoogleOAuthTransport(),$adapter,$adapter);
            $result=$service->refreshConnection($connectionId,array(
                'evidence_channel'=>'authenticated_platform',
                'evidence_reference'=>$reference,
                'evidence_at'=>gmdate('Y-m-d H:i:s'),
            ),$reference);
            return (string)($result['connection_state']??'')==='connected';
        };
    }
    public function refresh(int $connectionId,string $reference):bool{
        return (bool)($this->refresh)($connectionId,$reference);
    }
}
