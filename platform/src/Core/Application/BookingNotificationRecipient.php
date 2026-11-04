<?php
namespace Delnavazan\Platform\Core\Application;

/** Canonical booking recipient resolution with legacy payload compatibility. */
final class BookingNotificationRecipient {
    public static function email(object $row): string {
        if (str_ends_with((string) ($row->notification_kind ?? ''), '_teacher')) return trim((string) ($row->teacher_email ?? ''));
        $current = trim((string) ($row->student_account_email ?? ''));
        return $current !== '' ? $current : trim((string) ($row->student_email ?? $row->email ?? ''));
    }
}
