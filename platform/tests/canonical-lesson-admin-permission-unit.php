<?php
/**
 * Exercise the canonical Lesson administrator detail and command boundaries
 * without WordPress or a database. The lesson detail page, completion control
 * and both canonical commands resolve their schedule services from
 * Core\Application; this locks the capabilities around those paths so a future
 * rename or missing import cannot silently reintroduce a fatal or widen access.
 */
namespace Delnavazan\Platform\Core\Application {
    final class CoreReadService {
        public function lessonOperatorRecord(int $id): ?object {
            $GLOBALS['admin_calls']['lessonOperatorRecord'] = ($GLOBALS['admin_calls']['lessonOperatorRecord'] ?? 0) + 1;
            return $GLOBALS['admin_lesson'];
        }
    }

    final class CanonicalLessonScheduleReadService {
        public function forLesson(int $id): array {
            $GLOBALS['admin_calls']['scheduleRead'] = ($GLOBALS['admin_calls']['scheduleRead'] ?? 0) + 1;
            return $GLOBALS['admin_schedule'];
        }
    }

    final class CanonicalLessonScheduleService {
        public function schedule(int $lessonId, int $assignmentId, array $input, string $key): array {
            $GLOBALS['admin_calls']['scheduleWrite'] = ($GLOBALS['admin_calls']['scheduleWrite'] ?? 0) + 1;
            return array('schedule_version_id' => 77);
        }
        public function revise(int $lessonId, int $assignmentId, array $input, string $key): array {
            $GLOBALS['admin_calls']['scheduleWrite'] = ($GLOBALS['admin_calls']['scheduleWrite'] ?? 0) + 1;
            return array('schedule_version_id' => 78);
        }
        public function release(int $lessonId, array $input, string $key): array {
            $GLOBALS['admin_calls']['scheduleWrite'] = ($GLOBALS['admin_calls']['scheduleWrite'] ?? 0) + 1;
            return array('schedule_version_id' => 79);
        }
    }

    final class CanonicalLessonAuthorityService {
        public function complete(int $lessonId, string $expectedState, array $evidence, string $key): array {
            $GLOBALS['admin_calls']['complete'] = ($GLOBALS['admin_calls']['complete'] ?? 0) + 1;
            return array('lesson_id' => $lessonId);
        }
    }

    final class CanonicalLessonDeliveryReadService {
        public function forLesson(int $id): array {
            $GLOBALS['admin_calls']['deliveryRead'] = ($GLOBALS['admin_calls']['deliveryRead'] ?? 0) + 1;
            return array('outcomes' => array(), 'effective' => null);
        }
    }

    final class CanonicalLessonCompletionPolicy {
        public static function completionBasis(array $events): string { return 'none'; }
    }
}

namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class CanonicalLessonAuthorityRepository {
        public function lesson(int $id): ?object {
            $GLOBALS['admin_calls']['lessonRecord'] = ($GLOBALS['admin_calls']['lessonRecord'] ?? 0) + 1;
            return $GLOBALS['admin_lesson'];
        }
        public function events(int $id): array {
            $GLOBALS['admin_calls']['events'] = ($GLOBALS['admin_calls']['events'] ?? 0) + 1;
            return array();
        }
    }
}

namespace Delnavazan\Platform\Admin\RecordAction {
    final class RecordActionRenderer {
        public static function renderNotices(): void {}
        public static function renderSelected(string $type, object $record, string $page, array $keys): void {}
        public static function render(string $type, object $record, string $page): void {}
    }
}

namespace {
    final class DznAdminWpDie extends \RuntimeException {}

    function current_user_can(string $capability): bool { return in_array($capability, $GLOBALS['admin_caps'] ?? array(), true); }
    function absint($value): int { return abs((int) $value); }
    function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
    function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
    function esc_url($value): string { return (string) $value; }
    function sanitize_key($value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/', '', (string) $value)); }
    function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
    function wp_unslash($value) { return $value; }
    function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true): void {}
    function submit_button(...$arguments): void {
        $text = $arguments[0] ?? '';
        if (is_string($text) && $text !== '') echo '<button type="submit">' . htmlspecialchars($text, ENT_QUOTES) . '</button>';
    }
    function selected($selected, $current = true, $display = true): string { return (string) $selected === (string) $current ? ' selected' : ''; }
    function add_query_arg(...$arguments): string { return 'https://example.test/wp-admin/admin.php'; }
    function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
    function get_current_user_id(): int { return 1; }
    function wp_die(string $message = '', string $title = '', array $args = array()): never {
        throw new DznAdminWpDie($message, (int) ($args['response'] ?? 0));
    }

    function dzn_admin_assert(bool $ok, string $message): void {
        if (!$ok) throw new \RuntimeException($message);
    }

