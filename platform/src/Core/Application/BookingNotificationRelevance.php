<?php
namespace Delnavazan\Platform\Core\Application;

/** One durable-outbox relevance rule shared by the worker and authorised recovery. */
final class BookingNotificationRelevance {
    public static function isStillRelevant(object $row): bool {
        $kind = (string) ($row->notification_kind ?? '');
        $delivery = (string) ($row->delivery_state ?? '');
        $lifecycle = (string) ($row->lifecycle_status ?? '');
        $resolution = (string) ($row->resolution_state ?? '');
        $confirmation = (string) ($row->confirmation_state ?? '');
        if (in_array($kind, array('requested_student', 'requested_teacher'), true)) return $delivery === 'pending_request' && $lifecycle === 'submitted' && $resolution === 'unresolved';
        if (in_array($kind, array('cancelled', 'cancelled_teacher'), true)) return $delivery === 'cancelled' && $lifecycle === 'closed' && $resolution === 'cancelled' && $confirmation === 'cancelled';
        if ($kind === 'declined') return $delivery === 'declined' && $lifecycle === 'closed' && $resolution === 'teacher_declined';
        if ($kind === 'confirmed') return in_array($confirmation, array('confirmed', 'awaiting_payment'), true);
        return true;
    }
}
