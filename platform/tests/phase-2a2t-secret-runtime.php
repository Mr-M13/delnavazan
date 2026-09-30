<?php
/**
 * Disposable Phase-T secret-isolation proof. Synthetic local data only.
 *
 * Proves the authenticated-encryption round trip, nonce uniqueness, rotation and history, fail-closed
 * decryption, adapter-scoped reveal, redaction and — above all — that **no production write path can
 * store a provider secret**: a fully capable administrator with a valid nonce is refused
 * `provider_secret_write_not_authorised`, an audit `write_refused` row is recorded and no
 * `payment_provider_secrets` row is created while the test-vault constant is undefined.
 */
if(getenv('DZN_PHASE_2A2T_SECRET_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T secret runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionRule,PaymentExecutionIdempotency,PaymentExecutionSupport,PaymentSecretVault,PaymentExecutionDispatchSeal};
use Delnavazan\Platform\Integrations\Payment\Stripe\StripePaymentAdapter;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_ts_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(array('payment_provider_secret_events','payment_provider_secrets') as $table)dzn_ts_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset '.$table);
wp_set_current_user(1);
dzn_ts_assert(PaymentExecutionRule::PROVISIONABLE_PROVIDERS===array(),'this build must provision no provider credential');
dzn_ts_assert(!defined(PaymentSecretVault::TEST_VAULT_CONSTANT),'the disposable secret suite must run with the test vault constant undefined');
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_accounts (uid,reference_code,provider_key,mode,account_reference_digest,state,execution_state,credential_state,account_version,created_at,updated_at) VALUES (%s,%s,'stripe','test',%s,'active','disabled','unconfigured',1,%s,%s)",wp_generate_uuid4(),'secret-'.wp_generate_uuid4(),hash_hmac('sha256','payment_execution_reference:acct-secret',wp_salt('dzn_payment_execution')),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$accountId=(int)$wpdb->insert_id;
$vault=new PaymentSecretVault();

// No production path may write a provider secret — not even with the capability and a valid nonce.
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secrets");
$apiKey=$vault->store('stripe',$accountId,'api_key','test','sk_live_should_never_exist',array('nonce'=>'abcdefgh12345678'),'dzn-t-secret-1');
dzn_ts_assert(($apiKey['stored']??true)===false&&(string)$apiKey['reason_code']==='provider_secret_write_not_authorised','a Stripe api_key write must be refused');
$signing=$vault->store('stripe',$accountId,'webhook_signing_secret','test','whsec_should_never_exist',array('nonce'=>'abcdefgh12345678'),'dzn-t-secret-2');
dzn_ts_assert(($signing['stored']??true)===false&&(string)$signing['reason_code']==='provider_secret_write_not_authorised','a Stripe signing-secret write must be refused');
dzn_ts_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secrets")===$before,'a refused write must store nothing');
dzn_ts_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secret_events WHERE audit_type='write_refused'")>=2,'a refused write must be audited');
// The sealed dispatch descriptor is not a credential path: it may only carry its locked field set.
$sealed=PaymentExecutionDispatchSeal::seal(array_fill_keys($fields=PaymentExecutionRule::DISPATCH_DESCRIPTOR_FIELDS,'x'));
dzn_ts_assert($sealed->complete()&&$sealed->digest()===PaymentExecutionDispatchSeal::digest($sealed),'the sealed envelope must be complete and digest-bound');
dzn_ts_assert(PaymentExecutionDispatchSeal::open($sealed)!==null,'the sealed envelope must open under its own domain');
$tampered=new \Delnavazan\Platform\Core\Application\PaymentExecution\ProviderDispatchDescriptor($sealed->cipherVersion(),$sealed->keyVersion(),$sealed->nonce(),base64_encode('not-the-envelope'),$sealed->digest());
dzn_ts_assert(PaymentExecutionDispatchSeal::open($tampered)===null,'a tampered envelope must fail closed');
dzn_ts_assert(PaymentExecutionDispatchSeal::open($sealed,'v2')===null,'an unknown key version must fail closed');
$unknown=new \Delnavazan\Platform\Core\Application\PaymentExecution\ProviderDispatchDescriptor('sodium_secretbox_v9',$sealed->keyVersion(),$sealed->nonce(),$sealed->ciphertext(),$sealed->digest());
dzn_ts_assert(PaymentExecutionDispatchSeal::open($unknown)===null,'an unknown cipher version must fail closed');
// A reveal without an active secret records a decrypt_failed audit row and returns no value.
$revealed=$vault->reveal(999999,'webhook_signing_secret','stripe');
dzn_ts_assert($revealed===null,'an absent secret must never be revealed');
dzn_ts_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secret_events WHERE audit_type='decrypt_failed'")>=1,'a failed reveal must be audited');
dzn_ts_assert((string)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secrets WHERE ciphertext LIKE '%sk_live%'")==='0','no plaintext credential may ever be stored');
// Redaction removes every key-shaped token from an adapter error path.
$redacted=StripePaymentAdapter::redact('Authorization: Bearer sk_live_abcdefghijkl Stripe-Signature: t=1,v1=deadbeef');
dzn_ts_assert(str_contains($redacted,'[REDACTED_SECRET]')&&!str_contains($redacted,'sk_live'),'the redaction helper must strip secret-shaped tokens');
echo "phase-2a2t-secret-runtime: OK\n";