    function dzn_admin_fixture(): void {
        $GLOBALS['admin_lesson'] = (object) array(
            'id' => 5,
            'record_model' => 'canonical_term_lesson_v1',
            'reference_code' => 'DZN-5',
            'lifecycle_state' => 'authorised',
            'status' => 'active',
            'student_name' => 'Synthetic Student',
            'teacher_name' => 'Synthetic Teacher',
            'course_name' => 'Synthetic Course',
            'lesson_type' => 'standard',
            'class_status' => 'Scheduled',
            'schedule_label' => 'Scheduled',
            'teacher_assignment_id' => 9,
        );
        $GLOBALS['admin_schedule'] = array(
            'active_version' => (object) array(
                'id' => 3,
                'version_number' => 1,
                'local_wall_date' => '2026-10-20',
                'local_wall_time' => '10:00:00',
                'schedule_timezone' => 'Australia/Brisbane',
                'duration_minutes' => 30,
                'buffer_minutes' => 15,
                'availability_basis' => 'available',
                'ends_at_utc' => gmdate('Y-m-d H:i:s', time() - 3600),
            ),
            'versions' => array(),
            'events' => array(),
        );
        $GLOBALS['admin_caps'] = array();
        $GLOBALS['admin_calls'] = array();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('page' => 'dzn-lesson', 'id' => '5');
        $_POST = array();
    }

