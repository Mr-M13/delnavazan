<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\CanonicalLessonDeliveryService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService;
use Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService;
use Delnavazan\Platform\Core\Application\CoreDatasetReadinessService;
use Delnavazan\Platform\Core\Application\EnrolmentConversionService;
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;
use Delnavazan\Platform\Core\Application\StudentAccountClaimNotificationService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\EnrolmentConversionRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\StudentAccountClaimInvitationRepository;

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
        'issue_student_claim_for_enrolment' => 'dzn_issue_student_account_claims',
        'assign_initial_teacher' => 'dzn_manage_teacher_assignments',
        'replace_teacher_assignment' => 'dzn_manage_teacher_assignments',
        'create_canonical_term' => 'dzn_manage_canonical_terms',
        'activate_canonical_term' => 'dzn_manage_canonical_terms',
        'close_canonical_term' => 'dzn_manage_canonical_terms',
        'cancel_canonical_term' => 'dzn_manage_canonical_terms',
        'issue_canonical_lesson' => 'dzn_manage_canonical_lessons',
        'complete_canonical_lesson' => 'dzn_manage_canonical_lessons',
        'cancel_canonical_lesson' => 'dzn_manage_canonical_lessons',
        'record_delivery_outcome' => 'dzn_manage_canonical_lesson_delivery',
        'schedule_canonical_lesson' => 'dzn_manage_canonical_lesson_schedules',
        'revise_canonical_lesson_schedule' => 'dzn_manage_canonical_lesson_schedules',
        'release_canonical_lesson_schedule' => 'dzn_manage_canonical_lesson_schedules',
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
            'dzn-settings-menu',
            'Core dataset',
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
            if ( $action === 'convert_enrolment' && ! empty( $result['enrolment_id'] ) ) {
                self::redirect(
                    'Enrolment #' . (int) $result['enrolment_id'] . ' is ready. Submit the separate Student account-claim action below only when authorised. Operator evidence #' . (int) $result['operator_evidence_id'] . ' was recorded.',
                    false,
                    array( 'enrolment_id' => (int) $result['enrolment_id'] )
                );
            }
            if ( $action === 'issue_student_claim_for_enrolment' ) {
                $notificationId = (int) ( $result['notification_id'] ?? 0 );
                self::redirect( 'Student account-claim notification issuance recorded; operator evidence #' . (int) ( $result['operator_evidence_id'] ?? 0 ) . ' was recorded.', false, array(
                    'claim_issue' => '1',
                    'claim_invitation_id' => (int) ( $result['invitation_id'] ?? 0 ),
                    'claim_generation_id' => (int) ( $result['generation_id'] ?? 0 ),
                    'claim_notification_id' => $notificationId,
                ) );
            }
            self::redirect(
                'Completed ' . sanitize_key( (string) ( $result['operation'] ?? 'operator_command' ) ) .
                ' #' . (int) ( $result['operator_result_id'] ?? 0 ) .
                '; operator evidence #' . (int) $result['operator_evidence_id'] . ' was recorded.'
            );
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
            'issue_student_claim_for_enrolment' => self::issueStudentClaimForEnrolment(
                self::positiveInt( $post, 'enrolment_id' ),
                self::text( $post, 'operator_key' ),
                self::text( $post, 'evidence_reference' )
            ),
            'assign_initial_teacher' => self::assignInitialTeacher(
                self::positiveInt( $post, 'enrolment_id' ),
                self::text( $post, 'operator_key' ),
                self::text( $post, 'evidence_reference' )
            ),
            'replace_teacher_assignment' => self::replaceTeacherAssignment(
                self::positiveInt( $post, 'enrolment_id' ),
                self::positiveInt( $post, 'new_teacher_id' ),
                array(
                    'expected_assignment_id' => self::positiveInt( $post, 'expected_assignment_id' ),
                    'route' => self::text( $post, 'route' ),
                    'evidence_channel' => self::text( $post, 'evidence_channel' ),
                    'evidence_reference' => self::text( $post, 'evidence_reference' ),
                    'evidence_at' => self::text( $post, 'evidence_at' ),
                ),
                self::text( $post, 'operator_key' )
            ),
            'create_canonical_term' => self::createCanonicalTerm(
                self::positiveInt( $post, 'enrolment_id' ),
                self::optionalPositiveInt( $post, 'expected_latest_term_id' ),
                self::nullableText( $post, 'expected_latest_term_state' ),
                array( 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
                self::text( $post, 'operator_key' )
            ),
            'activate_canonical_term' => self::activateCanonicalTerm(
                self::positiveInt( $post, 'term_id' ),
                array( 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
                self::text( $post, 'operator_key' )
            ),
            'close_canonical_term' => self::closeCanonicalTerm(
                self::positiveInt( $post, 'term_id' ), self::text( $post, 'expected_term_state' ),
                array( 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
                self::text( $post, 'operator_key' )
            ),
            'cancel_canonical_term' => self::cancelCanonicalTerm(
                self::positiveInt( $post, 'term_id' ), self::text( $post, 'expected_term_state' ),
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
            'complete_canonical_lesson' => self::completeCanonicalLesson(
                self::positiveInt( $post, 'lesson_id' ),
                array( 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
                self::text( $post, 'operator_key' )
            ),
            'cancel_canonical_lesson' => self::cancelCanonicalLesson(
                self::positiveInt( $post, 'lesson_id' ),
                self::text( $post, 'expected_lesson_state' ),
                array(
                    'reason_code' => self::text( $post, 'reason_code' ),
                    'evidence_channel' => self::text( $post, 'evidence_channel' ),
                    'evidence_reference' => self::text( $post, 'evidence_reference' ),
                    'evidence_at' => self::text( $post, 'evidence_at' ),
                ),
                self::text( $post, 'operator_key' )
            ),
            'record_delivery_outcome' => self::recordDeliveryOutcome(
                self::positiveInt( $post, 'lesson_id' ), self::text( $post, 'expected_lesson_state' ),
                array( 'outcome_code' => self::text( $post, 'outcome_code' ), 'reason_code' => self::text( $post, 'reason_code' ), 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
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
            'revise_canonical_lesson_schedule' => self::reviseCanonicalLessonSchedule(
                self::positiveInt( $post, 'lesson_id' ), self::positiveInt( $post, 'teacher_assignment_id' ),
                array( 'expected_schedule_version_id' => self::positiveInt( $post, 'expected_schedule_version_id' ), 'schedule_timezone' => self::text( $post, 'schedule_timezone' ), 'local_wall_date' => self::text( $post, 'local_wall_date' ), 'local_wall_time' => self::text( $post, 'local_wall_time' ), 'reason_code' => self::text( $post, 'reason_code' ), 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
                self::text( $post, 'operator_key' )
            ),
            'release_canonical_lesson_schedule' => self::releaseCanonicalLessonSchedule(
                self::positiveInt( $post, 'lesson_id' ),
                array( 'expected_schedule_version_id' => self::positiveInt( $post, 'expected_schedule_version_id' ), 'reason_code' => self::text( $post, 'reason_code' ), 'evidence_channel' => self::text( $post, 'evidence_channel' ), 'evidence_reference' => self::text( $post, 'evidence_reference' ), 'evidence_at' => self::text( $post, 'evidence_at' ) ),
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

    /** Explicitly issue the existing #221 claim notification for a converted canonical Enrolment. */
    public static function issueStudentClaimForEnrolment( int $enrolmentId, string $key, string $reference ): array {
        self::requireCapability( self::ACTIONS['issue_student_claim_for_enrolment'] );
        $enrolment = ( new EnrolmentConversionRepository() )->enrolmentById( $enrolmentId );
        if ( ! $enrolment || (string) $enrolment->record_model !== 'canonical_student_course_v1' || empty( $enrolment->accepted_service_arrangement_id ) || (int) $enrolment->student_id < 1 ) {
            throw new \InvalidArgumentException( 'Canonical converted Enrolment required' );
        }
        $result = ( new StudentAccountClaimNotificationService() )->issue(
            (int) $enrolment->student_id,
            604800,
            $key
        );
        return self::withOperatorEvidence(
            $result,
            'student_account_claim_notification_issue',
            'enrolment',
            $enrolmentId,
            'staff_record',
            $reference,
            $key,
            'notification_id'
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

    public static function replaceTeacherAssignment( int $enrolmentId, int $teacherId, array $agreement, string $key ): array {
        self::requireCapability( self::ACTIONS['replace_teacher_assignment'] );
        $result = ( new TeacherAssignmentService() )->replace( $enrolmentId, $teacherId, $agreement, $key );
        return self::withOperatorEvidence( $result, 'teacher_assignment_replacement', 'enrolment', $enrolmentId, (string) ( $agreement['evidence_channel'] ?? '' ), (string) ( $agreement['evidence_reference'] ?? '' ), $key, 'assignment_id' );
    }

    public static function createCanonicalTerm( int $enrolmentId, ?int $expectedLatestTermId, ?string $expectedLatestTermState, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['create_canonical_term'] );
        $result = ( new CanonicalTermAuthorityService() )->create( $enrolmentId, $expectedLatestTermId, $expectedLatestTermState, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_term_creation', 'enrolment', $enrolmentId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'term_id' );
    }

    public static function activateCanonicalTerm( int $termId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['activate_canonical_term'] );
        $result = ( new CanonicalTermAuthorityService() )->activate( $termId, 'authorised', $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_term_activation', 'term', $termId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'term_id' );
    }

    public static function closeCanonicalTerm( int $termId, string $expectedState, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['close_canonical_term'] );
        $result = ( new CanonicalTermAuthorityService() )->close( $termId, $expectedState, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_term_closure', 'term', $termId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'term_id' );
    }

    public static function cancelCanonicalTerm( int $termId, string $expectedState, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['cancel_canonical_term'] );
        $result = ( new CanonicalTermAuthorityService() )->cancel( $termId, $expectedState, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_term_cancellation', 'term', $termId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'term_id' );
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

    public static function completeCanonicalLesson( int $lessonId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['complete_canonical_lesson'] );
        $result = ( new CanonicalLessonAuthorityService() )->complete( $lessonId, 'authorised', $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_lesson_completion', 'lesson', $lessonId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'lesson_id' );
    }

    public static function cancelCanonicalLesson( int $lessonId, string $expectedState, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['cancel_canonical_lesson'] );
        $result = ( new CanonicalLessonAuthorityService() )->cancel( $lessonId, $expectedState, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_lesson_cancellation', 'lesson', $lessonId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'lesson_id' );
    }

    public static function recordDeliveryOutcome( int $lessonId, string $expectedState, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['record_delivery_outcome'] );
        $result = ( new CanonicalLessonDeliveryService() )->record( $lessonId, $expectedState, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_lesson_delivery_outcome', 'lesson', $lessonId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'outcome_id' );
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

    public static function reviseCanonicalLessonSchedule( int $lessonId, int $assignmentId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['revise_canonical_lesson_schedule'] );
        $result = ( new CanonicalLessonScheduleService() )->revise( $lessonId, $assignmentId, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_lesson_schedule_revision', 'lesson', $lessonId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'schedule_version_id' );
    }

    public static function releaseCanonicalLessonSchedule( int $lessonId, array $evidence, string $key ): array {
        self::requireCapability( self::ACTIONS['release_canonical_lesson_schedule'] );
        $result = ( new CanonicalLessonScheduleService() )->release( $lessonId, $evidence, $key );
        return self::withOperatorEvidence( $result, 'canonical_lesson_schedule_release', 'lesson', $lessonId, (string) ( $evidence['evidence_channel'] ?? '' ), (string) ( $evidence['evidence_reference'] ?? '' ), $key, 'schedule_version_id' );
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
        if ( current_user_can( self::ACTIONS['issue_student_claim_for_enrolment'] ) ) {
            self::form( 'issue_student_claim_for_enrolment', 'Issue Student account-claim notification (explicit operator action)', array(
                array( 'enrolment_id', 'Canonical converted Enrolment ID' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
            echo '<p>This action issues the existing Student account claim through its notification workflow. Enrolment conversion never triggers it automatically.</p>';
        }
        if ( current_user_can( self::ACTIONS['assign_initial_teacher'] ) ) {
            self::form( 'assign_initial_teacher', 'Assign initial Teacher', array(
                array( 'enrolment_id', 'Canonical Enrolment ID' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['replace_teacher_assignment'] ) ) {
            self::form( 'replace_teacher_assignment', 'Replace Teacher Assignment by agreement', array(
                array( 'enrolment_id', 'Canonical Enrolment ID' ),
                array( 'expected_assignment_id', 'Current Teacher Assignment ID' ),
                array( 'new_teacher_id', 'New eligible Teacher ID' ),
                array( 'route', 'Agreement route: staff_attestation or authenticated_teacher' ),
                array( 'evidence_channel', 'Evidence channel: phone, whatsapp, email, video_call, in_person, or other_verified' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['create_canonical_term'] ) ) {
            self::form( 'create_canonical_term', 'Create canonical Term', array(
                array( 'enrolment_id', 'Canonical Enrolment ID' ),
                array( 'expected_latest_term_id', 'Latest closed/cancelled Term ID (leave blank for first Term)', false ),
                array( 'expected_latest_term_state', 'Latest Term state: closed or cancelled (leave blank for first Term)', false ),
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
            self::form( 'close_canonical_term', 'Close current canonical Term', array(
                array( 'term_id', 'Canonical Term ID' ), array( 'expected_term_state', 'Expected Term state: current' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ), array( 'evidence_reference', 'Operator evidence reference' ), array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ), array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
            self::form( 'cancel_canonical_term', 'Cancel authorised/current canonical Term', array(
                array( 'term_id', 'Canonical Term ID' ), array( 'expected_term_state', 'Expected Term state: authorised or current' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ), array( 'evidence_reference', 'Operator evidence reference' ), array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ), array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
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
        if ( current_user_can( self::ACTIONS['record_delivery_outcome'] ) ) {
            self::form( 'record_delivery_outcome', 'Record material Lesson delivery outcome', array(
                array( 'lesson_id', 'Canonical Lesson ID' ),
                array( 'expected_lesson_state', 'Expected Lesson state: authorised or completed' ),
                array( 'outcome_code', 'Outcome: student_no_show, teacher_non_delivery, interruption, or review_required' ),
                array( 'reason_code', 'Controlled reason code (lowercase_with_underscores)' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['complete_canonical_lesson'] ) ) {
            self::form( 'complete_canonical_lesson', 'Complete canonical Lesson', array(
                array( 'lesson_id', 'Canonical Lesson ID' ),
                array( 'evidence_channel', 'Evidence channel: staff_record, authenticated_platform, or document_reference' ),
                array( 'evidence_reference', 'Operator evidence reference' ),
                array( 'evidence_at', 'Evidence time (UTC: YYYY-MM-DD HH:MM:SS)' ),
                array( 'operator_key', 'Idempotency key (at least 24 characters; retain for a retry)' ),
            ) );
        }
        if ( current_user_can( self::ACTIONS['cancel_canonical_lesson'] ) ) {
            self::form( 'cancel_canonical_lesson', 'Cancel canonical Lesson', array(
                array( 'lesson_id', 'Canonical Lesson ID' ),
                array( 'expected_lesson_state', 'Expected Lesson state: authorised' ),
                array( 'reason_code', 'Cancellation reason: canonical_lesson_cancelled, attested_non_delivery, or academy_unavailable' ),
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
        if ( current_user_can( self::ACTIONS['revise_canonical_lesson_schedule'] ) ) {
            self::form( 'revise_canonical_lesson_schedule', 'Revise canonical Lesson schedule', array(
                array( 'lesson_id', 'Canonical Lesson ID' ), array( 'teacher_assignment_id', 'Expected current Teacher Assignment ID' ), array( 'expected_schedule_version_id', 'Current applicable Schedule Version ID' ),
                array( 'schedule_timezone', 'IANA timezone' ), array( 'local_wall_date', 'New local date: YYYY-MM-DD' ), array( 'local_wall_time', 'New local time: HH:MM' ), array( 'reason_code', 'Controlled reason code' ),
                array( 'evidence_channel', 'Evidence channel' ), array( 'evidence_reference', 'Operator evidence reference' ), array( 'evidence_at', 'Evidence time UTC' ), array( 'operator_key', 'Idempotency key' ),
            ) );
            self::form( 'release_canonical_lesson_schedule', 'Release canonical Lesson schedule', array(
                array( 'lesson_id', 'Canonical Lesson ID' ), array( 'expected_schedule_version_id', 'Current applicable Schedule Version ID' ), array( 'reason_code', 'Controlled reason code' ),
                array( 'evidence_channel', 'Evidence channel' ), array( 'evidence_reference', 'Operator evidence reference' ), array( 'evidence_at', 'Evidence time UTC' ), array( 'operator_key', 'Idempotency key' ),
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
        return $result + array(
            'operator_evidence_id' => $receipt,
            'operator_result_id' => (int) ( $result[ $resultIdKey ] ?? 0 ),
        );
    }

    private static function requireCapability( string $capability ): void {
        if ( ! current_user_can( $capability ) ) throw new \RuntimeException( 'Unauthorized' );
    }

    private static function positiveInt( array $post, string $key ): int {
        $value = absint( $post[ $key ] ?? 0 );
        if ( $value < 1 ) throw new \InvalidArgumentException( 'Positive identifier required' );
        return $value;
    }

    private static function optionalPositiveInt( array $post, string $key ): ?int {
        if ( self::text( $post, $key ) === '' ) return null;
        return self::positiveInt( $post, $key );
    }

    private static function text( array $post, string $key ): string {
        return sanitize_text_field( (string) ( $post[ $key ] ?? '' ) );
    }

    private static function nullableText( array $post, string $key ): ?string {
        $value = self::text( $post, $key );
        return $value === '' ? null : $value;
    }

    private static function form( string $action, string $title, array $fields ): void {
        echo '<h2>' . esc_html( $title ) . '</h2><form method="post">';
        wp_nonce_field( 'dzn_core_dataset_' . $action );
        echo '<input type="hidden" name="dzn_action" value="' . esc_attr( $action ) . '">';
        foreach ( $fields as $field ) {
            [ $name, $label ] = $field;
            $required = $field[2] ?? true;
            $value = self::contextId( $name );
            echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" inputmode="numeric"' . ( $required ? ' required' : '' ) . '></label></p>';
        }
        submit_button( 'Execute authorised command' );
        echo '</form>';
    }

    /** Prefill only stable record IDs from a workflow deep-link; each command still revalidates authority. */
    private static function contextId( string $name ): string {
        $value = wp_unslash( $_GET[ $name ] ?? '' );
        if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) return '';
        return (string) absint( $value );
    }

    private static function messages(): void {
        if ( ( $_GET['claim_issue'] ?? '' ) === '1' ) {
            $result = self::claimNotificationResultFromQuery( wp_unslash( $_GET ) );
            if ( $result === null ) {
                echo '<div class="notice notice-warning"><p>Claim notification status could not be verified from persisted records. Review the Student identity authority screen.</p><p><a href="' . esc_url( admin_url( 'admin.php?page=dzn-student-identity-authority' ) ) . '">Student identity &amp; acceptance authority</a></p></div>';
                return;
            }
            echo '<div class="notice notice-success"><p>' . esc_html( $result['message'] ) . '</p><p>' . esc_html( $result['next_step'] ) . ' <a href="' . esc_url( $result['next_url'] ) . '">Student identity &amp; acceptance authority</a></p></div>';
            return;
        }
        if ( ! isset( $_GET['dzn_notice'] ) ) return;
        $class = ( $_GET['dzn_error'] ?? '' ) === '1' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ) . '</p></div>';
    }

    /** Build the PRG result exclusively from persisted claim, outbox and notification records. */
    public static function claimNotificationResultFromQuery( array $query ): ?array {
        foreach ( array( 'claim_invitation_id', 'claim_generation_id', 'claim_notification_id' ) as $field ) {
            if ( ! isset( $query[ $field ] ) || ! is_scalar( $query[ $field ] ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $query[ $field ] ) ) return null;
        }
        $invitationId = (int) $query['claim_invitation_id'];
        $generationId = (int) $query['claim_generation_id'];
        $notificationId = (int) $query['claim_notification_id'];
        $notification = ( new NotificationRepository() )->find( $notificationId );
        $outbox = ( new NotificationOutboxRepository() )->forNotification( $notificationId );
        $lifecycle = ( new StudentAccountClaimInvitationRepository() )->lifecycleForGeneration( $invitationId, $generationId );
        if ( !$notification || !$outbox || !$lifecycle ) return null;
        $expectedIntentKey = hash_hmac( 'sha256', 'student_account_claim_intent:' . $generationId, wp_salt( 'dzn_student_account_claim' ) );
        if (
            (int) $notification->id !== $notificationId ||
            (string) $notification->intent_key !== 'STUDENT_ACCOUNT_CLAIM_ISSUED' ||
            (string) $notification->subject_aggregate !== 'student_account_claim_invitation' ||
            (int) $notification->subject_aggregate_id !== $invitationId ||
            (int) $notification->outbox_id !== (int) $outbox->id ||
            (int) $outbox->notification_id !== $notificationId ||
            (string) $outbox->event_type !== 'STUDENT_ACCOUNT_CLAIM_ISSUED' ||
            (string) $outbox->aggregate_type !== 'student_account_claim' ||
            (int) $outbox->aggregate_id !== $invitationId ||
            !hash_equals( $expectedIntentKey, (string) $outbox->idempotency_key ) ||
            (int) $lifecycle->invitation_id !== $invitationId ||
            (int) $lifecycle->generation_id !== $generationId
        ) return null;
        $state = (string) $notification->state;
        if ( ! NotificationRule::notificationState( $state ) ) return null;
        return array(
            'message' => 'Invitation #' . $invitationId . ' (' . (string) $lifecycle->invitation_state . '); generation #' . $generationId . ' (' . (string) $lifecycle->generation_state . '); notification #' . $notificationId . '; notification state: ' . $state . '. Recipient delivery: pending/unconfirmed.',
            'next_step' => 'Review the invitation lifecycle to see whether it is issued, expired, or claimed.',
            'next_url' => admin_url( 'admin.php?page=dzn-student-identity-authority' ),
        );
    }

    private static function redirect( string $notice, bool $error = false, array $context = array() ): never {
        $url = add_query_arg( array_merge( array( 'page' => self::MENU_SLUG, 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), $context ), admin_url( 'admin.php' ) );
        nocache_headers();
        if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 500 : 200 ) );
    }
}
