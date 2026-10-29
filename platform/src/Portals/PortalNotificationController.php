<?php
namespace Delnavazan\Platform\Portals;

/**
 * Read-state actions for the website inbox. These are presentation actions only: marking a message read
 * never approves a proposal, confirms a booking, changes a lesson or authorises a payment.
 */
final class PortalNotificationController {
    public static function register():void{
        add_action('admin_post_dzn_portal_notification_read',array(__CLASS__,'read'));
        add_action('admin_post_dzn_portal_notification_read_all',array(__CLASS__,'readAll'));
    }

    public static function read():never{
        self::authorize('dzn_portal_notification_read');
        $id=isset($_POST['notification'])&&is_scalar($_POST['notification'])?(int)$_POST['notification']:0;
        (new PortalNotificationInbox())->markRead(get_current_user_id(),$id);
        self::back();
    }

    public static function readAll():never{
        self::authorize('dzn_portal_notification_read_all');
        (new PortalNotificationInbox())->markAllRead(get_current_user_id());
        self::back();
    }

    private static function authorize(string $action):void{
        if(!is_user_logged_in())wp_die('برای انجام این کار وارد حساب خود شوید.',403);
        check_admin_referer($action);
    }

    private static function back():never{
        $referer=wp_get_referer();
        $fallback=home_url('/student-portal/');
        $target=$referer!==false?$referer:$fallback;
        // Only return to this site; a notification action must never become an open redirect.
        $target=wp_validate_redirect($target,$fallback);
        wp_safe_redirect($target);
        exit;
    }
}
