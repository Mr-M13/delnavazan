<?php
namespace Delnavazan\Platform\Core\Application\Finance\Integrity;

use Delnavazan\Platform\Core\Application\Finance\{FinanceFacts,FinanceRule,FinanceSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinanceSnapshotRepository,TeacherRateRepository};

/**
 * Pure, non-mutating proof of the per-Lesson snapshot and its effective correction (§8, §12.2).
 *
 * The snapshot is never mutated by a correction: the *effective* snapshot of a Lesson is the Lesson's
 * snapshot when no correction names it, otherwise the newest applicable correction row. Both are
 * re-verified — recorded rate row/version, occurrence anchor, currency and derivation digest — before a
 * consumer may read them, and a mismatch fails closed with `snapshot_derivation_mismatch`.
 */
final class FinanceSnapshotIntegrity {
    public static function digest(array $values):string{
        return FinanceSupport::digest(FinanceRule::SNAPSHOT_DIGEST_FIELDS,$values);
    }
    /** Recompute a stored snapshot's digest from its own recorded facts. */
    public static function assertDigest(object $snapshot):void{
        $values=array();
        foreach(FinanceRule::SNAPSHOT_DIGEST_FIELDS as $field)$values[$field]=$snapshot->{$field}??null;
        if(!hash_equals((string)$snapshot->derivation_digest,self::digest($values)))throw new \RuntimeException('snapshot_derivation_mismatch');
    }
    /**
     * The effective snapshot of a Lesson, proved against the canonical facts it named.
     *
     * @return array{snapshot:object,correction:?object,amount_minor:int,currency:string,rate_id:int,rate_version:int,scope_kind:string,compensation_basis:string,intro_policy_key:?string,intro_policy_version:?int}
     */
    public static function effective(int $lessonId,?FinanceSnapshotRepository $snapshots=null,?TeacherRateRepository $rates=null):array{
        $snapshots??=new FinanceSnapshotRepository();
        $rates??=new TeacherRateRepository();
        $snapshot=$snapshots->byLesson($lessonId);
        if(!$snapshot)throw new \RuntimeException('snapshot_missing_for_lesson');
        self::assertDigest($snapshot);
        $rate=$rates->byId((int)$snapshot->rate_id);
        if(!$rate||(int)$rate->rate_version!==(int)$snapshot->rate_version)throw new \RuntimeException('snapshot_derivation_mismatch');
        // §8.1: the recorded rate row must be the row the interval resolution returned for this instant.
        if(!FinanceRateIntegrity::covers($rate,(string)$snapshot->snapshot_instant_utc))throw new \RuntimeException('snapshot_derivation_mismatch');
        $facts=new FinanceFacts();
        $context=$facts->context((int)$snapshot->lesson_id);
        if((int)$context['teacher_id']!==(int)$snapshot->teacher_id||(int)$context['course_id']!==(int)$snapshot->course_id)throw new \RuntimeException('snapshot_derivation_mismatch');
        if($context['occurrence_starts_at_utc']!==(string)$snapshot->snapshot_instant_utc)throw new \RuntimeException('snapshot_derivation_mismatch');
        if((string)$context['kind']!==(string)$snapshot->lesson_kind)throw new \RuntimeException('snapshot_derivation_mismatch');
        $correction=$snapshots->applicableCorrection((int)$snapshot->id);
        if($correction){
            if((int)$correction->snapshot_id!==(int)$snapshot->id||(int)$correction->lesson_id!==(int)$snapshot->lesson_id)throw new \RuntimeException('snapshot_derivation_mismatch');
            if(!hash_equals((string)$snapshot->derivation_digest,(string)$correction->prior_snapshot_digest))throw new \RuntimeException('snapshot_derivation_mismatch');
            $values=array('snapshot_id'=>(int)$correction->snapshot_id,'lesson_id'=>(int)$correction->lesson_id,'corrected_rate_id'=>(int)$correction->corrected_rate_id,'corrected_rate_version'=>(int)$correction->corrected_rate_version,'corrected_rate_amount_minor'=>(int)$correction->corrected_rate_amount_minor,'corrected_currency'=>(string)$correction->corrected_currency,'corrected_derived_amount_minor'=>(int)$correction->corrected_derived_amount_minor,'intro_policy_key'=>$correction->intro_policy_key,'intro_policy_version'=>$correction->intro_policy_version===null?null:(int)$correction->intro_policy_version,'prior_snapshot_digest'=>(string)$correction->prior_snapshot_digest);
            if(!hash_equals((string)$correction->derivation_digest,FinanceSupport::digest(FinanceRule::CORRECTION_DIGEST_FIELDS,$values)))throw new \RuntimeException('snapshot_derivation_mismatch');
            $correctedRate=$rates->byId((int)$correction->corrected_rate_id);
            if(!$correctedRate||(int)$correctedRate->rate_version!==(int)$correction->corrected_rate_version)throw new \RuntimeException('snapshot_derivation_mismatch');
            $introKey=$correction->intro_policy_key===null?null:(string)$correction->intro_policy_key;
            $introVersion=$correction->intro_policy_version===null?null:(int)$correction->intro_policy_version;
            if(($introKey===null)!==($introVersion===null))throw new \RuntimeException('snapshot_correction_incomplete');
            if((string)$snapshot->lesson_kind==='introductory'){if($introKey!=='INTRO_PAYABILITY_POLICY')throw new \RuntimeException('snapshot_correction_incomplete');}
            elseif($introKey!==null)throw new \RuntimeException('snapshot_correction_incomplete');
            return array('snapshot'=>$snapshot,'correction'=>$correction,'amount_minor'=>(int)$correction->corrected_derived_amount_minor,'currency'=>(string)$correction->corrected_currency,'rate_id'=>(int)$correction->corrected_rate_id,'rate_version'=>(int)$correction->corrected_rate_version,'scope_kind'=>(string)$correctedRate->scope_kind,'compensation_basis'=>(string)$correctedRate->compensation_basis,'intro_policy_key'=>$introKey,'intro_policy_version'=>$introVersion);
        }
        $introKey=$snapshot->intro_policy_key===null?null:(string)$snapshot->intro_policy_key;
        $introVersion=$snapshot->intro_policy_version===null?null:(int)$snapshot->intro_policy_version;
        if(($introKey===null)!==($introVersion===null))throw new \RuntimeException('snapshot_correction_incomplete');
        if((string)$snapshot->lesson_kind==='introductory'){if($introKey!=='INTRO_PAYABILITY_POLICY')throw new \RuntimeException('snapshot_derivation_mismatch');}
        elseif($introKey!==null)throw new \RuntimeException('snapshot_derivation_mismatch');
        return array('snapshot'=>$snapshot,'correction'=>null,'amount_minor'=>(int)$snapshot->derived_amount_minor,'currency'=>(string)$snapshot->currency,'rate_id'=>(int)$snapshot->rate_id,'rate_version'=>(int)$snapshot->rate_version,'scope_kind'=>(string)$snapshot->scope_kind,'compensation_basis'=>(string)$snapshot->compensation_basis,'intro_policy_key'=>$introKey,'intro_policy_version'=>$introVersion);
    }
}
