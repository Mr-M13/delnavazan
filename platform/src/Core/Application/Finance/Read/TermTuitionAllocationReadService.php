<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\TuitionAllocationEstimator;
use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/** Owner-scoped, read-only paid-term projection for the illustrative allocation UI. */
final class TermTuitionAllocationReadService {
    public function ownStudent(): array {
        $principal=(new PortalPrincipalResolver())->resolve('student');
        return $this->read('student',(int)($principal['id']??0));
    }

    public function ownTeacher(): array {
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $teacherId=(int)($principal['id']??0);
        if(!(new PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),$teacherId))throw new \InvalidArgumentException('teacher_onboarding_required');
        return $this->read('teacher',$teacherId);
    }

    private function read(string $kind,int $principalId): array {
        if($principalId<1)throw new \InvalidArgumentException('portal_principal_required');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $ownership=$kind==='teacher'
            ? "INNER JOIN {$p}teacher_assignments a ON a.enrolment_id=f.enrolment_id AND a.teacher_id=%d AND a.applicable_slot=1 AND a.state='assigned'"
            : "INNER JOIN {$p}enrolments e ON e.id=f.enrolment_id AND e.student_id=%d AND e.archived_at IS NULL";
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT f.term_id,t.reference_code term_reference,o.currency,
                    COUNT(ob.id) obligation_count,COUNT(os.id) settlement_count,
                    COALESCE(SUM(os.amount_minor),0) paid_minor
             FROM {$p}commercial_term_funding_plans f
             INNER JOIN {$p}terms t ON t.id=f.term_id AND t.archived_at IS NULL
             {$ownership}
             INNER JOIN {$p}commercial_offers o ON o.id=f.offer_id
             INNER JOIN {$p}commercial_offer_obligations ob ON ob.offer_id=o.id
             LEFT JOIN {$p}commercial_obligation_settlements os ON os.obligation_id=ob.id AND os.currency=o.currency
             WHERE o.currency REGEXP '^[A-Z]{3}$'
             GROUP BY f.term_id,t.reference_code,o.currency
             HAVING COUNT(ob.id)>0 AND COUNT(os.id)=COUNT(ob.id)
             ORDER BY f.term_id DESC LIMIT 24",
            $principalId
        ),ARRAY_A);
        if($wpdb->last_error!=='')throw new \RuntimeException('tuition_allocation_read_unavailable');
        $items=[];
        foreach((array)$rows as$row){
            $amount=(int)($row['paid_minor']??0);$currency=strtoupper((string)($row['currency']??''));
            if($amount<0||preg_match('/^[A-Z]{3}$/D',$currency)!==1)continue;
            $items[]=array(
                'term_reference'=>trim((string)($row['term_reference']??''))?:('Term #'.(int)$row['term_id']),
                'currency'=>$currency,
                'amount_minor'=>$amount,
                'allocation'=>TuitionAllocationEstimator::estimate($amount),
            );
        }
        return array('state'=>$items?'available':'empty','items'=>$items,'illustrative'=>true);
    }
}
