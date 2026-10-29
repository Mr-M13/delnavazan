<?php
/**
 * Delivery-status vocabulary test: a provider hand-off is never reported as confirmed delivery.
 * The read service is exercised against an in-memory wpdb shape; it writes nothing.
 */
namespace Delnavazan\Platform\Tests {
    final class DeliveryStatusWpdb {
        public string $prefix='wp_';
        public string $last_error='';
        public array $rows=array();
        public function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[ds]/',function($m)use(&$i,$args){$v=$args[$i++]??'';return $m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",(string)$v)."'";},$sql);}
        public function suppress_errors($value=true){return false;}
        public function get_results($sql){return $this->rows;}
    }
}

namespace {
    $GLOBALS['wpdb']=new \Delnavazan\Platform\Tests\DeliveryStatusWpdb();
    require_once dirname(__DIR__).'/src/Core/Application/NotificationDeliveryStatusReadService.php';
    use Delnavazan\Platform\Core\Application\NotificationDeliveryStatusReadService as Status;

    $fail=static function(string $name,bool $ok):void{if(!$ok)throw new RuntimeException('Notification delivery status unit failed: '.$name);};

    /* Cross-channel vocabulary. */
    $fail('booking hand-off is handed off',Status::categoryFor('booking','handed_off')===Status::HANDED_OFF);
    $fail('booking hand-off is never confirmed',Status::categoryFor('booking','handed_off')!==Status::DELIVERY_CONFIRMED);
    $fail('reschedule sent is handed off',Status::categoryFor('lesson_reschedule','sent')===Status::HANDED_OFF);
    $fail('reschedule sent is never confirmed',Status::categoryFor('lesson_reschedule','sent')!==Status::DELIVERY_CONFIRMED);
    $fail('canonical dispatched is handed off',Status::categoryFor('canonical','dispatched')===Status::HANDED_OFF);
    $fail('canonical dispatched is never confirmed',Status::categoryFor('canonical','dispatched','2026-10-30 10:00:00')!==Status::DELIVERY_CONFIRMED);
    $fail('canonical delivered needs an applied receipt',Status::categoryFor('canonical','delivered')!==Status::DELIVERY_CONFIRMED);
    $fail('canonical delivered with a receipt is confirmed',Status::categoryFor('canonical','delivered','2026-10-30 10:00:00')===Status::DELIVERY_CONFIRMED);
    $fail('queued booking is queued',Status::categoryFor('booking','queued')===Status::QUEUED);
    $fail('queued reschedule is queued',Status::categoryFor('lesson_reschedule','retry')===Status::QUEUED);
    $fail('failed booking is failed',Status::categoryFor('booking','failed')===Status::FAILED);

    /* Read shape: the summary separates queue, hand-off and confirmed delivery. */
    $GLOBALS['wpdb']->rows=array(
        (object)array('event_key'=>'booking.confirmed.student','state'=>'handed_off','attempt_count'=>1,'handed_off_at'=>'2026-10-30 09:00:00','last_error_code'=>null,'updated_at'=>'2026-10-30 09:00:00','subject_id'=>44),
        (object)array('event_key'=>'booking.declined.student','state'=>'queued','attempt_count'=>0,'handed_off_at'=>null,'last_error_code'=>null,'updated_at'=>'2026-10-30 09:01:00','subject_id'=>44),
        (object)array('event_key'=>'booking.requested.student','state'=>'failed','attempt_count'=>3,'handed_off_at'=>null,'last_error_code'=>'send_refused','updated_at'=>'2026-10-30 09:02:00','subject_id'=>44),
    );
    $status=(new Status())->forBookingRequest(44);
    $fail('hand-off counted',($status['summary'][Status::HANDED_OFF]??0)===1);
    $fail('queue counted',($status['summary'][Status::QUEUED]??0)===1);
    $fail('failure counted',($status['summary'][Status::FAILED]??0)===1);
    $fail('no hand-off is counted as confirmed',($status['summary'][Status::DELIVERY_CONFIRMED]??0)===0);
    $fail('rows carry the operator category',count($status['rows'])===3&&$status['rows'][0]['category']===Status::HANDED_OFF);

    /* A missing table/row set is an empty status, not a fatal page. */
    $GLOBALS['wpdb']->last_error='no such table';
    $empty=(new Status())->forLesson(99);
    $fail('unavailable table yields an empty summary',$empty['rows']===array()&&array_sum($empty['summary'])===0);

    echo "Notification delivery status unit passed\n";
}
