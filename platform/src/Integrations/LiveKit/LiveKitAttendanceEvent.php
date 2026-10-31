<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Normalise one already-signature-verified LiveKit webhook delivery.
 *
 * The adapter accepts only normalized facts: event kind, the two opaque LiveKit identifiers, the
 * exact provider instant and the provider event id. The raw delivery is never persisted.
 */
final class LiveKitAttendanceEvent {
    /** Provider clocks may lead the application clock slightly; a wider skew is refused. */
    public const MAX_FUTURE_SECONDS=3600;
    public const SUPPORTED=array('participant_joined','participant_left','room_finished');

    /**
     * @return array{kind:string,room_name:string,room_sid:?string,identity:?string,participant_sid:?string,event_key:?string,provider_at:?string,received_at:string,valid:bool,reason:?string,event_type:string}
     */
    public static function normalise(array $raw,?string $now=null):array{
        $now??=gmdate('Y-m-d H:i:s');
        $eventType=(string)($raw['event']??'');
        $room=is_array($raw['room']??null)?$raw['room']:array();
        $participant=is_array($raw['participant']??null)?$raw['participant']:array();
        $roomName=trim((string)($room['name']??''));
        $roomSid=trim((string)($room['sid']??''));
        $identity=trim((string)($participant['identity']??''));
        $participantSid=trim((string)($participant['sid']??''));
        $result=array(
            'kind'=>self::kind($eventType),'room_name'=>$roomName,'room_sid'=>$roomSid===''?null:$roomSid,
            'identity'=>$identity===''?null:$identity,'participant_sid'=>$participantSid===''?null:$participantSid,
            'event_key'=>null,'provider_at'=>null,'received_at'=>$now,'valid'=>false,'reason'=>null,'event_type'=>$eventType,
        );
        if(!in_array($eventType,self::SUPPORTED,true)){$result['reason']='unsupported_event';return $result;}
        if($roomName===''||!str_starts_with($roomName,'dzn-')){$result['reason']='unknown_room';return $result;}
        if($eventType!=='room_finished'){
            if($identity===null){$result['reason']='missing_identity';return $result;}
            if($participantSid===null){$result['reason']='missing_connection_id';return $result;}
        }
        // Room termination is a lifecycle correlation fact, not a participation instant. It is
        // still accepted without inventing a leave time for any open participant.
        $providerAt=null;
        if($eventType!=='room_finished'){
            $providerRaw=$eventType==='participant_joined'
                ?($participant['joinedAt']??($raw['createdAt']??null))
                :($raw['createdAt']??($participant['leftAt']??null));
            $providerAt=self::utc($providerRaw,$now);
            if($providerAt===null){$result['reason']='missing_provider_time';return $result;}
        }
        $eventKey=trim((string)($raw['id']??''));
        if($eventKey==='')$eventKey=hash('sha256',implode('|',array($eventType,$roomName,$identity??'',$participantSid??'',$providerAt??'')));
        $result['event_key']=$eventKey;
        $result['provider_at']=$providerAt;
        $result['valid']=true;
        return $result;
    }

    private static function kind(string $eventType):string{
        return match($eventType){'participant_joined'=>'joined','participant_left'=>'left','room_finished'=>'room_finished',default=>'ignored'};
    }

    /** Convert a provider instant to a canonical UTC second without ever using the local clock. */
    public static function utc(mixed $value,?string $now=null):?string{
        $now??=gmdate('Y-m-d H:i:s');
        if($value===null||$value==='')return null;
        if(is_int($value)||is_float($value)||(is_string($value)&&preg_match('/^\d+(?:\.\d+)?$/D',$value))){
            $number=(float)$value;
            if($number<=0)return null;
            // LiveKit sends Unix seconds in webhook events and may expose nanoseconds on some builds.
            if($number>1e17)$number/=1e9;
            elseif($number>1e14)$number/=1e6;
            elseif($number>1e11)$number/=1e3;
            $timestamp=(int)floor($number);
        }elseif(is_string($value)){
            $parsed=strtotime($value);
            if($parsed===false)return null;
            $timestamp=(int)$parsed;
        }else{
            return null;
        }
        $utc=gmdate('Y-m-d H:i:s',$timestamp);
        if((int)strtotime($utc.' UTC')>(int)strtotime($now.' UTC')+self::MAX_FUTURE_SECONDS)return null;
        if((int)strtotime($utc.' UTC')<(int)strtotime('2020-01-01 00:00:00 UTC'))return null;
        return $utc;
    }
}
