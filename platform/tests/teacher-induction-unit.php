<?php
/**
 * Behavioural unit test for Teacher induction progress.
 *
 * The WordPress database is replaced with a small in-memory adapter that preserves the two
 * invariants the production repository relies on: one row per (teacher,module) and a stable
 * first-completion timestamp.
 */
namespace {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
    function current_time( $type, $gmt = 0 ): string { return gmdate( 'Y-m-d H:i:s' ); }
    function get_current_user_id(): int { return 7; }

    final class DznInductionFakeWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var array<int, mixed> */
        public array $last_args = array();
        /** @var array<string, object> */
        public array $rows = array();
        public int $queryCount = 0;

        public function prepare( string $query, ...$args ): string {
            $this->last_args = $args;
            $i = 0;
            return (string) preg_replace_callback( '/%[dsf]/', static function () use ( &$i, $args ): string {
                $value = $args[ $i ] ?? '';
                ++$i;
                return is_scalar( $value ) ? (string) $value : '';
            }, $query );
        }

        public function get_row( string $query ): ?object {
            if ( str_contains( $query, 'teacher_induction_progress' ) ) {
                $module = (string) ( $this->last_args[1] ?? '' );
                return $this->rows[ $module ] ?? null;
            }
            if ( str_contains( $query, 'teacher_principal_links' ) && str_contains( $query, 'teachers t' ) ) {
                if ( (int) ( $this->last_args[0] ?? 0 ) !== 7 ) { return null; }
                return (object) array( 'id' => 7, 'principal_status' => 'active', 'onboarding_state' => 'active', 'readiness_state' => 'ready', 'archived_at' => null );
            }
            return null;
        }

        public function get_var( string $query ): mixed {
            if ( str_contains( $query, 'teacher_principal_links' ) ) {
                return ( (int) ( $this->last_args[0] ?? 0 ) === 7 && (int) ( $this->last_args[1] ?? 0 ) === 7 ) ? 1 : null;
            }
            return null;
        }

        /** @return array<int, object> */
        public function get_results( string $query ): array {
            if ( str_contains( $query, 'teacher_induction_progress' ) ) {
                $teacher = (int) ( $this->last_args[0] ?? 0 );
                return array_values( array_filter( $this->rows, static fn( object $row ): bool => (int) $row->teacher_id === $teacher ) );
            }
            return array();
        }

        public function query( string $sql ): int {
            ++$this->queryCount;
            if ( ! str_contains( $sql, 'teacher_induction_progress' ) ) { return 1; }
            $args = $this->last_args;
            $teacher = (int) ( $args[0] ?? 0 );
            $module = (string) ( $args[1] ?? '' );
            $now = (string) ( $args[ count( $args ) - 3 ] ?? current_time( 'mysql', true ) );
            $isComplete = str_contains( $sql, "'completed'" );
            $row = $this->rows[ $module ] ?? null;
            if ( ! $row ) {
                $row = (object) array(
                    'id' => count( $this->rows ) + 1, 'teacher_id' => $teacher, 'module_id' => $module,
                    'state' => $isComplete ? 'completed' : 'in_progress', 'content_version' => $isComplete ? (int) $args[2] : 1,
                    'acknowledged_version' => $isComplete ? (int) $args[3] : 0,
                    'completed_at' => $isComplete ? $now : null, 'last_visited_at' => $now, 'version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                );
            } else {
                $row->last_visited_at = $now;
                $row->updated_at = $now;
                $row->version = (int) $row->version + 1;
                if ( $isComplete ) {
                    $row->state = 'completed';
                    $row->content_version = max( (int) $row->content_version, (int) $args[2] );
                    $row->acknowledged_version = max( (int) $row->acknowledged_version, (int) $args[3] );
                    $row->completed_at = $row->completed_at ?: $now;
                }
            }
            $this->rows[ $module ] = $row;
            return 1;
        }
    }

    $GLOBALS['wpdb'] = new DznInductionFakeWpdb();

    foreach ( array(
        'src/Core/Application/Induction/TeacherInductionCurriculum.php',
        'src/Core/Infrastructure/Repository/TeacherInductionRepository.php',
        'src/Core/Infrastructure/Repository/TeacherOnboardingRepository.php',
        'src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php',
        'src/Core/Application/Induction/TeacherInductionService.php',
    ) as $file ) {
        require_once dirname( __DIR__ ) . '/' . $file;
    }

    use Delnavazan\Platform\Core\Application\Induction\TeacherInductionCurriculum;
    use Delnavazan\Platform\Core\Application\Induction\TeacherInductionService;

    $fail = static function ( string $name, bool $ok ): void {
        if ( ! $ok ) { throw new RuntimeException( 'Teacher induction unit failed: ' . $name ); }
    };

