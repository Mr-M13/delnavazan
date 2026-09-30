<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * §8.4 — the attempt read seam.
 *
 * Each attempt is proved against the closure partition of §6.6/§9 before it is returned, so a caller never
 * reads authority from an attempt whose exhaustion shape, terminal vocabulary or persisted retry schedule
 * disagrees with the notification it closed.
 */
final class NotificationAttemptReadService {
    public function __construct(
        private ?NotificationAttemptRepository $repository=null,
        private ?NotificationRepository $notifications=null,
        private ?NotificationWorkflowRepository $workflows=null,
        private ?NotificationOutboxRepository $outbox=null
    ){
        $this->repository??=new NotificationAttemptRepository();
        $this->notifications??=new NotificationRepository();
        $this->workflows??=new NotificationWorkflowRepository();
        $this->outbox??=new NotificationOutboxRepository();
    }
    public function attempts(int $notificationId):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $this->validatedNotification($notificationId);
        $rows=array();
        foreach($this->repository->attemptsFor($notificationId) as $attempt)$rows[]=$this->shape($attempt);
        return $rows;
    }
    public function one(int $attemptId):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $attempt=$this->repository->find($attemptId);
        if(!$attempt)throw new \RuntimeException('notification_not_found');
        $this->validatedNotification((int)$attempt->notification_id);
        return $this->shape($attempt);
    }
    /** The append-only attempt history, proved through the same validated loader. */
    public function events(int $attemptId):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $this->one($attemptId);
        $events=array();
        foreach($this->repository->events($attemptId) as $event)$events[]=array(
            'event_sequence'=>(int)$event->event_sequence,'event_type'=>(string)$event->event_type,
            'from_state'=>$event->from_state===null?null:(string)$event->from_state,'to_state'=>(string)$event->to_state,
            'reason_code'=>$event->reason_code===null?null:(string)$event->reason_code,
            'evidence_reference_digest'=>$event->evidence_reference_digest===null?null:(string)$event->evidence_reference_digest,
            'occurred_at'=>(string)$event->occurred_at,
        );
        return $events;
    }
    /** Prove the notification and its closing attempt together, then its retry schedule when it re-armed. */
    private function validatedNotification(int $notificationId):object{
        $notification=$this->notifications->find($notificationId);
        if(!$notification)throw new \RuntimeException('notification_not_found');
        $version=$this->workflows->version((int)$notification->workflow_version_id);
        if(!$version)throw new \RuntimeException('workflow_version_integrity');
        $rows=array();
        foreach($this->workflows->rulesFor((int)$version->id) as $row)$rows[]=array(
            'rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,
            'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d,
        );
        NotificationIntegrity::versionIntegrity($version,$rows);
        $tier=NotificationRule::intentTier((string)$notification->intent_key);
        $composition=NotificationSchedule::validateComposition(array_values(array_filter($rows,static fn(array $row):bool=>$row['rule_kind']==='schedule')),$tier);
        $policy=NotificationRetry::validatePolicy(array_values(array_filter($rows,static fn(array $row):bool=>$row['rule_kind']==='retry')));
        $binding=NotificationRule::requiredBinding((string)$notification->intent_key);
        $subjectInstant=$tier==='F'?NotificationIntegrity::persistedInstant((string)$binding['aggregate'],(int)$notification->subject_aggregate_id,$binding['instant']):null;
        $workflow=$this->workflows->workflow((int)$version->workflow_id);
        // §7.1/§8.4: the same persisted outbox mirror the aggregate read seam proves is supplied here, so a
        // diverged or absent mirror refuses the attempt projection exactly as it refuses the aggregate one.
        $row=$this->outbox->forNotification($notificationId);
        // §8.4/§7.3: the attempt read seam proves the same shared aggregate verification as the aggregate
        // read — the persisted derivation, every closed attempt's closure partition and every persisted
        // retry schedule — before it returns any attempt authority.
        NotificationIntegrity::aggregateIntegrity($notification,$composition,$policy,$subjectInstant,$row,$this->repository->attemptsFor($notificationId),(int)$version->version_number,array(
            'workflow_key'=>$workflow?(string)$workflow->workflow_key:'','workflow_version'=>(int)$version->version_number,
            'intent_key'=>(string)$version->intent_key,'audience'=>(string)$version->audience,
        ));
        return $notification;
    }
    private function shape(object $attempt):array{
        return array(
            'attempt_id'=>(int)$attempt->id,'notification_id'=>(int)$attempt->notification_id,'outbox_id'=>(int)$attempt->outbox_id,
            'attempt_sequence'=>(int)$attempt->attempt_sequence,'state'=>(string)$attempt->state,
            'leased_at'=>(string)$attempt->leased_at,'lease_expires_at'=>(string)$attempt->lease_expires_at,
            'finished_at'=>$attempt->finished_at,'outcome_code'=>$attempt->outcome_code,'failure_class'=>$attempt->failure_class,
            'applied_jitter_bp'=>$attempt->applied_jitter_bp===null?null:(int)$attempt->applied_jitter_bp,
            'base_backoff_seconds'=>$attempt->base_backoff_seconds===null?null:(int)$attempt->base_backoff_seconds,
            'backoff_seconds'=>$attempt->backoff_seconds===null?null:(int)$attempt->backoff_seconds,
            'next_available_at'=>$attempt->next_available_at,
        );
    }
}
