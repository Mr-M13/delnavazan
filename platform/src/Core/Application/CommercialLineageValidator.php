<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalContinuationRepository,CommercialAuthorityRepository,StudentRepository,TeacherRepository};

/**
 * The single canonical, transaction-aware validator for the Phase 2A.2-R1 commercial aggregate.
 *
 * A commercial row can be intrinsically valid while the cross-authority relationship it represents
 * is corrupt: a Course can be replaced, a Student or Teacher re-pointed, the authoritative slot
 * authority or pre-payment hold superseded, or a product/price re-scoped. No individual row check can
 * see that. Every mutation owner that consumes the commercial relationship therefore proves the
 * stored aggregate through this one implementation immediately before it creates business truth, so
 * corruption fails the authority that would otherwise commit it rather than being discovered later by
 * a read seam, a verifier, or Term creation after purchase truth already exists.
 *
 * The authoritative chain proved here is:
 *
 *   continuation case → authorised first regular slot → pre-payment hold → product → price
 *   → offer → Student → Teacher → Course (canonical Enrolment)
 *
 * The validator is usable from a transaction that already holds locks. `$lock` applies to the
 * commercial aggregate rows the caller is about to consume — the offer, its obligations and pricing
 * snapshots, and the locked pricing sources (product, price, promotion, account adjustment). The
 * cross-authority rows (continuation case, authorised slot, pre-payment hold, canonical Enrolment,
 * Student, Teacher) are proved from their current committed state inside the caller's transaction,
 * because acquiring them here would add a lock edge between the commercial serialization device and
 * the Enrolment-chain/Teacher-root order that Phases L, M, N and Q already establish: a paid
 * settlement would then block an unrelated canonical Lesson or schedule command for the same
 * Enrolment. Each mutation owner still locks the authoritative rows its own mutation needs (the
 * pre-payment hold and its intervals for capacity handoff, the Enrolment and Term for Term binding).
 */
final class CommercialLineageValidator {
    /**
     * Prove the authoritative aggregate that owns one offer and return the hydrated offer row.
     *
     * @throws \InvalidArgumentException commercial_offer_integrity_conflict / commercial_course_continuity_conflict
     */
    public static function assertOfferAggregate(int $offerId,bool $lock=false,?CommercialAuthorityRepository $authority=null,?CanonicalContinuationRepository $continuations=null):object{
        $authority??=new CommercialAuthorityRepository();
        $continuations??=new CanonicalContinuationRepository();
        $offer=$authority->offer($offerId,$lock);
        if(!$offer)throw new \InvalidArgumentException('commercial_offer_integrity_conflict');
        self::assertForOffer($offer,$lock,$authority,$continuations);
        return $offer;
    }

    /**
     * Prove the authoritative aggregate for an already-hydrated (and, where required, locked) offer.
     *
     * The caller's `$offer` row is the authority for what is being proved; nothing here trusts a
     * caller-supplied claim about the relationship, only the stored rows behind it.
     */
    public static function assertForOffer(object $offer,bool $lock=false,?CommercialAuthorityRepository $authority=null,?CanonicalContinuationRepository $continuations=null):void{
        $authority??=new CommercialAuthorityRepository();
        $continuations??=new CanonicalContinuationRepository();
        self::assertChain($offer,$lock,$authority,$continuations);
    }