    $all = TeacherInductionCurriculum::all();
    $fail( 'seven canonical modules are declared', count( $all ) === 7 );
    $fail( 'canonical order is stable', array_column( $all, 'id' ) === array( 'portal', 'availability', 'responsibilities', 'schedule-changes', 'absence-replacement', 'finance', 'escalation' ) );
    foreach ( $all as $module ) {
        $fail( 'module ' . $module['id'] . ' has a positive version', (int) $module['version'] >= 1 );
        $fail( 'module ' . $module['id'] . ' has objectives and sections', ! empty( $module['objectives'] ) && ! empty( $module['sections'] ) );
        $fail( 'module ' . $module['id'] . ' targets an existing portal screen', in_array( $module['portal_screen'], array( 'home', 'classes', 'finance', 'settings' ), true ) );
        $fail( 'module ' . $module['id'] . ' is not empty copy', strlen( $module['description'] ) > 20 );
    }
    $absence = TeacherInductionCurriculum::module( 'absence-replacement' );
    $absence_text = json_encode( $absence, JSON_UNESCAPED_UNICODE );
    foreach ( array( 'دو', 'غیبت مازاد', 'سهمیه', 'کوتاهی مدرس', 'مورد اعتراض', 'نخستین غیبت', 'دومین غیبت', 'سومین غیبت', 'دو درخواست در انتظار', 'زمان پیشنهادی رد شد', 'اختلاف در حضور', 'جلسهٔ جبرانی برای کلاس برگزارشده' ) as $token ) {
        $fail( 'absence module teaches ' . $token, str_contains( $absence_text, $token ) );
    }
    $schedule = TeacherInductionCurriculum::module( 'schedule-changes' );
    $schedule_text = json_encode( $schedule, JSON_UNESCAPED_UNICODE );
    foreach ( array( 'پذیرش هنرجو', 'سه', 'تداخل', 'جلسهٔ جبرانی' ) as $token ) {
        $fail( 'schedule module teaches ' . $token, str_contains( $schedule_text, $token ) );
    }
    $finance = TeacherInductionCurriculum::module( 'finance' );
    $finance_text = json_encode( $finance, JSON_UNESCAPED_UNICODE );
    foreach ( array( 'توضیحی', 'صادر شده', 'پرداخت', 'اختلاف' ) as $token ) {
        $fail( 'finance module teaches ' . $token, str_contains( $finance_text, $token ) );
    }
    $escalation = TeacherInductionCurriculum::module( 'escalation' );
    $fail( 'escalation module contains the twelve required scenarios', count( $escalation['scenario_cards'] ) === 12 );

    $service = new TeacherInductionService();
    $hub = $service->hubForUser( 7 );
    $fail( 'a fresh teacher starts at zero progress', $hub['completed_count'] === 0 && $hub['state'] === 'not_started' && $hub['next_module_id'] === 'portal' );
    $visited = $service->moduleForUser( 'portal', 7 );
    $fail( 'opening a module records an in-progress visit', $visited['module']['state'] === 'in_progress' && $visited['module']['last_visited_at'] !== null );
    $first = $service->completeForUser( 'portal', 7 );
    $fail( 'completion is recorded', $first['state'] === 'completed' && $first['already_completed'] === false );
    $completedAt = $first['completed_at'];
    $again = $service->completeForUser( 'portal', 7 );
    $fail( 'repeated completion is idempotent', $again['already_completed'] === true && $again['completed_at'] === $completedAt );
    $fail( 'repeated completion keeps exactly one row', count( $GLOBALS['wpdb']->rows ) === 1 );
    foreach ( array( 'availability', 'responsibilities', 'schedule-changes', 'absence-replacement', 'finance', 'escalation' ) as $id ) {
        $service->completeForUser( $id, 7 );
    }
    $done = $service->hubForUser( 7 );
    $fail( 'all modules complete the hub', $done['completed_count'] === 7 && $done['state'] === 'completed' );
    $fail( 'completed guidance remains reachable', $done['next_module_id'] !== null );
    $fail( 'completion does not invent a canonical teacher state', ! isset( $done['onboarding_state'] ) && ! isset( $done['readiness_state'] ) );
    // A material policy acknowledgement is explicit and does not subtract completed progress.
    $GLOBALS['wpdb']->rows['absence-replacement']->acknowledged_version = 0;
    $reackHub = $service->hubForUser( 7 );
    $absenceProjection = null;
    foreach ( $reackHub['modules'] as $projectedModule ) { if ( 'absence-replacement' === $projectedModule['id'] ) { $absenceProjection = $projectedModule; } }
    $fail( 'old acknowledgement is surfaced for an updated policy', $absenceProjection && $absenceProjection['needs_reacknowledgement'] === true && $absenceProjection['state'] === 'completed' );
    $service->completeForUser( 'absence-replacement', 7 );
    $reackHub = $service->hubForUser( 7 );
    foreach ( $reackHub['modules'] as $projectedModule ) { if ( 'absence-replacement' === $projectedModule['id'] ) { $absenceProjection = $projectedModule; } }
    $fail( 're-acknowledgement clears the flag without removing completion', $absenceProjection['needs_reacknowledgement'] === false && $absenceProjection['state'] === 'completed' && $reackHub['completed_count'] === 7 );

    $refused = false;
    try { $service->hubForUser( 999 ); } catch ( Throwable $error ) { $refused = true; }
    $fail( 'another user cannot read teacher progress', $refused );
    $refused = false;
    try { $service->completeForUser( 'unknown-module', 7 ); } catch ( Throwable $error ) { $refused = true; }
    $fail( 'an unknown module id is refused', $refused );
    $fail( 'curriculum lookup refuses traversal-like ids', TeacherInductionCurriculum::moduleId( '../../etc/passwd' ) === '' );

    echo "Teacher induction unit passed\n";
}
