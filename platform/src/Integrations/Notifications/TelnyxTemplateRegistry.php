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
            'delnavazan_booking_confirmation_fa'=>array('event_key'=>'booking.requested.student','audience'=>'student','variable_order'=>array('name','course','when')),
            'delnavazan_intro_confirmed_fa'=>array('event_key'=>'booking.confirmed.student','audience'=>'student','variable_order'=>array('name','course','teacher','when')),
            'delnavazan_class_rescheduled_fa'=>array('event_key'=>'reschedule.change.accepted.student','audience'=>'student','variable_order'=>array('name','course','teacher','previous_when','new_when')),
            'delnavazan_class_reminder_fa'=>array('event_key'=>'lesson.reminder.student','audience'=>'student','variable_order'=>array('name','course','teacher','when')),
            'delnavazan_reschedule_request_fa'=>array('event_key'=>'reschedule.change.proposed.student','audience'=>'student','variable_order'=>array('name','teacher','course','current_when','new_when')),
            'delnavazan_enrolment_confirmation_fa'=>array('event_key'=>'post_intro.activated.student','audience'=>'student','variable_order'=>array('name','course','teacher')),
            'delnavazan_payment_due_fa'=>array('event_key'=>'renewal.manual_payment_required.student','audience'=>'student','variable_order'=>array('name','amount','course','due_date')),
            'delnavazan_payment_received_fa'=>array('event_key'=>'payment.recovered.student','audience'=>'student','variable_order'=>array('name','amount','course','paid_date')),
            'delnavazan_renewal_reminder_fa'=>array('event_key'=>'renewal.automatic_upcoming.student','audience'=>'student','variable_order'=>array('name','course','due_date','amount')),
            'delnavazan_class_cancelled_fa'=>array('event_key'=>'lesson.cancelled.student','audience'=>'student','variable_order'=>array('name','course','teacher','when')),
            'delnavazan_teacher_new_booking_fa'=>array('event_key'=>'booking.new.teacher','audience'=>'teacher','variable_order'=>array('teacher','course','student','when')),
            'delnavazan_teacher_schedule_change_fa'=>array('event_key'=>'reschedule.change.accepted.teacher','audience'=>'teacher','variable_order'=>array('teacher','course','student','previous_when','new_when')),
            'delnavazan_reschedule_rejected_fa'=>array('event_key'=>'reschedule.change.rejected.student','audience'=>'student','variable_order'=>array('name','course','teacher','when')),
            'delnavazan_class_access_fa'=>array('event_key'=>'lesson.access.student','audience'=>'student','variable_order'=>array('name','course','teacher','when')),
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
            'current_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ به وقت تهران',
            'previous_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ به وقت تهران',
            'new_when'=>'یکشنبه ۱۱ آبان ۱۴۰۵، ساعت ۱۸:۰۰ به وقت تهران',
            'amount'=>'۲٬۵۰۰٬۰۰۰ تومان','due_date'=>'۱۵ آبان ۱۴۰۵','paid_date'=>'۱۲ آبان ۱۴۰۵',
        );
    }
}
