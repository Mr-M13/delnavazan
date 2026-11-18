<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * The two SMS.ir templates approved by the provider account.
 *
 * SMS.ir is used for Iranian (+98) destinations only. It is not a fallback for another provider and
 * the catalogue copy is not silently sent as free text: every event-owned SMS hand-off is mapped to
 * one of these exact approved bodies. The generic account-referral template covers every event; the
 * new-intro-booking template is used only when a Teacher receives a new introductory request.
 *
 * The provider approval is external. This registry is the local contract that keeps the mapping
 * explicit, testable and visible in the notification editor; it never sends a message by itself.
 */
final class SmsIrTemplateRegistry {
    public const GENERIC='account_referral';
    public const INTRO_BOOKING='intro_booking_request';
    public const INTRO_BOOKING_EVENT='booking.new.teacher';

    /**
     * @return array<string,array{label:string,body:string,variables:string[],events:string[]|string}>
     */
    public static function definitions():array{
        return array(
            self::GENERIC=>array(
                'label'=>'Account referral',
                'body'=>'دلنوازان: #NAME# عزیز، لطفا به حساب کاربری خود مراجعه کنید.',
                'variables'=>array('name'),
                'events'=>'*',
            ),
            self::INTRO_BOOKING=>array(
                'label'=>'New intro booking request',
                'body'=>"دلنوازان : درود #NAME# عزیز\nهنرجویی برای کلاس آشنایی #INSTRUMENT# در تاریخ #DATE# ساعت #TIME# از شما درخواست کرده.\nلطفا برای تأیید یا رد به حساب کاربری سر بزنید.\nبا تشکر،",
                'variables'=>array('name','instrument','date','time'),
                'events'=>array(self::INTRO_BOOKING_EVENT),
            ),
        );
    }

    /** The approved template a local event must use. */
    public static function keyForEvent(string $eventKey):string{
        return trim($eventKey)===self::INTRO_BOOKING_EVENT?self::INTRO_BOOKING:self::GENERIC;
    }

    /** @return array{key:string,label:string,body:string,variables:string[]} */
    public static function forEvent(string $eventKey):array{
        $key=self::keyForEvent($eventKey);
        $definition=self::definitions()[$key];
        return array('key'=>$key,'label'=>$definition['label'],'body'=>$definition['body'],'variables'=>$definition['variables']);
    }

    /** The approved body with the event's variables rendered. */
    public static function renderForEvent(string $eventKey,array $variables):string{
        $definition=self::forEvent($eventKey);
        $values=self::valuesForEvent($eventKey,$variables);
        $body=strtr($definition['body'],array(
            '#NAME#'=>$values['name'],
            '#INSTRUMENT#'=>$values['instrument'],
            '#DATE#'=>$values['date'],
            '#TIME#'=>$values['time'],
        ));
        // A malformed or absent time must not leave a dangling «ساعت» in an approved message.
        if($values['time']==='')$body=str_replace(' ساعت ',' ',$body);
        return trim($body);
    }

    /**
     * The provider template parameters, in the uppercase names used by the approved SMS.ir bodies.
     *
     * @return array<int,array{name:string,value:string}>
     */
    public static function parametersForEvent(string $eventKey,array $variables):array{
        $definition=self::forEvent($eventKey);
        $values=self::valuesForEvent($eventKey,$variables);
        $out=array();
        foreach($definition['variables'] as $code)$out[]=array('name'=>strtoupper($code),'value'=>(string)($values[$code]??''));
        return $out;
    }

    /** @return array{name:string,instrument:string,date:string,time:string} */
    private static function valuesForEvent(string $eventKey,array $variables):array{
        [$date,$time]=self::splitWhen((string)($variables['when']??''));
        return array(
            'name'=>self::name($eventKey,$variables),
            'instrument'=>trim((string)($variables['course']??$variables['instrument']??'')),
            'date'=>trim((string)($variables['date']??$date)),
            'time'=>trim((string)($variables['time']??$time)),
        );
    }

    /** The recipient's display name for the template's #NAME# slot. */
    private static function name(string $eventKey,array $variables):string{
        foreach(array('name','teacher','student') as $code){
            if(isset($variables[$code])&&is_scalar($variables[$code])&&trim((string)$variables[$code])!=='')return trim((string)$variables[$code]);
        }
        return '';
    }

    /**
     * Split the catalogue's human-readable `when` into date and time without changing its locale.
     * The catalogue producers emit either a Persian sentence, a Gregorian sentence or an ISO value.
     *
     * @return array{0:string,1:string}
     */
    public static function splitWhen(string $when):array{
        $when=trim($when);
        if($when==='')return array('','');
        if(preg_match('/^(.*?)(?:،\s*|\s+)ساعت\s*([0-9۰-۹]{1,2}:[0-9۰-۹]{2}(?:\s*تا\s*[0-9۰-۹]{1,2}:[0-9۰-۹]{2})?)/u',$when,$matches)===1){
            return array(trim($matches[1]),trim($matches[2]));
        }
        if(preg_match('/^(.+?)[,،]\s*([0-9]{1,2}:[0-9]{2}.*)$/u',$when,$matches)===1){
            return array(trim($matches[1]),trim((string)preg_replace('/\s+[A-Za-z_]+\/[A-Za-z_]+$/','',$matches[2])));
        }
        if(preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}))?/',$when,$matches)===1){
            return array((string)$matches[1],(string)($matches[2]??''));
        }
        return array($when,'');
    }
}
