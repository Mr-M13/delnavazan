<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;

/**
 * Which unresolved automatic-attendance cases may fall back to a Teacher confirmation.
 *
 * A missing, incomplete, unverified or below-threshold evidence set is a genuine absence of
 * automatic settlement, so the Teacher who was present may supply the human evidence inside the
 * bounded window. Contradictory or integrity-sensitive cases remain administrator review: a
 * Teacher confirmation must never erase a conflict, a mismatched identity, a duplicate event, a
 * late-evidence violation or a canonical truth conflict.
 */
final class TeacherAttendanceFallbackRule {
    public const SAFE_ANOMALIES=array(
        'teacher_participation_unproven',
        'student_participation_unproven',
        'overlap_below_threshold',
        'provider_evidence_missing',
        'provider_evidence_incomplete',
        'participant_unknown',
        'participant_identity_unverified',
        'provider_identity_unmapped',
        'provider_outage',
    );

    /** Whether one durable case may be resolved by the owning Teacher's manual confirmation. */
    public static function allows(?object $case,CanonicalAttendanceRepository $repository):bool{
        if($case===null)return true;
        $state=(string)($case->state??'');
        if($state==='open')return true;
        if($state!=='ready_for_review')return false;
        foreach($repository->anomaliesForCase((int)$case->id) as $anomaly){
            if(!in_array((string)($anomaly->code??''),self::SAFE_ANOMALIES,true))return false;
        }
        return true;
    }

    /** A stable reason for the administrator-only branch, or null when the Teacher may confirm. */
    public static function refusal(?object $case,CanonicalAttendanceRepository $repository):?string{
        if(self::allows($case,$repository))return null;
        if($case===null)return null;
        if((string)($case->state??'')==='settlement_pending')return 'attendance_settlement_in_progress';
        return 'attendance_conflict_review_required';
    }
}
