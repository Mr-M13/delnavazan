<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\PaymentExecution\StripeTestModeReadinessService;

/** Central, capability-gated operator view; provider credentials are never accepted or rendered here. */
final class IntegrationControlPlaneController {
    public static function googleSettings():void{self::providerSettings('google');}
    public static function whatsAppSettings():void{self::providerSettings('whatsapp');}
    public static function smsSettings():void{self::providerSettings('sms');}

    /** Stripe configuration/readiness belongs in Settings; Finance keeps payment operations. */
    public static function stripeSettings():void{
        if(!current_user_can(PaymentExecutionController::CAPABILITY))wp_die('Forbidden',403);
        $readiness=(new StripeTestModeReadinessService())->read();
        echo '<div class="wrap"><h1>Stripe setup</h1><p>Secret-free test-mode readiness. This screen never displays credentials and does not enable production payments.</p>';
        echo '<p><strong>'.esc_html($readiness['ready']?'Ready for an explicitly authorised test-mode check':'Setup is incomplete').'</strong></p><table class="widefat striped"><thead><tr><th>Check</th><th>State</th><th>Detail</th></tr></thead><tbody>';
        foreach($readiness['checks'] as $key=>$check)echo '<tr><th>'.esc_html((string)$key).'</th><td>'.esc_html((string)$check['state']).'</td><td>'.esc_html((string)$check['detail']).'</td></tr>';
        echo '</tbody></table><p>Payment execution and reconciliation are under Finance. Production activation remains disabled.</p></div>';
    }

    private static function providerSettings(string $provider):void{
        if(!current_user_can(IntegrationControlPlaneReadService::READ_CAPABILITY))wp_die('Forbidden',403);
        $r=(new IntegrationControlPlaneReadService())->report();
        if($provider==='google'){
            echo '<div class="wrap"><h1>Google Console</h1><p>Google OAuth and Calendar connection readiness. Client values are never displayed.</p><table class="widefat striped"><tbody>';
            self::row('OAuth client configuration',$r['google_calendar']['oauth_client']);
            self::row('Configuration source',$r['google_calendar']['configuration_source']);
            self::row('Authorized redirect URI',$r['google_calendar']['authorized_redirect_uri']);
            self::row('Connection test',$r['google_calendar']['test_connection']);
        foreach($r['google_calendar']['projection_states'] as $state=>$count)self::row('Calendar projection: '.$state,(string)$count);
            echo '</tbody></table><p>Configure a Google OAuth web client with this exact HTTPS redirect URI, then provide the client ID and secret through deployment configuration. Client values are never entered or displayed here. Connection remains untested until an authorized teacher completes OAuth.</p></div>';return;
        }
        $state=$r['notifications']['channels'][$provider]??'not_configured';
        $label=$provider==='whatsapp'?'WhatsApp':'SMS';
        echo '<div class="wrap"><h1>'.esc_html($label).' setup</h1><p>Channel status: <strong>'.esc_html((string)$state).'</strong></p><p>A provider and deployment credentials must be selected before this channel can send. This screen does not send test messages or expose secrets.</p></div>';
    }

    public static function screen():void{
        if(!current_user_can(IntegrationControlPlaneReadService::READ_CAPABILITY))wp_die('Forbidden',403);
        $r=(new IntegrationControlPlaneReadService())->report();
        echo '<div class="wrap"><h1>Integrations &amp; delivery</h1><p>Read-only configuration and delivery readiness. Status does not prove external delivery.</p>';
        echo '<h2>Email and notifications</h2><table class="widefat striped"><tbody>';
        self::row('Teacher invitation mail transport',$r['email']['invitation_transport']);
        self::row('Invitation delivery verification','not verified: WordPress mail acceptance is not recipient delivery');
        self::row('General notification runtime',$r['notifications']['runtime']);
        self::row('Notification copy',$r['notifications']['copy_definitions']);
        foreach($r['notifications']['channels'] as $channel=>$state)self::row(ucfirst($channel).' channel',$state);
        foreach($r['email']['invitation_outbox'] as $state=>$count)self::row('Invitation outbox: '.$state,(string)$count);
        foreach($r['notifications']['states'] as $state=>$count)self::row('Notification state: '.$state,(string)$count);
        foreach($r['notifications']['delivery_states'] as $state=>$count)self::row('Notification delivery evidence: '.$state,(string)$count);
        echo '</tbody></table><h2>Google</h2><table class="widefat striped"><tbody>';
        self::row('Implemented capability',$r['google_calendar']['capability']);
        self::row('OAuth client configuration',$r['google_calendar']['oauth_client']);
        self::row('OAuth credentials source',$r['google_calendar']['configuration_source']);
        self::row('OAuth authorized redirect URI',$r['google_calendar']['authorized_redirect_uri']);
        self::row('Connection test',$r['google_calendar']['test_connection']);
        echo '</tbody></table><h2>Stripe</h2><p>Use Payment Execution for secret-free Stripe test-mode readiness. Production payment activation remains separately gated.</p>';
        echo '<p>WhatsApp and SMS have transport seams only. Choose providers and supply deployment credentials before enabling either channel. No provider test, message, or payment is sent from this screen.</p>';
        echo '<p>Generated UTC: '.esc_html((string)$r['generated_at']).'</p></div>';
    }
    private static function row(string $label,string $value):void{echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';}
}
