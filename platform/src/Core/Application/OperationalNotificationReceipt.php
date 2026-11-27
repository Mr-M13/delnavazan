<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Durable once-only claim for an operational notification event.
 *
 * The portal inbox is a presentation record, not a send lock: a student without a portal account
 * produces no inbox row, so a two-minute worker could resend the same email indefinitely. This
 * claim uses the existing unique `platform_audit_events.idempotency_key` as the durable lock. It is
 * written before any transport, so a missing portal row, a provider failure or overlapping cron
 * runs cannot produce a second copy. It stores no contact value.
 */
final class OperationalNotificationReceipt {
    public const AGGREGATE='notification';

    /**
     * Claim one event/subject/audience/channel once. False means already claimed or the claim could
     * not be written; callers must then skip the send rather than risk a duplicate.
     */
    public static function claim(string $eventKey,int $subjectId,string $audience,string $channel='all'):bool{
        global $wpdb;
        if($subjectId<1)return false;
        // WordPress-free unit harnesses have no database; they exercise the send path, not the lock.
        if(!is_object($wpdb))return true;
        $eventKey=trim($eventKey);
        if($eventKey==='')return false;
        $salt=function_exists('wp_salt')?wp_salt('dzn_operational_notification'):'dzn_operational_notification';
        $key=hash_hmac('sha256','operational_notification:'.$eventKey.':'.$subjectId.':'.$audience.':'.$channel,$salt);
        $detail=function_exists('wp_json_encode')
            ?wp_json_encode(array('event_key'=>$eventKey,'audience'=>$audience,'channel'=>$channel))
            :json_encode(array('event_key'=>$eventKey,'audience'=>$audience,'channel'=>$channel));
        $table=$wpdb->prefix.'dzn_platform_audit_events';
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($table,array(
            'aggregate_type'=>self::AGGREGATE,
            'aggregate_id'=>$subjectId,
            'event_type'=>substr('notification.'.$channel,0,64),
            'actor_type'=>'system',
            'actor_id'=>null,
            'reason_code'=>'claimed',
            'safe_detail'=>is_string($detail)?$detail:null,
            'idempotency_key'=>$key,
            'occurred_at'=>gmdate('Y-m-d H:i:s'),
        ));
        $wpdb->suppress_errors($old);
        return $ok!==false;
    }
}
