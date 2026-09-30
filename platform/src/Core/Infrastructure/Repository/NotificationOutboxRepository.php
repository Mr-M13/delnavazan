<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\NotificationSupport;

/**
 * Phase 2A.2-S read/write adapter over the *existing* `platform_outbox` seam.
 *
 * S extends the Phase-1/R2 seam; it creates no second queue, no second lease mechanism and no
 * provider-specific delivery table, and it never replaces `RecurringOutboxRepository` — R2 keeps its
 * insert-only `publish()` contract unchanged. S mutates only rows it owns: a row is S-owned when it has
 * a `notification_id` and carries neither `invitation_id` nor `generation_id` (the Phase-1 delivery seam)
 * nor the legacy `prepared` status.
 */
final class NotificationOutboxRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    /** One S-owned row, locked for the caller's transaction when requested. */
    public function row(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}platform_outbox WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    /** The row that backs one notification, read through the S-owned unique key. */
    public function forNotification(int $notificationId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}platform_outbox WHERE notification_id=%d".($lock?' FOR UPDATE':''),$notificationId);}
    /**
     * The pending intent rows S may observe: rows with no notification yet, an intent S registers and a
     * status no other writer owns. A Phase-1 `prepared` row and every non-S row are never selected.
     */
    public function pendingIntents(int $limit=100):array{
        global $wpdb;$limit=max(1,min($limit,500));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}platform_outbox WHERE notification_id IS NULL AND invitation_id IS NULL AND generation_id IS NULL AND status IN ('pending') ORDER BY id ASC LIMIT %d",
            $limit
        ))?:array();
    }
    /**
     * Claimable S-owned work: only a `queued` notification inside its derived window is dispatchable, and
     * the pre-scheduling rows (the derived triple still NULL) are never selected (§9).
     */
    public function claimable(string $now,int $limit=50):array{
        global $wpdb;$limit=max(1,min($limit,200));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.* FROM {$this->p}platform_outbox o JOIN {$this->p}notifications n ON n.id=o.notification_id
             WHERE n.state='queued' AND o.status='queued' AND o.scheduled_for IS NOT NULL AND o.expires_at IS NOT NULL
               AND o.expires_at>%s AND o.available_at<=%s AND o.leased_at IS NULL
             ORDER BY o.available_at ASC, o.id ASC LIMIT %d",
            $now,$now,$limit
        ))?:array();
    }
    /**
     * Queued S-owned work whose mandatory window has already closed before any lease: the `queued` state
     * invariant is "eligible and inside its window" (§6.5), so such a row must be expired, never claimed.
     */
    public function overdue(string $now,int $limit=50):array{
        global $wpdb;$limit=max(1,min($limit,200));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.* FROM {$this->p}platform_outbox o JOIN {$this->p}notifications n ON n.id=o.notification_id
             WHERE n.state='queued' AND o.status='queued' AND o.leased_at IS NULL AND o.expires_at IS NOT NULL AND o.expires_at<=%s
             ORDER BY o.expires_at ASC, o.id ASC LIMIT %d",
            $now,$limit
        ))?:array();
    }
    /** Close an overdue queued row in place: it left its window before any lease, so it is never claimable. */
    public function expireOverdue(int $rowId,string $reasonCode):void{
        $this->guardedUpdate($rowId,array('status'=>'queued','leased_at'=>null),array('status'=>'expired','failure_reason_code'=>$reasonCode,'processed_at'=>NotificationSupport::now()));
    }
    /**
     * Enrich a pending intent row before any instant is derived: identity columns only, so the derived
     * triple stays NULL and the row is not claimable (§6.3(c)(8)).
     */
    public function enrichIdentity(int $rowId,array $fields):void{$this->guardedUpdate($rowId,array('status'=>'pending'),$fields);}
    /**
     * The `scheduled` transition: the mirror is written in full — `scheduled_for`, `expires_at`,
     * `deferral_count` and an `available_at` that starts equal to the mirrored `scheduled_for`.
     */
    public function mirrorSchedule(int $rowId,array $fields):void{
        $data=array(
            'scheduled_for'=>$fields['scheduled_for'],'expires_at'=>$fields['expires_at'],
            'deferral_count'=>$fields['deferral_count'],'available_at'=>$fields['scheduled_for'],
            'status'=>'scheduled','failure_reason_code'=>null,
        );
        if(array_key_exists('priority',$fields))$data['priority']=$fields['priority'];
        $this->guardedUpdate($rowId,array('status'=>'pending'),$data);
    }
    /** Move a mirrored row into the claimable vocabulary once the notification is eligible and queued. */
    public function queue(int $rowId):void{$this->guardedUpdate($rowId,array('status'=>'scheduled'),array('status'=>'queued'));}
    /** Move a claimable row back out of the claim set when its notification leaves `queued` for a terminal state. */
    public function retire(int $rowId,string $status,?string $reasonCode):void{
        $this->guardedUpdate($rowId,array('status'=>'queued'),array('status'=>$status,'failure_reason_code'=>$reasonCode,'processed_at'=>NotificationSupport::now()));
    }
    /** An accepted §6.3(e) deferral moves the mirror's `scheduled_for`/`deferral_count` and `available_at`. */
    public function mirrorDeferral(int $rowId,string $scheduledFor,int $deferralCount):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'platform_outbox',array('scheduled_for'=>$scheduledFor,'deferral_count'=>$deferralCount,'available_at'=>$scheduledFor),array('id'=>$rowId));
        if($changed!==1)throw new \RuntimeException('notification_outbox_state_conflict');
    }
    /** A §9 re-arm moves `available_at` alone: the mirrored `scheduled_for` follows the aggregate only. */
    public function rearm(int $rowId,string $nextAvailableAt):void{
        $this->guardedUpdate($rowId,array('status'=>'leased'),array('status'=>'queued','available_at'=>$nextAvailableAt,'leased_at'=>null,'lease_token_digest'=>null));
    }
    /**
     * The lease: guarded on the exact prior status, so a lost race simply loses the claim, and
     * `attempt_count` is incremented exactly once per acquisition (§9).
     */
    public function lease(int $rowId,string $now,string $tokenDigest):int{
        global $wpdb;
        $updated=$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}platform_outbox SET status='leased', leased_at=%s, lease_token_digest=%s, attempt_count=attempt_count+1 WHERE id=%d AND status='queued' AND leased_at IS NULL",
            $now,$tokenDigest,$rowId
        ));
        if($updated!==1)throw new \RuntimeException('notification_lease_not_acquired');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT attempt_count FROM {$this->p}platform_outbox WHERE id=%d",$rowId));
    }
    /** Release the lease without closing the work: the row returns to its prior claimable status. */
    public function releaseLease(int $rowId,string $status='queued'):void{
        $this->guardedUpdate($rowId,array('status'=>'leased'),array('status'=>$status,'leased_at'=>null,'lease_token_digest'=>null));
    }
    /** Close the row in place after a terminal closure or an exhausted lease. */
    public function close(int $rowId,string $status,?string $reasonCode):void{
        $this->guardedUpdate($rowId,array('status'=>'leased'),array('status'=>$status,'leased_at'=>null,'lease_token_digest'=>null,'failure_reason_code'=>$reasonCode,'processed_at'=>NotificationSupport::now()));
    }
    /** Close the row in place after exhaustion: `available_at` is deliberately left unadvanced. */
    public function closeExhausted(int $rowId,?string $reasonCode):void{
        $this->guardedUpdate($rowId,array('status'=>'leased'),array('status'=>'failed','leased_at'=>null,'lease_token_digest'=>null,'failure_reason_code'=>$reasonCode));
    }
    /** Mark one S-owned row as delivered, preserving the mirrored schedule values. */
    public function markDelivered(int $rowId,string $now):void{
        $this->guardedUpdate($rowId,array('status'=>'leased'),array('status'=>'delivered','leased_at'=>null,'lease_token_digest'=>null,'processed_at'=>$now));
    }
    /** Close an S-owned row from any non-terminal state when the notification closes by command. */
    public function closeAny(int $rowId,string $status,?string $reasonCode):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'platform_outbox',array('status'=>$status,'leased_at'=>null,'lease_token_digest'=>null,'failure_reason_code'=>$reasonCode,'processed_at'=>NotificationSupport::now()),array('id'=>$rowId));
        if($changed!==1)throw new \RuntimeException('notification_outbox_state_conflict');
    }
    /** The durable attempt counter S reuses instead of adding a competing counter (§6.6). */
    public function attemptCount(int $rowId):int{global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT attempt_count FROM {$this->p}platform_outbox WHERE id=%d",$rowId));}
    private function guardedUpdate(int $rowId,array $where,array $fields):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'platform_outbox',$fields,$where+array('id'=>$rowId));
        if($changed!==1)throw new \RuntimeException('notification_outbox_state_conflict');
    }
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
}
