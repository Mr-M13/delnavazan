<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\PaymentExecution\StripeTestModeReadinessService;
use Delnavazan\Platform\Core\Application\PaymentExecution\StripeTestConfigurationService;
use Delnavazan\Platform\Integrations\IntegrationTestRunner;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfigurationService;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateDiscoveryService;
use Delnavazan\Platform\Integrations\Notifications\TelnyxWebhookController;

/** Workflow-first integration settings. Secret inputs are write-only and safe checks make no provider calls. */
final class IntegrationControlPlaneController {
    public static function register():void{add_action('admin_post_dzn_integration_settings_save',array(__CLASS__,'save'));add_action('admin_post_dzn_integration_settings_test',array(__CLASS__,'test'));add_action('admin_post_dzn_telnyx_template_sync',array(__CLASS__,'syncTelnyxTemplates'));add_action('admin_post_dzn_telnyx_public_key_sync',array(__CLASS__,'syncTelnyxPublicKey'));add_action('admin_post_dzn_telnyx_webhook_sync',array(__CLASS__,'syncTelnyxWebhook'));add_action('admin_post_dzn_stripe_test_settings_save',array(__CLASS__,'saveStripeTestSettings'));add_action('admin_post_dzn_stripe_test_connection',array(__CLASS__,'testStripeConnection'));add_action('admin_post_dzn_stripe_test_activation',array(__CLASS__,'setStripeTestActivation'));}
    /** Explicit provider-template discovery. It reads templates and writes local mappings; it never sends. */
    public static function syncTelnyxTemplates():void{
        self::authorizeSettings();check_admin_referer('dzn_telnyx_template_sync');
        $result=(new TelnyxTemplateDiscoveryService(new IntegrationSettingsService()))->sync();
        update_option('dzn_telnyx_template_sync_result',array(
            'ok'=>(bool)($result['ok']??false),'code'=>(string)($result['error']??'synced'),
            'discovered'=>(int)($result['discovered']??0),'approved'=>(int)($result['approved']??0),
            'mapped'=>(int)($result['mapped']??0),'at'=>current_time('mysql',true),
        ),false);
        wp_safe_redirect(add_query_arg(array('page'=>'dzn-integration-whatsapp','telnyx_templates'=>($result['ok']??false)?'synced':'failed'),admin_url('admin.php')));exit;
    }
    /** Fetch the account public signing key. Public configuration; never an API key. */
    public static function syncTelnyxPublicKey():void{
        self::authorizeSettings();check_admin_referer('dzn_telnyx_public_key_sync');
        $settings=new IntegrationSettingsService();$result=(new TelnyxConfigurationService($settings))->publicKey();
        if($result['ok']??false){$settings->save('sms',array('webhook_public_key'=>(string)$result['public_key']));$settings->save('whatsapp',array('webhook_public_key'=>(string)$result['public_key']));}
        update_option('dzn_telnyx_public_key_sync_result',array('ok'=>(bool)($result['ok']??false),'code'=>(string)($result['error']??'synced'),'at'=>current_time('mysql',true)),false);
        wp_safe_redirect(add_query_arg(array('page'=>'dzn-integration-whatsapp','telnyx_public_key'=>($result['ok']??false)?'synced':'failed'),admin_url('admin.php')));exit;
    }
    /** Point the Messaging Profile at the local signed webhook and confirm the read-after-write value. */
    public static function syncTelnyxWebhook():void{
        self::authorizeSettings();check_admin_referer('dzn_telnyx_webhook_sync');
        $result=(new TelnyxConfigurationService(new IntegrationSettingsService()))->setWebhook(TelnyxWebhookController::url());
        update_option('dzn_telnyx_webhook_sync_result',array('ok'=>(bool)($result['ok']??false),'code'=>(string)($result['error']??'synced'),'at'=>current_time('mysql',true)),false);
        wp_safe_redirect(add_query_arg(array('page'=>'dzn-integration-whatsapp','telnyx_webhook'=>($result['ok']??false)?'synced':'failed'),admin_url('admin.php')));exit;
    }
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
            $record=array('state'=>$evidence['state'],'code'=>$evidence['code'],'at'=>current_time('mysql',true));
            if($provider==='email'&&isset($evidence['diagnostic'])&&is_array($evidence['diagnostic']))$record['diagnostic']=$evidence['diagnostic'];
            if(in_array($provider,array('whatsapp','sms'),true)){
                $safe=array();foreach(array('http','template','language','provider_code','provider_title','provider_detail','reason') as $key)if(array_key_exists($key,$evidence))$safe[$key]=$evidence[$key];
                if($safe!==array())$record['diagnostic']=$safe;
            }
            update_option('dzn_integration_test_'.$provider,$record,false);
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
        echo '<div class="wrap"><h1>Stripe setup</h1>';
        self::stripeSettingsContent(false);
        echo '</div>';
    }

    /** Render the Stripe configuration/readiness body inside either the Stripe page or Finance → Settings. */
    public static function stripeSettingsContent(bool $embedded=false):void{
        if(!current_user_can(PaymentExecutionController::CAPABILITY))wp_die('Forbidden',403);
        $configuration=new StripeTestConfigurationService();$saved=$configuration->savedState();$readiness=(new StripeTestModeReadinessService())->read();$last=(array)get_option('dzn_stripe_test_connection_result',array());
        $testEnvironment=StripeTestConfigurationService::testEnvironmentAllowed();
        $production=function_exists('wp_get_environment_type')&&(string)wp_get_environment_type()==='production';
        $preLaunchActive=$production&&StripeTestConfigurationService::preLaunchProductionTestModeActive();
        echo '<p>Configure Stripe sandbox credentials on a recognised test environment. Pre-launch production TEST mode is available only while all three explicit gates are active. Secret keys are encrypted and write-only. Live credentials and live payment execution are prohibited.</p>';
        if($preLaunchActive)echo '<div class="notice notice-error"><p><strong>PRE-LAUNCH PRODUCTION TEST MODE IS ACTIVE.</strong> Only Stripe TEST credentials and test Checkout are permitted. Disable it immediately by removing <code>DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED</code>, <code>DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED</code>, or <code>DZN_PLATFORM_PAYMENT_TEST_VAULT</code> from the production configuration.</p></div>';
        elseif(!$testEnvironment)echo '<div class="notice notice-warning"><p>Stripe test configuration and Test Connection are disabled. Production requires the explicit pre-launch production TEST-mode gate plus the operator authorization and disposable test-vault gates.</p></div>';
        if(isset($_GET['stripe_result'])){$result=sanitize_key((string)$_GET['stripe_result']);$messages=array(
            'saved'=>'Stripe test configuration saved.','success'=>'Stripe accepted the test key and confirmed sandbox mode.',
            'failed'=>'Stripe test connection failed. Check the test key and try again.',
            'blocked'=>'Stripe test operation is not ready. Check the environment, operator, vault, and readiness gates below.',
            'invalid_stripe_test_publishable_key'=>'The publishable key must be a valid Stripe TEST key (pk_test_). No credentials were saved.',
            'invalid_stripe_test_secret_key'=>'The secret key must be a valid Stripe TEST key (sk_test_). No credentials were saved.',
            'invalid_stripe_test_webhook_secret'=>'The webhook signing secret must be a valid Stripe TEST secret (whsec_). No credentials were saved.',
            'complete_stripe_test_configuration_required'=>'Enter the publishable key, secret key, and webhook signing secret together for the first save. No credentials were saved.',
            'stripe_test_environment_required'=>'The production TEST-mode gates are not active for this request. No credentials were saved.',
            'stripe_account_scope_ambiguous'=>'The Stripe account state is ambiguous, so the save was refused. No credentials were saved.',
            'stripe_test_account_state_unavailable'=>'The existing Stripe TEST account is not in a configurable state. No credentials were saved.',
            'stripe_test_secret_store_refused'=>'The encrypted TEST credential vault refused a write. Some fields may have been saved; review readiness before retrying. Credential values are never shown here.',
            'stripe_persistence_duplicate'=>'A Stripe TEST configuration record conflicts with existing stored state. Review readiness and contact support before retrying.',
            'stripe_persistence_schema'=>'The Stripe TEST storage schema is unavailable or out of date. Do not re-enter credentials until the storage issue is resolved.',
            'stripe_persistence_transient'=>'The Stripe TEST storage transaction could not complete. Some fields may have been saved; review readiness before retrying.',
            'stripe_persistence_failed'=>'The Stripe TEST storage operation failed. Some fields may have been saved; review readiness before retrying.',
            'stripe_test_configuration_persistence_unconfirmed'=>'The Stripe TEST save could not be confirmed by a read-after-write check. Some fields may have been saved; review readiness before retrying.',
            'stripe_configuration_unauthorized'=>'Your account lacks the Stripe provider-configuration capability. No credentials were saved.',
            'save_failed'=>'Stripe test configuration could not be confirmed. Check readiness before repeating the save; credential values are never shown here.'
        );echo '<div class="notice notice-info"><p>'.esc_html($messages[$result]??'Stripe operation completed.').'</p></div>';}
        $webhookUrl=rest_url('delnavazan-platform/v1/payment-provider-events/stripe/'.$configuration->webhookReference());
        echo '<h2>Test mode configuration</h2><p>Webhook endpoint: <code>'.esc_html($webhookUrl).'</code></p>';
        if($testEnvironment){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_stripe_test_settings_save">'.($embedded?'<input type="hidden" name="return_to_finance" value="1">':'');wp_nonce_field('dzn_stripe_test_settings_save');
        echo '<table class="form-table"><tbody><tr><th><label for="stripe-pk">Publishable key</label></th><td><input class="regular-text" id="stripe-pk" name="settings[publishable_key]" value="'.esc_attr($configuration->publishableKey()).'" placeholder="pk_test_…" autocomplete="off"></td></tr>';
        foreach(array('secret_key'=>'Secret key','webhook_signing_secret'=>'Webhook signing secret') as $field=>$label){$has=$field==='secret_key'?$saved['secret']:$saved['webhook'];echo '<tr><th><label for="stripe-'.esc_attr($field).'">'.esc_html($label).'</label></th><td><input class="regular-text" type="password" id="stripe-'.esc_attr($field).'" name="settings['.esc_attr($field).']" value="" autocomplete="new-password" placeholder="'.($has?'•••••••••••• (configured; leave blank to keep)':'Enter Stripe test credential').'"></td></tr>';}
        echo '</tbody></table><p><button class="button button-primary">Save test configuration</button></p></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_stripe_test_connection">'.($embedded?'<input type="hidden" name="return_to_finance" value="1">':'');wp_nonce_field('dzn_stripe_test_connection');echo '<p><button class="button" name="run_test" value="1">Test Connection</button>';
        if($last)echo ' · Last test: '.esc_html((string)($last['state']??'unknown')).' ('.esc_html((string)($last['code']??'')).') at '.esc_html((string)($last['at']??''));echo '</p></form>';
        echo '<h2>Deliberate Checkout activation</h2><p>Requires the environment and operator gates, one complete Stripe TEST credential set, and one active test account. No live credentials or real charges are supported.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_stripe_test_activation">'.($embedded?'<input type="hidden" name="return_to_finance" value="1">':'');wp_nonce_field('dzn_stripe_test_activation');
        if(!empty($saved['execution_enabled']))echo '<button class="button" name="disable_test_checkout" value="1">Disable Stripe test Checkout</button>';
        else echo '<button class="button button-secondary" name="enable_test_checkout" value="1">Enable Stripe test Checkout</button>';
        echo '</form>';
        }else{echo '<p>Credential entry and outbound test requests are unavailable in production.</p>';}
        echo '<h2>Stripe test-mode readiness</h2><p>'.esc_html(self::stripeReadinessSummary($readiness)).'</p>';
        echo '<p>End-to-end settlement: <strong>'.esc_html($readiness['settlement_verified']?'verified':'not yet verified').'</strong>. '.esc_html((string)$readiness['settlement_detail']).'</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach($readiness['checks'] as $key=>$check)echo '<tr><th>'.esc_html((string)$key).'</th><td>'.esc_html((string)$check['state']).'</td><td>'.esc_html((string)$check['detail']).'</td></tr>';
        echo '</tbody></table><p>Payment execution and reconciliation are under Finance.</p>';
    }
    /** Return to the consolidated Finance settings tab when the embedded form requested it. */
    private static function redirectStripe(string $result):never{
        $target=isset($_POST['return_to_finance'])&&(string)$_POST['return_to_finance']==='1'
            ? add_query_arg(array('page'=>FinanceAdminController::MENU_SLUG,'tab'=>FinanceAdminController::TAB_SETTINGS,'stripe_result'=>$result),admin_url('admin.php'))
            : add_query_arg(array('page'=>'dzn-stripe-settings','stripe_result'=>$result),admin_url('admin.php'));
        wp_safe_redirect($target);exit;
    }
    /** Activation is an independent setting; effective readiness still requires every prerequisite. */
    public static function stripeReadinessSummary(array $readiness):string{
        return ($readiness['ready']?'Configuration ready':'Setup is incomplete')
            .' · Checkout '.(($readiness['checks']['checkout_enabled']['state']??'blocked')==='ready'?'enabled':'not enabled')
            .' · Test payment readiness: '.($readiness['checkout_ready']?'ready':'blocked');
    }
    public static function setStripeTestActivation():void{
        self::authorizeStripeSettings();check_admin_referer('dzn_stripe_test_activation');
        $enable=isset($_POST['enable_test_checkout']);
        try{
            $service=new StripeTestConfigurationService();
            if($enable)$service->activateTestCheckout();else $service->deactivateTestCheckout();
            $result='saved';
        }catch(\Throwable){$result='blocked';}
        self::redirectStripe($result);
    }
    public static function saveStripeTestSettings():void{
        self::authorizeStripeSettings();check_admin_referer('dzn_stripe_test_settings_save');
        try{(new StripeTestConfigurationService())->save((array)($_POST['settings']??array()));$result='saved';}
        catch(\Throwable $e){$result=self::safeStripeSaveResult($e);}
        self::redirectStripe($result);
    }
    /** Map only known fixed errors to user-facing codes. Never reflect exception text or credential data. */
    public static function safeStripeSaveResult(\Throwable $error):string{
        $allowed=array(
            'invalid_stripe_test_publishable_key','invalid_stripe_test_secret_key','invalid_stripe_test_webhook_secret',
            'complete_stripe_test_configuration_required','stripe_test_environment_required','stripe_account_scope_ambiguous',
            'stripe_test_account_state_unavailable','stripe_test_secret_store_refused'
        );
        if(in_array($error->getMessage(),$allowed,true))return $error->getMessage();
        if($error->getMessage()==='Unauthorized')return 'stripe_configuration_unauthorized';
        if($error instanceof \Delnavazan\Platform\Core\Infrastructure\Repository\PersistenceException){
            return match($error->errno){
                1062=>'stripe_persistence_duplicate',
                1054,1146=>'stripe_persistence_schema',
                1205,1213=>'stripe_persistence_transient',
                default=>'stripe_persistence_failed',
            };
        }
        // Repository update and transaction wrappers include database text in their exception
        // message. Classify the boundary only; never return that text to the browser.
        if(str_starts_with($error->getMessage(),'Payment secret persistence failed:')
            ||str_starts_with($error->getMessage(),'Payment provider persistence failed:')
            ||str_starts_with($error->getMessage(),'Transaction '))return 'stripe_persistence_failed';
        return 'save_failed';
    }
    public static function testStripeConnection():void{
        self::authorizeStripeSettings();check_admin_referer('dzn_stripe_test_connection');if(!isset($_POST['run_test']))wp_die('Explicit test action required',400);
        $result=(new StripeTestConfigurationService())->testConnection();$state=(string)$result['state'];$code=(string)$result['code'];
        update_option('dzn_stripe_test_connection_result',array('state'=>$state,'code'=>$code,'at'=>current_time('mysql',true)),false);
        self::redirectStripe($state==='success'?'success':($state==='failed'?'failed':'blocked'));
    }
    private static function authorizeStripeSettings():void{if(!current_user_can(PaymentExecutionController::CAPABILITY)||!current_user_can('dzn_manage_payment_providers'))wp_die('Forbidden',403);}
    private static function settingsForm(string $provider):void{
        self::authorizeSettings();$service=new IntegrationSettingsService();$configs=$service->redacted($provider);$schema=IntegrationSettingsService::providerSchemas()[$provider]??array();
        $labels=array('email'=>'Email','whatsapp'=>'WhatsApp (Telnyx / Meta WABA)','sms'=>'SMS fallback (Telnyx)','google'=>'Google Workspace classroom');
        $safe=array('email'=>'Sends an explicitly requested TEST email to the entered destination. Mailer acceptance is not mailbox delivery.','whatsapp'=>'Uses the approved Meta WhatsApp Cloud API template through Telnyx. The test sends only after you press the button.','sms'=>'Sends a clearly marked Telnyx test SMS to the entered number only after you press the test button.','google'=>'Tests local OAuth configuration. The organizer connection is authorized separately and only by an administrator.');
        $telnyx=new TelnyxConfiguration($service);
        $last=(array)get_option('dzn_integration_test_'.$provider,array());
        echo '<div class="wrap"><h1>'.esc_html($labels[$provider]??$provider).'</h1><p>Credentials are encrypted at rest and never displayed. Leave a secret blank to keep its current value.</p>';
        if(isset($_GET['integration_result'])){$result=sanitize_text_field((string)$_GET['integration_result']);echo '<div class="notice notice-info"><p>'.esc_html($provider==='email'?self::emailResultMessage($result):$result).'</p></div>';}
        echo '<p>Readiness: <strong>'.esc_html($service->ready($provider)?'Ready':'Needs configuration').'</strong>';
        if($last){$lastCode=(string)($last['code']??'');echo ' · Last explicit test: '.esc_html((string)($last['state']??'unknown')).' ('.esc_html($provider==='email'?self::emailCodeMessage($lastCode):$lastCode).') at '.esc_html((string)($last['at']??''));}echo '</p>';
        if($provider==='whatsapp'){
            echo '<h2>WhatsApp via Telnyx and Meta WABA</h2><p>These are the existing WhatsApp provider fields. Saved values are preserved and secrets remain write-only. The fields below describe provider configuration only and never enable a send by themselves.</p>';
            echo '<div class="notice notice-warning inline"><p><strong>Twilio WhatsApp is not implemented in this platform.</strong> No Twilio WhatsApp credentials are read, stored or validated here, and this page cannot enable a Twilio WhatsApp send. The configured WhatsApp route uses Telnyx and approved Meta WABA templates.</p></div>';
            echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-notifications&tab=templates&channel=whatsapp')).'">Manage WhatsApp template mapping</a></p>';
        }
        if($provider==='email'){
            echo '<p>The dedicated teacher invitation email has its own one-time-secret delivery authority. <a href="'.esc_url(admin_url('admin.php?page=dzn-communications-templates')).'">Open the Teacher invitation template</a>.</p>';
        }
        if($provider==='google'){
            $health=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceOAuthService())->health();
            $state=(string)($health['state']??'not_connected');
            echo '<h2>Delnavazan Workspace organizer</h2><p>The single organizer account creates every Meet space. Teachers are invited as external co-hosts and never authorize Delnavazan against their personal Google account.</p>';
            echo '<table class="widefat striped"><tbody><tr><th>Organizer</th><td><code>'.esc_html((string)($health['organizer_email']??'')).'</code></td></tr><tr><th>Connection</th><td>'.esc_html($state).'</td></tr><tr><th>Required scopes</th><td>'.esc_html(!empty($health['scope_match'])?'exact match':'re-consent required').'</td></tr><tr><th>Credential</th><td>'.esc_html(!empty($health['credential_valid'])?'valid':'missing or expired').'</td></tr><tr><th>Last checked</th><td>'.esc_html((string)($health['last_checked_at']??'never')).'</td></tr></tbody></table>';
            echo '<p><strong>Authorized redirect URI:</strong> <code>'.esc_html((string)($health['callback_url']??'')).'</code></p>';
            echo '<p>Granted scopes: Calendar events, Meet spaces and members, Meet space settings, Meet conference records, Drive read-only, and organizer e-mail identity. Recording is configured only when all recording-required consent purposes are current.</p>';
            if(in_array($state,array('connected','refresh_failed'),true)){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_meet_connect">';wp_nonce_field('dzn_meet_connect');echo '<button class="button">Re-authorize organizer</button></form> ';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_meet_disconnect">';wp_nonce_field('dzn_meet_disconnect');echo '<button class="button">Disconnect locally</button></form> ';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_meet_revoke">';wp_nonce_field('dzn_meet_revoke');echo '<button class="button button-link-delete">Revoke Google grant</button></form>';
            }else{
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_meet_connect">';wp_nonce_field('dzn_meet_connect');echo '<button class="button button-primary">Connect organizer account</button></form>';
            }
            if(isset($_GET['meet_result']))echo '<div class="notice notice-info inline"><p>'.esc_html(sanitize_text_field((string)$_GET['meet_result'])).'</p></div>';
        }
        if(in_array($provider,array('sms','whatsapp'),true)){
            $keyCheck=(array)get_option('dzn_telnyx_public_key_sync_result',array());
            $hookCheck=(array)get_option('dzn_telnyx_webhook_sync_result',array());
            echo '<h2>Verified webhook</h2><p>Configure this URL on the Telnyx Messaging Profile. Telnyx signs every request with Ed25519; the public key is not a secret.</p>';
            echo '<p><strong>Webhook URL:</strong> <code>'.esc_html(TelnyxWebhookController::url()).'</code></p>';
            echo '<p><strong>Signature verification:</strong> '.esc_html($telnyx->webhookReady()?'configured':'not configured — enter or fetch the Telnyx public key below').'</p>';
            if($keyCheck)echo '<p>Last public-key fetch: '.esc_html(($keyCheck['ok']??false)?'succeeded':'failed').' · '.esc_html((string)($keyCheck['code']??'')).' · '.esc_html((string)($keyCheck['at']??'')).'</p>';
            if($hookCheck)echo '<p>Last webhook check: '.esc_html(($hookCheck['ok']??false)?'configured and confirmed':'failed').' · '.esc_html((string)($hookCheck['code']??'')).' · '.esc_html((string)($hookCheck['at']??'')).'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_telnyx_public_key_sync">';wp_nonce_field('dzn_telnyx_public_key_sync');echo '<button class="button" name="fetch_public_key" value="1">Fetch Telnyx public key</button></form> ';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dzn-notif-inline-form"><input type="hidden" name="action" value="dzn_telnyx_webhook_sync">';wp_nonce_field('dzn_telnyx_webhook_sync');echo '<button class="button" name="configure_webhook" value="1">Verify and configure webhook</button></form>';
        }
        if($provider==='whatsapp'){
            try{$discovery=(new TelnyxTemplateDiscoveryService($service))->status();}
            catch(\Throwable $error){$discovery=array('counts'=>array(),'templates'=>array());}
            $sync=(array)get_option('dzn_telnyx_template_sync_result',array());
            echo '<h2>Approved template discovery</h2><p>Only an explicit refresh reads the WABA. Discovery never sends a message.</p>';
            echo '<table class="widefat striped"><tbody>';
            foreach(array('approved'=>'Approved','pending'=>'Pending','rejected'=>'Rejected','other'=>'Other','mapped'=>'Mapped to an event','unmapped'=>'Unmapped','variable_mismatch'=>'Variable contract mismatch') as $key=>$label)echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)(int)($discovery['counts'][$key]??0)).'</td></tr>';
            echo '</tbody></table>';
            if($sync)echo '<p>Last refresh: '.esc_html(($sync['ok']??false)?'succeeded':'failed').' · code '.esc_html((string)($sync['code']??'')).' · '.esc_html((string)(int)($sync['discovered']??0)).' discovered, '.esc_html((string)(int)($sync['mapped']??0)).' mapped · '.esc_html((string)($sync['at']??'')).'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_telnyx_template_sync">';wp_nonce_field('dzn_telnyx_template_sync');echo '<p><button class="button" name="refresh_templates" value="1">Refresh approved templates</button> <span class="description">Read-only provider call; no message is sent.</span></p></form>';
        }
        if(in_array($provider,array('whatsapp','sms'),true)&&!empty($last['diagnostic'])&&is_array($last['diagnostic'])){
            echo '<h2>Safe provider test detail</h2><p>Destination, phone numbers and credentials are never stored or shown.</p><table class="widefat striped"><tbody>';
            foreach(array('http'=>'HTTP status','template'=>'Template','language'=>'Language','provider_code'=>'Provider code','provider_title'=>'Provider title','provider_detail'=>'Provider detail','reason'=>'Reason') as $key=>$label){
                $value=$last['diagnostic'][$key]??null;if($value===null||$value==='')continue;
                echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';
            }
            echo '</tbody></table>';
        }
        if($provider==='email'&&!empty($last['diagnostic'])&&is_array($last['diagnostic']))self::renderEmailDiagnostic($last['diagnostic']);
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_integration_settings_save"><input type="hidden" name="provider" value="'.esc_attr($provider).'">';wp_nonce_field('dzn_integration_save_'.$provider);
        echo '<table class="form-table"><tbody>';
        foreach($schema as $field=>$type){$label=ucwords(str_replace('_',' ',$field));$secret=$type==='secret';$value=$configs[$field]??'';if($provider==='email'&&$field==='from_email'&&$value==='')$value='no-reply@delnavazan.com';if($provider==='email'&&$field==='from_name'&&$value==='')$value='Delnavazan';if($provider==='google'&&$field==='redirect_uri'&&$value==='')$value=admin_url('admin-post.php?action=dzn_google_oauth_callback');if($provider==='google'&&$field==='meet_redirect_uri'&&$value==='')$value=admin_url('admin-post.php?action=dzn_meet_oauth_callback');
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
    private static function emailResultMessage(string $result):string{
        if($result==='success_mailer_accepted')return 'The mail server accepted the test message. Check the destination inbox and spam folder to confirm delivery.';
        $prefix='failed_';return str_starts_with($result,$prefix)?self::emailCodeMessage(substr($result,strlen($prefix))):$result;
    }
    private static function emailCodeMessage(string $code):string{return match($code){
        'smtp_tls_failed'=>'SMTP TLS/SSL negotiation failed. Check the selected encryption mode, port, and server certificate.',
        'smtp_authentication_failed'=>'SMTP authentication was rejected. Check the mailbox username and password with the mail host.',
        'smtp_connection_failed'=>'Could not connect to the SMTP server. Check the host, port, firewall, and server availability.',
        'smtp_sender_rejected'=>'The SMTP server rejected the From address. Confirm that this mailbox is allowed to send as the configured sender.',
        'smtp_recipient_rejected'=>'The SMTP server rejected the test destination. Confirm the destination address is valid and allowed by the mail host.',
        'smtp_message_rejected'=>'The SMTP server rejected the message content or sending policy. Ask the mail host to review its rejection policy.',
        'mailer_rejected_unknown'=>'The mailer rejected the message but did not provide a classifiable reason. Check the host mail logs for this test time.',
        'mailer_accepted'=>'The mail server accepted the test message; recipient delivery is not confirmed.',
        default=>$code,
    };}
    /** Capability-gated safe details for the most recent explicit email test. */
    private static function renderEmailDiagnostic(array $diagnostic):void{
        $effective=(array)($diagnostic['effective']??array());
        echo '<h2>Safe SMTP runtime evidence</h2><p>Passwords, usernames, addresses, message content, and raw server text are omitted.</p><table class="widefat striped"><tbody>';
        foreach(array('host'=>'Effective Host','port'=>'Effective Port','smtp_secure'=>'SMTPSecure','smtp_auto_tls'=>'SMTPAutoTLS','smtp_auth'=>'SMTPAuth') as $key=>$label){
            $value=$effective[$key]??'';if(is_bool($value))$value=$value?'true':'false';
            echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';
        }
        foreach(array('verify_peer'=>'TLS verify_peer','verify_peer_name'=>'TLS verify_peer_name','allow_self_signed'=>'TLS allow_self_signed','peer_name'=>'TLS peer_name') as $key=>$label){
            $value=((array)($effective['tls_context']??array()))[$key]??'runtime default';if(is_bool($value))$value=$value?'true':'false';
            echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';
        }
        foreach(array('php_version'=>'PHP version','sapi'=>'PHP SAPI','openssl_extension'=>'OpenSSL extension','ssl_tls_transport_available'=>'SSL/TLS transport available','stream_socket_client_enabled'=>'Socket stream enabled','open_basedir_enabled'=>'open_basedir enabled','default_ca_file_exists'=>'Default CA file available','default_ca_path_exists'=>'Default CA path available','openssl_cafile_configured'=>'openssl.cafile configured','openssl_cafile_readable'=>'openssl.cafile readable','openssl_capath_configured'=>'openssl.capath configured','openssl_capath_readable'=>'openssl.capath readable','default_ca_file_readable'=>'Default CA file readable','default_ca_path_readable'=>'Default CA path readable','proxy_environment_configured'=>'Proxy environment configured','mailer_class'=>'PHPMailer class','mailer_file'=>'PHPMailer source file','phpmailer_version'=>'PHPMailer version','smtp_class_file'=>'SMTP source file') as $key=>$label){
            $value=((array)($diagnostic['runtime']??array()))[$key]??'unknown';if(is_bool($value))$value=$value?'yes':'no';
            echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';
        }
        $probe=(array)($diagnostic['stream_probe']??array());
        echo '<tr><th>PHP SSL stream probe</th><td>'.esc_html((string)($probe['state']??'unavailable')).'</td></tr>';
        echo '<tr><th>PHP SSL stream failure category</th><td>'.esc_html((string)($probe['category']??'unknown')).'</td></tr>';
        echo '<tr><th>PHP SSL stream errno</th><td>'.(int)($probe['errno']??0).'</td></tr>';
        echo '<tr><th>PHPMailer SSL context option names</th><td>'.esc_html(implode(', ',array_map('strval',(array)($probe['ssl_context_keys']??array())))).'</td></tr>';
        echo '<tr><th>PHPMailer socket context option names</th><td>'.esc_html(implode(', ',array_map('strval',(array)($probe['socket_context_keys']??array())))).'</td></tr>';
        echo '</tbody></table><h3>phpmailer_init callbacks (priority order)</h3><ul>';
        foreach((array)($diagnostic['hooks']??array()) as $hook){$hook=(array)$hook;$source=(string)($hook['callback']??'unknown');$file=(string)($hook['file']??'');$line=(int)($hook['line']??0);$location=$file!==''?$file.($line?':'.$line:''):'';
            echo '<li>'.esc_html('priority '.(int)($hook['priority']??0).' · '.$source.($location!==''?' · '.$location:'')).'</li>';
        }
        echo '</ul><h3>Redacted SMTP protocol trace</h3><ol>';
        foreach((array)($diagnostic['trace']??array()) as $step)echo '<li>'.esc_html((string)$step).'</li>';
        echo '</ol>';
    }
    public static function screen():void{
        if(!current_user_can('manage_options')&&!current_user_can(IntegrationControlPlaneReadService::READ_CAPABILITY)
            &&!current_user_can('dzn_manage_provider_integrations')&&!current_user_can('dzn_view_provider_integrations'))wp_die('Forbidden',403);
        $settings=new IntegrationSettingsService();
        echo '<div class="wrap dzn-settings-page" dir="ltr"><h1>Integrations</h1>';
        echo '<p class="dzn-settings-lede">Provider configuration and readiness. “Credentials stored” means local configuration exists; it is not proof that the provider is working. Provider calls happen only when an authorised operator presses an explicit test.</p>';
        self::renderFrontlineContactNotice();
        echo '<div class="dzn-settings-cards">';
        self::renderProviderCard($settings,'email','Email','Account, security and operational email delivery.','dzn-integration-email',array('Sender name/address','Host mail or SMTP','Write-only password'));
        self::renderProviderCard($settings,'whatsapp','WhatsApp','Business-initiated WhatsApp through the existing Telnyx / Meta WABA route.','dzn-integration-whatsapp',array('Telnyx sender and WABA','Meta approved templates','Provider-template mapping is in Notifications → Templates'));
        self::renderProviderCard($settings,'sms','SMS','A constrained fallback only after a verified terminal WhatsApp failure. It is never a primary channel.','dzn-integration-sms',array('Telnyx SMS sender/profile','Consent and suppression remain mandatory','Direct SMS is not enabled by a checkbox'));
        self::renderProviderCard($settings,'google','Google Workspace classroom','One Delnavazan-owned organizer creates Meet spaces; Teachers are external co-hosts and do not connect personal Google accounts.','dzn-integration-google',array('Academy OAuth client','Organizer account and exact scopes','Consent-gated recording and Drive artifacts'));
        self::renderStripeCard();
        self::renderClassroomCard();
        echo '</div>';
        echo '<section class="dzn-settings-card"><h2>Routing and provider boundaries</h2><ul class="dzn-settings-list">';
        echo '<li><strong>Twilio WhatsApp is not implemented.</strong> No Twilio credential is read or stored, and no Twilio send can be enabled here.</li>';
        echo '<li><strong>Telnyx / Meta routing remains authoritative.</strong> The notification workspace does not contain a second provider form.</li>';
        echo '<li><strong>Google Workspace is centrally managed.</strong> Teachers are external co-hosts; no personal Google authorization is requested.</li>';
        echo '<li><strong>Stripe configuration remains under Finance.</strong> This page links to that authority and does not create a second secret store.</li>';
        echo '</ul></section>';
        echo '</div>';
    }

    private static function renderFrontlineContactNotice():void{
        $phone=class_exists(GeneralSettingsController::class)?GeneralSettingsController::FRONTLINE_PHONE_DISPLAY:'0413 413 004';
        echo '<div class="notice notice-info inline"><p><strong>Frontline WhatsApp contact:</strong> '.esc_html($phone).' — this is the academy contact, not the Telnyx notification sender. The sender below is used only to deliver notifications.</p></div>';
    }

    private static function renderProviderCard(IntegrationSettingsService $settings,string $provider,string $label,string $supports,string $route,array $controls):void{
        $ready=false;$redacted=array();
        try{$ready=$settings->ready($provider);$redacted=$settings->redacted($provider);}catch(\Throwable){}
        $secretStored=false;foreach($redacted as $value)if($value===true){$secretStored=true;break;}
        $last=(array)get_option('dzn_integration_test_'.$provider,array());
        $lastState=(string)($last['state']??'');
        $lastCode=(string)($last['code']??'');
        $state=$ready?'Configured locally, unverified':($secretStored?'Credentials stored, provider not ready':'Not configured');
        $badge=$ready?'is-warn':($secretStored?'is-warn':'is-locked');
        echo '<section class="dzn-settings-card dzn-integration-card"><h2>'.esc_html($label).'</h2>';
        echo '<p><span class="dzn-status-badge '.esc_attr($badge).'">'.esc_html($state).'</span></p>';
        echo '<p>'.esc_html($supports).'</p>';
        echo '<p><strong>Last explicit verification:</strong> '.(($lastState==='success')?'Succeeded':($lastState==='failed'?'Failed':($lastState==='blocked'?'Blocked':'Not run'))).' '.($lastCode!==''?'<span class="dzn-settings-meta">('.esc_html(self::friendlyProviderCode($lastCode)).')</span>':'').'</p>';
        echo '<p class="dzn-settings-meta">Technical code: <code dir="ltr">'.esc_html($lastCode!==''?$lastCode:'not_run').'</code>'.($last?' · '.(string)($last['at']??''):'').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page='.$route)).'">'.(self::canConfigureProvider()?'Configure':'Inspect').'</a>'.((current_user_can('manage_options')||current_user_can(IntegrationControlPlaneReadService::READ_CAPABILITY))?' <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-system-health&component=providers')).'">Inspect evidence</a>':'').'</p>';
        echo '<details class="dzn-settings-advanced"><summary><strong>Advanced provider controls</strong></summary><ul class="dzn-settings-list">';
        foreach($controls as $control)echo '<li>'.esc_html($control).'</li>';
        echo '</ul><p class="dzn-settings-meta">The provider page is a protected route. Credentials are encrypted at rest, write-only and never present in this page HTML. Explicit tests may make a provider request; no test runs automatically.</p></details>';
        echo '</section>';
    }

    private static function renderStripeCard():void{
        $last=(array)get_option('dzn_stripe_test_connection_result',array());
        $allowed=current_user_can(PaymentExecutionController::CAPABILITY);
        echo '<section class="dzn-settings-card dzn-integration-card"><h2>Stripe</h2>';
        echo '<p><span class="dzn-status-badge '.($allowed?'is-warn':'is-locked').'">'.esc_html($allowed?((string)($last['state']??'')==='success'?'Test connection confirmed':'Configuration authority available'):'Restricted').'</span></p>';
        echo '<p>Checkout, payment evidence and reconciliation remain Finance-owned. Stripe credentials and readiness use the existing Finance payment-provider authority; this page does not create a second secret store.</p>';
        if($allowed)echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=dzn-finance&tab=settings#stripe')).'">Configure in Finance</a></p>';
        else echo '<p>Stripe configuration is available to staff with payment-provider authority.</p>';
        if($last)echo '<p class="dzn-settings-meta">Last test: '.esc_html((string)($last['state']??'unknown')).' · <code dir="ltr">'.esc_html((string)($last['code']??'')).'</code> · '.esc_html((string)($last['at']??'')).'</p>';
        echo '</section>';
    }

    private static function renderClassroomCard():void{
        $health=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceOAuthService())->health();
        $state=(string)($health['state']??'not_connected');
        $badge=$state==='connected'?'is-ok':'is-locked';
        echo '<section class="dzn-settings-card dzn-integration-card"><h2>Google Meet classroom</h2><p><span class="dzn-status-badge '.esc_attr($badge).'">'.esc_html($state).'</span></p>';
        echo '<p>Organizer <code>'.esc_html((string)($health['organizer_email']??'')).'</code> creates and owns every lesson space. Teachers join as external co-hosts and Students as regular participants.</p>';
        echo '<p class="dzn-settings-meta">Scopes: '.esc_html(!empty($health['scope_match'])?'exact':'re-consent required').' · Credential: '.esc_html(!empty($health['credential_valid'])?'valid':'missing or expired').' · Last checked: '.esc_html((string)($health['last_checked_at']??'never')).(!empty($health['failure_reason_code'])?' · Last error: <code>'.esc_html((string)$health['failure_reason_code']).'</code>':'').'</p>';
        try{
            $report=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomReconciler())->report(10);
            $counts=(array)($report['counts']??array());
            echo '<p class="dzn-settings-meta">Missing future classrooms: lessons '.esc_html((string)(int)($counts['missing_lesson_classrooms']??0)).' · bookings '.esc_html((string)(int)($counts['missing_booking_classrooms']??0)).' · Unconfirmed accepted bookings: '.esc_html((string)(int)($counts['unconfirmed_booking_projections']??0)).' · Failed/blocked: '.esc_html((string)(int)($counts['blocked_or_failed_classrooms']??0)).'</p>';
            $problems=array_merge((array)($report['failed_booking_projections']??array()),(array)($report['blocked_or_failed_classrooms']??array()));
            if($problems){
                echo '<div class="notice notice-warning inline"><p><strong>Classroom recovery is required.</strong> Retry transient rows with <code>wp dzn-meet worker --discover</code>; review the dry run with <code>wp dzn-meet repair --dry-run</code>. Permanent co-host refusals require an explicit <code>wp dzn-meet approve-cohost-exception</code> or tenant-policy correction.</p><ul>';
                foreach(array_slice($problems,0,5) as $row){
                    $id=(int)($row['classroom_id']??0);
                    $lesson=(int)($row['lesson_id']??0);
                    $booking=(int)($row['booking_confirmation_id']??$row['confirmation_id']??0);
                    $reason=(string)($row['failure_reason_code']??$row['reason_code']??$row['cohost_error_code']??'');
                    echo '<li>'.($id>0?'Classroom #'.esc_html((string)$id).' · ':'').($lesson>0?'lesson #'.esc_html((string)$lesson):'booking #'.esc_html((string)$booking)).' · '.esc_html((string)($row['classroom_state']??$row['state']??'failed')).' · co-host '.esc_html((string)($row['cohost_state']??'unknown')).($reason!==''?' · <code>'.esc_html($reason).'</code>':'').'</li>';
                }
                echo '</ul></div>';
            }
        }catch(\Throwable){}
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-integration-google')).'">Manage organizer connection</a></p></section>';
    }

    private static function canConfigureProvider():bool{
        return current_user_can('manage_options')||current_user_can('dzn_manage_provider_integrations');
    }

    private static function friendlyProviderCode(string $code):string{
        return match($code){
            'smtp_tls_failed'=>'TLS negotiation failed — check encryption and certificate.',
            'smtp_authentication_failed'=>'The mail host rejected the username or password.',
            'smtp_connection_failed'=>'The mail host could not be reached.',
            'smtp_sender_rejected'=>'The mail host rejected the sender address.',
            'smtp_recipient_rejected'=>'The mail host rejected the test destination.',
            'success_mailer_accepted'=>'The mail server accepted the test message; inbox delivery is not confirmed.',
            'not_configured'=>'Provider configuration is incomplete.',
            default=>$code!==''?$code:'No technical code recorded.',
        };
    }
}
