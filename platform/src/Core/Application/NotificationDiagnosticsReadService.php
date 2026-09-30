<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * §14 — the capability-protected, channel-independent diagnostics projection.
 *
 * Every diagnostic reports a count and identifiers only: no PII, no envelope content, no secret and no
 * channel-only field. A diagnostic never mutates anything and never repairs an aggregate.
 */
final class NotificationDiagnosticsReadService {
    public function __construct(
        private ?NotificationRepository $repository=null,
        private ?NotificationWorkflowRepository $workflows=null,
        private ?NotificationAttemptRepository $attempts=null,
        private ?NotificationOutboxRepository $outbox=null
    ){
        $this->repository??=new NotificationRepository();
        $this->workflows??=new NotificationWorkflowRepository();
        $this->attempts??=new NotificationAttemptRepository();
        $this->outbox??=new NotificationOutboxRepository();
    }
    /** The full diagnostic projection, keyed by the closed §14 vocabulary. */
    public function report():array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        global $wpdb;$prefix=$wpdb->prefix.'dzn_';$now=NotificationSupport::now();
        $states=$this->repository->countsByState();
        $registered=array();
        foreach($this->workflows->workflows() as $workflow){}
        foreach(NotificationRule::INTENTS as $intent){
            if(NotificationRule::unboundIntent($intent))continue;
            $version=$this->workflows->activeVersionForIntent($intent);
            if($version===null)$registered[$intent]=true;
        }
        $unregistered=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}platform_outbox WHERE notification_id IS NULL AND invitation_id IS NULL AND generation_id IS NULL AND status='pending' AND event_type NOT IN (".implode(',',array_fill(0,count(NotificationRule::INTENTS),'%s')).")",
            ...NotificationRule::INTENTS
        ));
        $backlog=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}notifications WHERE state IN ('pending','scheduled') AND scheduled_for IS NOT NULL AND scheduled_for<=%s",$now));
        $stuck=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}platform_outbox WHERE status='leased' AND leased_at IS NOT NULL AND available_at<=%s",$now));
        $orphan=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}platform_outbox WHERE notification_id IS NOT NULL AND notification_id NOT IN (SELECT id FROM {$prefix}notifications)");
        $expiredLeases=count($this->attempts->expiredLeases($now,200));
        $exhausted=(int)($states['failed']??0);
        $window=(int)($states['expired']??0);
        return array(
            'counts'=>$states,
            'unregistered_intent'=>$unregistered,
            'unroutable_intent'=>count($registered),
            'unroutable_intents'=>array_keys($registered),
            'orchestration_backlog'=>$backlog,
            'stuck_lease'=>$stuck,
            'expired_lease'=>$expiredLeases,
            'orphan_outbox_row'=>$orphan,
            'retry_exhausted'=>$exhausted,
            'retry_window_exhausted'=>$window,
            'generated_at'=>$now,
        );
    }
    /** One diagnostic by name, so a caller never depends on the whole projection's shape. */
    public function one(string $diagnostic):array{
        if(!NotificationRule::diagnostic($diagnostic))throw new \InvalidArgumentException('unregistered_intent');
        $report=$this->report();
        return array('diagnostic'=>$diagnostic,'value'=>$report[$diagnostic]??0,'generated_at'=>$report['generated_at']);
    }
}
