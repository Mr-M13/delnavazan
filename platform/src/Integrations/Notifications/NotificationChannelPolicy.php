<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Read-only channel policy for a notification event.
 *
 * The policy is deliberately not a recipient preference: it is the academy's operational rule for a
 * exact event. Senders that own a mandatory channel keep sending it; this class is consulted where a
 * channel is optional, such as the website inbox or a future mobile route.
 */
final class NotificationChannelPolicy {
    public static function allows(string $eventKey,string $channel):bool{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return false;
        if(!(new NotificationTemplateLibrary())->eventEnabled($eventKey))return false;
        if(!in_array($channel,(array)$event['channels'],true))return false;
        return (new NotificationTemplateLibrary())->channelPermitted($eventKey,$channel);
    }

    /** SMS is only ever a constrained fallback and only when this event explicitly enables it. */
    public static function allowsSmsFallback(string $eventKey):bool{
        return self::allows($eventKey,'sms');
    }

    /** A mandatory category (security/account/payment) can never be disabled by a channel preference. */
    public static function isMandatory(string $eventKey):bool{return NotificationCatalogue::isMandatory($eventKey);}
}
