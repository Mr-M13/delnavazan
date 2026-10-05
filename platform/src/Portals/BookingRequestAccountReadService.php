<?php
namespace Delnavazan\Platform\Portals;

/** Booking ownership is the authenticated account recorded at submission, never an email match. */
final class BookingRequestAccountReadService {
    public function forCurrentUser():array{
        $userId=(int)get_current_user_id();
        if($userId<1)throw new \InvalidArgumentException('portal_principal_required');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT r.id,r.reference_code,r.lifecycle_status,r.resolution_state,r.created_at,c.state coordination_state FROM {$p}booking_requests r LEFT JOIN {$p}coordination_cases c ON c.booking_request_id=r.id WHERE r.created_by=%d AND r.privacy_erased_at IS NULL ORDER BY r.id DESC LIMIT 50",$userId));
        if($wpdb->last_error)throw new \RuntimeException('booking_account_read_failed');
        $result=[];
        foreach((array)$rows as $row){
            $messages=$wpdb->get_results($wpdb->prepare("SELECT intent_key,delivered_at FROM {$p}notifications WHERE subject_aggregate='booking_request' AND subject_aggregate_id=%d AND audience='student' AND recipient_kind='student' AND delivered_at IS NOT NULL ORDER BY delivered_at DESC LIMIT 20",(int)$row->id));
            if($wpdb->last_error)throw new \RuntimeException('booking_message_read_failed');
            $result[]=[
                'reference'=>(string)$row->reference_code,
                'request_state'=>(string)$row->lifecycle_status,
                'resolution_state'=>(string)$row->resolution_state,
                'coordination_state'=>$row->coordination_state===null?null:(string)$row->coordination_state,
                'created_at'=>(string)$row->created_at,
                'messages'=>array_map(static fn($message):array=>['intent_key'=>(string)$message->intent_key,'delivered_at'=>(string)$message->delivered_at],(array)$messages),
            ];
        }
        return $result;
    }
}
