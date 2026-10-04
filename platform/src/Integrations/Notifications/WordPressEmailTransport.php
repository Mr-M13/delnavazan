<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
final class WordPressEmailTransport implements NotificationTransportPort {
 public function channel():string{return 'email';} public function configured():bool{return function_exists('wp_mail');}
 public function send(array $message):array{$to=(string)($message['to']??'');$subject=(string)($message['subject']??'');$body=(string)($message['body']??'');if($to===''||$body==='')return array('status'=>'refused','reason'=>'contact_unusable');return wp_mail($to,$subject,$body)?array('status'=>'accepted'):array('status'=>'retryable','reason'=>'send_refused');}
}
