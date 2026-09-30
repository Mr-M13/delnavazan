<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * §8.4 — the notification aggregate read seam.
 *
 * The aggregate is proved against its own shape, its frozen rules and its locked derivation before it is
 * returned, and the projection carries digests and instants only: never a contact value, a parameter
 * value or an envelope.
 */
final class NotificationReadService {
    public function __construct(
        private ?NotificationRepository $repository=null,
        private ?NotificationWorkflowRepository $workflows=null,
        private ?NotificationOutboxRepository $outbox=null,
        private ?NotificationAttemptRepository $attempts=null
    ){
        $this->repository??=new NotificationRepository();
        $this->workflows??=new NotificationWorkflowRepository();
        $this->outbox??=new NotificationOutboxRepository();
        $this->attempts??=new NotificationAttemptRepository();
    }
    public function one(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $notification=$this->repository->find($id);
        if(!$notification)throw new \RuntimeException('notification_not_found');
        return $this->shape($notification);
    }
    /** The append-only history of a validated aggregate; the history can never bypass the proof. */
    public function events(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $this->shape($this->repository->find($id)??throw new \RuntimeException('notification_not_found'));
        $events=array();
        foreach($this->repository->events($id) as $event)$events[]=array(
            'event_sequence'=>(int)$event->event_sequence,'event_type'=>(string)$event->event_type,
            'from_state'=>$event->from_state===null?null:(string)$event->from_state,'to_state'=>(string)$event->to_state,
            'reason_code'=>$event->reason_code===null?null:(string)$event->reason_code,
            'evidence_channel'=>(string)$event->evidence_channel,'evidence_reference_digest'=>(string)$event->evidence_reference_digest,
            'occurred_at'=>(string)$event->occurred_at,
        );
        return $events;
    }
    /** The notification that owns one outbox row, when there is one. */
    public function forOutbox(int $outboxId):?array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $notification=$this->repository->forOutbox($outboxId);
        return $notification===null?null:$this->shape($notification);
    }
    private function shape(object $notification):array{
        NotificationIntegrity::notificationShape($notification);
        $version=$this->workflows->version((int)$notification->workflow_version_id);
        if(!$version)throw new \RuntimeException('workflow_version_integrity');
        $rows=array();
        foreach($this->workflows->rulesFor((int)$version->id) as $row)$rows[]=array(
            'rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,
            'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d,
        );
        // A post-activation rule append must block the read, exactly as it blocks dispatch.
        NotificationIntegrity::versionIntegrity($version,$rows);
        $tier=NotificationRule::intentTier((string)$notification->intent_key);
        $composition=NotificationSchedule::validateComposition(array_values(array_filter($rows,static fn(array $row):bool=>$row['rule_kind']==='schedule')),$tier);
        $policy=NotificationRetry::validatePolicy(array_values(array_filter($rows,static fn(array $row):bool=>$row['rule_kind']==='retry')));
        $binding=NotificationRule::requiredBinding((string)$notification->intent_key);
        $subjectInstant=$tier==='F'?NotificationIntegrity::persistedInstant((string)$binding['aggregate'],(int)$notification->subject_aggregate_id,$binding['instant']):null;
        $workflow=$this->workflows->workflow((int)$version->workflow_id);
        $row=$this->outbox->forNotification((int)$notification->id);
        // §6.5: the aggregate and its outbox mirror are 1:1 in storage, so a missing mirror is a divergence.
        if($row===null&&$notification->outbox_id!==null)throw new \RuntimeException('schedule_derivation_divergence');
        $attempts=$this->attempts->attemptsFor((int)$notification->id);
        // §7.3/§8.4: the aggregate is proved before any authority is returned — the persisted derivation
        // reproduces, the outbox mirror agrees, and every closed attempt's closure partition and persisted
        // retry schedule reproduce exactly.
        NotificationIntegrity::aggregateIntegrity($notification,$composition,$policy,$subjectInstant,$row,$attempts,(int)$version->version_number,array(
            'workflow_key'=>$workflow?(string)$workflow->workflow_key:'','workflow_version'=>(int)$version->version_number,
            'intent_key'=>(string)$version->intent_key,'audience'=>(string)$version->audience,
        ));
        return array(
            'notification_id'=>(int)$notification->id,'state'=>(string)$notification->state,
            'notification_key_digest'=>(string)$notification->notification_key_digest,
            'workflow_id'=>(int)$notification->workflow_id,'workflow_version_id'=>(int)$notification->workflow_version_id,
            'intent_key'=>(string)$notification->intent_key,'audience'=>(string)$notification->audience,
            'recipient_kind'=>(string)$notification->recipient_kind,'recipient_digest'=>(string)$notification->recipient_digest,
            'subject_aggregate'=>(string)$notification->subject_aggregate,'subject_aggregate_id'=>(int)$notification->subject_aggregate_id,
            'observed_at'=>$notification->observed_at,'schedule_anchor_at'=>$notification->schedule_anchor_at,
            'scheduled_for'=>$notification->scheduled_for,'expires_at'=>$notification->expires_at,
            'deferral_count'=>(int)$notification->deferral_count,'timezone'=>(string)$notification->timezone,
            'failure_reason_code'=>$notification->failure_reason_code,'outbox_id'=>$notification->outbox_id===null?null:(int)$notification->outbox_id,
            'notification_version'=>(int)$notification->notification_version,
            'contact_envelope_present'=>(bool)($notification->recipient_contact_envelope!==null),
            'template_version_id'=>$notification->template_version_id===null?null:(int)$notification->template_version_id,
            'rendered_snapshot_id'=>$notification->rendered_snapshot_id===null?null:(int)$notification->rendered_snapshot_id,
            'schedule_composition_present'=>true,
        );
    }
}
