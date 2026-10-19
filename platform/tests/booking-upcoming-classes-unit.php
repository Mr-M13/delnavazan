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
namespace Delnavazan\Platform\Core\Application {
    class ProviderReferenceVault {
        public function open(string $kind, int $mappingId, object|array $row): array {
            return array('join_uri_reference' => 'https://meet.google.com/abc-defg-hij');
        }
    }
}
namespace Delnavazan\Platform\Portals {
    final class PortalPrincipalResolver {
        public function resolve(string $role): array { return array('kind' => $role, 'id' => 14); }
    }
}
namespace {
    $GLOBALS['dzn_upcoming_capability'] = true;
    function current_user_can(string $capability): bool {
        return $capability === 'dzn_view_own_portal_schedule' && $GLOBALS['dzn_upcoming_capability'];
    }

    require_once dirname(__DIR__) . '/src/Portals/BookingUpcomingReadProjector.php';
    require_once dirname(__DIR__) . '/src/Portals/TeacherUpcomingBookingReadService.php';

    $assert = static function(bool $ok, string $message): void {
        if (!$ok) throw new \RuntimeException($message);
    };
    $row = (object) array(
        'confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'confirmation_id' => 17,
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
        'projection_secret_state' => 'active',
        'projection_secret_key_version' => 'v1',
        'projection_secret_cipher_version' => 'v1',
        'projection_secret_nonce' => 'nonce',
        'projection_secret_ciphertext' => 'ciphertext',
        'request_reference' => 'BR-OWNED',
        'student_display_reference' => 'Owned Student',
        'course_reference' => 'Introductory Setar',
        'starts_at_utc' => gmdate('Y-m-d H:i:s', time() - 1800),
        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 900),
        'schedule_timezone' => 'Australia/Brisbane',
    );
    $repository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository();
    $repository->rows[] = $row;
    foreach (array(
        array('confirmation_state' => 'pending_projection'),
        array('delivery_state' => 'declined'),
        array('lifecycle_status' => 'cancelled', 'resolution_state' => 'student_cancelled'),
        array('teacher_id' => 99, 'delivery_teacher_id' => 99),
        array('starts_at_utc' => '2020-01-01 08:00:00', 'ends_at_utc' => '2020-01-01 08:45:00'),
    ) as $changes) {
        $candidate = clone $row;
        foreach ($changes as $key => $value) $candidate->{$key} = $value;
        $repository->rows[] = $candidate;
    }

    $service = new \Delnavazan\Platform\Portals\TeacherUpcomingBookingReadService($repository);
    $classes = $service->forTeacher(14);
    $assert($repository->observedTeacherId === 14, 'The read must query only the resolved Teacher principal.');
    $assert(count($classes) === 1, 'Only the provider-confirmed, owned booking inside the join window may appear.');
    $assert($classes[0]['confirmation_uid'] === $row->confirmation_uid && $classes[0]['lesson_kind'] === 'intro', 'The confirmed booking projection is incomplete.');
    $assert($classes[0]['join_available'] === true && $classes[0]['join_issue'] === null, 'A valid vaulted Meet reference must make the booking joinable.');
    $assert(!array_key_exists('provider_event_digest', $classes[0]) && !array_key_exists('projection_secret_id', $classes[0]), 'Provider evidence must not escape the read boundary.');

    try {
        $service->forTeacher(99);
        throw new \RuntimeException('A Teacher requested another Teacher principal.');
    } catch (\InvalidArgumentException $error) {
        $assert($error->getMessage() === 'portal_object_not_owned', 'Unexpected Teacher ownership refusal.');
    }

    $GLOBALS['dzn_upcoming_capability'] = false;
    try {
        $service->forTeacher(14);
        throw new \RuntimeException('A Teacher without schedule access saw confirmed bookings.');
    } catch (\InvalidArgumentException $error) {
        $assert($error->getMessage() === 'portal_object_not_owned', 'Unexpected access refusal.');
    }
    echo "Booking upcoming classes unit passed\n";
}
