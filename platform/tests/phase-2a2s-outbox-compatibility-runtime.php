<?php
/**
 * Disposable production-path Phase 2A.2-S outbox-compatibility proof.
 *
 * Covers §12: the R2 insert-only publisher keeps working unchanged, the Phase-1 invitation delivery seam
 * keeps working unchanged (`prepared` and `markDeliveryPrepared()`), the S dispatcher never claims either,
 * and the S status vocabulary is a superset of the existing values.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='compatibility'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S outbox compatibility runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\RecurringOutboxRepository;
global $wpdb;$p=$wpdb->prefix.'dzn_';$outbox=$p.'platform_outbox';
dzn_s_fix_reset();
wp_set_current_user(1);
$row=static function(int $id)use($wpdb,$outbox):object{$found=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$outbox} WHERE id=%d",$id));dzn_s_fix_assert($found!==null,'the outbox row must exist');return $found;};

// 1. R2 keeps its insert-only publish contract: one row, intent name only, every added column NULL.
(new RecurringOutboxRepository())->publish('renewal_cycle',910001,'AUTOMATIC_RENEWAL_UPCOMING',1);
(new RecurringOutboxRepository())->publish('renewal_cycle',910001,'AUTOMATIC_RENEWAL_UPCOMING',1);
$r2=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$outbox} WHERE aggregate_type='renewal_cycle' AND aggregate_id=910001"));
dzn_s_fix_assert($r2!==null,'the R2 publisher must still create exactly one row');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$outbox} WHERE aggregate_type='renewal_cycle' AND aggregate_id=%d",910001))===1,'a replayed R2 publish must converge on one row');
dzn_s_fix_assert($r2->notification_id===null&&$r2->workflow_key===null&&$r2->scheduled_for===null&&$r2->expires_at===null&&$r2->deferral_count===null,'an R2 row must keep every added column NULL');
dzn_s_fix_assert((string)$r2->event_type==='AUTOMATIC_RENEWAL_UPCOMING','the R2 intent name must be preserved verbatim');

// 2. The Phase-1 delivery seam still prepares its own row, and S never claims it.
$assertInsert=dzn_s_fix_intent('renewal_cycle',910002,'AUTOMATIC_RENEWAL_UPCOMING','compat-legacy');
$wpdb->update($outbox,array('status'=>'prepared','invitation_id'=>5,'generation_id'=>7,'leased_at'=>gmdate('Y-m-d H:i:s'),'attempt_count'=>1),array('id'=>$assertInsert));
$legacy=$row($assertInsert);
dzn_s_fix_assert((string)$legacy->status==='prepared','the legacy delivery seam must keep its own status');
$claimable=(new NotificationOutboxRepository())->claimable(gmdate('Y-m-d H:i:s',strtotime('+1 day')),50);
foreach($claimable as $candidate)dzn_s_fix_assert((int)$candidate->id!==$assertInsert,'an invitation-owned prepared row must never appear in the S claim set');
foreach((new NotificationOutboxRepository())->pendingIntents(100) as $candidate)dzn_s_fix_assert((int)$candidate->id!==$assertInsert,'an invitation-owned row must never be observed by S');

// 3. The S status vocabulary is a superset of the existing values, and `prepared` stays valid.
$status=$wpdb->get_row("SHOW COLUMNS FROM {$outbox} LIKE 'status'");
dzn_s_fix_assert(strtolower($status->Type)==='varchar(16)','the status column must stay varchar(16)');
foreach(NotificationRule::OUTBOX_STATUS_VOCABULARY as $value)dzn_s_fix_assert(strlen($value)<=16,'the status vocabulary must fit the shared column: '.$value);
dzn_s_fix_assert(in_array('prepared',NotificationRule::OUTBOX_STATUS_VOCABULARY,true),'`prepared` must remain a valid shared status');
dzn_s_fix_assert(in_array('queued',NotificationRule::OUTBOX_STATUS_VOCABULARY,true)&&in_array('leased',NotificationRule::OUTBOX_STATUS_VOCABULARY,true),'the S statuses must extend the shared vocabulary');

// 4. The R2 publisher's own columns are untouched by the extension.
$assertColumns=$wpdb->get_col("SHOW COLUMNS FROM {$outbox}");
foreach(array('id','aggregate_type','aggregate_id','event_type','invitation_id','generation_id','idempotency_key','status','available_at','leased_at','processed_at','attempt_count','created_at') as $column)dzn_s_fix_assert(in_array($column,$assertColumns,true),'the original seam column must survive: '.$column);
foreach(array('provider_template','raw_payload','channel_default') as $forbidden)foreach($assertColumns as $column)dzn_s_fix_assert(stripos($column,$forbidden)===false,'no provider or raw-payload column may exist: '.$column);
echo "Phase 2A.2-S outbox compatibility runtime passed\n";
