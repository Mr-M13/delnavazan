<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Phase 2A.2-R2 channel-neutral notification intents.
 *
 * R2 publishes provider-neutral facts into the existing `platform_outbox` seam owned by the Phase 1
 * foundation. An intent row carries an intent name only: no template, no recipient, no delivery
 * state machine and no transport. Phase S consumes these rows; delivery is never R2's authority.
 *
 * The intent identity is a keyed digest of (aggregate, aggregate id, intent), so a replayed R2
 * command converges on exactly one durable intent row instead of duplicating a notification.
 */
final class RecurringOutboxRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function intentKey(string $aggregate,int $aggregateId,string $intent):string{
        return hash_hmac('sha256','recurring_intent:'.$aggregate.':'.$aggregateId.':'.$intent,wp_salt('dzn_recurring'));
    }

    public function publish(string $aggregate,int $aggregateId,string $intent,int $actor):void{
        global $wpdb;
        $now=gmdate('Y-m-d H:i:s');
        $key=$this->intentKey($aggregate,$aggregateId,$intent);
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}platform_outbox WHERE idempotency_key=%s",$key));
        if($existing!==null)return;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.'platform_outbox',array(
            'aggregate_type'=>substr($aggregate,0,32),
            'aggregate_id'=>$aggregateId,
            'event_type'=>substr($intent,0,64),
            'invitation_id'=>null,'generation_id'=>null,
            'idempotency_key'=>$key,
            'status'=>'pending','available_at'=>$now,'leased_at'=>null,'processed_at'=>null,
            'attempt_count'=>0,'created_at'=>$now,
        ));
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        // A concurrent identical publish is convergence, not corruption: the winner's row stands.
        if($ok===false&&$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}platform_outbox WHERE idempotency_key=%s",$key))===null){
            throw new \RuntimeException('Recurring notification intent persistence failed: '.$error);
        }
    }
}
