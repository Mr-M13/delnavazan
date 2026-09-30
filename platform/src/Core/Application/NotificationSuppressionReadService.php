<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;

/**
 * §8.4/§6.8 — the suppression read seam: purpose, state and the subject digest, never a contact value.
 */
final class NotificationSuppressionReadService {
    public function __construct(private ?NotificationSuppressionRepository $repository=null){$this->repository??=new NotificationSuppressionRepository();}
    public function one(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $suppression=$this->repository->find($id);
        if(!$suppression)throw new \RuntimeException('notification_not_found');
        return $this->shape($suppression);
    }
    public function events(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $this->one($id);
        $events=array();
        foreach($this->repository->events($id) as $event)$events[]=array(
            'event_sequence'=>(int)$event->event_sequence,'event_type'=>(string)$event->event_type,
            'from_state'=>$event->from_state===null?null:(string)$event->from_state,'to_state'=>(string)$event->to_state,
            'reason_code'=>(string)$event->reason_code,'occurred_at'=>(string)$event->occurred_at,
        );
        return $events;
    }
    /** The active suppression matching one recipient digest and purpose, or null. */
    public function active(string $subjectDigest,string $purpose,string $now=''):?array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        if(!preg_match('/^[0-9a-f]{64}$/',$subjectDigest))throw new \InvalidArgumentException('suppressed');
        $suppression=$this->repository->active($subjectDigest,$purpose,$now===''?NotificationSupport::now():$now);
        return $suppression===null?null:$this->shape($suppression);
    }
    private function shape(object $suppression):array{
        return array(
            'suppression_id'=>(int)$suppression->id,'subject_kind'=>(string)$suppression->subject_kind,
            'subject_digest'=>(string)$suppression->subject_digest,'purpose'=>(string)$suppression->purpose,
            'reason_code'=>(string)$suppression->reason_code,'state'=>(string)$suppression->state,
            'effective_from'=>(string)$suppression->effective_from,'expires_at'=>$suppression->expires_at,
            'released_at'=>$suppression->released_at,'suppression_version'=>(int)$suppression->suppression_version,
        );
    }
}
