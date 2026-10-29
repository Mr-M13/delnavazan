<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Read-through copy resolution for the operational senders that own their own durable queue.
 *
 * Those senders keep their delivery authority, idempotency key and retry behaviour; only the wording
 * becomes editable. Resolution is deliberately fail-soft: an unknown event, a missing stored version or
 * an unrenderable placeholder returns the sender's reviewed fallback rather than blocking an operational
 * message or sending a half-rendered one.
 */
final class NotificationCopyResolver {
    /**
     * @param array<string,string> $variables
     * @param array{subject:string,body:string} $fallback
     * @return array{subject:string,body:string,source:string}
     */
    public static function email(string $eventKey,string $locale,array $variables,array $fallback):array{
        return self::resolve($eventKey,'email',$locale,$variables,$fallback);
    }

    /** @param array<string,string> $variables @param array{subject:string,body:string} $fallback */
    public static function message(string $eventKey,string $channel,string $locale,array $variables,array $fallback):array{
        return self::resolve($eventKey,$channel,$locale,$variables,$fallback);
    }

    /** @param array<string,string> $variables @param array{subject:string,body:string} $fallback */
    private static function resolve(string $eventKey,string $channel,string $locale,array $variables,array $fallback):array{
        try{
            $event=NotificationCatalogue::get($eventKey);
            if($event===null||!in_array($channel,(array)$event['channels'],true))return self::fallback($fallback);
            $copy=(new NotificationTemplateLibrary())->activeCopy($eventKey,$channel,$locale);
            if(!is_array($copy))return self::fallback($fallback);
            $subject=(string)($copy['subject']??'');
            $body=(string)($copy['body']??'');
            if($body==='')return self::fallback($fallback);
            foreach(NotificationCatalogue::variables($eventKey) as $code){
                if(!str_contains($subject,'{{'.$code.'}}')&&!str_contains($body,'{{'.$code.'}}'))continue;
                if(!array_key_exists($code,$variables))return self::fallback($fallback);
            }
            $replace=static function(string $text)use($variables):string{
                return (string)preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',static function(array $m)use($variables):string{
                    return array_key_exists($m[1],$variables)?(string)$variables[$m[1]]:'';
                },$text);
            };
            $renderedSubject=$channel==='sms'?'':$replace($subject);
            $renderedBody=$replace($body);
            if(trim($renderedBody)==='')return self::fallback($fallback);
            return array('subject'=>$renderedSubject,'body'=>$renderedBody,'source'=>(string)($copy['source']??'default'));
        }catch(\Throwable $error){
            if(function_exists('do_action'))do_action('dzn_notification_copy_resolution_failed',$eventKey,$channel,$error->getMessage());
            return self::fallback($fallback);
        }
    }

    /** @param array{subject?:string,body?:string} $fallback */
    private static function fallback(array $fallback):array{
        return array('subject'=>(string)($fallback['subject']??''),'body'=>(string)($fallback['body']??''),'source'=>'fallback');
    }
}
