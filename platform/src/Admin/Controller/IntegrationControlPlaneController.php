<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\IntegrationControlPlaneReadService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\PaymentExecution\{StripeLiveConfigurationService,StripeLiveReadinessService,StripeProviderMode,StripeTestModeReadinessService,StripeTestConfigurationService};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialPaymentRepository,PaymentProviderRepository};
use Delnavazan\Platform\Integrations\Payment\Stripe\StripeEventTranslator;
use Delnavazan\Platform\Integrations\IntegrationTestRunner;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfigurationService;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateDiscoveryService;
use Delnavazan\Platform\Integrations\Notifications\TelnyxWebhookController;

/** Workflow-first integration settings. Secret inputs are write-only and safe checks make no provider calls. */
final class IntegrationControlPlaneController {
    public static function register():void{add_action('admin_post_dzn_integration_settings_save',array(__CLASS__,'save'));add_action('admin_post_dzn_integration_settings_test',array(__CLASS__,'test'));add_action('admin_post_dzn_telnyx_template_sync',array(__CLASS__,'syncTelnyxTemplates'));add_action('admin_post_dzn_telnyx_public_key_sync',array(__CLASS__,'syncTelnyxPublicKey'));add_action('admin_post_dzn_telnyx_webhook_sync',array(__CLASS__,'syncTelnyxWebhook'));add_action('admin_post_dzn_stripe_test_settings_save',array(__CLASS__,'saveStripeTestSettings'));add_action('admin_post_dzn_stripe_test_connection',array(__CLASS__,'testStripeConnection'));add_action('admin_post_dzn_stripe_test_activation',array(__CLASS__,'setStripeTestActivation'));add_action('admin_post_dzn_stripe_live_settings_save',array(__CLASS__,'saveStripeLiveSettings'));add_action('admin_post_dzn_stripe_live_connection',array(__CLASS__,'testStripeLiveConnection'));add_action('admin_post_dzn_stripe_live_webhook_verify',array(__CLASS__,'verifyStripeLiveWebhook'));add_action('admin_post_dzn_stripe_live_activation',array(__CLASS__,'setStripeLiveActivation'));}
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
        $liveConfiguration=null;$liveSaved=array();$liveReadiness=array('stage'=>StripeLiveReadinessService::STAGE_NOT_CONFIGURED,'verified'=>false,'ready'=>false,'active'=>false,'checks'=>array());
        try{$liveConfiguration=new StripeLiveConfigurationService();$liveSaved=$liveConfiguration->savedState();$liveReadiness=(new StripeLiveReadinessService())->read();}
        catch(\Throwable){echo '<div class="notice notice-error"><p>Stripe Live configuration is unavailable in this request. Verify that Schema migration 070 is installed and try again.</p></div>';}
        $testEnvironment=StripeTestConfigurationService::testEnvironmentAllowed();
        $production=function_exists('wp_get_environment_type')&&(string)wp_get_environment_type()==='production';
        $preLaunchActive=$production&&StripeTestConfigurationService::preLaunchProductionTestModeActive();
        echo '<p>Stripe test and live are separate accounts, credential scopes and webhook selectors. Secret keys are encrypted at rest, write-only and never displayed or returned by an API. Live credentials can only be configured in production, and saving them never activates live payments.</p>';
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
            'live_saved'=>'Stripe live configuration saved. Live payments are still inactive; run the live checks and confirm Activate Live Payments.',
            'stripe_live_environment_required'=>'Live credentials can only be configured in a production environment. No live credentials were saved.',
            'invalid_stripe_live_publishable_key'=>'The publishable key must be a live Stripe key (pk_live_). No live credentials were saved.',
            'invalid_stripe_live_secret_key'=>'The secret key must be a live Stripe key (sk_live_). No live credentials were saved.',
            'invalid_stripe_live_webhook_secret'=>'The webhook signing secret must be a valid Stripe signing secret (whsec_). No live credentials were saved.',
            'invalid_stripe_live_account_identifier'=>'The Stripe account identifier must look like acct_… . No live credentials were saved.',
            'complete_stripe_live_configuration_required'=>'Enter the live publishable key, live secret key and live webhook signing secret together for the first save. No live credentials were saved.',
            'stripe_live_account_scope_ambiguous'=>'The live Stripe account state is ambiguous, so the save was refused. No live credentials were saved.',
            'stripe_live_account_state_unavailable'=>'The existing Stripe live account is not in a configurable state. No live credentials were saved.',
            'stripe_live_secret_store_refused'=>'The encrypted credential vault refused a live write. Some fields may have been saved; review readiness before retrying. Credential values are never shown here.',
            'stripe_live_configuration_persistence_unconfirmed'=>'The live configuration could not be confirmed by a read-after-write check. Some fields may have been saved; review readiness before retrying.',
            'live_connection_success'=>'Stripe accepted the live secret key and confirmed a live balance response.',
            'live_connection_failed'=>'The live Stripe connection failed. Check the live secret key and account identifier; no credential is shown here.',
            'live_connection_blocked'=>'The live Stripe connection could not run because the environment, account or encrypted credentials are not ready.',
            'live_webhook_verified'=>'Stripe confirms the live event destination, its required events and payload/API version.',
            'live_webhook_failed'=>'Stripe did not confirm the live event destination. Review the URL, enabled events and API version, then retry.',
            'live_webhook_blocked'=>'The live webhook check could not run because the account or encrypted credentials are not ready.',
            'live_activation_confirmation_required'=>'Live activation requires the explicit confirmation checkbox. Nothing was activated.',
            'live_activation_blocked'=>'Live activation was refused because the readiness checklist is not fully ready. Nothing was activated.',
            'live_activated'=>'Live payments are now active. Test mode remains available by deactivating live payments.',
            'live_deactivated'=>'Live payments are stopped. Credentials and historical payment records were preserved; test mode remains available.',
            'live_activation_failed'=>'Live activation did not complete. The account was returned to a fail-closed state; review readiness and try again.',
            'save_failed'=>'Stripe configuration could not be confirmed. Check readiness before repeating the save; credential values are never shown here.'
        );echo '<div class="notice notice-info"><p>'.esc_html($messages[$result]??'Stripe operation completed.').'</p></div>';}
        $webhookUrl=rest_url('delnavazan-platform/v1/payment-provider-events/stripe/'.$configuration->webhookReference());
        echo '<h2>Test mode configuration</h2><p>Webhook endpoint: <code id="dzn-stripe-test-webhook">'.esc_html($webhookUrl).'</code> <button type="button" class="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'dzn-stripe-test-webhook\').textContent)">Copy URL</button></p>';
        echo '<p><strong>Signing secret:</strong> '.esc_html(!empty($saved['webhook'])?'configured':'missing').' · <strong>Webhook readiness:</strong> '.esc_html((($readiness['checks']['test_webhook_secret']['state']??'blocked')==='ready')?'configured':'not verified').'</p>';
        $testAccountId=(int)($saved['account_id']??0);
        if($testAccountId>0){
            $testReceipts=(new PaymentProviderRepository())->recentReceiptsForAccount($testAccountId,8);
            $testLast=(new PaymentProviderRepository())->latestReceiptForAccount($testAccountId);
            $testVerified=(new PaymentProviderRepository())->latestReceiptForAccount($testAccountId,'verified');
            $testRejected=(new PaymentProviderRepository())->latestReceiptForAccount($testAccountId,'refused');
            echo '<p><strong>Last test webhook:</strong> '.esc_html($testLast?(string)$testLast->received_at.' · '.$testLast->verification_state:'None recorded').' · <strong>Last verified:</strong> '.esc_html($testVerified?(string)$testVerified->received_at:'None').' · <strong>Last rejected:</strong> '.esc_html($testRejected?(string)$testRejected->received_at.' · '.($testRejected->refusal_reason_code??'refused'):'None').'</p>';
            echo '<table class="widefat striped"><thead><tr><th>Received</th><th>State</th><th>Safe reason</th><th>Bytes</th></tr></thead><tbody>';
            foreach($testReceipts as $receipt)echo '<tr><td>'.esc_html((string)$receipt->received_at).'</td><td>'.esc_html((string)$receipt->verification_state).'</td><td>'.esc_html((string)($receipt->refusal_reason_code??'—')).'</td><td>'.(int)$receipt->body_bytes.'</td></tr>';
            if(!$testReceipts)echo '<tr><td colspan="4">No test webhook deliveries recorded.</td></tr>';
            echo '</tbody></table>';
        }
        echo '<p><strong>Test webhook events (derived from the deployed adapter):</strong> <code>'.esc_html(implode(', ',StripeEventTranslator::requiredWebhookEvents())).'</code></p>';
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
        if($liveConfiguration!==null)self::renderStripeLivePanel($liveConfiguration,$liveReadiness,$saved,$liveSaved,$embedded);
    }

    /** Dedicated Stripe Live Mode panel: environment, credentials, webhook operations and activation. */
    private static function renderStripeLivePanel(StripeLiveConfigurationService $live,array $readiness,array $testSaved,array $saved,bool $embedded=false):void{
        $activation=StripeProviderMode::activation();
        $production=StripeProviderMode::liveEnvironmentAllowed();
        $stage=(string)($readiness['stage']??StripeLiveReadinessService::STAGE_NOT_CONFIGURED);
        $webhookUrl=$live->webhookUrl();
        $webhookReady=(int)($saved['account_id']??0)>0&&($saved['account_reference']??null)!==null;
        $connection=StripeLiveConfigurationService::connectionResult();
        $verification=StripeLiveConfigurationService::webhookVerification();
        $repository=new PaymentProviderRepository();
        $accountId=(int)($saved['account_id']??0);
        $lastReceived=$accountId>0?$repository->latestReceiptForAccount($accountId):null;
        $lastVerified=$accountId>0?$repository->latestReceiptForAccount($accountId,'verified'):null;
        $lastRejected=$accountId>0?$repository->latestReceiptForAccount($accountId,'refused'):null;
        $receipts=$accountId>0?$repository->recentReceiptsForAccount($accountId,10):array();
        $events=$accountId>0?$repository->recentEventsForAccount($accountId,10):array();
        $evidence=array();
        if($accountId>0&&($saved['account_reference']??null)!==null){
            try{$evidence=(new CommercialPaymentRepository())->recentEvidenceForProviderAccount('stripe',\Delnavazan\Platform\Core\Application\CommercialIdempotency::evidence((string)$saved['account_reference']),10);}catch(\Throwable){$evidence=array();}
        }
        $lastSuccess=null;$lastFailure=null;
        foreach($evidence as $row){if($lastSuccess===null&&(string)$row->processing_state==='accepted')$lastSuccess=$row;if($lastFailure===null&&in_array((string)$row->processing_state,array('rejected','unmatched'),true))$lastFailure=$row;}

        echo '<hr><h2 id="stripe-live">Stripe Live Mode</h2>';
        echo '<p><strong>Environment:</strong> '.esc_html($production?'Production':'Not production').' · <strong>Live state:</strong> <span class="dzn-status-badge '.($stage===StripeLiveReadinessService::STAGE_ACTIVE?'is-ok':'is-warn').'">'.esc_html($stage).'</span></p>';
        echo '<table class="widefat striped"><tbody>';
        foreach(array(
            'Current payment environment'=>$stage===StripeLiveReadinessService::STAGE_ACTIVE?'Live (test remains configured)':(!empty($testSaved['execution_enabled'])?'Test':'Test / live inactive'),
            'Test account registration'=>(int)($testSaved['account_id']??0)>0?'Registered as '.StripeProviderMode::TEST_ACCOUNT_REFERENCE:'Not registered',
            'Live account registration'=>(int)($saved['account_id']??0)>0?'Registered as '.StripeProviderMode::LIVE_ACCOUNT_REFERENCE:'Not registered',
            'Stripe live connection'=>($connection['state']??'not run').' · '.($connection['code']??'not_run').($connection['at']??''?' · '.$connection['at']:''),
            'Live payment execution readiness'=>!empty($readiness['ready'])?'Ready':($readiness['verified']??false?'Verified, not ready':'Not ready'),
            'Webhook verification'=>($verification['state']??'not run').' · '.($verification['code']??'not_run').($verification['at']??''?' · '.$verification['at']:''),
            'Last webhook received'=>$lastReceived?(string)$lastReceived->received_at.' · '.$lastReceived->verification_state:'None recorded',
            'Last successfully processed webhook'=>$lastVerified?(string)$lastVerified->received_at.' · '.$lastVerified->verification_state:'None recorded',
            'Last rejected webhook'=>$lastRejected?(string)$lastRejected->received_at.' · '.($lastRejected->refusal_reason_code??'refused'):'None recorded',
            'Last verified event'=>$events?(string)$events[0]->received_at.' · '.$events[0]->event_type:'None recorded',
            'Last successful live payment'=>$lastSuccess?(string)$lastSuccess->ingested_at.' · '.$lastSuccess->evidence_kind.' · '.$lastSuccess->amount_minor.' '.$lastSuccess->currency:'None recorded',
            'Last live payment failure'=>$lastFailure?(string)$lastFailure->ingested_at.' · '.$lastFailure->processing_state.' · '.($lastFailure->reason_code??''):'None recorded',
        ) as $label=>$value)echo '<tr><th>'.esc_html((string)$label).'</th><td>'.esc_html((string)$value).'</td></tr>';
        echo '</tbody></table>';

        echo '<h3>Live credentials</h3>';
        if(!$production){
            echo '<div class="notice notice-warning inline"><p>Live credentials can only be configured in production. Current environment: <code>'.esc_html(function_exists('wp_get_environment_type')?(string)wp_get_environment_type():'unknown').'</code>. Nothing is shown or saved here.</p></div>';
        }else{
            echo '<p>Secret values are encrypted with the existing payment-secret vault and are write-only. Test and live credentials are separate. Saving credentials invalidates prior live verification and stops live charging until activation is repeated.</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Save these Stripe live credentials? Live payments will remain inactive until you confirm activation.\')"><input type="hidden" name="action" value="dzn_stripe_live_settings_save">'.($embedded?'<input type="hidden" name="return_to_finance" value="1">':'').wp_nonce_field('dzn_stripe_live_settings_save', '_wpnonce', true, false);
            echo '<table class="form-table"><tbody>';
            echo '<tr><th><label for="stripe-live-pk">Live publishable key</label></th><td><input class="regular-text" id="stripe-live-pk" name="settings[publishable_key]" value="'.esc_attr($live->publishableKey()).'" placeholder="pk_live_…" autocomplete="off"></td></tr>';
            foreach(array('secret_key'=>'Live secret key','webhook_signing_secret'=>'Live webhook signing secret') as $field=>$label){
                $has=$field==='secret_key'?$saved['secret']:$saved['webhook'];
                echo '<tr><th><label for="stripe-live-'.esc_attr($field).'">'.esc_html($label).'</label></th><td><input class="regular-text" type="password" id="stripe-live-'.esc_attr($field).'" name="settings['.esc_attr($field).']" value="" autocomplete="new-password" placeholder="'.($has?'•••••••••••• (configured; leave blank to keep)':'Enter Stripe live credential').'"></td></tr>';
            }
            echo '<tr><th><label for="stripe-live-account">Stripe account identifier (optional)</label></th><td><input class="regular-text" id="stripe-live-account" name="settings[account_identifier]" value="" autocomplete="off" placeholder="acct_… (non-secret; leave blank to keep)"><p class="description">Used only to identify the Stripe account to administrators. It is not a credential.</p></td></tr>';
            echo '</tbody></table><p><button class="button button-primary">Save live configuration</button> <span class="description">Does not activate live payments.</span></p></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_stripe_live_connection">'.wp_nonce_field('dzn_stripe_live_connection', '_wpnonce', true, false).'<p><button class="button" name="run_live_test" value="1">Test live connection</button> <span class="description">Read-only Stripe balance check; no charge is created.</span></p></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_stripe_live_webhook_verify">'.wp_nonce_field('dzn_stripe_live_webhook_verify', '_wpnonce', true, false).'<p><button class="button" name="verify_live_webhook" value="1">Verify live webhook</button> <span class="description">Reads the Stripe event destination back; no event is sent.</span></p></form>';
        }

        echo '<h3>Live webhook</h3>';
        if($webhookReady){
            echo '<p><strong>Webhook URL:</strong> <code id="dzn-stripe-live-webhook">'.esc_html($webhookUrl).'</code> <button type="button" class="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'dzn-stripe-live-webhook\').textContent)">Copy URL</button></p>';
        }else{
            echo '<p><strong>Live webhook URL is not ready.</strong> Save the live account credentials first. The expected URL is <code>'.esc_html($webhookUrl).'</code>, but its <code>stripe_live</code> selector is only active once the live account is registered.</p>';
        }
        echo '<p><strong>Signing secret:</strong> '.esc_html(!empty($saved['webhook'])?'configured':'missing').' · <strong>Webhook readiness:</strong> '.esc_html((!empty($readiness['checks']['webhook_destination_verified']['state'])&&$readiness['checks']['webhook_destination_verified']['state']==='ready')?'verified':'not verified').'</p>';
        echo '<p><strong>Required events:</strong></p><ul>';
        foreach(StripeEventTranslator::requiredWebhookEvents() as $event)echo '<li><code>'.esc_html($event).'</code></li>';
        echo '</ul><details><summary><strong>All events supported by the deployed adapter</strong></summary><ul>';
        foreach(StripeEventTranslator::supportedRawTypes() as $event)echo '<li><code>'.esc_html($event).'</code>'.(in_array($event,StripeEventTranslator::requiredWebhookEvents(),true)?' <em>(required)</em>':'').'</li>';
        echo '</ul></details>';
        if($verification){
            echo '<p><strong>Last destination verification:</strong> '.esc_html((string)($verification['state']??'unknown')).' · '.esc_html((string)($verification['code']??'')).' · API version '.esc_html((string)($verification['api_version']??'not recorded')).($verification['at']??''?' · '.esc_html((string)$verification['at']):'').'</p>';
            if(!empty($verification['missing_events'])&&is_array($verification['missing_events']))echo '<div class="notice notice-warning inline"><p>Missing required events: <code>'.esc_html(implode(', ',array_map('strval',$verification['missing_events']))).'</code></p></div>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Received</th><th>State</th><th>Safe reason</th><th>Bytes</th></tr></thead><tbody>';
        foreach($receipts as $receipt)echo '<tr><td>'.esc_html((string)$receipt->received_at).'</td><td>'.esc_html((string)$receipt->verification_state).'</td><td>'.esc_html((string)($receipt->refusal_reason_code??'—')).'</td><td>'.(int)$receipt->body_bytes.'</td></tr>';
        if(!$receipts)echo '<tr><td colspan="4">No live webhook deliveries recorded.</td></tr>';
        echo '</tbody></table><p class="description">A verified HTTP delivery is not the same as a settled payment. Settlement appears only in accepted live payment evidence.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Verified event received</th><th>Normalised type</th><th>Provider occurrence</th></tr></thead><tbody>';
        foreach($events as $event)echo '<tr><td>'.esc_html((string)$event->received_at).'</td><td>'.esc_html((string)$event->event_type).'</td><td>'.esc_html((string)($event->provider_occurred_at??'—')).'</td></tr>';
        if(!$events)echo '<tr><td colspan="3">No verified live provider events recorded.</td></tr>';
        echo '</tbody></table>';

        echo '<h3>Live readiness checklist</h3><table class="widefat striped"><tbody>';
        foreach($readiness['checks'] as $key=>$check)echo '<tr><th>'.esc_html((string)$key).'</th><td>'.esc_html((string)$check['state']).'</td><td>'.esc_html((string)$check['detail']).'</td></tr>';
        echo '</tbody></table>';

        echo '<h3>Activate live payments</h3>';
        if($stage===StripeLiveReadinessService::STAGE_ACTIVE){
            echo '<div class="notice notice-success inline"><p>Live payments are active. Deactivating stops new live charges immediately and preserves credentials and every historical record.</p></div>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Stop live payments now? New live charges will be refused; historical records are preserved.\')"><input type="hidden" name="action" value="dzn_stripe_live_activation">'.wp_nonce_field('dzn_stripe_live_activation', '_wpnonce', true, false).'<p><button class="button" name="disable_live_payments" value="1">Deactivate live payments (rollback to Test)</button></p></form>';
        }else{
            $disabled=(empty($readiness['ready'])||empty($readiness['verified']))?' disabled':'';
            echo '<p>Activation requires every blocking readiness check to pass, an explicit confirmation, and a deliberate administrator action. It is never triggered by saving credentials.</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Activate real Stripe live payments? Real customer charges become possible after this action.\')"><input type="hidden" name="action" value="dzn_stripe_live_activation">'.wp_nonce_field('dzn_stripe_live_activation', '_wpnonce', true, false);
            echo '<p><label><input type="checkbox" name="confirm_live_activation" value="1" required> I confirm that real Stripe live payments should be enabled after all readiness checks pass.</label></p>';
            echo '<p><button class="button button-primary" name="enable_live_payments" value="1"'.$disabled.'>Activate Live Payments</button>'.($disabled?' <span class="description">Complete the blocking checks above first.</span>':'').'</p></form>';
        }

        echo '<h3>Stripe live setup instructions</h3><ol>';
        foreach(array(
            'Open the Stripe Dashboard and switch it to Live Mode.',
            'Open Developers → Webhooks → Add endpoint (or Event destinations) in Live Mode.',
            'Use the Delnavazan live webhook URL shown above once the live account is registered.',
            'Select the required events listed above (the full supported list is safe to subscribe).',
            'Choose the Snapshot payload format and API version 2026-10-28.endive. Unreviewed versions fail readiness.',
            'Save the destination in Stripe and copy its signing secret (whsec_…).',
            'Paste the live secret key, publishable key and signing secret into the encrypted form above and save.',
            'Press Test live connection. Then press Verify live webhook; this reads the destination back from Stripe and checks URL, status, events and API version.',
            'Send a Stripe test event from the live endpoint (or complete a low-value authorised live verification) and confirm a verified receipt appears above. A 200 alone is not proof of settlement.',
            'Only after the checklist is Ready, tick the confirmation and press Activate Live Payments.',
        ) as $step)echo '<li>'.esc_html($step).'</li>';
        echo '</ol>';
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
    public static function saveStripeLiveSettings():void{
        self::authorizeStripeSettings();check_admin_referer('dzn_stripe_live_settings_save');
        try{(new StripeLiveConfigurationService())->save((array)($_POST['settings']??array()));$result='live_saved';}
        catch(\Throwable $e){$result=self::safeStripeLiveResult($e);}
        self::redirectStripe($result);
    }
    public static function testStripeLiveConnection():void{
        self::authorizeStripeSettings();check_admin_referer('dzn_stripe_live_connection');if(!isset($_POST['run_live_test']))wp_die('Explicit live test action required',400);
        $result=(new StripeLiveConfigurationService())->testConnection();
        self::redirectStripe($result['state']==='success'?'live_connection_success':($result['state']==='failed'?'live_connection_failed':'live_connection_blocked'));
    }
    public static function verifyStripeLiveWebhook():void{
        self::authorizeStripeSettings();check_admin_referer('dzn_stripe_live_webhook_verify');if(!isset($_POST['verify_live_webhook']))wp_die('Explicit live webhook action required',400);
        $result=(new StripeLiveConfigurationService())->verifyWebhookDestination();
        self::redirectStripe($result['state']==='success'?'live_webhook_verified':($result['state']==='failed'?'live_webhook_failed':'live_webhook_blocked'));
    }
    public static function setStripeLiveActivation():void{
        self::authorizeStripeSettings();check_admin_referer('dzn_stripe_live_activation');
        try{
            $service=new StripeLiveConfigurationService();
            if(isset($_POST['disable_live_payments'])){$service->deactivate();$result='live_deactivated';}
            else{
                if((string)($_POST['enable_live_payments']??'')!=='1'||(string)($_POST['confirm_live_activation']??'')!=='1'){$result='live_activation_confirmation_required';}
                else{$service->activate();$result='live_activated';}
            }
        }catch(\Throwable $e){$result=$e->getMessage()==='stripe_live_readiness_blocked'?'live_activation_blocked':'live_activation_failed';}
        self::redirectStripe($result);
    }
    /** Map only known live errors to user-facing codes; exception text and credentials are never reflected. */
    public static function safeStripeLiveResult(\Throwable $error):string{
        $allowed=array(
            'stripe_live_environment_required','invalid_stripe_live_publishable_key','invalid_stripe_live_secret_key',
            'invalid_stripe_live_webhook_secret','invalid_stripe_live_account_identifier',
            'complete_stripe_live_configuration_required','stripe_live_account_scope_ambiguous',
            'stripe_live_account_state_unavailable','stripe_live_secret_store_refused',
            'stripe_live_configuration_persistence_unconfirmed',
        );
        if(in_array($error->getMessage(),$allowed,true))return $error->getMessage();
        if($error->getMessage()==='Unauthorized')return 'stripe_configuration_unauthorized';
        if($error instanceof \Delnavazan\Platform\Core\Infrastructure\Repository\PersistenceException){
            return match($error->errno){
                1062=>'stripe_persistence_duplicate',1054,1146=>'stripe_persistence_schema',
                1205,1213=>'stripe_persistence_transient',default=>'stripe_persistence_failed',
            };
        }
        if(str_starts_with($error->getMessage(),'Payment secret persistence failed:')
            ||str_starts_with($error->getMessage(),'Payment provider persistence failed:')
            ||str_starts_with($error->getMessage(),'Transaction '))return 'stripe_persistence_failed';
        return 'save_failed';
    }
    private static function authorizeStripeSettings():void{if(!current_user_can(PaymentExecutionController::CAPABILITY)||!current_user_can('dzn_manage_payment_providers'))wp_die('Forbidden',403);}
    private static function settingsForm(string $provider):void{
        self::authorizeSettings();$service=new IntegrationSettingsService();$configs=$service->redacted($provider);$schema=IntegrationSettingsService::providerSchemas()[$provider]??array();
        $labels=array('email'=>'Email','whatsapp'=>'WhatsApp (Telnyx / Meta WABA)','sms'=>'SMS fallback (Telnyx)','google'=>'Google Workspace & Meet');
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
        self::renderGoogleCard($settings);
        self::renderStripeCard();
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
        $liveStage='Not configured';
        try{if($allowed)$liveStage=(string)(new StripeLiveReadinessService())->read()['stage'];}catch(\Throwable){}
        echo '<section class="dzn-settings-card dzn-integration-card"><h2>Stripe</h2>';
        echo '<p><span class="dzn-status-badge '.($allowed?'is-warn':'is-locked').'">'.esc_html($allowed?((string)($last['state']??'')==='success'?'Test connection confirmed':'Configuration authority available'):'Restricted').'</span> <span class="dzn-status-badge '.($liveStage==='Active'?'is-ok':'is-warn').'">'.esc_html('Live: '.$liveStage).'</span></p>';
        echo '<p>Checkout, payment evidence and reconciliation remain Finance-owned. Stripe test and live credentials, webhook management and readiness use the existing Finance payment-provider authority and encrypted vault; this page does not create a second secret store.</p>';
        if($allowed)echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=dzn-stripe-settings#stripe-live')).'">Configure Stripe</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-finance&tab=settings#stripe')).'">Configure in Finance</a></p>';
        else echo '<p>Stripe configuration is available to staff with payment-provider authority.</p>';
        if($last)echo '<p class="dzn-settings-meta">Last test: '.esc_html((string)($last['state']??'unknown')).' · <code dir="ltr">'.esc_html((string)($last['code']??'')).'</code> · '.esc_html((string)($last['at']??'')).'</p>';
        echo '</section>';
    }

    /**
     * One canonical Google Workspace & Meet card.
     *
     * The academy-wide organizer connection, Calendar/Meet provisioning, classroom reconciliation
     * and consent-gated recording status are facets of the same integration, not separate OAuth
     * implementations. Teacher personal Google authorization is not requested or stored.
     */
    private static function renderGoogleCard(IntegrationSettingsService $settings):void{
        $ready=false;$redacted=array();
        try{$ready=$settings->ready('google');$redacted=$settings->redacted('google');}catch(\Throwable){}
        $secretStored=false;foreach($redacted as $value)if($value===true){$secretStored=true;break;}
        $health=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceOAuthService())->health();
        $state=(string)($health['state']??'not_connected');
        $badge=$state==='connected'&&!empty($health['credential_valid'])?'is-ok':($ready?'is-warn':'is-locked');
        $last=(array)get_option('dzn_integration_test_google',array());
        echo '<section class="dzn-settings-card dzn-integration-card dzn-integration-card--google"><h2>Google Workspace &amp; Meet</h2>';
        echo '<p><span class="dzn-status-badge '.esc_attr($badge).'">'.esc_html($state==='connected'?'Connected':'Needs attention').'</span> <span class="dzn-status-badge '.($ready?'is-warn':'is-locked').'">'.esc_html($ready?'OAuth client configured':'OAuth client not configured').'</span></p>';
        echo '<p>One Delnavazan-owned organizer creates Calendar events and Meet spaces; Teachers are external co-hosts and do not connect personal Google accounts. This is the single management interface for OAuth, Calendar, Meet provisioning, classroom reconciliation and recording status.</p>';
        echo '<p>Organizer <code>'.esc_html((string)($health['organizer_email']??'')).'</code> creates and owns every lesson space. Teachers join as external co-hosts and Students as regular participants.</p>';
        echo '<p class="dzn-settings-meta">Scopes: '.esc_html(!empty($health['scope_match'])?'exact':'re-consent required').' · Credential: '.esc_html(!empty($health['credential_valid'])?'valid':'missing or expired').' · Last checked: '.esc_html((string)($health['last_checked_at']??'never')).(!empty($health['failure_reason_code'])?' · Last error: <code>'.esc_html((string)$health['failure_reason_code']).'</code>':'').'</p>';
        try{
            $report=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomReconciler())->report(10);
            $counts=(array)($report['counts']??array());
            echo '<p class="dzn-settings-meta">Missing future classrooms: lessons '.esc_html((string)(int)($counts['missing_lesson_classrooms']??0)).' · bookings '.esc_html((string)(int)($counts['missing_booking_classrooms']??0)).' · Unconfirmed accepted bookings: '.esc_html((string)(int)($counts['unconfirmed_booking_projections']??0)).' · Failed/blocked: '.esc_html((string)(int)($counts['blocked_or_failed_classrooms']??0)).'</p>';
            $problems=array_merge((array)($report['failed_booking_projections']??array()),(array)($report['blocked_or_failed_classrooms']??array()));
            if($problems){
                echo '<div class="notice notice-warning inline"><p><strong>Classroom recovery is required.</strong> Review <code>wp dzn-meet repair --dry-run --json --user=&lt;administrator_id&gt;</code>, then apply the approved digest with <code>wp dzn-meet repair --plan=&lt;digest&gt; --user=&lt;administrator_id&gt;</code>. Manual <code>worker --discover</code> is preview-only. Permanent co-host refusals require an explicit <code>wp dzn-meet approve-cohost-exception</code> or tenant-policy correction.</p><ul>';
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
        echo '<p class="dzn-settings-meta">Last explicit OAuth configuration test: '.esc_html((string)($last['state']??'not run')).' · <code dir="ltr">'.esc_html((string)($last['code']??'not_run')).'</code>'.($last?' · '.esc_html((string)($last['at']??'')):'').'. No live provider operation is run to render this page.</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=dzn-integration-google')).'">Manage Google Workspace &amp; Meet</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-system-health')).'">Inspect Google diagnostics</a></p></section>';
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
