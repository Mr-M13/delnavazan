<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\PaymentExecution\StripeTestModeReadinessService;
use Delnavazan\Platform\Integrations\IntegrationTestRunner;

/** Workflow-first integration settings. Secret inputs are write-only and safe checks make no provider calls. */
final class IntegrationControlPlaneController {
    public static function register():void{add_action('admin_post_dzn_integration_settings_save',array(__CLASS__,'save'));add_action('admin_post_dzn_integration_settings_test',array(__CLASS__,'test'));}
    public static function save():void{self::handle('save');}
    public static function test():void{self::handle('test');}
    private static function handle(string $operation):void{
        self::authorizeSettings();$provider=sanitize_key((string)($_POST['provider']??''));
        check_admin_referer('dzn_integration_'.$operation.'_'.$provider);
        $service=new IntegrationSettingsService();
        if($operation==='save'){$service->save($provider,(array)($_POST['settings']??array()));$result='saved';}
        else{
            if(!isset($_POST['run_test']))wp_die('Explicit test action required',400);
            $evidence=(new IntegrationTestRunner())->run($provider,(array)($_POST['test']??array()));
            $result=$evidence['state'].'_'.$evidence['code'];
            update_option('dzn_integration_test_'.$provider,array('state'=>$evidence['state'],'code'=>$evidence['code'],'at'=>current_time('mysql',true)),false);
        }
        wp_safe_redirect(add_query_arg(array('page'=>'dzn-integration-'.$provider,'integration_result'=>$result),admin_url('admin.php')));exit;
    }
    private static function authorizeSettings():void{if(!current_user_can('manage_options')&&!current_user_can('dzn_manage_provider_integrations'))wp_die('Forbidden',403);}
    public static function googleSettings():void{self::settingsForm('google');}
    public static function whatsAppSettings():void{self::settingsForm('whatsapp');}
    public static function smsSettings():void{self::settingsForm('sms');}
    public static function emailSettings():void{self::settingsForm('email');}

