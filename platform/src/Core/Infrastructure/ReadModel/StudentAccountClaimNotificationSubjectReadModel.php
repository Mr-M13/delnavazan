<?php
namespace Delnavazan\Platform\Core\Infrastructure\ReadModel;

use Delnavazan\Platform\Core\Application\NotificationSubjectReadPort;
use Delnavazan\Platform\Core\Application\NotificationSupport;

/** Read-only #221 projection used to suppress stale, revoked, replaced, expired or claimed claim sends. */
final class StudentAccountClaimNotificationSubjectReadModel implements NotificationSubjectReadPort {
    public function subject(string $aggregate,int $aggregateId,?string $instantColumn):?array{
        if($aggregate!=='student_account_claim_invitation'||$aggregateId<1)return null;
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=NotificationSupport::now();
        $row=$wpdb->get_row($wpdb->prepare("SELECT i.id,i.student_id,i.state,i.current_generation,g.state AS generation_state,g.active_slot,g.expires_at,s.status AS student_status,s.archived_at FROM {$p}student_account_claim_invitations i INNER JOIN {$p}student_account_claim_generations g ON g.invitation_id=i.id AND g.generation_number=i.current_generation INNER JOIN {$p}students s ON s.id=i.student_id WHERE i.id=%d LIMIT 1",$aggregateId));
        if(!$row)return null;
        $live=(string)$row->state==='active'&&(string)$row->generation_state==='active'&&(int)$row->active_slot===1&&(string)$row->expires_at>$now&&(string)$row->student_status==='active'&&$row->archived_at===null;
        return array('exists'=>$live,'instant'=>null,'timezone'=>null,'subject_reference_digest'=>hash_hmac('sha256','student_account_claim_invitation:'.$aggregateId,NotificationSupport::salt()));
    }
}
