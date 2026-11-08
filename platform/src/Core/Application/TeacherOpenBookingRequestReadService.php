<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherOpenBookingRequestRepository;

/**
 * Teacher-scoped read of unmatched requests for the Teacher's own eligible courses.
 *
 * Each preference carries its exact declared-availability state so the Teacher can see,
 * before claiming, that an unmatched request is an explicit availability exception.
 */
final class TeacherOpenBookingRequestReadService {
    public function __construct(
        private ?TeacherOpenBookingRequestRepository $requests = null,
        private ?TeacherAvailabilityService $availability = null
    ) {
        $this->requests ??= new TeacherOpenBookingRequestRepository();
        $this->availability ??= new TeacherAvailabilityService();
    }

    public function own(): array {
        $user = (int) get_current_user_id();
        if ($user < 1) { throw new \RuntimeException('Unauthorized'); }
        $teacher = (new TeacherAvailabilityRepository())->activeTeacherIdForUser($user);
        if (!$teacher) { throw new \RuntimeException('Active ready Teacher required'); }
        $rows = $this->requests->openForTeacher($teacher);
        foreach ($rows as $request) {
            $request->requested_times = $this->requests->requestedTimes((int) $request->booking_request_id);
            foreach ($request->requested_times as &$preference) {
                // `outside` is an explicit statement that the request cannot be matched
                // through normal availability and is the reason it appears in this section.
                $preference['coverage'] = $this->availability->coverage(
                    $teacher,
                    (string) ($preference['starts_at_utc'] ?? ''),
                    (string) ($preference['occupied_ends_at_utc'] ?? '')
                ) ?? 'outside';
            }
            unset($preference);
        }
        return $rows;
    }
}
