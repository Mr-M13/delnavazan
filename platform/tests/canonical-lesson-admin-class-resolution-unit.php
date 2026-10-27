<?php
/** Execute admin entry paths up to their real schedule-class autoload boundary, without a database. */
namespace Delnavazan\Platform\Core\Application {
    final class CoreReadService {
        public function lessonOperatorRecord(int $id): object { return $GLOBALS['lesson_fixture']; }
    }
}
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class CanonicalLessonAuthorityRepository {
        public function lesson(int $id): object { return $GLOBALS['lesson_fixture']; }
    }
}
namespace {
    use Delnavazan\Platform\Admin\Controller\ScreenController;

    final class ScheduleAutoloadBoundary extends \RuntimeException {}

    function current_user_can(string $capability): bool { return true; }
    function absint($value): int { return abs((int) $value); }
    function admin_url(string $path): string { return $path; }
    function esc_url(string $value): string { return $value; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function wp_nonce_field(string $action): void {}
    function submit_button(...$arguments): void {}

    $GLOBALS['lesson_fixture'] = (object) array(
        'id' => 42,
        'record_model' => 'canonical_term_lesson_v1',
        'reference_code' => 'SYNTHETIC-42',
        'lifecycle_state' => 'authorised',
        'status' => 'active',
    );
    $sourceRoot = dirname(__DIR__) . '/src/';
    $prefix = 'Delnavazan\\Platform\\';
    $expectedClass = '';
    $probe = static function (string $class) use (&$expectedClass, $sourceRoot, $prefix): void {
        // Stop before construction or storage access, but only at the correct autoload request.
        if ($class !== $expectedClass) {
            throw new \RuntimeException('Unexpected class resolution: ' . $class . '; expected ' . $expectedClass);
        }
        $path = $sourceRoot . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (!is_readable($path)) throw new \RuntimeException('Missing autoload target: ' . $path);
        throw new ScheduleAutoloadBoundary($class);
    };
    require $sourceRoot . 'Admin/Controller/Menu.php';
    require $sourceRoot . 'Admin/Controller/ScreenController.php';
    spl_autoload_register($probe);

    $readClass = 'Delnavazan\\Platform\\Core\\Application\\CanonicalLessonScheduleReadService';
    $writeClass = 'Delnavazan\\Platform\\Core\\Application\\CanonicalLessonScheduleService';
    $cases = array(
        array('entityDetail', array('lesson', 42), $readClass),
        array('canonicalLessonCompletionForm', array($GLOBALS['lesson_fixture']), $readClass),
        array('canonicalLessonSchedule', array($GLOBALS['lesson_fixture']), $readClass),
        array('completeCanonicalLesson', array(array('lesson_id' => 42)), $readClass),
        array('canonicalScheduleAction', array(array('lesson_id' => 42), 'schedule_initial'), $writeClass),
    );
    foreach ($cases as [$method, $arguments, $class]) {
        $expectedClass = $class;
        $reached = false;
        ob_start();
        try {
            (new \ReflectionMethod(ScreenController::class, $method))->invokeArgs(null, $arguments);
        } catch (ScheduleAutoloadBoundary $boundary) {
            $reached = $boundary->getMessage() === $class;
        } finally {
            ob_end_clean();
        }
        if (!$reached) throw new \RuntimeException($method . ' did not reach its schedule service');
    }
    spl_autoload_unregister($probe);

    // Verify the same prefix-to-src mapping used by the plugin loads the declared real classes.
    spl_autoload_register(static function (string $class) use ($sourceRoot, $prefix): void {
        if (!str_starts_with($class, $prefix)) return;
        $path = $sourceRoot . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($path)) require $path;
    });
    foreach (array($readClass, $writeClass) as $class) {
        if (!class_exists($class)) throw new \RuntimeException('Autoload failed: ' . $class);
    }
    echo "canonical-lesson-admin-class-resolution-unit: OK\n";
}