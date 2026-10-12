<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/**
 * Local Telnyx configuration probe used by the explicit admin QA action.
 *
 * This deliberately does not call Telnyx. Provider acceptance belongs to a
 * separately authorised deployment test; the control-plane check is safe to
 * run repeatedly while validating the WhatsApp-to-SMS fallback configuration.
 */
final class TelnyxSmsAdapter {
    public function __construct(private IntegrationSettingsService $settings){}
    public function sendTest(string $destination):array {
        $from=$this->settings->value('sms','sender_id');
        $profile=$this->settings->value('sms','messaging_profile_id');
        if($from===''&&$profile==='')return array('state'=>'failed','code'=>'telnyx_sender_not_configured');
        // Validate only the safe destination shape already supplied by the
        // control-plane runner. Do not retain it or read the API key here.
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$destination))return array('state'=>'failed','code'=>'destination_invalid');
        return array('state'=>'success','code'=>'telnyx_mock_accepted');
    }
}
