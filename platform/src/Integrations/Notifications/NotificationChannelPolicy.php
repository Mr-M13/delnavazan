<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Read-only channel policy for a notification event.
 *
 * The policy is deliberately not a recipient preference: it is the academy's operational rule for a
 * category (for example, security messages may never use a marketing channel, and payment messages are
 * email/website only until their copy is reviewed). Senders that own a mandatory channel keep sending it;
 * this class is consulted where a channel is optional, such as the website inbox or a future mobile route.
 */
final class NotificationChannelPolicy {
    public static function allows(string $eventKey,string $channel):bool{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return false;
        if(!in_array($channel,(array)$event['channels'],true))return false;
        $policy=(new NotificationTemplateLibrary())->channelPolicy((string)$event['category']);
        return !empty($policy[$channel]);
    }

    /** SMS is only ever a constrained fallback and only when the event and the category permit it. */
    public static function allowsSmsFallback(string $eventKey):bool{
        if(!self::allows($eventKey,'sms'))return false;
        $event=NotificationCatalogue::get($eventKey);
        $policy=(new NotificationTemplateLibrary())->channelPolicy((string)$event['category']);
        return !empty($policy['sms_fallback']);
    }

    /** A mandatory category (security/account/payment) can never be disabled by a channel preference. */
    public static function isMandatory(string $eventKey):bool{return NotificationCatalogue::isMandatory($eventKey);}
}
