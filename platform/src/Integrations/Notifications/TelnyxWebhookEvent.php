<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Normalises one verified Telnyx Messaging API v2 webhook into the small, channel-neutral vocabulary
 * the notification authority already understands.
 *
 * Telnyx uses the same event envelope for SMS/MMS and WhatsApp. Inbound WhatsApp puts the sender and
 * text under `payload.body`; SMS puts them under `payload.from.phone_number` and `payload.text`. Both
 * shapes are accepted here. The raw payload never leaves this class.
 */
final class TelnyxWebhookEvent {
    public const EVENT_TYPES=array('message.received','message.sent','message.finalized');
    public const STOP_WORDS=array('STOP','STOPALL','STOP ALL','UNSUBSCRIBE','CANCEL','END','QUIT','OPT OUT','OPTOUT','لغو','توقف','انصراف');

    /**
     * @return array{event_type:string,event_id:string,occurred_at:string,channel:string,direction:string,message_reference:string,delivery_state:?string,recipient_phone:string,text:string,stop:bool}
     * @throws \InvalidArgumentException on a malformed or unsupported event
     */
    public static function normalise(string $rawBody):array{
        try{$body=json_decode($rawBody,true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new \InvalidArgumentException('webhook_payload_invalid');}
        if(!is_array($body))throw new \InvalidArgumentException('webhook_payload_invalid');
        $data=is_array($body['data']??null)?$body['data']:null;
        if($data===null)throw new \InvalidArgumentException('webhook_payload_invalid');
        $eventType=(string)($data['event_type']??'');
        if(!in_array($eventType,self::EVENT_TYPES,true))throw new \InvalidArgumentException('webhook_event_unsupported');
        $eventId=trim((string)($data['id']??''));
        if($eventId==='')throw new \InvalidArgumentException('webhook_event_id_missing');
        $occurredAt=self::utc((string)($data['occurred_at']??''));
        if($occurredAt===null)throw new \InvalidArgumentException('webhook_occurred_at_invalid');
        $payload=is_array($data['payload']??null)?$data['payload']:array();
        $inner=is_array($payload['body']??null)?$payload['body']:array();
        $type=strtoupper((string)($payload['type']??($inner['type']??'')));
        $channel=$type==='WHATSAPP'||($type===''&&isset($inner['from']))?'whatsapp':'sms';
        $direction=strtolower((string)($payload['direction']??($inner['direction']??'')));
        if($eventType==='message.received')$direction='inbound';
        $innerText=$inner['text']??null;
        $nestedText=is_array($innerText)?($innerText['body']??null):(is_string($innerText)?$innerText:null);
        $text=self::firstString(array($payload['text']??null,$nestedText));
        $from=$payload['from']??null;$innerFrom=$inner['from']??null;
        $bodyFromPhone=is_array($innerFrom)?($innerFrom['phone_number']??null):$innerFrom;
        $fromPhone=is_array($from)?($from['phone_number']??null):$from;
        // Inbound WhatsApp carries the sender under `body.from`; SMS carries it under
        // `from.phone_number`. Prefer the WhatsApp body sender when both are present.
        $recipientPhone=self::phone(self::firstString(array($bodyFromPhone,$fromPhone)));
        $reference=self::firstString(array($payload['id']??null,$inner['id']??null));
        $to=is_array($payload['to']??null)&&is_array($payload['to'][0]??null)?$payload['to'][0]:array();
        $status=strtolower(self::firstString(array($to['status']??null,$payload['status']??null,$inner['status']??null)));
        return array(
            'event_type'=>$eventType,
            'event_id'=>$eventId,
            'occurred_at'=>$occurredAt,
            'channel'=>$channel,
            'direction'=>$direction===''?($eventType==='message.received'?'inbound':'outbound'):$direction,
            'message_reference'=>$reference,
            'delivery_state'=>$eventType==='message.received'?null:self::deliveryState($eventType,$status),
            'recipient_phone'=>$recipientPhone,
            'text'=>$text,
            'stop'=>$eventType==='message.received'&&self::isStop($text),
        );
    }

    public static function deliveryState(string $eventType,string $status):?string{
        if($eventType==='message.sent')return in_array($status,array('delivered','delivery_failed','sending_failed','delivery_unconfirmed','expired'),true)?self::deliveryState('message.finalized',$status):'sent';
        if($eventType!=='message.finalized')return null;
        return match($status){
            'delivered'=> 'delivered',
            'delivery_failed','sending_failed','failed'=> 'failed',
            'delivery_unconfirmed','undelivered'=> 'undelivered',
            'expired'=> 'expired',
            'sent'=> 'sent',
            'queued','sending'=> 'accepted',
            default=> null,
        };
    }

    public static function isStop(string $text):bool{
        $normal=trim(preg_replace('/\s+/u',' ',$text)??'');
        $normal=preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u','',$normal)??$normal;
        $normal=function_exists('mb_strtoupper')?mb_strtoupper($normal,'UTF-8'):strtoupper($normal);
        return in_array($normal,self::STOP_WORDS,true);
    }

    public static function phone(string $value):string{
        $value=trim($value);
        if($value==='')return '';
        $plus=str_starts_with($value,'+');
        $digits=preg_replace('/[^0-9]/','',$value)??'';
        if($digits==='')return '';
        return ($plus?'+':'').$digits;
    }

    private static function utc(string $value):?string{
        $value=trim($value);
        if($value==='')return null;
        try{$date=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));}
        catch(\Throwable){return null;}
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private static function firstString(array $values):string{
        foreach($values as $value)if(is_string($value)&&trim($value)!=='')return trim($value);
        return '';
    }
}
