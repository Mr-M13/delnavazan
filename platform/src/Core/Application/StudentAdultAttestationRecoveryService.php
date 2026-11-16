<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingStudentAccountRepository;

/** New prospective evidence only; never repairs or backdates prior consent decisions. */
final class StudentAdultAttestationRecoveryService {
    /** Read-only check; never offer adult recovery to known minors or guardians. */
    public function eligible():bool {
        if(!is_user_logged_in() || !current_user_can('read'))return false;
        $actor=(int)get_current_user_id();
        if($actor<1)return false;
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results($wpdb->prepare(
            "SELECT s.current_acceptance_capacity_classification_id FROM {$p}student_principal_links l " .
            "INNER JOIN {$p}students s ON s.id=l.student_id " .
            "WHERE l.wordpress_user_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL " .
            "AND s.status='active' AND s.archived_at IS NULL",$actor));
        return count($rows)===1 && $rows[0]->current_acceptance_capacity_classification_id===null;
    }

    public function recover():void {
        if(!is_user_logged_in() || !current_user_can('read'))throw new \RuntimeException('capacity_authentication_required');
        global $wpdb;
        $actor=(int)get_current_user_id();
        $repo=new BookingStudentAccountRepository();
        $repo->assertTransactionalStorage();
        $repo->begin();
        try {
            $link=$repo->activePrincipalForUserForUpdate($actor);
            if(!$link)throw new \RuntimeException('capacity_principal_unavailable');
            $studentId=(int)$link->student_id;
            $p=$wpdb->prefix.'dzn_';
            $student=$wpdb->get_row($wpdb->prepare("SELECT id,current_acceptance_capacity_classification_id FROM {$p}students WHERE id=%d AND status='active' AND archived_at IS NULL FOR UPDATE",$studentId));
            if(!$student)throw new \RuntimeException('capacity_student_unavailable');
            if($student->current_acceptance_capacity_classification_id!==null)throw new \RuntimeException('capacity_already_classified');
            $repo->recordAdultSelfAttestation($studentId,$actor,gmdate('Y-m-d H:i:s'),'account_recovery');
            $repo->commit();
        }catch(\Throwable $error){$repo->rollback();throw $error;}
    }
}
