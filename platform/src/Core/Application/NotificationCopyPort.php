<?php
namespace Delnavazan\Platform\Core\Application;
interface NotificationCopyPort {
    /** Resolve copy by immutable template-version identity + channel + locale. Null fails closed. */
    public function render(int $templateVersionId,string $channel,string $locale,array $parameters):?array;
}
