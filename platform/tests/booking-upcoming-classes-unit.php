<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherDeliveryRepository {
        public int $observedTeacherId = 0;
        public array $rows = array();
        public function confirmedUpcomingForTeacher(int $teacherId, int $limit = 50): array {
            $this->observedTeacherId = $teacherId;
            return $this->rows;
        }
    }
}
namespace {
    $GLOBALS['dzn_upcoming_capability'] = true;
    function current_user_can(string $capability): bool {
        return $capability === 'dzn_view_own_portal_schedule' && $GLOBALS['dzn_upcoming_capability'];
    }

    require_once dirname(__DIR__) . '/src/Portals/TeacherUpcomingBookingReadService.php';

    $assert = static function(bool $ok, string $message): void {
        if (!$ok) throw new \RuntimeException($message);
    };
    $row = (object) array(
        'confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'teacher_id' => 14,
        'delivery_teacher_id' => 14,
        'confirmation_state' => 'confirmed',
        'delivery_state' => 'confirmed',
        'lifecycle_status' => 'closed',
        'resolution_state' => 'teacher_confirmed',
        'privacy_erased_at' => null,
        'provider_code' => 'google_calendar',
        'provider_event_digest' => str_repeat('a', 64),
        'confirmed_at' => '2099-01-01 00:00:00',
        'projection_secret_id' => 91,
        'request_reference' => 'BR-OWNED',
        'student_display_reference' => 'Owned Student',
        'course_reference' => 'Introductory Setar',
        'starts_at_utc' => '2099-02-01 08:00:00',
        'ends_at_utc' => '2099-02-01 08:45:00',
        'schedule_timezone' => 'Australia/Brisbane',
    );
    $repository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository();
    $repository->rows[] = $row;
    foreach (array(
        array('confirmation_state' => 'pending_projection'),
        array('delivery_state' => 'declined'),
        array('lifecycle_status' => 'cancelled', 'resolution_state' => 'student_cancelled'),
        array('teacher_id' => 99, 'delivery_teacher_id' => 99),
        array('projection_secret_id' => 0),
        array('starts_at_utc' => '2020-01-01 08:00:00', 'ends_at_utc' => '2020-01-01 08:45:00'),
    ) as $changes) {
        $candidate = clone $row;
        foreach ($changes as $key => $value) $candidate->{$key} = $value;
        $repository->rows[] = $candidate;
    }

    $service = new \Delnavazan\Platform\Portals\TeacherUpcomingBookingReadService($repository);
    $classes = $service->forTeacher(14);
    $assert($repository->observedTeacherId === 14, 'The read must query only the resolved Teacher principal.');
    $assert(count($classes) === 1, 'Only the provider-confirmed, owned, future booking may appear.');
    $assert($classes[0]['confirmation_uid'] === $row->confirmation_uid && $classes[0]['lesson_kind'] === 'intro', 'The confirmed booking projection is incomplete.');
    $assert(!array_key_exists('provider_event_digest', $classes[0]) && !array_key_exists('projection_secret_id', $classes[0]), 'Provider evidence must not escape the read boundary.');

    $GLOBALS['dzn_upcoming_capability'] = false;
    try {
        $service->forTeacher(14);
        throw new \RuntimeException('A Teacher without schedule access saw confirmed bookings.');
    } catch (\InvalidArgumentException $error) {
        $assert($error->getMessage() === 'portal_object_not_owned', 'Unexpected access refusal.');
    }
    echo "Booking upcoming classes unit passed\n";
}
