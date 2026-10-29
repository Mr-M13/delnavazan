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
    /** @return int[] every Student sharing this number, so one STOP suppresses every affected recipient. */
    public function studentIdsForPhone(string $phone):array{
        return $this->idsForPhone('students',$phone);
    }
    public function teacherIdsForPhone(string $phone):array{
        return $this->idsForPhone('teachers',$phone);
    }
    public function studentIdForPhone(string $phone):?int{return $this->studentIdsForPhone($phone)[0]??null;}
    public function teacherIdForPhone(string $phone):?int{return $this->teacherIdsForPhone($phone)[0]??null;}
    public function recipientDigest(int $studentId):string{
        return hash_hmac('sha256','recipient:student:'.$studentId,NotificationSupport::salt());
    }
    public function teacherRecipientDigest(int $teacherId):string{
        return hash_hmac('sha256','recipient:teacher:'.$teacherId,NotificationSupport::salt());
    }
    /** @return int[] */
    private function idsForPhone(string $table,string $phone):array{
        global $wpdb;
        $variants=$this->variants($phone);
        if($variants===array())return array();
        $name=$wpdb->prefix.'dzn_'.$table;
        $placeholders=implode(',',array_fill(0,count($variants),'%s'));
        $args=array_merge($variants,$variants);
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$name} WHERE archived_at IS NULL AND (phone IN ({$placeholders}) OR whatsapp_phone IN ({$placeholders})) ORDER BY id ASC",
            ...$args
        ));
        return array_values(array_unique(array_map('intval',is_array($ids)?$ids:array())));
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
        if(str_starts_with($digits,'0')&&strlen($digits)===11){$out[]='+98'.substr($digits,1);$out[]='98'.substr($digits,1);}
        if(str_starts_with($digits,'9')&&strlen($digits)===10){$out[]='0'.$digits;$out[]='98'.$digits;}
        if(str_starts_with($digits,'98')&&strlen($digits)===12){$out[]='0'.substr($digits,2);$out[]=substr($digits,2);}
        return array_values(array_unique(array_filter($out)));
    }
}
