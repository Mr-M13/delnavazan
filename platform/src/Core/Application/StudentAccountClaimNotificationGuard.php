<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Last-moment #221 binding proof.  The encrypted #220 snapshot is decrypted only for the authorised
 * transport command; this proof confirms that its claim code still names the current live generation.
 */
final class StudentAccountClaimNotificationGuard {
    public static function current(object $notification,array $parameters):bool{
        if((string)$notification->intent_key!=='STUDENT_ACCOUNT_CLAIM_ISSUED')return true;
        $secret=(string)($parameters['claim_code']??'');
        if(preg_match('/^[a-f0-9]{64}$/D',$secret)!==1)return false;
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=NotificationSupport::now();
        $digest=hash_hmac('sha256',$secret,wp_salt('auth'));
        $row=$wpdb->get_var($wpdb->prepare("SELECT g.id FROM {$p}student_account_claim_invitations i INNER JOIN {$p}student_account_claim_generations g ON g.invitation_id=i.id AND g.generation_number=i.current_generation INNER JOIN {$p}students s ON s.id=i.student_id WHERE i.id=%d AND i.state='active' AND g.state='active' AND g.active_slot=1 AND g.token_digest=%s AND g.expires_at>%s AND s.status='active' AND s.archived_at IS NULL",(int)$notification->subject_aggregate_id,$digest,$now));
        return $row!==null;
    }
    /** Repeat the plaintext-to-generation proof while the reservation transaction holds both owning rows. */
    public static function lockCurrent(object $notification,array $parameters):bool{
        if((string)$notification->intent_key!=='STUDENT_ACCOUNT_CLAIM_ISSUED')return true;
        $secret=(string)($parameters['claim_code']??'');
        if(preg_match('/^[a-f0-9]{64}$/D',$secret)!==1)return false;
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=NotificationSupport::now();
        $digest=hash_hmac('sha256',$secret,wp_salt('auth'));
        $invitation=$wpdb->get_row($wpdb->prepare("SELECT id,student_id,state,current_generation FROM {$p}student_account_claim_invitations WHERE id=%d FOR UPDATE",(int)$notification->subject_aggregate_id));
        if(!$invitation||(string)$invitation->state!=='active')return false;
        $row=$wpdb->get_row($wpdb->prepare("SELECT g.id FROM {$p}student_account_claim_generations g INNER JOIN {$p}students s ON s.id=%d WHERE g.invitation_id=%d AND g.generation_number=%d AND g.state='active' AND g.active_slot=1 AND g.token_digest=%s AND g.expires_at>%s AND s.status='active' AND s.archived_at IS NULL FOR UPDATE",(int)$invitation->student_id,(int)$invitation->id,(int)$invitation->current_generation,$digest,$now));
        return $row!==null;
    }
}
