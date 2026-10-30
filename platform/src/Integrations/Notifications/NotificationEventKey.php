<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;

/**
 * Resolves the local event key from a frozen template version, without trusting caller input.
 * Template keys are `{event_key}.{channel}.{locale}`; the event key itself may contain dots.
 */
final class NotificationEventKey {
    public static function fromTemplateVersion(int $templateVersionId,?NotificationTemplateRepository $templates=null):?string{
        if($templateVersionId<1)return null;
        $templates??=new NotificationTemplateRepository();
        $version=$templates->version($templateVersionId);
        if(!$version)return null;
        $template=$templates->template((int)$version->template_id);
        if(!$template)return null;
        $parts=explode('.',(string)$template->template_key);
        if(count($parts)<3)return null;
        array_pop($parts);array_pop($parts);
        return implode('.',$parts);
    }
    public static function channelFromTemplateVersion(int $templateVersionId,?NotificationTemplateRepository $templates=null):?string{
        if($templateVersionId<1)return null;
        $templates??=new NotificationTemplateRepository();
        $version=$templates->version($templateVersionId);
        if(!$version)return null;
        $template=$templates->template((int)$version->template_id);
        if(!$template)return null;
        $parts=explode('.',(string)$template->template_key);
        return count($parts)<3?null:(string)$parts[count($parts)-2];
    }
}