    private static function assertChain(object $offer,bool $lock,CommercialAuthorityRepository $authority,CanonicalContinuationRepository $continuations):void{
        $conflict='commercial_offer_integrity_conflict';
        $courseConflict='commercial_course_continuity_conflict';
        $offerId=(int)$offer->id;
        if($offerId<1)throw new \InvalidArgumentException($conflict);
        // The intrinsic pricing snapshot must already be coherent; the aggregate proof is additive to it.
        if(!CommercialValidator::offerValid($offer,$authority->obligationsForOffer($offerId,$lock),$authority->offerAdjustments($offerId,$lock)))throw new \InvalidArgumentException($conflict);
        $studentId=(int)$offer->beneficiary_student_id;
        $teacherId=(int)$offer->teacher_id;
        $courseId=(int)$offer->course_id;
        if($studentId<1||$teacherId<1||$courseId<1)throw new \InvalidArgumentException($conflict);
        // A purchase is always for the offer's own beneficiary: an offer may never sell to another Student.
        if((int)$offer->student_id!==$studentId)throw new \InvalidArgumentException($conflict);

        // 1. The continuation case remains the authoritative source of the offer's identity.
        $case=$continuations->caseById((int)$offer->continuation_case_id);
        if(!$case)throw new \InvalidArgumentException($conflict);
        if((int)$case->intro_lesson_id!==(int)$offer->intro_lesson_id)throw new \InvalidArgumentException($conflict);
        if((int)$case->student_id!==$studentId||(int)$case->teacher_id!==$teacherId)throw new \InvalidArgumentException($conflict);
        if((int)$case->course_id!==$courseId)throw new \InvalidArgumentException($courseConflict);

        // 2. The offer must hold the case's own authorised first regular slot, not merely some slot.
        if($offer->slot_authority_id===null)throw new \InvalidArgumentException($conflict);
        $authorisedSlot=$continuations->slotAuthorityForIntro((int)$case->intro_lesson_id);
        $slotAuthority=$continuations->slotAuthorityById((int)$offer->slot_authority_id);
        if(!$authorisedSlot||!$slotAuthority)throw new \InvalidArgumentException($conflict);
        if((int)$authorisedSlot->id!==(int)$slotAuthority->id)throw new \InvalidArgumentException($conflict);
        if((int)$slotAuthority->intro_lesson_id!==(int)$case->intro_lesson_id||(int)$slotAuthority->intro_schedule_version_id!==(int)$case->intro_schedule_version_id)throw new \InvalidArgumentException($conflict);
        if((int)$slotAuthority->student_id!==$studentId||(int)$slotAuthority->teacher_id!==$teacherId)throw new \InvalidArgumentException($conflict);
        if((int)$slotAuthority->course_id!==$courseId)throw new \InvalidArgumentException($courseConflict);

        // 3. The held capacity the offer's window is bound to must still be that slot's hold.
        if($offer->reservation_id===null)throw new \InvalidArgumentException($conflict);
        $reservation=$continuations->reservationForCase((int)$case->id);
        if(!$reservation||(int)$reservation->id!==(int)$offer->reservation_id)throw new \InvalidArgumentException($conflict);
        if((int)($reservation->slot_authority_id??0)!==(int)$slotAuthority->id)throw new \InvalidArgumentException($conflict);
        if((int)$reservation->source_intro_lesson_id!==(int)$case->intro_lesson_id||(int)$reservation->source_intro_schedule_version_id!==(int)$case->intro_schedule_version_id)throw new \InvalidArgumentException($conflict);
        if((int)$reservation->student_id!==$studentId||(int)$reservation->teacher_id!==$teacherId)throw new \InvalidArgumentException($conflict);
        if((int)$reservation->course_id!==$courseId)throw new \InvalidArgumentException($courseConflict);

        // 4. The sellable product, its price row and the offer must describe one Course.
        $product=$authority->product((int)$offer->product_id,$lock);
        if(!$product||(int)$product->id!==(int)$offer->product_id)throw new \InvalidArgumentException($conflict);
        if((int)$product->course_id!==$courseId)throw new \InvalidArgumentException($courseConflict);
        $price=$authority->price((int)$offer->course_price_id,$lock);
        if(!$price)throw new \InvalidArgumentException($conflict);
        if((int)$price->product_id!==(int)$offer->product_id)throw new \InvalidArgumentException($conflict);
        if((string)$price->currency!==(string)$offer->currency||(string)$price->region_code!==(string)$offer->region_code)throw new \InvalidArgumentException($conflict);

        // 5. Student, Teacher and Course authority still agree on the one canonical Enrolment.
        $enrolment=$authority->canonicalEnrolmentFor($studentId,$courseId);
        if(!$enrolment)throw new \InvalidArgumentException($conflict);
        if((int)$enrolment->student_id!==$studentId||(int)$enrolment->course_id!==$courseId)throw new \InvalidArgumentException($conflict);
        if((int)$enrolment->teacher_id!==$teacherId)throw new \InvalidArgumentException($conflict);
        if((new StudentRepository())->usable($studentId)===null||(new TeacherRepository())->usable($teacherId)===null)throw new \InvalidArgumentException($conflict);

        // 6. The immutable pricing pipeline and its snapshotted sources must still correspond exactly.
        self::assertAdjustmentSources($offer,$lock,$authority);
    }

    /**
     * Prove the immutable pricing pipeline: promotion first, account adjustment second, each
     * snapshot still exactly corresponding to its locked authoritative source.
     */
    private static function assertAdjustmentSources(object $offer,bool $lock,CommercialAuthorityRepository $authority):void{
        $conflict='commercial_offer_integrity_conflict';
        $snapshots=$authority->offerAdjustments((int)$offer->id,$lock);
        $offerCurrency=(string)$offer->currency;
        if($offer->promotion_id!==null){
            $promotion=$authority->promotion((int)$offer->promotion_id,$lock);
            if(!$promotion)throw new \InvalidArgumentException($conflict);
            $snapshot=CommercialValidator::adjustmentSnapshotFor($offer,$snapshots,'promotion');
            if($snapshot===null)throw new \InvalidArgumentException($conflict);
            if(!CommercialValidator::promotionSnapshotMatches($promotion,$snapshot,$offerCurrency,(int)($offer->promotion_amount_minor??0)))throw new \InvalidArgumentException($conflict);
        }
        if($offer->account_adjustment_id!==null){
            $adjustment=$authority->adjustment((int)$offer->account_adjustment_id,$lock);
            if(!$adjustment)throw new \InvalidArgumentException($conflict);
            $snapshot=CommercialValidator::adjustmentSnapshotFor($offer,$snapshots,'account_adjustment');
            if($snapshot===null)throw new \InvalidArgumentException($conflict);
            // The running amount is the amount AFTER any earlier immutable pipeline stage.
            $running=(int)$offer->base_amount_minor-(int)($offer->promotion_amount_minor??0);
            if(!CommercialValidator::accountAdjustmentSnapshotMatches($adjustment,$snapshot,$running,(int)$snapshot->application_order,$offerCurrency,(int)($offer->account_adjustment_amount_minor??0)))throw new \InvalidArgumentException('commercial_adjustment_snapshot_conflict');
        }
    }
}
