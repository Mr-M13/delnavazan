<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;

/**
 * §8.4/§6.7 — the delivery read seam: normalised vocabulary, ranks and digests only.
 *
 * A retained-but-not-applied fact is reported as such; the raw provider payload and any provider
 * identifier are never part of a projection.
 */
final class NotificationDeliveryReadService {
    public function __construct(private ?NotificationDeliveryRepository $repository=null){$this->repository??=new NotificationDeliveryRepository();}
    public function deliveries(int $notificationId):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $rows=array();
        foreach($this->repository->deliveries($notificationId) as $delivery)$rows[]=$this->shape($delivery);
        return $rows;
    }
    /** The highest applied rank, which is the aggregate's real delivery progress. */
    public function appliedRank(int $notificationId):int{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        return $this->repository->appliedRank($notificationId);
    }
    /** Facts retained without being applied: a regression or a stale fact, never a silent rewrite. */
    public function retained(array $input,string $key):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        if(trim($key)==='')throw new \InvalidArgumentException('notification_command_key_required');
        global $wpdb;$prefix=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results("SELECT notification_id,delivery_state,applied FROM {$prefix}notification_deliveries WHERE applied=0 ORDER BY id")?:array();
        $out=array();
        foreach($rows as $row)$out[]=array('notification_id'=>(int)$row->notification_id,'delivery_state'=>(string)$row->delivery_state);
        return $out;
    }
    private function shape(object $delivery):array{
        return array(
            'delivery_id'=>(int)$delivery->id,'notification_id'=>(int)$delivery->notification_id,
            'attempt_id'=>(int)$delivery->attempt_id,'delivery_sequence'=>(int)$delivery->delivery_sequence,
            'delivery_state'=>(string)$delivery->delivery_state,'delivery_rank'=>(int)$delivery->delivery_rank,
            'applied'=>(int)$delivery->applied,'occurred_at'=>(string)$delivery->occurred_at,'recorded_at'=>(string)$delivery->recorded_at,
        );
    }
}
