<?php
/**
 * Disposable production-path Phase 2A.2-S privacy proof (§11).
 *
 * Covers: no raw contact or parameter value in any read or diagnostic; the authenticated-encryption
 * round trip with its cipher version; erasure nulling the envelope while the digest-anchored row and its
 * events remain; the dispatch-in-flight erasure refusal; and the read/write/dispatch capability
 * separation.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='privacy'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S privacy runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
use Delnavazan\Platform\Core\Application\NotificationPrivacyService;
use Delnavazan\Platform\Core\Application\NotificationReadService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationSupport;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);

// 1. The capability separation is real: read, write and dispatch are distinct grants.
$capabilities=NotificationRule::CAPABILITIES;
dzn_s_fix_assert(count(array_unique($capabilities))===count($capabilities),'every S capability must be distinct');
dzn_s_fix_assert(!in_array(NotificationRule::READ_CAPABILITY,$capabilities,true),'the read capability must be separate from every write capability');
dzn_s_fix_assert(in_array('dzn_operate_notification_dispatch',$capabilities,true),'dispatch must have its own capability');
foreach($capabilities as $capability)dzn_s_fix_assert($capability!==NotificationRule::READ_CAPABILITY,'no write capability may double as the read capability');
$role=get_role('administrator');
foreach($capabilities as $capability)dzn_s_fix_assert($role->has_cap($capability),'the administrator must hold the S capability: '.$capability);

// 2. Crypto round trip: the envelope is ciphertext with its version, and a wrong key fails closed.
$params=array('student_name'=>'Synthetic Learner','amount'=>'25000');
$envelope=NotificationSupport::encryptEnvelope($params);
dzn_s_fix_assert($envelope!==null&&$envelope['cipher_version']==='sodium_secretbox_v1','the parameter envelope must use authenticated encryption with its version');
dzn_s_fix_assert(!str_contains($envelope['envelope'],'Synthetic Learner'),'the envelope must never contain the raw parameter value');
dzn_s_fix_assert(NotificationSupport::decryptEnvelope($envelope['envelope'],$envelope['cipher_version'])===$params,'the envelope must decrypt to the same parameter set');
dzn_s_fix_assert(NotificationSupport::decryptEnvelope($envelope['envelope'],'unknown_v9')===null,'an unknown cipher version must fail closed');
dzn_s_fix_assert(NotificationSupport::decryptEnvelope(substr($envelope['envelope'],0,-1),$envelope['cipher_version'])===null,'a forged envelope must fail closed');

// 3. A read or diagnostic never returns a contact value or an envelope, and an erasure nulls the envelope
//    while the digest-anchored row and its history remain as integrity evidence.
$ready=dzn_s_fix_ready('TERM_LAPSED','privacy');
$cycle=dzn_s_fix_cycle(null,null,'privacy');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','privacy');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','privacy');
$observed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('privacy'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-privacy'));
$notificationId=(int)$observed['notification_id'];
$wpdb->update($p.'notifications',array('recipient_contact_envelope'=>$envelope['envelope'],'contact_cipher_version'=>$envelope['cipher_version'],'contact_expires_at'=>gmdate('Y-m-d H:i:s',strtotime('-1 hour'))),array('id'=>$notificationId));
$read=(new NotificationReadService())->one($notificationId);
foreach(array_keys($read) as $field)dzn_s_fix_assert(stripos($field,'envelope')===false||$field==='contact_envelope_present','a read projection must never expose the envelope itself: '.$field);
dzn_s_fix_assert($read['contact_envelope_present']===true,'the read projection must report the envelope presence as a boolean only');
$report=(new NotificationDiagnosticsReadService())->report();
dzn_s_fix_assert(!str_contains(wp_json_encode($report),'Synthetic Learner'),'no diagnostic may contain a raw parameter or contact value');
$privacy=new NotificationPrivacyService();
$erased=$privacy->eraseRecipient($notificationId,array_merge(dzn_s_fix_evidence('privacy-erase'),array('reason_code'=>'owner_request')),dzn_s_fix_key('erase'));
dzn_s_fix_assert($erased['erased']===true,'the erasure must record');
$afterErase=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",$notificationId));
dzn_s_fix_assert($afterErase->recipient_contact_envelope===null&&$afterErase->contact_cipher_version===null,'erasure must null the contact envelope');
dzn_s_fix_assert((string)$afterErase->notification_key_digest!==''&&$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_events WHERE notification_id=%d",$notificationId))>0,'the digest-anchored row and its history must remain as integrity evidence');
dzn_s_fix_assert(dzn_s_fix_count('notification_privacy_tombstones')===1,'erasure must record exactly one tombstone');
$purged=$privacy->purgeExpiredEnvelopes(dzn_s_fix_evidence('privacy-purge'),dzn_s_fix_key('purge'));
dzn_s_fix_assert(isset($purged['purged']),'the retention pass must report its outcome');

// 4. A dispatch in flight is never erased.
$wpdb->update($p.'notifications',array('state'=>'dispatching'),array('id'=>$notificationId));
dzn_s_fix_rejected(fn()=>$privacy->eraseRecipient($notificationId,array_merge(dzn_s_fix_evidence('privacy-inflight'),array('reason_code'=>'owner_request')),dzn_s_fix_key('erase-inflight')),'notification_dispatch_in_flight','an erasure while the dispatch is in flight');
echo "Phase 2A.2-S privacy runtime passed\n";
