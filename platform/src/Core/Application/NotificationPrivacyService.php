<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationPrivacyRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;

/**
 * §11/§8.1 — retention, erasure and the digest-only tombstone.
 *
 * Erasure nulls the narrow contact envelope while the digest-anchored row and its events remain as
 * integrity evidence, and a notification whose dispatch is in flight is never erased. No secret, contact
 * value or envelope content is ever returned by a read or written to a diagnostic.
 */
final class NotificationPrivacyService {
    private const CAPABILITY='dzn_manage_notification_privacy';
    public function __construct(
        private ?NotificationPrivacyRepository $repository=null,
        private ?NotificationRepository $notifications=null
    ){
        $this->repository??=new NotificationPrivacyRepository();
        $this->notifications??=new NotificationRepository();
    }

    /** Erase the contact envelope of one notification and record immutable tombstone evidence. */
    public function eraseRecipient(int $notificationId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $reason=NotificationSupport::reason($input,'reason_code');
        $digest=NotificationSupport::keyDigest($key);
        $this->repository->begin();
        try{
            // §10/§11: the erasure locks the aggregate root before it decides, so a dispatch that won the root
            // first is always observed as in flight and is never erased behind a live lease.
            $notification=$this->notifications->find($notificationId,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            if(in_array((string)$notification->state,array('dispatching','dispatched'),true))throw new \RuntimeException('notification_dispatch_in_flight');
            if($this->repository->tombstone($notificationId)!==null){$this->repository->commit();return array('notification_id'=>$notificationId,'erased'=>false,'idempotent'=>true);}
            $now=NotificationSupport::now();
            $this->repository->clearContactEnvelope($notificationId);
            $this->repository->insertTombstone(array(
                'uid'=>NotificationSupport::uid(),'notification_id'=>$notificationId,
                'subject_reference_digest'=>(string)$notification->subject_reference_digest,'erased_scope'=>'contact_envelope',
                'erased_at'=>$now,'erased_by'=>$actor,'reason_code'=>$reason,
                'integrity_digest'=>hash_hmac('sha256','erasure:'.$notificationId.':'.(string)$notification->subject_reference_digest.':'.$now,NotificationSupport::salt()),
            ));
            $this->repository->commit();
            return array('notification_id'=>$notificationId,'erased'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    /** Null every envelope past its declared usefulness bound, reporting the ids it touched. */
    public function purgeExpiredEnvelopes(array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $now=NotificationSupport::now();
        $ids=$this->repository->overdueEnvelopes($now);
        foreach($ids as $id)$this->repository->clearContactEnvelope((int)$id);
        return array('purged'=>count($ids),'notification_ids'=>$ids);
    }

    /** Record tombstone evidence without erasing (used when the owner erased elsewhere). */
    public function recordTombstone(int $notificationId,array $input,string $key):array{return $this->eraseRecipient($notificationId,$input,$key);}
}
