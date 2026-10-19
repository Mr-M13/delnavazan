<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherDeliveryRepository {
        public int $observedStudentId = 0;
        public array $rows = array();
        public function confirmedUpcomingForStudent(int $studentId, int $limit = 50): array {
            $this->observedStudentId = $studentId;
            return $this->rows;
        }
        public function confirmedUpcomingForTeacher(int $teacherId, int $limit = 50): array {
            return isset($this->rows[0]) ? array($this->rows[0]) : array();
        }
    }
}
namespace Delnavazan\Platform\Core\Application {
    class ProviderReferenceVault {
        public bool $fail = false;
        public function open(string $kind, int $mappingId, object|array $row): array {
            if ($this->fail) throw new \RuntimeException('provider_reference_key_unavailable');
            return array('join_uri_reference' => 'https://meet.google.com/abc-defg-hij');
        }
    }
}
namespace Delnavazan\Platform\Portals {
    final class PortalPrincipalResolver {
        public function resolve(string $role): array { return array('kind' => $role, 'id' => $role === 'teacher' ? 14 : 27); }
    }
}
namespace {
    function current_user_can(string $capability): bool { return $capability === 'dzn_view_own_portal_schedule'; }
    require_once dirname(__DIR__) . '/src/Portals/BookingUpcomingReadProjector.php';
    require_once dirname(__DIR__) . '/src/Portals/StudentUpcomingBookingReadService.php';
    require_once dirname(__DIR__) . '/src/Portals/TeacherUpcomingBookingReadService.php';

    $assert = static function(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };
    $row = (object) array(
        'confirmation_id' => 17,
        'confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'student_id' => 27,
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
        'request_reference' => 'BR-STUDENT',
        'student_display_reference' => 'Student Owned',
        'teacher_display_reference' => 'Teacher Owned',
        'course_reference' => 'Introductory Setar',
        'starts_at_utc' => '2099-02-01 08:00:00',
        'ends_at_utc' => '2099-02-01 08:45:00',
        'schedule_timezone' => 'Australia/Brisbane',
    );
    $repository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository();
    $repository->rows = array($row, (object) array_merge((array) $row, array('student_id' => 99)));
    $projector = new \Delnavazan\Platform\Portals\BookingUpcomingReadProjector();
    $service = new \Delnavazan\Platform\Portals\StudentUpcomingBookingReadService($repository, $projector);
    $classes = $service->forStudent(27);
    $assert($repository->observedStudentId === 27 && count($classes) === 1, 'Confirmed introductory bookings must be scoped to the resolved Student.');
    $assert($classes[0]['version'] === 'student_booking_class_v1' && $classes[0]['join_available'] === true, 'The owned confirmed intro must remain visible and joinable without an enrolment.');
    $assert(!isset($classes[0]['projection_secret_ciphertext']), 'Vault material escaped the Student read projection.');
    $teacherClasses = (new \Delnavazan\Platform\Portals\TeacherUpcomingBookingReadService($repository, $projector))->forTeacher(14);
    $assert(count($teacherClasses) === 1 && $teacherClasses[0]['confirmation_uid'] === $classes[0]['confirmation_uid'] && $teacherClasses[0]['join_available'] === true, 'One provider-confirmed acceptance must project to both owning participant views.');

    $repository->rows[0]->projection_secret_ciphertext = '';
    $classes = $service->forStudent(27);
    $assert(count($classes) === 1 && $classes[0]['join_available'] === false && $classes[0]['join_issue'] === 'provider_reference_unavailable', 'Provider failure must disable joining without hiding the class.');
    try { $service->forStudent(99); throw new \RuntimeException('A different Student principal read the booking.'); }
    catch (\InvalidArgumentException $error) { $assert($error->getMessage() === 'portal_object_not_owned', 'Unexpected Student ownership refusal.'); }
    echo "Booking student upcoming unit passed\n";
}
