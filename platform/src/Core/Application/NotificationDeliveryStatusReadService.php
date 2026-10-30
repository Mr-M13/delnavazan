<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only, contact-free delivery status for a booking request or class record.
 *
 * The operational queues and the canonical authority use different tables and state names, but the
 * operator-facing distinction is always the same: queued, handed off to a provider, or delivery
 * confirmed. A transport hand-off is never promoted to "delivered"; only an applied delivery fact in
 * the canonical authority is reported as confirmed.
 */
final class NotificationDeliveryStatusReadService {
    public const QUEUED='queued';
    public const HANDED_OFF='handed_off';
    public const DELIVERY_CONFIRMED='delivery_confirmed';
    public const PORTAL_RECORDED='portal_recorded';
    public const FAILED='failed';
    public const CLOSED='closed';

    /** @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>} */
    public function forBookingRequest(int $bookingRequestId):array{
        if($bookingRequestId<1)return $this->empty();
        $rows=$this->query(
            "SELECT n.notification_kind AS event_key,n.state,n.attempt_count,n.handed_off_at,n.last_error_code,n.updated_at,d.booking_request_id AS subject_id
             FROM {$this->table('booking_request_student_notifications')} n
             INNER JOIN {$this->table('booking_request_teacher_deliveries')} d ON d.id=n.delivery_id
             WHERE d.booking_request_id=%d
             ORDER BY n.id DESC LIMIT 100",
            $bookingRequestId
        );
        $status=$this->shape('booking',$rows);
        $portal=$this->query(
            "SELECT p.event_key,p.audience,'website' AS channel,'recorded' AS state,p.created_at AS updated_at,d.booking_request_id AS subject_id
             FROM {$this->table('portal_notifications')} p
             INNER JOIN {$this->table('booking_request_student_notifications')} n ON n.id=p.source_id
             INNER JOIN {$this->table('booking_request_teacher_deliveries')} d ON d.id=n.delivery_id
             WHERE p.source_kind='booking_notification' AND d.booking_request_id=%d
             ORDER BY p.id DESC LIMIT 100",
            $bookingRequestId
        );
        return $this->append($status,'portal',$portal);
    }

    /** @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>} */
    public function forLesson(int $lessonId):array{
        if($lessonId<1)return $this->empty();
        $rows=$this->query(
            "SELECT n.event_type AS event_key,n.audience,n.channel,n.state,n.attempt_count,n.sent_at,n.last_error_code,n.updated_at,p.lesson_id AS subject_id
             FROM {$this->table('lesson_reschedule_notifications')} n
             INNER JOIN {$this->table('lesson_reschedule_proposals')} p ON p.id=n.proposal_id
             WHERE p.lesson_id=%d
             ORDER BY n.id DESC LIMIT 100",
            $lessonId
        );
        $status=$this->shape('lesson_reschedule',$rows);
        $attendance=$this->query(
            "SELECT event_key,audience,'website' AS channel,'recorded' AS state,created_at AS updated_at,source_id AS subject_id
             FROM {$this->table('portal_notifications')}
             WHERE source_kind='attendance_notification' AND source_id=%d
             ORDER BY id DESC LIMIT 100",
            $lessonId
        );
        $status=$this->append($status,'portal',$attendance);
        $reschedule=$this->query(
            "SELECT p.event_key,p.audience,'website' AS channel,'recorded' AS state,p.created_at AS updated_at,r.lesson_id AS subject_id
             FROM {$this->table('portal_notifications')} p
             INNER JOIN {$this->table('lesson_reschedule_proposals')} r ON r.id=p.source_id
             WHERE p.source_kind='lesson_reschedule' AND r.lesson_id=%d
             ORDER BY p.id DESC LIMIT 100",
            $lessonId
        );
        return $this->append($status,'portal',$reschedule);
    }

    /** @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>} */
    public function forSubject(string $aggregate,int $subjectId):array{
        if($aggregate===''||$subjectId<1)return $this->empty();
        $rows=$this->query(
            "SELECT intent_key AS event_key,state,delivered_at,updated_at
             FROM {$this->table('notifications')}
             WHERE subject_aggregate=%s AND subject_aggregate_id=%d
             ORDER BY id DESC LIMIT 100",
            $aggregate,$subjectId
        );
        return $this->shape('canonical',$rows);
    }

