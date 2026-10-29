<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\NotificationSupport;

/**
 * Resolves an inbound STOP sender to the canonical Student recipient digest.
 *
 * Only the local student contact projection is read. The raw phone number is used for this lookup and
 * is never returned, logged or stored by the webhook receipt. The returned digest is exactly the one
 * the notification authority already uses for suppression.
 */
class StudentNotificationContactLookup {
    public function studentIdForPhone(string $phone):?int{
        global $wpdb;
        $variants=$this->variants($phone);
        if($variants===array())return null;
        $table=$wpdb->prefix.'dzn_students';
        $placeholders=implode(',',array_fill(0,count($variants),'%s'));
        $args=array_merge($variants,$variants);
        $id=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE archived_at IS NULL AND (phone IN ({$placeholders}) OR whatsapp_phone IN ({$placeholders})) ORDER BY id ASC LIMIT 1",
            ...$args
        ));
        return $id===null?null:(int)$id;
    }
    public function recipientDigest(int $studentId):string{
        return hash_hmac('sha256','recipient:student:'.$studentId,NotificationSupport::salt());
    }
    /** @return string[] */
    private function variants(string $phone):array{
        $phone=trim($phone);
        if($phone==='')return array();
        $plus=str_starts_with($phone,'+');
        $digits=preg_replace('/[^0-9]/','',$phone)??'';
        if($digits==='')return array();
        $out=array($phone,($plus?'+':'').$digits,$digits);
        if(str_starts_with($digits,'00'))$out[]='+'.substr($digits,2);
        if(str_starts_with($digits,'0')&&strlen($digits)===11)$out[]='+98'.substr($digits,1);
        return array_values(array_unique(array_filter($out)));
    }
}
