<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only projection of the recorded introductory outcome onto its legacy Lesson.
 *
 * A booking-linked introductory Lesson is created by the post-intro workflow only after the
 * Teacher records `delivered`. It is a legacy occurrence, not a canonical Term Lesson, so it has
 * no Phase-O delivery outcome and no Phase-P attendance case. The recorded introductory outcome
 * is therefore the authoritative delivery/attendance fact for that class; this projection exposes
 * it to the ordinary class and attendance read surfaces without inventing canonical authority.
 *
 * Standalone introductory Lessons (no Booking Request) and regular Lessons are never projected.
 */
final class IntroLessonOutcomeProjection {
    public const DELIVERED='delivered';
    public const ATTENDED='attended';
    public const SETTLED='settled';

    /** Whether the recorded outcome belongs to a booking-linked legacy introductory Lesson. */
    public static function applies(string $recordModel,string $lessonType,int $bookingRequestId):bool{
        return $recordModel==='legacy_phase1'
            &&$lessonType==='introductory'
            &&$bookingRequestId>0;
    }

    /** Whether the class was recorded as delivered by the introductory workflow. */
    public static function isDelivered(string $recordModel,string $lessonType,int $bookingRequestId,?string $outcome):bool{
        return self::applies($recordModel,$lessonType,$bookingRequestId)
            &&trim((string)$outcome)===self::DELIVERED;
    }

    /** Canonical-outcome vocabulary used by class and student read models. */
    public static function attendanceState(string $recordModel,string $lessonType,int $bookingRequestId,?string $outcome):?string{
        return self::isDelivered($recordModel,$lessonType,$bookingRequestId,$outcome)?self::ATTENDED:null;
    }

    /** Portal delivery summary vocabulary. */
    public static function deliveryStateSummary(string $recordModel,string $lessonType,int $bookingRequestId,?string $outcome):?string{
        return self::isDelivered($recordModel,$lessonType,$bookingRequestId,$outcome)?self::DELIVERED:null;
    }

    /** Portal attendance summary vocabulary: the recorded outcome settles the occurrence. */
    public static function attendanceStateSummary(string $recordModel,string $lessonType,int $bookingRequestId,?string $outcome):?string{
        return self::isDelivered($recordModel,$lessonType,$bookingRequestId,$outcome)?self::SETTLED:null;
    }

    /** Truthful detail for the class record: this is a recorded outcome, not provider evidence. */
    public static function attendanceDetail(string $recordModel,string $lessonType,int $bookingRequestId,?string $outcome):?string{
        return self::isDelivered($recordModel,$lessonType,$bookingRequestId,$outcome)
            ?'Recorded by the introductory outcome.'
            :null;
    }
}
