<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Append-only evidence of Stripe live activation, deactivation and blocked rotations.
 *
 * It stores only a controlled event type, reason code, account id, readiness digest and actor. No
 * credential, ciphertext, payload or provider reference is ever written here.
 */
final class StripeLiveActivationRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function insert(array $data):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.'stripe_live_activation_events',$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException('Stripe live activation evidence persistence failed: '.$error);
        return (int)$wpdb->insert_id;
    }
    public function recent(int $limit=20):array{
        global $wpdb;$limit=max(1,min(50,$limit));
        return $wpdb->get_results($wpdb->prepare("SELECT event_type,reason_code,account_id,actor_id,occurred_at FROM {$this->p}stripe_live_activation_events ORDER BY id DESC LIMIT %d",$limit))?:array();
    }
}