    /** @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>} */
    public function empty():array{
        return array(
            'summary'=>array(self::QUEUED=>0,self::HANDED_OFF=>0,self::DELIVERY_CONFIRMED=>0,self::PORTAL_RECORDED=>0,self::FAILED=>0,self::CLOSED=>0),
            'rows'=>array(),
        );
    }

    /** One shared operator vocabulary, so a hand-off can never be labelled as confirmed delivery. */
    public static function categoryFor(string $source,string $state,?string $deliveredAt=null):string{
        $state=strtolower(trim($state));
        if($source==='canonical'){
            if($state==='delivered'&&$deliveredAt!==null&&$deliveredAt!=='')return self::DELIVERY_CONFIRMED;
            if($state==='dispatched')return self::HANDED_OFF;
            if(in_array($state,array('pending','scheduled','queued','dispatching'),true))return self::QUEUED;
            if(in_array($state,array('failed','expired'),true))return self::FAILED;
            return self::CLOSED;
        }
        if($source==='booking'){
            if($state==='handed_off')return self::HANDED_OFF;
            if(in_array($state,array('queued','retryable','sending'),true))return self::QUEUED;
            return self::FAILED;
        }
        if($source==='lesson_reschedule'){
            if($state==='sent')return self::HANDED_OFF;
            if(in_array($state,array('queued','retry'),true))return self::QUEUED;
            return self::FAILED;
        }
        if($source==='portal'&&$state==='recorded')return self::PORTAL_RECORDED;
        return self::CLOSED;
    }

    /** @param array{summary:array<string,int>,rows:array<int,array<string,mixed>>} $status
     *  @param array<int,object> $rows
     */
    private function append(array $status,string $source,array $rows):array{
        $extra=$this->shape($source,$rows);
        foreach($extra['summary'] as $category=>$count)$status['summary'][$category]+=$count;
        return array('summary'=>$status['summary'],'rows'=>array_merge($status['rows'],$extra['rows']));
    }

    /** @param array<int,object> $rows */
    private function shape(string $source,array $rows):array{
        $out=$this->empty();
        foreach($rows as $row){
            $state=(string)($row->state??'');
            $deliveredAt=isset($row->delivered_at)?(string)$row->delivered_at:null;
            $category=self::categoryFor($source,$state,$deliveredAt);
            $out['summary'][$category]=(int)($out['summary'][$category]??0)+1;
            $out['rows'][]=array(
                'source'=>$source,
                'event_key'=>(string)($row->event_key??''),
                'audience'=>(string)($row->audience??''),
                'channel'=>(string)($row->channel??'email'),
                'state'=>$state,
                'category'=>$category,
                'attempt_count'=>(int)($row->attempt_count??0),
                'subject_id'=>(int)($row->subject_id??0),
                'handed_off_at'=>isset($row->handed_off_at)&&$row->handed_off_at!==null?(string)$row->handed_off_at:(isset($row->sent_at)&&$row->sent_at!==null?(string)$row->sent_at:null),
                'delivery_confirmed_at'=>$category===self::DELIVERY_CONFIRMED?$deliveredAt:null,
                'last_error_code'=>isset($row->last_error_code)&&$row->last_error_code!==null?(string)$row->last_error_code:null,
                'updated_at'=>(string)($row->updated_at??''),
            );
        }
        return $out;
    }

    private function table(string $name):string{
        global $wpdb;
        return $wpdb->prefix.'dzn_'.$name;
    }

    /** @return array<int,object> */
    private function query(string $sql,int|string ...$args):array{
        global $wpdb;
        if(!is_object($wpdb)||!method_exists($wpdb,'get_results'))return array();
        $old=method_exists($wpdb,'suppress_errors')?$wpdb->suppress_errors(true):null;
        try{
            $rows=$wpdb->get_results($wpdb->prepare($sql,...$args));
            if(!is_array($rows))return array();
            if(isset($wpdb->last_error)&&(string)$wpdb->last_error!=='')return array();
            return $rows;
        }catch(\Throwable){
            // A missing optional queue table is an absent status, never a fatal admin page.
            return array();
        }finally{
            if(method_exists($wpdb,'suppress_errors'))$wpdb->suppress_errors($old);
        }
    }
}
