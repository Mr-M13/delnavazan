<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only operator projection for the WhatsApp-first / SMS-on-verified-failure policy.
 *
 * It exposes state, attempt ids, fallback ids and safe reason codes only.  It never reads or returns a
 * recipient value, provider identifier, message body, API key or raw provider payload.
 */
final class NotificationSmsFallbackReadService {
    public const BLOCKED='blocked';
    public const ELIGIBLE='eligible';
    public const SENT='sent';
    public const DELIVERED='delivered';

    /** @return array{rows:array<int,array<string,mixed>>,counts:array<string,int>} */
    public function report(int $limit=50):array{
        $limit=max(1,min($limit,200));
        global $wpdb;
        if(!is_object($wpdb)||!method_exists($wpdb,'get_results'))return array('rows'=>array(),'counts'=>array());
        $p=$wpdb->prefix.'dzn_';
        $old=method_exists($wpdb,'suppress_errors')?$wpdb->suppress_errors(true):null;
        try{
            $commands=$wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$p}notification_commands WHERE operation IN ('".NotificationSmsFallbackService::COMMAND_AUTHORISED."','".NotificationSmsFallbackService::COMMAND_BLOCKED."') ORDER BY id DESC LIMIT %d",
                $limit
            ))?:array();
            $rows=array();$counts=array(self::BLOCKED=>0,self::ELIGIBLE=>0,self::SENT=>0,self::DELIVERED=>0);
            foreach($commands as $command){
                $sourceId=(int)$command->notification_id;
                $source=$this->notification($p,$sourceId);
                if($source===null)continue;
                $fallbackId=(string)$command->operation===NotificationSmsFallbackService::COMMAND_AUTHORISED?(int)$command->result_id:0;
                $fallback=$fallbackId>0?$this->notification($p,$fallbackId):null;
                $attemptId=$fallbackId>0?$this->sourceAttempt($p,$fallbackId):0;
                $whatsappState=$this->latestDelivery($p,$sourceId);
                $smsState=$fallbackId>0?$this->latestDelivery($p,$fallbackId):null;
                $fallbackState=$fallback?(string)$fallback->state:'';
                $fallbackReason=$fallback&&$fallback->failure_reason_code!==null?(string)$fallback->failure_reason_code:'';
                $category=self::BLOCKED;
                if((string)$command->operation===NotificationSmsFallbackService::COMMAND_AUTHORISED){
                    $category=$fallbackState==='delivered'||$smsState==='delivered'?self::DELIVERED:($fallbackState==='dispatched'||$smsState==='sent'?self::SENT:self::ELIGIBLE);
                }
                $counts[$category]=(int)($counts[$category]??0)+1;
                $rows[]=array(
                    'source_notification_id'=>$sourceId,
                    'source_state'=>(string)$source->state,
                    'whatsapp_state'=>$whatsappState,
                    'whatsapp_label'=>$this->whatsappLabel((string)$source->state,$whatsappState),
                    'source_attempt_id'=>$attemptId,
                    'fallback_notification_id'=>$fallbackId>0?$fallbackId:null,
                    'fallback_state'=>$fallbackState!==''?$fallbackState:null,
                    'sms_state'=>$smsState,
                    'sms_label'=>$this->smsLabel((string)$command->operation,$fallbackState,$smsState,(string)$command->result_state,$fallbackReason),
                    'blocked_reason'=>(string)$command->operation===
                        NotificationSmsFallbackService::COMMAND_BLOCKED?(string)$command->result_state:null,
                    'recorded_at'=>(string)$command->created_at,
                );
            }
            return array('rows'=>$rows,'counts'=>$counts);
        }catch(\Throwable){
            return array('rows'=>array(),'counts'=>array());
        }finally{
            if(method_exists($wpdb,'suppress_errors'))$wpdb->suppress_errors($old);
        }
    }

    public static function whatsappLabel(string $state,?string $delivery):string{
        if($delivery==='delivered'||$state==='delivered')return 'WhatsApp delivered';
        if($delivery==='failed'||$state==='failed')return 'WhatsApp failed';
        if($state==='dispatched')return 'WhatsApp accepted (delivery pending)';
        if(in_array($state,array('pending','scheduled','queued','dispatching'),true))return 'WhatsApp queued';
        if($state==='suppressed')return 'WhatsApp suppressed';
        if($state==='cancelled')return 'WhatsApp cancelled';
        if($state==='expired')return 'WhatsApp expired';
        return 'WhatsApp status unknown';
    }

    public static function smsLabel(string $operation,string $fallbackState,?string $smsState,string $reason,string $fallbackReason=''):string{
        if($operation===NotificationSmsFallbackService::COMMAND_BLOCKED)return 'SMS fallback blocked: '.$reason;
        if($smsState==='delivered'||$fallbackState==='delivered')return 'SMS delivered';
        if($fallbackState==='dispatched'||$smsState==='sent')return 'SMS fallback sent';
        if(in_array((string)$smsState,array('failed','undelivered','expired'),true))return 'SMS fallback blocked: sms_delivery_failed';
        if($fallbackState==='cancelled'&&$fallbackReason==='whatsapp_delivered')return 'SMS fallback cancelled: WhatsApp delivered';
        if(in_array($fallbackState,array('failed','suppressed','expired','cancelled'),true))return 'SMS fallback blocked: '.($fallbackReason!==''?$fallbackReason:$fallbackState);
        return 'SMS fallback eligible';
    }

    private function notification(string $p,int $id):?object{
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id,state,reference_code,failure_reason_code FROM {$p}notifications WHERE id=%d",$id));
    }
    private function latestDelivery(string $p,int $notificationId):?string{
        global $wpdb;
        $state=$wpdb->get_var($wpdb->prepare("SELECT delivery_state FROM {$p}notification_deliveries WHERE notification_id=%d AND applied=1 ORDER BY delivery_sequence DESC LIMIT 1",$notificationId));
        return $state===null?null:(string)$state;
    }
    private function sourceAttempt(string $p,int $fallbackId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT result_id FROM {$p}notification_commands WHERE notification_id=%d AND operation=%s ORDER BY id DESC LIMIT 1",$fallbackId,NotificationSmsFallbackService::COMMAND_SOURCE));
    }
}
