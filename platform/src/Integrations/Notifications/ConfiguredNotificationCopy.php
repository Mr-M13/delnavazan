<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationCopyPort;

/**
 * Copy registry supplied by deployment/application configuration. It stores no provider credentials and
 * renders only allowlisted {{variable_code}} placeholders from the already-frozen snapshot.
 */
final class ConfiguredNotificationCopy implements NotificationCopyPort {
    public function __construct(private array $definitions){}
    public function render(int $templateVersionId,string $channel,string $locale,array $parameters):?array{
        $key=$templateVersionId.':'.$channel.':'.$locale;
        $definition=$this->definitions[$key]??null;
        if(!is_array($definition)||!isset($definition['body']))return null;
        $render=function(string $text)use($parameters):string{
            return preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',static function($m)use($parameters){
                if(!array_key_exists($m[1],$parameters))throw new \RuntimeException('template_variable_mismatch');
                return (string)$parameters[$m[1]];
            },$text);
        };
        return array('subject'=>$render((string)($definition['subject']??'')),'body'=>$render((string)$definition['body']));
    }
}
