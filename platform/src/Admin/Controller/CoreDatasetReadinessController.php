<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService;
use Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService;
use Delnavazan\Platform\Core\Application\CoreDatasetReadinessService;
use Delnavazan\Platform\Core\Application\EnrolmentConversionService;
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;

/**
 * Authenticated Core-operator entrypoint. Canonical authorities remain the only domain writers.
 *
 * The readiness receipt supplements, but never substitutes for, the authority's own command and
 * lifecycle evidence. The entrypoint is reachable by an actor holding any one of the exact narrow
 * command capabilities below, and by no unrelated capability; each action is still guarded by the
 * same narrow capability as its authority.
 */
final class CoreDatasetReadinessController {
    private const MENU_SLUG = 'dzn-core-dataset-readiness';
    private const ACTIONS = array(
        'convert_enrolment' => 'dzn_convert_service_arrangements_to_enrolments',
        'assign_initial_teacher' => 'dzn_manage_teacher_assignments',
        'create_canonical_term' => 'dzn_manage_canonical_terms',
        'activate_canonical_term' => 'dzn_manage_canonical_terms',
        'issue_canonical_lesson' => 'dzn_manage_canonical_lessons',
        'schedule_canonical_lesson' => 'dzn_manage_canonical_lesson_schedules',
    );

    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
    }

    public static function menu(): void {
        // WordPress registers the submenu page, its callback and its $_registered_pages entry only for
        // a capability the current actor holds, and records a no-privilege entry that denies the page
        // otherwise. The page capability is therefore the actor's own exact command capability rather
        // than the unrelated diagnostics capability, so an actor holding exactly one documented Core
        // command reaches this entrypoint and an actor holding none of them gets no entrypoint at all.
        $capability = self::entrypointCapability();
        if ( $capability === null ) return;
        $hook = add_submenu_page(
            'dzn-platform',
            'Core dataset readiness',
            'Core dataset readiness',
            $capability,
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
        // Handle mutations before admin-header.php so the post/redirect/get response can send headers.
        if ( $hook ) add_action( 'load-' . $hook, array( __CLASS__, 'handlePost' ) );
    }

    /**
     * The entrypoint's page capability is one of the actor's own narrow command capabilities, never a
     * diagnostics or other read capability. Null means the actor holds no Core operator command, so the
     * entrypoint is not registered for them at all; it can never widen an action the actor cannot run.
     */
    private static function entrypointCapability(): ?string {
        foreach ( self::ACTIONS as $capability ) {
            if ( current_user_can( $capability ) ) return $capability;
        }
        return null;
    }

    /** The authenticated submenu's sole mutation dispatcher. */
    public static function handlePost(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        $post = wp_unslash( $_POST );
        $action = sanitize_key( $post['dzn_action'] ?? '' );
        if ( ! isset( self::ACTIONS[ $action ] ) ) return;

        try {
            $result = self::dispatchOperatorAction( $post );
            self::redirect( 'Completed; operator evidence #' . (int) $result['operator_evidence_id'] . ' was recorded.' );
        } catch ( \Throwable ) {
            // The individual authority controls its own atomic domain command and rejection semantics.
            self::redirect( 'Operation failed; no readiness receipt was recorded.', true );
        }
    }

    /**
     * Testable command dispatcher used by the authenticated admin handler. Its complete capability
     * and nonce checks remain here, so calling code cannot bypass the operator boundary.
     */
    public static function dispatchOperatorAction( array $post ): array {
        $action = sanitize_key( $post['dzn_action'] ?? '' );
        if ( ! isset( self::ACTIONS[ $action ] ) ) throw new \InvalidArgumentException( 'Unknown Core operator action' );
        if ( ! current_user_can( self::ACTIONS[ $action ] ) ) {
            wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );
        }
        check_admin_referer( 'dzn_core_dataset_' . $action );

        return match ( $action ) {
            'convert_enrolment' => self::convertEnrolment(
                self::positiveInt( $post, 'accepted_service_arrangement_id' ),
                self::text( $post, 'operator_key' ),
                self::text( $post, 'evidence_reference' )
            ),
            'assign_initial_teacher' => self::assignInitialTeacher(
                self::positiveInt( $post, 'enrolment_id' ),
                self::text( $post, 'operator_key' ),
                self::text( $post, 'evidence_reference' )
            ),
            'create_canonical_term' => self::createCanonicalTerm(
                self::positiveInt( $post, 'enrolment_id' ),
                array( 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
                self::text( $post, 'operator_key' )
            ),
            'activate_canonical_term' => self::activateCanonicalTerm(
                self::positiveInt( $post, 'term_id' ),
                array( 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
                self::text( $post, 'operator_key' )
            ),
            'issue_canonical_lesson' => self::issueCanonicalLesson(
                self::positiveInt( $post, 'term_id' ),
                self::positiveInt( $post, 'teacher_assignment_id' ),
                array(
                    'evidence_channel' => self::text( $post, 'evidence_channel' ),
                    'evidence_reference' => self::text( $post, 'evidence_reference' ),
                    'evidence_at' => self::text( $post, 'evidence_at' ),
                ),
                self::text( $post, 'operator_key' )
            ),
            'schedule_canonical_lesson' => self::scheduleCanonicalLesson(
                self::positiveInt( $post, 'lesson_id' ),
                self::positiveInt( $post, 'teacher_assignment_id' ),
                array(
                    'schedule_timezone' => self::text( $post, 'schedule_timezone' ),
                    'local_wall_date' => self::text( $post, 'local_wall_date' ),
                    'local_wall_time' => self::text( $post, 'local_wall_time' ),
                    'reason_code' => self::text( $post, 'reason_code' ),
                    'evidence_channel' => self::text( $post, 'evidence_channel' ),
                    'evidence_reference' => self::text( $post, 'evidence_reference' ),
                    'evidence_at' => self::text( $post, 'evidence_at' ),
                ),
                self::text( $post, 'operator_key' )
            ),
        };
    }

    public static function convertEnrolment( int $arrangementId, string $key, string $reference ): array {
        self::requireCapability( self::ACTIONS['convert_enrolment'] );
        $result = ( new EnrolmentConversionService() )->convert( $arrangementId, $key );
        return self::withOperatorEvidence(
            $result,
            'enrolment_conversion',
            'accepted_service_arrangement',
            $arrangementId,
            'staff_record',
            $reference,
            $key,
            'enrolment_id'
        );
    }

    public static function assignInitialTeacher( int $enrolmentId, string $key, string $reference ): array {
        self::requireCapability( self::ACTIONS['assign_initial_teacher'] );
        $result = ( new TeacherAssignmentService() )->assignInitial( $enrolmentId, $key );
        return self::withOperatorEvidence(
            $result,
            'initial_teacher_assignment',
            'enrolment',
            $enrolmentId,
            'staff_record',
            $reference,
            $key,
            'assignment_id'
        );
    }

    public static function createCanonicalTerm( int $enrolmentId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['create_canonical_term'] );
        $result = ( new CanonicalTermAuthorityService() )->create( $enrolmentId, null, null, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_term_creation', 'enrolment', $enrolmentId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'term_id' );
    }

    public static function activateCanonicalTerm( int $termId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['activate_canonical_term'] );
        $result = ( new CanonicalTermAuthorityService() )->activate( $termId, 'authorised', $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_term_activation', 'term', $termId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'term_id' );
    }

    public static function issueCanonicalLesson( int $termId, int $assignmentId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['issue_canonical_lesson'] );
        $result = ( new CanonicalLessonAuthorityService() )->createStandard( $termId, $assignmentId, $evidence, $key );
        return self::withOperatorEvidence(
            $result,
            'canonical_lesson_issuance',
            'term',
            $termId,
            (string) ( $evidence['evidence_channel'] ?? '' ),
            (string) ( $evidence['evidence_reference'] ?? '' ),
            $key,
            'lesson_id'
        );
    }

    public static function scheduleCanonicalLesson( int $lessonId, int $assignmentId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['schedule_canonical_lesson'] );
        $result = ( new CanonicalLessonScheduleService() )->schedule( $lessonId, $assignmentId, $evidence, $key );
        return self::withOperatorEvidence(
            $result,
            'canonical_lesson_schedule',
            'lesson',
            $lessonId,
            (string) ( $evidence['evidence_channel'] ?? '' ),
            (string) ( $evidence['evidence_reference'] ?? '' ),
            $key,
            'schedule_version_id'
        );
    }

    /** Corrections remain append-only evidence and are deliberately not a substitute for a domain command. */
    public static function recordCorrection( string $kind, int $id, string $prior, string $corrected, string $reason, string $channel, string $reference, string $note = '' ): int {
        return ( new CoreDatasetReadinessService() )->recordCorrection( $kind, $id, $prior, $corrected, $reason, $channel, $reference, $note );
    }

    public static function render(): void {
        if ( self::entrypointCapability() === null ) return;
        echo '<div class="wrap"><h1>Core dataset readiness</h1>';
        echo '<p>Authenticated operator commands retain their existing Core authorities and capabilities. Each successful command records a separate, digest-only readiness receipt.</p>';
        self::messages();
        if ( current_user_can( self::ACTIONS['convert_enrolment'] ) ) {
            self::form( 'convert_enrolment', 'Convert accepted Service Arrangement to Enrolment', array(
                array( 'accepted_service_arrangement_id', 'Accepted Service Arrangement ID' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['assign_initial_teacher'] ) ) {
            self::form( 'assign_initial_teacher', 'Assign initial Teacher', array(
                array( 'enrolment_id', 'Canonical Enrolment ID' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['create_canonical_term'] ) ) {
            self::form( 'create_canonical_term', 'Create first canonical Term', array(
                array( 'enrolment_id', 'Canonical Enrolment ID' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
            self::form( 'activate_canonical_term', 'Activate authorised canonical Term', array(
                array( 'term_id', 'Canonical Term ID' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['issue_canonical_lesson'] ) ) {
            self::form( 'issue_canonical_lesson', 'Issue canonical standard Lesson', array(
                array( 'term_id', 'Canonical Term ID' ),
                array( 'teacher_assignment_id', 'Expected current Teacher Assignment ID' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['schedule_canonical_lesson'] ) ) {
            self::form( 'schedule_canonical_lesson', 'Schedule canonical Lesson', array(
                array( 'lesson_id', 'Canonical Lesson ID' ),
                array( 'teacher_assignment_id', 'Expected current Teacher Assignment ID' ),
                array( 'schedule_timezone', 'IANA timezone, e.g. Australia/Brisbane' ),
                array( 'local_wall_date', 'Local date: YYYY-MM-DD' ),
                array( 'local_wall_time', 'Local time: HH:MM' ),
                array( 'reason_code', 'Reason code, e.g. initial_schedule' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        echo '<p>Corrections remain append-only evidence for a separately executed, audited domain command; this screen cannot create a correction in place of that command.</p></div>';
    }

    private static function withOperatorEvidence( array $result, string $operation, string $targetKind, int $targetId, string $channel, string $reference, string $key, string $resultIdKey ): array {
        $receipt = ( new CoreDatasetReadinessService() )->recordOperation(
            $operation,
            $targetKind,
            $targetId,
            'operator_entrypoint',
            $channel,
            $reference,
            $key,
            array( 'state' => 'completed', 'id' => (int) ( $result[ $resultIdKey ] ?? 0 ) )
        );
        return $result + array( 'operator_evidence_id' => $receipt );
    }

    private static function requireCapability( string $capability ): void {
        if ( ! current_user_can( $capability ) ) throw new \RuntimeException( 'Unauthorized' );
    }

    private static function positiveInt( array $post, string $key ): int {
        $value = absint( $post[ $key ] ?? 0 );
        if ( $value < 1 ) throw new \InvalidArgumentException( 'Positive identifier required' );
        return $value;
    }

    private static function text( array $post, string $key ): string {
        return sanitize_text_field( (string) ( $post[ $key ] ?? '' ) );
    }

    private static function form( string $action, string $title, array $fields ): void {
        echo '<h2>' . esc_html( $title ) . '</h2><form method="post">';
        wp_nonce_field( 'dzn_core_dataset_' . $action );
        echo '<input type="hidden" name="dzn_action" value="' . esc_attr( $action ) . '">';
        foreach ( $fields as [ $name, $label ] ) {
            echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" name="' . esc_attr( $name ) . '" required></label></p>';
        }
        submit_button( 'Execute authorised command' );
        echo '</form>';
    }

    private static function messages(): void {
        if ( ! isset( $_GET['dzn_notice'] ) ) return;
        $class = ( $_GET['dzn_error'] ?? '' ) === '1' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ) . '</p></div>';
    }

    private static function redirect( string $notice, bool $error = false ): never {
        $url = add_query_arg( array( 'page' => self::MENU_SLUG, 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), admin_url( 'admin.php' ) );
        nocache_headers();
        if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 500 : 200 ) );
    }
}
