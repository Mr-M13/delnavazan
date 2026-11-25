<?php
$root=dirname(__DIR__);
$repo=dirname($root);
$read=static fn(string $path):string=>(string)file_get_contents($path);
$plugin=$read($root.'/delnavazan-platform.php');
$migration=$read($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$controller=$read($root.'/src/Integrations/Notifications/TelnyxWebhookController.php');
$service=$read($root.'/src/Integrations/Notifications/TelnyxWebhookService.php');
$signature=$read($root.'/src/Integrations/Notifications/TelnyxWebhookSignature.php');
$normalizer=$read($root.'/src/Integrations/Notifications/TelnyxWebhookEvent.php');
$bindings=$read($root.'/src/Integrations/Notifications/NotificationProviderBindings.php');
$configuration=$read($root.'/src/Integrations/Notifications/TelnyxConfiguration.php');
$sms=$read($root.'/src/Integrations/TelnyxSmsAdapter.php');
$whatsapp=$read($root.'/src/Integrations/TelnyxWhatsAppAdapter.php');
$discovery=$read($root.'/src/Integrations/Notifications/TelnyxTemplateDiscoveryService.php');
$registry=$read($root.'/src/Integrations/Notifications/TelnyxTemplateRegistry.php');
$authority=$read($root.'/src/Integrations/Notifications/TelnyxTemplateAuthority.php');
$admin=$read($root.'/src/Admin/Controller/IntegrationControlPlaneController.php');
$notificationAdmin=$read($root.'/src/Admin/Controller/NotificationManagementController.php');
$readService=$read($root.'/src/Core/Application/IntegrationControlPlaneReadService.php');
$settings=$read($root.'/src/Core/Application/IntegrationSettingsService.php');
$composite=$read($root.'/src/Integrations/Notifications/CompositeNotificationTransport.php');
$suppressionRepo=$read($root.'/src/Core/Infrastructure/Repository/NotificationSuppressionRepository.php');
$fail=static function(string $name,bool $ok):void{if(!$ok)throw new RuntimeException('Telnyx integration contract failed: '.$name);};

$fail('webhook route is registered',str_contains($plugin,'TelnyxWebhookController')&&str_contains($plugin,"'register'"));
$fail('production bindings filter is registered',str_contains($plugin,"dzn_notification_transport_bindings")&&str_contains($plugin,'NotificationProviderBindings'));
$fail('schema 057 is installed',str_contains($migration,'057_telnyx_provider_events')&&str_contains($migration,'telnyx_events')&&str_contains($migration,'telnyx_messages')&&str_contains($migration,'telnyx_templates'));
$fail('provider tables are digest-only',str_contains($migration,'message_reference_digest')&&str_contains($migration,'event_reference_digest')&&str_contains($migration,'must stay contact-free and payload-free'));
$fail('the provider template text migration is installed',str_contains($migration,'079_telnyx_template_text')&&str_contains($migration,'template_text text NULL')&&str_contains($migration,'verify_telnyx_template_text_schema'));
$fail('the provider template text migration is in the upgrade ledger',str_contains($migration,"if(!in_array('079_telnyx_template_text'")&&str_contains($migration,"\$required[] = '079_telnyx_template_text'"));
$fail('provider template text is static provider copy, never a rendered body',str_contains($discovery,"'template_text'")&&str_contains($discovery,'mb_substr')&&str_contains($migration,'never a rendered message'));
$fail('webhook verifies raw-body Ed25519 signature',str_contains($signature,'sodium_crypto_sign_verify_detached')&&str_contains($signature,"'|'.\$rawBody")&&str_contains($signature,'webhook_timestamp_stale'));
$fail('webhook controller uses the configured verifier',str_contains($controller,'TelnyxWebhookService::fromSettings')&&str_contains($controller,'get_body')&&str_contains($controller,'no-store'));
$fail('webhook is idempotent by event digest',str_contains($service,'event_reference_digest')&&str_contains($service,'incrementReplay')&&str_contains($service,'terminal'));
$fail('delivery facts use the existing evidence service',str_contains($service,'NotificationDeliveryEvidenceService')&&str_contains($service,'provider_fact_digest')&&str_contains($service,'provider_event_reference_digest'));
$fail('out-of-order evidence remains monotonic',str_contains($service,'delivery_regression')||str_contains($service,'delivery_event_stale'));
$fail('inbound STOP creates purpose-wide suppression',str_contains($service,"'purpose'=>'*'")&&str_contains($service,'suppressFromVerifiedProvider')&&str_contains($service,'provider_stop'));
$fail('suppression repository supports purpose wildcard',str_contains($suppressionRepo,"purpose='*'"));
$fail('STOP parser handles SMS and WhatsApp shapes',str_contains($normalizer,"\$inner['text']")&&str_contains($normalizer,'STOP_WORDS')&&str_contains($normalizer,'لغو'));
$fail('SMS adapter uses Messages API v2',str_contains($sms,'https://api.telnyx.com/v2/messages')&&str_contains($sms,'messaging_profile_id')&&str_contains($sms,'sender_id'));
$fail('WhatsApp adapter uses the WhatsApp endpoint',str_contains($whatsapp,'https://api.telnyx.com/v2/messages/whatsapp')&&str_contains($whatsapp,'whatsapp_message')&&str_contains($whatsapp,'templatePayload'));
$fail('explicit test delivery is tracked without notification evidence',str_contains($whatsapp,'rememberTest')&&str_contains($service,"'test_delivery'"));
$fail('WhatsApp payload supplies the required messaging profile',str_contains($whatsapp,"\$payload['messaging_profile_id']=\$profile")&&str_contains($configuration,'messagingProfileId'));
$fail('explicit WhatsApp test resolves a deterministic approved template',str_contains($read('platform/src/Integrations/IntegrationTestRunner.php'),'TelnyxWhatsAppTestTemplate::resolve')&&str_contains($read('platform/src/Integrations/Notifications/TelnyxWhatsAppTestTemplate.php'),'APPROVED'));
$fail('explicit WhatsApp test surfaces safe provider errors',str_contains($whatsapp,'providerError')&&str_contains($whatsapp,'safeProviderText')&&str_contains($admin,'Safe provider test detail'));
$fail('WhatsApp adapter never invents variables',str_contains($whatsapp,'variable_contract_mismatch')&&str_contains($whatsapp,'template_not_mapped'));
$fail('WhatsApp failures never authorize SMS',!str_contains($whatsapp,'recipientRejection')&&!str_contains($whatsapp,'fallback'));
$fail('WhatsApp locale maps to the approved fa language through the shared authority',str_contains($whatsapp,'TelnyxTemplateAuthority::providerLanguage')&&str_contains($authority,"str_starts_with(strtolower(\$locale),'fa')"));
$providerRepository=$read($root.'/src/Core/Infrastructure/Repository/NotificationProviderRepository.php');
$fail('provider hand-off idempotency is scoped per channel',str_contains($providerRepository,'AND channel=%s')&&str_contains($whatsapp,"messageForAttempt((int)\$notification->id,(int)\$attempt->id,'whatsapp')")&&str_contains($whatsapp,"messageForAttempt(\$notificationId,0,'whatsapp')"));
$fail('SMS hand-off idempotency is scoped per channel',str_contains($sms,"messageForAttempt((int)\$notification->id,(int)\$attempt->id,'sms')")&&str_contains($sms,"messageForAttempt(\$notificationId,0,'sms')"));
$fail('stored phone numbers are normalised to E.164',str_contains($sms,'PhoneNumberNormalizer')&&str_contains($whatsapp,'PhoneNumberNormalizer'));
$fail('shared-number STOP suppresses every matching subject',str_contains($read('platform/src/Integrations/Notifications/StudentNotificationContactLookup.php'),'studentIdsForPhone')&&str_contains($read('platform/src/Integrations/Notifications/StudentNotificationContactLookup.php'),'teacherIdsForPhone')&&str_contains($service,'studentIdsForPhone')&&str_contains($service,'teacherIdsForPhone'));
$fail('provider STOP is scoped to the mobile route',str_contains($read('platform/src/Core/Application/NotificationRule.php'),'providerStopApplies')&&str_contains($read('platform/src/Core/Application/NotificationDispatchService.php'),'providerStopApplies')&&str_contains($suppressionRepo,'allowWildcard'));
$fail('unsupported Telnyx events are acknowledged without retry',str_contains($service,"ignored_event")&&str_contains($service,"webhook_event_unsupported"));
$fail('WhatsApp inbound text uses the nested body shape',str_contains($normalizer,"\$innerText['body']"));
$fail('WhatsApp body sender wins over the business profile sender',str_contains($normalizer,'$bodyFromPhone')&&str_contains($normalizer,'firstString(array($bodyFromPhone,$fromPhone))'));

$fail('out-of-order facts never regress message state',str_contains($service,"if(!empty(\$result['applied']))\$this->provider->updateMessageState"));
$fail('bindings require a verified webhook key',str_contains($configuration,'webhookPublicKey')&&str_contains($configuration,'webhookPublicKey()!==')&&str_contains($bindings,'smsReady()')&&str_contains($bindings,'whatsappReady()'));
$fail('template registry maps approved names',str_contains($registry,'delnavazan_payment_due_v2_fa')&&str_contains($registry,'delnavazan_class_access_v2_fa')&&str_contains($registry,'variable_order'));
$fail('template discovery is explicit and read-only',str_contains($discovery,'/v2/whatsapp/message_templates')&&str_contains($discovery,'Refresh')===false&&str_contains($discovery,'sync()'));
$fail('settings expose Telnyx webhook key and WABA',str_contains($settings,"'webhook_public_key'=>'text'")&&str_contains($settings,"'waba_id'=>'text'")&&str_contains($settings,"'sender_id'=>'text'"));
$fail('admin shows verified webhook and discovery',str_contains($admin,'Verified webhook')&&str_contains($admin,'Refresh approved templates')&&str_contains($admin,'dzn_telnyx_template_sync')&&str_contains($admin,'Webhook URL'));
$fail('admin lists discovered templates with their linked event and variable evidence',str_contains($admin,'Discovered templates (')&&str_contains($admin,'TelnyxTemplateRegistry::definitionForName')&&str_contains($admin,'Not linked'));
$fail('admin compares the provider body with the reviewed local copy',str_contains($admin,'Local copy')&&str_contains($admin,'NotificationCatalogue::defaultCopy')&&str_contains($admin,'No registry'));
$fail('notification admin shows the linked provider name and approved body',str_contains($notificationAdmin,'providerEvidence')&&str_contains($notificationAdmin,'Approved provider body')&&str_contains($notificationAdmin,'dzn_notification_provider_refresh'));
$fail('notification admin names linked and expected provider templates',str_contains($notificationAdmin,'Telnyx:')&&str_contains($notificationAdmin,'provider_expected_name'));
$readiness=$read($root.'/src/Integrations/Notifications/WhatsAppProviderReadinessReadService.php');
$diagnostics=$read($root.'/src/Core/Application/Diagnostics/DiagnosticRegistry.php');
$fail('diagnostics report WhatsApp provider readiness',str_contains($diagnostics,'notifications.whatsapp_provider')&&str_contains($diagnostics,'checkWhatsAppProviderMapping')&&str_contains($readiness,'manual_unverified')&&str_contains($readiness,'disabled_blocked'));
$fail('notification admin shows the registry contract and marks manual mapping without evidence',str_contains($notificationAdmin,'Discovery record:')&&str_contains($notificationAdmin,'Manual mapping (unverified)')&&str_contains($notificationAdmin,'$expectedOrder=$registryOrder'));
$fail('admin readiness is secret-free',str_contains($readService,"'telnyx'=>array")&&str_contains($readService,'webhook_signature')&&str_contains($readService,'dispatch_actor'));
$fail('mobile channels are independently policy-gated',str_contains($composite,'channelPolicy')&&str_contains($composite,'allowed(')&&str_contains($composite,'foreach'));
$fail('no webhook code sends a live message',strpos($service,'wp_remote_post')===false&&strpos($controller,'wp_remote_post')===false);
$fail('no provider secret is rendered by admin',strpos($admin,'api_key')===false||strpos($admin,'value(\'sms\',\'api_key\')')===false);
$providerSection=substr($migration,(int)strpos($migration,'install_telnyx_provider_events'),(int)strpos($migration,'verify_telnyx_provider_events_schema')-(int)strpos($migration,'install_telnyx_provider_events'));
$fail('no raw provider payload is stored',strpos($providerSection,'payload text')===false&&strpos($providerSection,'phone varchar')===false);
require_once $root.'/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root.'/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
$definitions=\Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry::definitions();
$registryEvents=array();
foreach($definitions as $name=>$definition){
    $eventKey=(string)$definition['event_key'];
    $event=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::get($eventKey);
    if($event===null||!in_array('whatsapp',(array)$event['channels'],true))throw new RuntimeException('Telnyx integration contract failed: mapped event does not permit WhatsApp: '.$name);
    if(isset($registryEvents[$eventKey]))throw new RuntimeException('Telnyx integration contract failed: duplicate event mapping for '.$eventKey);
    $registryEvents[$eventKey]=$name;
    $declared=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::variables($eventKey);
    $order=array_values((array)$definition['variable_order']);
    if($order===array()||count($order)!==count(array_unique($order)))throw new RuntimeException('Telnyx integration contract failed: invalid variable order for '.$name);
    if(array_diff($order,$declared)!==array())throw new RuntimeException('Telnyx integration contract failed: undeclared variable in '.$name);
}

/* The three booking intro events ship a WhatsApp channel whose registry order is exactly the
 * first-appearance order of the Persian WhatsApp body placeholders. */
$bookingWhatsApp=array(
    'booking.cancelled.student'=>'delnavazan_intro_cancelled_fa',
    'booking.declined.student'=>'delnavazan_intro_declined_fa',
    'booking.rescheduled.student'=>'delnavazan_intro_rescheduled_fa',
);
$firstAppearance=static function(string $text):array{
    $order=array();
    if(preg_match_all('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',$text,$m)){
        foreach($m[1] as $code)if(!in_array($code,$order,true))$order[]=$code;
    }
    return $order;
};
foreach($bookingWhatsApp as $eventKey=>$name){
    $event=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::get($eventKey);
    $fail('booking WhatsApp event declares the channel: '.$eventKey,$event!==null&&in_array('whatsapp',(array)$event['channels'],true));
    $fail('booking WhatsApp event is registered: '.$eventKey,isset($definitions[$name])&&$definitions[$name]['event_key']===$eventKey);
    $fa=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::exactCopy($eventKey,'whatsapp','fa-IR');
    $en=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::exactCopy($eventKey,'whatsapp','en');
    $fail('booking WhatsApp event has Persian and English copy: '.$eventKey,is_array($fa)&&is_array($en));
    $fail('booking WhatsApp registry order equals the Persian body first-appearance order: '.$eventKey,array_values((array)$definitions[$name]['variable_order'])===$firstAppearance((string)$fa['body']));
}

/* WhatsApp provider-template manifest (deployment/notifications/whatsapp-templates.json). The
 * audited workflow creates templates from this file; discovery maps them through the registry, so
 * every manifest name must resolve to a registry definition and every variable must be declared. */
$manifestPath=$repo.'/deployment/notifications/whatsapp-templates.json';
$fail('WhatsApp template manifest exists',is_file($manifestPath));
$manifest=json_decode((string)file_get_contents($manifestPath),true);
$fail('WhatsApp template manifest is valid JSON',is_array($manifest)&&is_array($manifest['templates']??null));
$fail('WhatsApp template manifest provider is telnyx',(string)($manifest['provider']??'')==='telnyx');
$fail('WhatsApp template manifest version is 1',(int)($manifest['version']??0)===1);
$manifestNames=array();
foreach((array)($manifest['templates']??array()) as $template){
    $name=(string)($template['name']??'');
    $eventKey=(string)($template['event_key']??'');
    if($name===''||$eventKey==='')throw new RuntimeException('Telnyx integration contract failed: manifest template missing name or event key');
    if(isset($manifestNames[$name]))throw new RuntimeException('Telnyx integration contract failed: duplicate manifest template name '.$name);
    $manifestNames[$name]=$eventKey;
    if(!isset($definitions[$name]))throw new RuntimeException('Telnyx integration contract failed: manifest template is not in the registry: '.$name);
    if($definitions[$name]['event_key']!==$eventKey)throw new RuntimeException('Telnyx integration contract failed: manifest/registry event mismatch for '.$name);
    if(strtoupper((string)($template['category']??''))!=='UTILITY')throw new RuntimeException('Telnyx integration contract failed: manifest template is not Utility: '.$name);
    if((string)($template['language']??'')!=='fa')throw new RuntimeException('Telnyx integration contract failed: manifest template is not fa: '.$name);
    $event=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::get($eventKey);
    if($event===null)throw new RuntimeException('Telnyx integration contract failed: manifest event not in catalogue: '.$eventKey);
    $variables=array_values((array)($template['variables']??array()));
    if($variables===array())throw new RuntimeException('Telnyx integration contract failed: manifest template has no variables: '.$name);
    $declared=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::variables($eventKey);
    if(array_diff($variables,$declared)!==array())throw new RuntimeException('Telnyx integration contract failed: manifest declares an undeclared variable for '.$name);
    $body=(string)($template['body']??'');
    if(preg_match('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',$body)===1)throw new RuntimeException('Telnyx integration contract failed: manifest body still has a named placeholder for '.$name);
    preg_match_all('/\{\{([0-9]+)\}\}/',$body,$matches);
    $positions=array_map('intval',$matches[1]);
    if($positions!==range(1,count($variables)))throw new RuntimeException('Telnyx integration contract failed: manifest positional order does not match variables for '.$name);
    if(preg_match('/^\s*\{\{[0-9]+\}\}/',$body)===1||preg_match('/\{\{[0-9]+\}\}\s*$/',$body)===1)throw new RuntimeException('Telnyx integration contract failed: manifest body must not begin or end with a parameter for '.$name);
    $fa=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::defaultCopy($eventKey,'whatsapp','fa-IR');
    $faBody=is_array($fa)?(string)($fa['body']??''):'';
    $normalized=preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',static function(array $match)use($variables):string{
        $position=array_search($match[1],$variables,true);
        return $position===false?$match[0]:'{{'.($position+1).'}}';
    },$faBody);
    if($normalized!==$body)throw new RuntimeException('Telnyx integration contract failed: local WhatsApp copy does not match the manifest for '.$name);
    if(preg_match('/\{\{[a-z][a-z0-9_]{0,63}\}\}\s*$/',$faBody)===1)throw new RuntimeException('Telnyx integration contract failed: local WhatsApp copy must end with static text for '.$name);
    $en=\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::defaultCopy($eventKey,'whatsapp','en');
    $enBody=is_array($en)?(string)($en['body']??''):'';
    if(preg_match('/\{\{[a-z][a-z0-9_]{0,63}\}\}\s*$/',$enBody)===1)throw new RuntimeException('Telnyx integration contract failed: local English WhatsApp copy must end with static text for '.$name);
    preg_match_all('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',$faBody,$faPlaceholders);
    preg_match_all('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',$enBody,$enPlaceholders);
    if($faPlaceholders[1]!==$enPlaceholders[1])throw new RuntimeException('Telnyx integration contract failed: local fa/en WhatsApp placeholders differ for '.$name);
    $samples=is_array($template['samples']??null)?(array)$template['samples']:array();
    $sampleKeys=array_values(array_keys($samples));
    sort($sampleKeys);$sortedVariables=$variables;sort($sortedVariables);
    if($sampleKeys!==$sortedVariables)throw new RuntimeException('Telnyx integration contract failed: manifest samples do not match variables for '.$name);
}
if(count($manifestNames)!==count($definitions))throw new RuntimeException('Telnyx integration contract failed: manifest and registry disagree on template count');
echo "Telnyx SMS and WhatsApp integration contract passed\n";
