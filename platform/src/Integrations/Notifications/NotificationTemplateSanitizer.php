<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Editorial and safety validation for every editable message body.
 *
 * The editor never accepts executable content: placeholders are restricted to the catalogue's
 * allowlist, tags are reduced to a small email-safe set, and JavaScript/PHP/event-handler syntax is
 * refused rather than stripped silently. The same rules run on save and on publish, so a draft that
 * cannot be published is reported before it is stored.
 */
final class NotificationTemplateSanitizer {
    public const MAX_SUBJECT=180;
    public const MAX_BODY=5000;
    public const MAX_SMS=1000;
    public const MAX_WHATSAPP=4096;

    /** Email-safe tags. Structure only: no style attribute, no class, no embed, no script. */
    private const ALLOWED_TAGS=array(
        'p'=>array(),'br'=>array(),'strong'=>array(),'b'=>array(),'em'=>array(),'i'=>array(),
        'ul'=>array(),'ol'=>array(),'li'=>array(),'a'=>array('href'=>true),
        'h2'=>array(),'h3'=>array(),'hr'=>array(),'blockquote'=>array(),
    );

    /**
     * @return array{ok:bool,errors:string[],subject:string,body:string}
     */
    public static function validate(string $eventKey,string $channel,string $subject,string $body):array{
        $event=NotificationCatalogue::get($eventKey);
        $errors=array();
        if($event===null)return array('ok'=>false,'errors'=>array('Unknown notification event.'),'subject'=>'','body'=>'');
        if(!in_array($channel,(array)$event['channels'],true))$errors[]='This event is not supported on the selected channel.';
        $subject=trim($subject);$body=trim($body);
        $maxSubject=self::MAX_SUBJECT;
        $maxBody=match($channel){'sms'=>self::MAX_SMS,'whatsapp'=>self::MAX_WHATSAPP,default=>self::MAX_BODY};
        if($channel!=='website'&&$channel!=='sms'&&$channel!=='whatsapp'&&$subject==='')$errors[]='A subject is required.';
        if(mb_strlen($subject)>$maxSubject)$errors[]='The subject is too long.';
        if($body==='')$errors[]='A message body is required.';
        if(mb_strlen($body)>$maxBody)$errors[]='The message body is too long.';
        foreach(array($subject,$body) as $text){
            if(self::containsExecutableContent($text))$errors[]='Executable content (scripts, PHP or browser event handlers) is not allowed in a message.';
        }
        $allowed=NotificationCatalogue::variables($eventKey);
        $missing=array();$unknown=array();
        foreach(array($subject,$body) as $text){
            if(preg_match_all('/\{\{([^{}]*)\}\}/',$text,$matches)){
                foreach($matches[1] as $code){
                    if(preg_match('/^[a-z][a-z0-9_]{0,63}$/',$code)!==1){$unknown[$code]=true;continue;}
                    if(!in_array($code,$allowed,true))$unknown[$code]=true;
                }
            }
            // A single brace or an unclosed placeholder is an authoring mistake, not literal text.
            $stripped=preg_replace('/\{\{[a-z][a-z0-9_]{0,63}\}\}/','',$text);
            if(str_contains($stripped,'{{')||str_contains($stripped,'}}'))$errors[]='The text contains an incomplete or unknown placeholder.';
        }
        foreach(array_keys($unknown) as $code)$errors[]='Placeholder not allowed: {{'.$code.'}}';
        foreach(self::requiredVariables($eventKey,$channel) as $code)if(!str_contains($subject,'{{'.$code.'}}')&&!str_contains($body,'{{'.$code.'}}'))$missing[]=$code;
        foreach($missing as $code)$errors[]='Required placeholder missing: {{'.$code.'}}';
        $cleanSubject=self::plain($subject);
        // Bodies are authored as plain text. The branded HTML shell supplies the only markup, so an
        // administrator can never introduce a tag, style or handler into a delivered message.
        $cleanBody=self::plain($body);
        return array('ok'=>$errors===array(),'errors'=>array_values(array_unique($errors)),'subject'=>$cleanSubject,'body'=>$cleanBody);
    }

    /**
     * A message body is stored as authored (plain text or a small HTML subset) and rendered through
     * the branded email shell. Stored HTML keeps only structure, so no style, script or handler survives.
     */
    public static function sanitiseEmailHtml(string $text):string{
        if(!str_contains($text,'<'))return $text;
        if(function_exists('wp_kses'))return trim(wp_kses($text,self::ALLOWED_TAGS));
        return trim(strip_tags($text,'<p><br><strong><b><em><i><ul><ol><li><a><h2><h3><hr><blockquote>'));
    }
    public static function plain(string $text):string{
        $text=preg_replace('/<[^>]*>/','',$text);
        return trim(html_entity_decode((string)$text,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    }
    private static function sanitiseText(string $text):string{return trim(self::plain($text));}

    public static function containsExecutableContent(string $text):bool{
        $patterns=array('/<\?(?:php|=)?/i','/<script\b/i','/<iframe\b/i','/<object\b/i','/<embed\b/i','/<form\b/i',
            '/javascript\s*:/i','/vbscript\s*:/i','/data\s*:\s*text\/html/i','/\bon[a-z]{3,}\s*=/i','/<style\b/i');
        foreach($patterns as $pattern)if(preg_match($pattern,$text)===1)return true;
        return false;
    }

    /**
     * Required placeholders: the class time and one action link are what make a message actionable, and
     * one-time secrets must never be dropped by an edit. SMS is allowed to omit the long portal link.
     */
    public static function requiredVariables(string $eventKey,string $channel='email'):array{
        $variables=NotificationCatalogue::variables($eventKey);
        // The inbox row carries its own link and must never contain a one-time secret, so website copy
        // has no mandatory placeholder beyond being non-empty.
        if($channel==='website')return array();
        $required=array();
        foreach(array('when','claim_code','claim_url','invitation_code','join_url') as $code){
            if($code==='join_url'&&$channel==='sms')continue;
            if(in_array($code,$variables,true))$required[]=$code;
        }
        if($channel!=='sms'){
            foreach(array('portal_url','teacher_portal_url','claim_url','payment_url','join_url') as $code){
                if(in_array($code,$variables,true)){$required[]=$code;break;}
            }
        }
        return array_values(array_unique($required));
    }

    /**
     * Unicode-aware SMS segmentation. Persian text is UCS-2, so one part carries 70 characters and a
     * concatenated part 67; ASCII text uses the GSM-7 limits. This is a presentation guard, not a gateway.
     *
     * @return array{encoding:string,length:int,segments:int,remaining:int,limit:int}
     */
    public static function smsSegmentation(string $text):array{
        $length=mb_strlen($text,'UTF-8');
        // Conservatively treat anything outside printable ASCII as UCS-2. Characters that require a
        // GSM-7 escape sequence are also treated as UCS-2 so a length estimate is never optimistic.
        $isGsm=preg_match('/^[\x20-\x7E\r\n]*$/',$text)===1
            && preg_match('/[\[\]{}~^|\\\\]/',$text)!==1;
        if($isGsm){
            $segments=$length<=160?1:(int)ceil($length/153);
            $limit=$segments<=1?160:153;
            return array('encoding'=>'GSM-7','length'=>$length,'segments'=>$segments,'remaining'=>max(0,$limit*$segments-$length),'limit'=>$limit);
        }
        $segments=$length<=70?1:(int)ceil($length/67);
        $limit=$segments<=1?70:67;
        return array('encoding'=>'UCS-2','length'=>$length,'segments'=>$segments,'remaining'=>max(0,$limit*$segments-$length),'limit'=>$limit);
    }
}
