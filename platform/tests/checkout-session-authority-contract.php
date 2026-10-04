<?php
$m=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Migration/Migrator.php');
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/CheckoutSessionRepository.php');
$b=file_get_contents(dirname(__DIR__).'/delnavazan-platform.php');
foreach(array('036_checkout_session_authority','checkout_sessions','UNIQUE KEY obligation_active(obligation_id,active_slot)','request_key_digest','provider_reference_digest') as $n)if(strpos($m,$n)===false)throw new RuntimeException('Checkout schema contract missing '.$n);
foreach(array('stripe_session_id','checkout_url','client_secret','provider_payload') as $n)if(strpos($m,"foreach(array('stripe_session_id','checkout_url','client_secret','provider_payload','provider_reference') as \$forbidden")===false)throw new RuntimeException('Checkout forbidden-field verifier missing');
if(strpos($b,"DZN_PLATFORM_SCHEMA_VERSION', '36'")===false)throw new RuntimeException('Schema identity not advanced');
if(strpos($m,"'036_checkout_session_authority'")===false||strpos($m,'verify_checkout_session_authority_schema();')===false)throw new RuntimeException('Checkout schema must be required and verified on current schema');
foreach(array('activeForObligation','byRequestDigest','active_slot'=>null) as $n)if(strpos($r,$n)===false)throw new RuntimeException('Checkout repository contract missing '.$n);
echo "Checkout session authority source contract passed\n";
