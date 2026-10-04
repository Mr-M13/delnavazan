<?php
namespace Delnavazan\Platform\Integrations\Notifications;
use Delnavazan\Platform\Core\Application\NotificationRecipientReadPort;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;

/**
 * Transport-side lookup keyed only by the already-authorised notification digest.
 * It re-resolves canonical recipient state, proves the digest still matches the aggregate, then decrypts
 * the short-lived envelope locally. Nothing is added to NotificationTransportPort.
 */
final class NotificationTransportRecipientResolver {
 public function __construct(private NotificationRecipientReadPort $recipients,private ?NotificationRepository $notifications=null){$this->notifications??=new NotificationRepository();}
 public function __invoke(array $command):?array{
  $key=(string)($command['notification_key_digest']??'');if($key==='')return null;
  $n=$this->notifications->byKeyDigest($key);if(!$n)return null;
  $r=$this->recipients->recipient((string)$n->audience,(string)$n->recipient_kind,(int)$n->subject_aggregate_id);
  if(!is_array($r)||empty($r['resolvable'])||(string)($r['recipient_digest']??'')!==(string)$n->recipient_digest)return null;
  $expires=(string)($r['contact_expires_at']??'');if($expires===''||$expires<=NotificationSupport::now())return null;
  return NotificationSupport::decryptEnvelope($r['contact_envelope']??null,$r['contact_cipher_version']??null);
 }
}