    /** Stripe configuration/readiness remains with Payment Execution. */
    public static function stripeSettings():void{
        if(!current_user_can(PaymentExecutionController::CAPABILITY))wp_die('Forbidden',403);
        $readiness=(new StripeTestModeReadinessService())->read();
        echo '<div class="wrap"><h1>Stripe setup</h1><p>Stripe test-mode readiness is secret-free. Production activation remains disabled.</p><p><strong>'.esc_html($readiness['ready']?'Ready':'Setup is incomplete').'</strong></p><table class="widefat striped"><tbody>';
        foreach($readiness['checks'] as $key=>$check)echo '<tr><th>'.esc_html((string)$key).'</th><td>'.esc_html((string)$check['state']).'</td><td>'.esc_html((string)$check['detail']).'</td></tr>';
        echo '</tbody></table><p>Payment execution and reconciliation are under Finance.</p></div>';
    }
    private static function settingsForm(string $provider):void{
        self::authorizeSettings();$service=new IntegrationSettingsService();$configs=$service->redacted($provider);$schema=IntegrationSettingsService::providerSchemas()[$provider]??array();
        $labels=array('email'=>'Email','whatsapp'=>'Meta WhatsApp Cloud API','sms'=>'SMS fallback','google'=>'Google OAuth / Calendar');
        $safe=array('email'=>'Sends an explicitly requested TEST email to the entered destination. Mailer acceptance is not mailbox delivery.','whatsapp'=>'Sends the configured Meta test template to the entered number only after you press the test button.','sms'=>'Sends a clearly marked Telnyx test SMS to the entered number only after you press the test button.','google'=>'Tests local OAuth configuration separately from Google OAuth discovery reachability. It does not authorize or impersonate a teacher.');
        $last=(array)get_option('dzn_integration_test_'.$provider,array());
        echo '<div class="wrap"><h1>'.esc_html($labels[$provider]??$provider).'</h1><p>Credentials are encrypted at rest and never displayed. Leave a secret blank to keep its current value.</p>';
        if(isset($_GET['integration_result']))echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field((string)$_GET['integration_result'])).'</p></div>';
        echo '<p>Readiness: <strong>'.esc_html($service->ready($provider)?'Ready':'Needs configuration').'</strong>';
        if($last)echo ' · Last explicit test: '.esc_html((string)($last['state']??'unknown')).' ('.esc_html((string)($last['code']??'')).') at '.esc_html((string)($last['at']??''));echo '</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_integration_settings_save"><input type="hidden" name="provider" value="'.esc_attr($provider).'">';wp_nonce_field('dzn_integration_save_'.$provider);
        echo '<table class="form-table"><tbody>';
        foreach($schema as $field=>$type){$label=ucwords(str_replace('_',' ',$field));$secret=$type==='secret';$value=$configs[$field]??'';if($provider==='email'&&$field==='from_email'&&$value==='')$value='no-reply@delnavazan.com';if($provider==='email'&&$field==='from_name'&&$value==='')$value='Delnavazan';if($provider==='google'&&$field==='redirect_uri'&&$value==='')$value=admin_url('admin-post.php?action=dzn_google_oauth_callback');
            echo '<tr><th><label for="dzn-'.esc_attr($field).'">'.esc_html($label).'</label></th><td>';
            if($field==='mode')echo '<select id="dzn-mode" name="settings[mode]"><option value="host_mail"'.selected((string)$value,'host_mail',false).'>Host mail</option><option value="smtp"'.selected((string)$value,'smtp',false).'>SMTP</option></select>';
            elseif($field==='encryption')echo '<select id="dzn-encryption" name="settings[encryption]"><option value="tls"'.selected((string)$value,'tls',false).'>TLS</option><option value="ssl"'.selected((string)$value,'ssl',false).'>SSL</option><option value="none"'.selected((string)$value,'none',false).'>None</option></select>';
            else echo '<input class="regular-text" id="dzn-'.esc_attr($field).'" name="settings['.esc_attr($field).']" type="'.($secret?'password':'text').'" value="'.($secret?'':esc_attr((string)$value)).'" autocomplete="'.($secret?'new-password':'off').'" placeholder="'.($secret?(!empty($value)?'Configured; enter a new value to replace':'Enter secret'):'').'">';
            echo '</td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">Save settings</button></p></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_integration_settings_test"><input type="hidden" name="provider" value="'.esc_attr($provider).'">';wp_nonce_field('dzn_integration_test_'.$provider);
        if(in_array($provider,array('email','whatsapp','sms'),true))echo '<p><label>Test destination <input required class="regular-text" name="test[destination]" type="'.($provider==='email'?'email':'tel').'" autocomplete="off"></label></p>';
        echo '<p>'.esc_html($safe[$provider]??'').'</p><p><button class="button" name="run_test" value="1">Send explicit test</button></p></form></div>';
    }
    public static function screen():void{
        if(!current_user_can(IntegrationControlPlaneReadService::READ_CAPABILITY))wp_die('Forbidden',403);
        $r=(new IntegrationControlPlaneReadService())->report();
        $settings=new IntegrationSettingsService();
        echo '<div class="wrap"><h1>Integrations</h1><p>Local readiness is separate from explicit provider tests. Test actions do not participate in notification delivery.</p><h2>Notification routing</h2><p>WhatsApp primary → email supporting channel → Telnyx SMS fallback.</p><ul>';
        foreach(array('email'=>'Email settings','whatsapp'=>'Meta WhatsApp Cloud API settings','sms'=>'SMS fallback settings','google'=>'Google OAuth / Calendar settings') as $provider=>$label)echo '<li><a href="'.esc_url(admin_url('admin.php?page=dzn-integration-'.$provider)).'">'.esc_html($label).'</a> — '.esc_html($settings->ready($provider)?'Ready':'Needs configuration').'</li>';
        echo '</ul><h2>Current runtime signals</h2><table class="widefat striped"><tbody><tr><th>Email invitation transport</th><td>'.esc_html((string)$r['email']['invitation_transport']).'</td></tr><tr><th>Notification runtime</th><td>'.esc_html((string)$r['notifications']['runtime']).'</td></tr>';
        foreach($r['notifications']['channels'] as $channel=>$state)echo '<tr><th>'.esc_html(ucfirst($channel)).' binding</th><td>'.esc_html((string)$state).'</td></tr>';
        echo '<tr><th>Google teacher calendar</th><td>'.esc_html((string)$r['google_calendar']['oauth_client']).'</td></tr></tbody></table><h2>Google teacher connection</h2><p>Teachers connect or disconnect their own account from their account settings. Calendar entries are projections of Delnavazan’s canonical schedule; Delnavazan remains authoritative.</p><table class="widefat striped"><tbody>';
        foreach($r['google_calendar']['projection_states'] as $state=>$count)echo '<tr><th>Calendar projection: '.esc_html((string)$state).'</th><td>'.esc_html((string)$count).'</td></tr>';
        echo '</tbody></table><h2>Stripe</h2><p>Use Payment Execution for Stripe test readiness. Production activation remains separately gated.</p></div>';
    }
}
