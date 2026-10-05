<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;

/** Central, capability-gated operator view; provider credentials are never accepted or rendered here. */
final class IntegrationControlPlaneController {
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
        echo '</tbody></table><h2>Google</h2><table class="widefat striped"><tbody>';
        self::row('Implemented capability',$r['google_calendar']['capability']);
        self::row('OAuth client configuration',$r['google_calendar']['oauth_client']);
        self::row('OAuth credentials source',$r['google_calendar']['configuration_source']);
        self::row('Connection test',$r['google_calendar']['test_connection']);
        echo '</tbody></table><h2>Stripe</h2><p>Use Payment Execution for secret-free Stripe test-mode readiness. Production payment activation remains separately gated.</p>';
        echo '<p>WhatsApp and SMS have transport seams only. Choose providers and supply deployment credentials before enabling either channel. No provider test, message, or payment is sent from this screen.</p>';
        echo '<p>Generated UTC: '.esc_html((string)$r['generated_at']).'</p></div>';
    }
    private static function row(string $label,string $value):void{echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';}
}