    function dzn_admin_render_lesson_detail(array $capabilities): string {
        $GLOBALS['admin_caps'] = $capabilities;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('page' => 'dzn-lesson', 'id' => '5');
        $_POST = array();
        ob_start();
        try {
            \Delnavazan\Platform\Admin\Controller\ScreenController::screen('lesson');
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    function dzn_admin_invoke(string $method, array $arguments): mixed {
        return (new \ReflectionMethod(\Delnavazan\Platform\Admin\Controller\ScreenController::class, $method))->invokeArgs(null, $arguments);
    }

    $sourceRoot = dirname(__DIR__) . '/src/';
    require $sourceRoot . 'Admin/Controller/Menu.php';
    require $sourceRoot . 'Admin/Diagnostic/NonceLifecycleDiagnostic.php';
    require $sourceRoot . 'Admin/Controller/ScreenController.php';

    // The list route requires the base Lesson capability before any record read.
    dzn_admin_fixture();
    $html = dzn_admin_render_lesson_detail(array());
    dzn_admin_assert(str_contains($html, 'Access denied.'), 'Lesson detail must deny an operator without dzn_manage_lessons.');
    dzn_admin_assert(empty($GLOBALS['admin_calls']), 'A denied Lesson detail request must not read the Lesson record.');

    // A Lesson reader without canonical authority sees the record but no completion control.
    dzn_admin_fixture();
    $html = dzn_admin_render_lesson_detail(array('dzn_manage_lessons'));
    dzn_admin_assert(str_contains($html, 'Class · DZN-5'), 'An authorized Lesson detail request must render the canonical record heading.');
    dzn_admin_assert(isset($GLOBALS['admin_calls']['lessonOperatorRecord']), 'An authorized Lesson detail request must use the application read seam.');
    dzn_admin_assert(!str_contains($html, 'Class completion'), 'Completion controls must require dzn_manage_canonical_lessons.');
    dzn_admin_assert(!str_contains($html, 'Canonical Lesson schedule'), 'Schedule controls must require dzn_manage_canonical_lesson_schedules.');
    dzn_admin_assert(str_contains($html, 'Schedule changes are handled by staff with class scheduling access.'), 'The detail page must explain the restricted schedule control.');
    dzn_admin_assert(($GLOBALS['admin_calls']['scheduleRead'] ?? 0) === 0, 'An operator without canonical authority must not read schedule state.');

    // Canonical completion authority exposes the completion control and reads schedule truth.
    dzn_admin_fixture();
    $html = dzn_admin_render_lesson_detail(array('dzn_manage_lessons', 'dzn_manage_canonical_lessons'));
    dzn_admin_assert(str_contains($html, 'Class completion') && str_contains($html, 'Mark class as completed'), 'Ended canonical classes must expose the completion control to canonical Lesson operators.');
    dzn_admin_assert(!str_contains($html, 'Canonical Lesson schedule'), 'Completion authority alone must not expose schedule editing.');
    dzn_admin_assert(($GLOBALS['admin_calls']['scheduleRead'] ?? 0) >= 1, 'The completion control must read canonical schedule state.');

    // Full detail page: completion and schedule controls use the same imported read service.
    dzn_admin_fixture();
    $html = dzn_admin_render_lesson_detail(array('dzn_manage_lessons', 'dzn_manage_canonical_lessons', 'dzn_manage_canonical_lesson_schedules'));
    dzn_admin_assert(str_contains($html, 'Class completion') && str_contains($html, 'Mark class as completed'), 'Full canonical detail must expose the completion control.');
    dzn_admin_assert(str_contains($html, 'Canonical Lesson schedule') && str_contains($html, 'Revise canonical schedule'), 'Full canonical detail must expose the schedule control.');
    dzn_admin_assert(($GLOBALS['admin_calls']['scheduleRead'] ?? 0) >= 2, 'Completion and schedule sections must both read canonical schedule state.');

    // Completion is not offered before the scheduled occurrence ends.
    dzn_admin_fixture();
    $GLOBALS['admin_schedule']['active_version']->ends_at_utc = gmdate('Y-m-d H:i:s', time() + 3600);
    $html = dzn_admin_render_lesson_detail(array('dzn_manage_lessons', 'dzn_manage_canonical_lessons'));
    dzn_admin_assert(str_contains($html, 'Mark as completed becomes available after the scheduled class ends.'), 'Completion must remain unavailable before the scheduled end.');
    dzn_admin_assert(!str_contains($html, 'Mark class as completed'), 'Completion must not render a form before the scheduled end.');

    // Completion is not offered without a confirmed occurrence.
    dzn_admin_fixture();
    $GLOBALS['admin_schedule']['active_version'] = null;
    $html = dzn_admin_render_lesson_detail(array('dzn_manage_lessons', 'dzn_manage_canonical_lessons'));
    dzn_admin_assert(str_contains($html, 'Mark as completed becomes available after a canonical schedule is confirmed.'), 'Completion must remain unavailable without a confirmed canonical schedule.');
    dzn_admin_assert(!str_contains($html, 'Mark class as completed'), 'Completion must not render a form without a confirmed canonical schedule.');

    // Delivery authority is independent and must render when held.
    dzn_admin_fixture();
    $html = dzn_admin_render_lesson_detail(array('dzn_manage_lessons', 'dzn_manage_canonical_lesson_delivery'));
    dzn_admin_assert(str_contains($html, 'Delivery &amp; attendance'), 'Delivery authority must expose the append-only delivery section.');
    dzn_admin_assert(str_contains($html, 'Record outcome'), 'An ended authorised occurrence must offer a delivery outcome form.');
    dzn_admin_assert(isset($GLOBALS['admin_calls']['deliveryRead']), 'The delivery section must use its canonical read authority.');

    // Unauthorized completion stops before the repository and schedule read.
    dzn_admin_fixture();
    $GLOBALS['admin_caps'] = array('dzn_manage_lessons');
    try {
        dzn_admin_invoke('completeCanonicalLesson', array(array('lesson_id' => 5)));
        dzn_admin_assert(false, 'Completion without dzn_manage_canonical_lessons must fail.');
    } catch (\RuntimeException $exception) {
        dzn_admin_assert($exception->getMessage() === 'Unauthorized', 'Completion denial must be an Unauthorized failure.');
    }
    dzn_admin_assert(empty($GLOBALS['admin_calls']), 'An unauthorized completion must not read or mutate a Lesson.');

    // Authorized completion reaches the canonical authority and schedule read.
    dzn_admin_fixture();
    $GLOBALS['admin_caps'] = array('dzn_manage_canonical_lessons');
    $result = dzn_admin_invoke('completeCanonicalLesson', array(array('lesson_id' => 5)));
    dzn_admin_assert($result === 5, 'Authorized canonical completion must return the Lesson identifier.');
    dzn_admin_assert(isset($GLOBALS['admin_calls']['lessonRecord'], $GLOBALS['admin_calls']['scheduleRead'], $GLOBALS['admin_calls']['complete']), 'Authorized completion must read the Lesson and schedule, then invoke the completion authority.');

    // Unauthorized schedule commands stop before instantiating the write service.
    dzn_admin_fixture();
    $GLOBALS['admin_caps'] = array('dzn_manage_lessons');
    try {
        dzn_admin_invoke('canonicalScheduleAction', array(array('lesson_id' => 5), 'schedule_initial'));
        dzn_admin_assert(false, 'Scheduling without dzn_manage_canonical_lesson_schedules must fail.');
    } catch (\RuntimeException $exception) {
        dzn_admin_assert($exception->getMessage() === 'Unauthorized', 'Schedule denial must be an Unauthorized failure.');
    }
    dzn_admin_assert(($GLOBALS['admin_calls']['scheduleWrite'] ?? 0) === 0, 'An unauthorized schedule command must not invoke the write service.');

    // Authorized schedule command reaches the write service.
    dzn_admin_fixture();
    $GLOBALS['admin_caps'] = array('dzn_manage_canonical_lesson_schedules');
    $result = dzn_admin_invoke('canonicalScheduleAction', array(array('lesson_id' => 5), 'schedule_initial'));
    dzn_admin_assert($result === 77, 'Authorized scheduling must return the new schedule version identifier.');
    dzn_admin_assert(($GLOBALS['admin_calls']['scheduleWrite'] ?? 0) === 1, 'Authorized scheduling must invoke the write service exactly once.');

    // The POST route maps completion to its own capability and denies by default.
    dzn_admin_fixture();
    $GLOBALS['admin_caps'] = array('dzn_manage_lessons');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('dzn_action' => 'complete_canonical_lesson', 'lesson_id' => '5');
    try {
        \Delnavazan\Platform\Admin\Controller\ScreenController::handlePost('lesson');
        dzn_admin_assert(false, 'The completion POST must require dzn_manage_canonical_lessons.');
    } catch (DznAdminWpDie $denied) {
        dzn_admin_assert($denied->getCode() === 403, 'The completion POST must return HTTP 403 without canonical Lesson authority.');
    }
    dzn_admin_assert(empty($GLOBALS['admin_calls']), 'A denied completion POST must not reach the mutation authority.');

    echo "canonical-lesson-admin-permission-unit: OK\n";
}
