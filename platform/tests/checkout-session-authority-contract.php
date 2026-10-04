<?php
$m=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Migration/Migrator.php');
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/CheckoutSessionRepository.php');
$b=file_get_contents(dirname(__DIR__).'/delnavazan-platform.php');
$d=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutSessionDiagnosticsService.php');
$c=file_get_contents(dirname(__DIR__).'/src/Admin/Controller/PaymentExecutionController.php');
foreach(array('036_checkout_session_authority','checkout_sessions','UNIQUE KEY obligation_active(obligation_id,active_slot)','request_key_digest','provider_reference_digest') as $n)if(strpos($m,$n)===false)throw new RuntimeException('Checkout schema contract missing '.$n);
foreach(array('stripe_session_id','checkout_url','client_secret','provider_payload') as $n)if(strpos($m,"foreach(array('stripe_session_id','checkout_url','client_secret','provider_payload','provider_reference') as \$forbidden")===false)throw new RuntimeException('Checkout forbidden-field verifier missing');
if(strpos($b,"DZN_PLATFORM_SCHEMA_VERSION', '36'")===false)throw new RuntimeException('Schema identity not advanced');
if(strpos($m,"'036_checkout_session_authority'")===false||strpos($m,'verify_checkout_session_authority_schema();')===false)throw new RuntimeException('Checkout schema must be required and verified on current schema');
foreach(array('activeForObligation','byRequestDigest','active_slot'=>null) as $n)if(strpos($r,$n)===false)throw new RuntimeException('Checkout repository contract missing '.$n);
$diagnosticQuery=strpos($r,'SELECT uid,state,provider_key,created_at,expires_at FROM');
if($diagnosticQuery===false||strpos(substr($r,$diagnosticQuery,strpos($r,';', $diagnosticQuery)-$diagnosticQuery),'SELECT *')!==false)throw new RuntimeException('Checkout diagnostic query must select only safe local lifecycle fields');
foreach(array('requireCapability','activeForDiagnostics','stale_creating','expired_open','age_seconds','needs_attention') as $n)if(strpos($d,$n)===false)throw new RuntimeException('Checkout operational diagnostics missing '.$n);
foreach(array('Active Checkout attempts','CheckoutSessionDiagnosticsService','attempt_uid','needs_attention') as $n)if(strpos($c,$n)===false)throw new RuntimeException('Checkout attempt diagnostics presentation missing '.$n);
foreach(array('provider_reference','redirect_url','student_id','amount_minor','currency') as $n)if(strpos($d,$n)!==false)throw new RuntimeException('Checkout diagnostics must not expose provider references or student/commercial details');
echo "Checkout session authority source contract passed\n";
