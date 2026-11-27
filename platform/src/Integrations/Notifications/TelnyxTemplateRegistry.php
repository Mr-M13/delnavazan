<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Delnavazan's approved Telnyx WhatsApp template registry.
 *
 * The provider template name is the stable contract. Each entry records the local notification event
 * and the exact order of local variable codes required by the approved Meta body. Discovery uses this
 * registry to map an approved provider template to an event; the WhatsApp adapter uses the same order
 * to build `body.parameters` without guessing from a translated sentence.
 */
final class TelnyxTemplateRegistry {
    public const PROVIDER='telnyx';
    public const LANGUAGE='fa';

    /** @return array<string,array{event_key:string,audience:string,variable_order:string[]}> */
    public static function definitions():array{
        return array(
            'delnavazan_booking_confirmation_v2_fa'=>array('event_key'=>'booking.requested.student','audience'=>'student','variable_order'=>array('name','course','when','portal_url')),
            'delnavazan_intro_confirmed_v2_fa'=>array('event_key'=>'booking.confirmed.student','audience'=>'student','variable_order'=>array('name','course','teacher','when','portal_url')),
            'delnavazan_intro_cancelled_fa'=>array('event_key'=>'booking.cancelled.student','audience'=>'student','variable_order'=>array('name','course','teacher','when','portal_url')),
            'delnavazan_intro_declined_fa'=>array('event_key'=>'booking.declined.student','audience'=>'student','variable_order'=>array('name','teacher','course','portal_url')),
            'delnavazan_intro_rescheduled_fa'=>array('event_key'=>'booking.rescheduled.student','audience'=>'student','variable_order'=>array('name','course','teacher','when','portal_url')),
            'delnavazan_class_rescheduled_v2_fa'=>array('event_key'=>'reschedule.change.accepted.student','audience'=>'student','variable_order'=>array('name','when','timezone','portal_url')),
            'delnavazan_class_reminder_v2_fa'=>array('event_key'=>'lesson.reminder.student','audience'=>'student','variable_order'=>array('name','course','teacher','when','portal_url')),
            'delnavazan_reschedule_request_v2_fa'=>array('event_key'=>'reschedule.change.proposed.student','audience'=>'student','variable_order'=>array('name','when','timezone','portal_url')),
            'delnavazan_enrolment_confirmation_v2_fa'=>array('event_key'=>'post_intro.activated.student','audience'=>'student','variable_order'=>array('name','course','teacher','portal_url')),
            'delnavazan_payment_due_v2_fa'=>array('event_key'=>'renewal.manual_payment_required.student','audience'=>'student','variable_order'=>array('name','course','amount','due_date','portal_url')),
            'delnavazan_payment_received_v2_fa'=>array('event_key'=>'payment.recovered.student','audience'=>'student','variable_order'=>array('name','course','amount','paid_date','portal_url')),
            'delnavazan_renewal_reminder_v2_fa'=>array('event_key'=>'renewal.automatic_upcoming.student','audience'=>'student','variable_order'=>array('name','course','due_date','amount','portal_url')),
            'delnavazan_class_cancelled_v2_fa'=>array('event_key'=>'lesson.cancelled.student','audience'=>'student','variable_order'=>array('name','course','teacher','when','portal_url')),
            'delnavazan_teacher_new_booking_v2_fa'=>array('event_key'=>'booking.new.teacher','audience'=>'teacher','variable_order'=>array('teacher','course','student','when','response_window','teacher_portal_url')),
            'delnavazan_admin_new_intro_v2_fa'=>array('event_key'=>'booking.submitted.admin','audience'=>'admin','variable_order'=>array('booking_reference','learner_name','instrument','requested_time','queue_url')),
            'delnavazan_teacher_schedule_change_v2_fa'=>array('event_key'=>'reschedule.change.accepted.teacher','audience'=>'teacher','variable_order'=>array('teacher','when','timezone','teacher_portal_url')),
            'delnavazan_reschedule_rejected_v2_fa'=>array('event_key'=>'reschedule.change.rejected.student','audience'=>'student','variable_order'=>array('name','when','portal_url')),
            'delnavazan_class_access_v2_fa'=>array('event_key'=>'lesson.access.student','audience'=>'student','variable_order'=>array('name','course','teacher','when','portal_url')),
            'delnavazan_payment_failed_student_fa'=>array('event_key'=>'payment.failed.student','audience'=>'student','variable_order'=>array('name','course','amount','portal_url')),
            'delnavazan_refund_resolved_student_v2_fa'=>array('event_key'=>'refund.resolved.student','audience'=>'student','variable_order'=>array('name','amount','portal_url')),
            'delnavazan_refund_review_required_student_v2_fa'=>array('event_key'=>'refund.review_required.student','audience'=>'student','variable_order'=>array('name','amount','portal_url')),
            'delnavazan_renewal_automatic_charged_student_v2_fa'=>array('event_key'=>'renewal.automatic_charged.student','audience'=>'student','variable_order'=>array('name','course','amount','paid_date','portal_url')),
            'delnavazan_renewal_automatic_failed_student_v2_fa'=>array('event_key'=>'renewal.automatic_failed.student','audience'=>'student','variable_order'=>array('name','course','amount','due_date','portal_url')),
            'delnavazan_renewal_guarantee_deadline_approaching_student_v2_fa'=>array('event_key'=>'renewal.guarantee_deadline_approaching.student','audience'=>'student','variable_order'=>array('name','course','due_date','amount','portal_url')),
            'delnavazan_renewal_term_lapsed_student_v2_fa'=>array('event_key'=>'renewal.term_lapsed.student','audience'=>'student','variable_order'=>array('name','course','portal_url')),
            'delnavazan_reschedule_change_cancelled_student_fa'=>array('event_key'=>'reschedule.change.cancelled.student','audience'=>'student','variable_order'=>array('name','portal_url')),
            'delnavazan_reschedule_change_cancelled_teacher_fa'=>array('event_key'=>'reschedule.change.cancelled.teacher','audience'=>'teacher','variable_order'=>array('teacher','teacher_portal_url')),
            'delnavazan_reschedule_change_failed_student_fa'=>array('event_key'=>'reschedule.change.failed.student','audience'=>'student','variable_order'=>array('name','portal_url')),
            'delnavazan_reschedule_change_failed_teacher_fa'=>array('event_key'=>'reschedule.change.failed.teacher','audience'=>'teacher','variable_order'=>array('teacher','teacher_portal_url')),
            'delnavazan_reschedule_change_proposed_teacher_fa'=>array('event_key'=>'reschedule.change.proposed.teacher','audience'=>'teacher','variable_order'=>array('teacher','when','timezone','teacher_portal_url')),
            'delnavazan_reschedule_change_rejected_teacher_fa'=>array('event_key'=>'reschedule.change.rejected.teacher','audience'=>'teacher','variable_order'=>array('teacher','when','teacher_portal_url')),
            'delnavazan_reschedule_replacement_accepted_student_fa'=>array('event_key'=>'reschedule.replacement.accepted.student','audience'=>'student','variable_order'=>array('name','when','timezone','portal_url')),
            'delnavazan_reschedule_replacement_accepted_teacher_fa'=>array('event_key'=>'reschedule.replacement.accepted.teacher','audience'=>'teacher','variable_order'=>array('teacher','when','timezone','teacher_portal_url')),
            'delnavazan_reschedule_replacement_cancelled_student_fa'=>array('event_key'=>'reschedule.replacement.cancelled.student','audience'=>'student','variable_order'=>array('name','portal_url')),
            'delnavazan_reschedule_replacement_cancelled_teacher_fa'=>array('event_key'=>'reschedule.replacement.cancelled.teacher','audience'=>'teacher','variable_order'=>array('teacher','teacher_portal_url')),
            'delnavazan_reschedule_replacement_failed_student_fa'=>array('event_key'=>'reschedule.replacement.failed.student','audience'=>'student','variable_order'=>array('name','portal_url')),
            'delnavazan_reschedule_replacement_failed_teacher_fa'=>array('event_key'=>'reschedule.replacement.failed.teacher','audience'=>'teacher','variable_order'=>array('teacher','teacher_portal_url')),
            'delnavazan_reschedule_replacement_proposed_student_fa'=>array('event_key'=>'reschedule.replacement.proposed.student','audience'=>'student','variable_order'=>array('name','when','timezone','portal_url')),
            'delnavazan_reschedule_replacement_proposed_teacher_fa'=>array('event_key'=>'reschedule.replacement.proposed.teacher','audience'=>'teacher','variable_order'=>array('teacher','when','timezone','teacher_portal_url')),
            'delnavazan_reschedule_replacement_rejected_student_fa'=>array('event_key'=>'reschedule.replacement.rejected.student','audience'=>'student','variable_order'=>array('name','when','portal_url')),
            'delnavazan_reschedule_replacement_rejected_teacher_fa'=>array('event_key'=>'reschedule.replacement.rejected.teacher','audience'=>'teacher','variable_order'=>array('teacher','when','teacher_portal_url')),
        );
    }

