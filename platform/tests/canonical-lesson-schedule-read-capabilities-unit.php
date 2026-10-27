<?php
/**
 * Prove the canonical schedule read seam exposes the occurrence to completion
 * and delivery authorities without granting schedule-edit authority, and that
 * schedule editing still requires dzn_manage_canonical_lesson_schedules.
 */
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class CanonicalLessonAuthorityRepository {
        public function __construct(...$arguments) {}
        public function lesson(int $id): ?object { return $GLOBALS['read_lesson']; }
    }
    final class CanonicalLessonScheduleRepository {
        public function __construct(...$arguments) {}
        public function versionsForLesson(int $id): array { return $GLOBALS['read_versions']; }
        public function eventsForLesson(int $id): array { return array(); }
    }
}

namespace Delnavazan\Platform\Core\Application {
    final class CanonicalLessonScheduleValidator {
        public static function validForLesson(int $lessonId, $repository, $lessons): bool {
            return (bool) ($GLOBALS['read_valid'] ?? true);
        }
    }
}

namespace {
    use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleReadService;

    final class DznReadExpectationFailed extends \RuntimeException {}

    function current_user_can(string $capability): bool {
        return in_array($capability, $GLOBALS['read_caps'] ?? array(), true);
    }

    function dzn_read_assert(bool $ok, string $message): void {
        if (!$ok) throw new DznReadExpectationFailed($message);
    }

    function dzn_read_fixture(): void {
        $GLOBALS['read_lesson'] = (object) array('id' => 5, 'record_model' => 'canonical_term_lesson_v1');
        $GLOBALS['read_versions'] = array(
            (object) array('id' => 2, 'applicable_slot' => 0),
            (object) array('id' => 3, 'applicable_slot' => 1, 'ends_at_utc' => '2026-10-20 00:00:00'),
        );
        $GLOBALS['read_valid'] = true;
        $GLOBALS['read_caps'] = array();
    }

    function dzn_read_unauthorized(callable $call, string $label): void {
        try {
            $call();
            dzn_read_assert(false, $label . ' must reject a caller without its capability.');
        } catch (\RuntimeException $exception) {
            dzn_read_assert($exception->getMessage() === 'Unauthorized', $label . ' must fail closed with Unauthorized.');
        }
    }

    require dirname(__DIR__) . '/src/Core/Application/CanonicalLessonScheduleReadService.php';

    // No authority: every entry point is closed.
    dzn_read_fixture();
    $service = new CanonicalLessonScheduleReadService();
    dzn_read_unauthorized(static fn() => $service->forLesson(5), 'Schedule read');
    dzn_read_unauthorized(static fn() => $service->forLessonCompletion(5), 'Completion schedule read');
    dzn_read_unauthorized(static fn() => $service->forLessonDelivery(5), 'Delivery schedule read');
    dzn_read_unauthorized(static fn() => $service->activeSchedule(5), 'Active schedule read');

    // Schedule editing authority can read schedule state only.
    dzn_read_fixture();
    $GLOBALS['read_caps'] = array('dzn_manage_canonical_lesson_schedules');
    $service = new CanonicalLessonScheduleReadService();
    $state = $service->forLesson(5);
    dzn_read_assert($state['state'] === 'scheduled' && (int) $state['active_version']->id === 3, 'Schedule authority must see the applicable occurrence.');
    dzn_read_assert((int) $service->activeSchedule(5)->id === 3, 'Active schedule must use the protected schedule read.');
    dzn_read_unauthorized(static fn() => $service->forLessonCompletion(5), 'Completion schedule read');
    dzn_read_unauthorized(static fn() => $service->forLessonDelivery(5), 'Delivery schedule read');

    // Completion authority can read the occurrence needed by its own command.
    dzn_read_fixture();
    $GLOBALS['read_caps'] = array('dzn_manage_canonical_lessons');
    $service = new CanonicalLessonScheduleReadService();
    $state = $service->forLessonCompletion(5);
    dzn_read_assert($state['state'] === 'scheduled' && (int) $state['active_version']->id === 3, 'Completion authority must see the applicable occurrence.');
    dzn_read_assert(strtotime((string) $state['active_version']->ends_at_utc) < time(), 'Completion must receive the UTC end instant.');
    dzn_read_unauthorized(static fn() => $service->forLesson(5), 'Schedule read');
    dzn_read_unauthorized(static fn() => $service->forLessonDelivery(5), 'Delivery schedule read');

    // Delivery authority can read the occurrence needed to record an outcome.
    dzn_read_fixture();
    $GLOBALS['read_caps'] = array('dzn_manage_canonical_lesson_delivery');
    $service = new CanonicalLessonScheduleReadService();
    $state = $service->forLessonDelivery(5);
    dzn_read_assert($state['state'] === 'scheduled' && (int) $state['active_version']->id === 3, 'Delivery authority must see the applicable occurrence.');
    dzn_read_unauthorized(static fn() => $service->forLesson(5), 'Schedule read');
    dzn_read_unauthorized(static fn() => $service->forLessonCompletion(5), 'Completion schedule read');

    // Full authority works through every entry point without changing its result.
    dzn_read_fixture();
    $GLOBALS['read_caps'] = array(
        'dzn_manage_canonical_lesson_schedules',
        'dzn_manage_canonical_lessons',
        'dzn_manage_canonical_lesson_delivery',
    );
    $service = new CanonicalLessonScheduleReadService();
    foreach (array('forLesson', 'forLessonCompletion', 'forLessonDelivery') as $method) {
        $state = $service->$method(5);
        dzn_read_assert($state['lesson_id'] === 5 && (int) $state['active_version']->id === 3, $method . ' must return the same applicable occurrence.');
    }

    // Fail-closed integrity and record-model checks remain on every path.
    dzn_read_fixture();
    $GLOBALS['read_caps'] = array('dzn_manage_canonical_lessons');
    $GLOBALS['read_valid'] = false;
    try {
        (new CanonicalLessonScheduleReadService())->forLessonCompletion(5);
        dzn_read_assert(false, 'Completion must reject an integrity conflict.');
    } catch (\InvalidArgumentException $exception) {
        dzn_read_assert($exception->getMessage() === 'canonical_schedule_integrity_conflict', 'Completion must expose the canonical integrity conflict.');
    }

    dzn_read_fixture();
    $GLOBALS['read_caps'] = array('dzn_manage_canonical_lesson_delivery');
    $GLOBALS['read_lesson'] = (object) array('id' => 5, 'record_model' => 'legacy_phase1');
    try {
        (new CanonicalLessonScheduleReadService())->forLessonDelivery(5);
        dzn_read_assert(false, 'Delivery must reject a non-canonical Lesson.');
    } catch (\InvalidArgumentException $exception) {
        dzn_read_assert($exception->getMessage() === 'canonical_lesson_required', 'Delivery must require the canonical record model.');
    }

    echo "canonical-lesson-schedule-read-capabilities-unit: OK\n";
}
