<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Portals\PortalNotificationInbox;

/**
 * Write-side seam for the portal inbox.
 *
 * Operational senders keep their own delivery authority and call this recorder after their business fact
 * has committed. Recording is idempotent per business event and always fail-soft: an inbox failure never
 * rolls back a lesson, booking, attendance or payment decision.
 */
final class PortalNotificationRecorder {
    public static function student(int $studentId,string $eventKey,string $sourceKind,int $sourceId,array $variables=array(),?string $linkUrl=null):?int{
        return self::record($eventKey,'student',$studentId,$sourceKind,$sourceId,$variables,$linkUrl);
    }
    public static function teacher(int $teacherId,string $eventKey,string $sourceKind,int $sourceId,array $variables=array(),?string $linkUrl=null):?int{
        return self::record($eventKey,'teacher',$teacherId,$sourceKind,$sourceId,$variables,$linkUrl);
    }
    /** Admin inbox rows are kept separate from the student/teacher inbox query by audience. */
    public static function admin(string $eventKey,string $sourceKind,int $sourceId,array $variables=array(),?string $linkUrl=null):?int{
        return self::record($eventKey,'admin',0,$sourceKind,$sourceId,$variables,$linkUrl);
    }

    public static function record(string $eventKey,string $audience,int $recipientId,string $sourceKind,int $sourceId,array $variables=array(),?string $linkUrl=null):?int{
        if(!isset($GLOBALS['wpdb'])||!is_object($GLOBALS['wpdb']))return null;
        try{
            return (new PortalNotificationInbox())->record($eventKey,$audience,$recipientId,$sourceKind,$sourceId,$variables,$linkUrl);
        }catch(\Throwable $error){
            if(function_exists('do_action'))do_action('dzn_portal_notification_record_failed',$eventKey,$audience,$recipientId,$error->getMessage());
            return null;
        }
    }

    /** Producers outside this plugin can record an inbox message without touching the table. */
    public static function register():void{
        add_action('dzn_portal_notification_record',static function($eventKey,$audience,$recipientId,$sourceKind,$sourceId,$variables=array(),$linkUrl=null):void{
            if(!is_string($eventKey)||!is_string($audience)||!is_string($sourceKind))return;
            self::record($eventKey,$audience,(int)$recipientId,$sourceKind,(int)$sourceId,is_array($variables)?$variables:array(),is_string($linkUrl)?$linkUrl:null);
        },10,7);
    }
}