    /** @return array{event_key:string,audience:string,variable_order:string[]}|null */
    public static function definitionForName(string $name):?array{
        return self::definitions()[trim($name)]??null;
    }
    public static function eventKeyForName(string $name):?string{
        return self::definitionForName($name)['event_key']??null;
    }
    /** @return string[] */
    public static function variableOrderForName(string $name):array{
        return self::definitionForName($name)['variable_order']??array();
    }
    /** The approved template name for one local event, or null when no approved name is registered. */
    public static function nameForEvent(string $eventKey):?string{
        foreach(self::definitions() as $name=>$definition)if($definition['event_key']===$eventKey)return $name;
        return null;
    }
    /** Synthetic samples for an explicit provider test; never real recipient data. */
    public static function sampleParameters():array{
        return array(
            'name'=>'باران','student'=>'باران','teacher'=>'ندا','course'=>'تار',
            'when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ به وقت تهران',
            'timezone'=>'Asia/Tehran',
            'portal_url'=>'https://example.invalid/student-portal/',
            'teacher_portal_url'=>'https://example.invalid/teacher-portal/',
            'response_window'=>'۲۴ ساعت',
            'current_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ به وقت تهران',
            'previous_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ به وقت تهران',
            'new_when'=>'یکشنبه ۱۱ آبان ۱۴۰۵، ساعت ۱۸:۰۰ به وقت تهران',
            'amount'=>'۲٬۵۰۰٬۰۰۰ تومان','due_date'=>'۱۵ آبان ۱۴۰۵','paid_date'=>'۱۲ آبان ۱۴۰۵',
        );
    }
}
