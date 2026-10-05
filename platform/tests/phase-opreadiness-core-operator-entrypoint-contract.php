<?php
/**
 * WordPress-free source contract for the authenticated Core operator entrypoint.
 *
 * It locks the route from the registered readiness submenu through its pre-render POST hook,
 * operation-specific authorization and nonce, each existing authority wrapper, and the durable
 * CoreDatasetReadinessService receipt returned to the operator. It deliberately proves that no
 * new broad capability, REST route, provider path or correction-as-domain-command route is added,
 * and that the submenu itself is registered behind the actor's own narrow command capability rather
 * than the unrelated diagnostics capability.
 */
$root = dirname( __DIR__ );
$controller = file_get_contents( $root . '/src/Admin/Controller/CoreDatasetReadinessController.php' );
$plugin = file_get_contents( $root . '/delnavazan-platform.php' );

function dzn_core_operator_entrypoint_assert( bool $ok, string $message ): void {
    if ( ! $ok ) throw new RuntimeException( $message );
}

// §1 The already-registered Core readiness controller owns a real authenticated admin entrypoint.
dzn_core_operator_entrypoint_assert(
    str_contains( $plugin, 'CoreDatasetReadinessController::register()' ),
    'The plugin must register the Core readiness controller.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "add_submenu_page(\n            'dzn-settings-menu'") && str_contains( $controller, "self::MENU_SLUG" ),
    'The operator entrypoint must be the Core readiness submenu under the authenticated Settings menu.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "if ( \$hook ) add_action( 'load-' . \$hook, array( __CLASS__, 'handlePost' ) );" ),
    'The readiness submenu must dispatch its POST handler on its authenticated pre-render load hook.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, 'self::dispatchOperatorAction( $post );' ),
    'The authenticated POST hook must reach the complete operator dispatcher.'
);
dzn_core_operator_entrypoint_assert(
    ! str_contains( $controller, 'register_rest_route' ) && ! str_contains( $controller, 'admin_post_' ),
    'Core operator commands must not acquire a public REST or unaffiliated admin-post surface.'
);
// WordPress only registers a submenu page — and therefore its load-$hook handler — for a capability
// the actor holds, so the entrypoint must be registered behind the actor's own narrow command
// capability and never behind an unrelated read capability such as dzn_view_diagnostics.
dzn_core_operator_entrypoint_assert(
    ! str_contains( $controller, 'dzn_view_diagnostics' ),
    'The Core operator entrypoint must not be gated by the unrelated diagnostics capability.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "\$capability = self::entrypointCapability();" ) &&
    str_contains( $controller, "if ( \$capability === null ) return;" ) &&
    str_contains( $controller, "\$capability,\n            self::MENU_SLUG," ),
    'The readiness submenu must be registered with a command capability the actor actually holds.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, 'foreach ( self::ACTIONS as $capability )' ) &&
    str_contains( $controller, 'if ( current_user_can( $capability ) ) return $capability;' ),
    'The entrypoint capability must be one of the exact command capabilities.'
);

// §2 The reachable mutations map one-for-one to their pre-existing narrow Core capabilities.
$actions = array(
    'convert_enrolment' => 'dzn_convert_service_arrangements_to_enrolments',
    'issue_student_claim_for_enrolment' => 'dzn_issue_student_account_claims',
    'assign_initial_teacher' => 'dzn_manage_teacher_assignments',
    'issue_canonical_lesson' => 'dzn_manage_canonical_lessons',
);
foreach ( $actions as $action => $capability ) {
    dzn_core_operator_entrypoint_assert(
        str_contains( $controller, "'$action' => '$capability'" ),
        "The $action operator action must retain its existing $capability capability."
    );
    dzn_core_operator_entrypoint_assert(
        str_contains( $controller, "check_admin_referer( 'dzn_core_dataset_' . \$action );" ),
        'Every recognised operator action must require its action-bound admin nonce.'
    );
}
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "if ( ! current_user_can( self::ACTIONS[ \$action ] ) )") &&
    str_contains( $controller, "wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );" ),
    'A denied operator action must stop at the controller with a 403 before an authority can run.'
);

// Existing canonical correction authorities must remain reachable from this same narrow, audited
// entrypoint so operations can recover without direct database edits.
foreach ( array(
    'replace_teacher_assignment' => array( 'dzn_manage_teacher_assignments', 'replaceTeacherAssignment(', 'TeacherAssignmentService() )->replace' ),
    'cancel_canonical_lesson' => array( 'dzn_manage_canonical_lessons', 'cancelCanonicalLesson(', 'CanonicalLessonAuthorityService() )->cancel' ),
) as $action => $contract ) {
    [ $capability, $wrapper, $authority ] = $contract;
    dzn_core_operator_entrypoint_assert(
        str_contains( $controller, "'$action' => '$capability'" ) &&
        str_contains( $controller, "current_user_can( self::ACTIONS['$action'] )" ) &&
        substr_count( $controller, $wrapper ) >= 2 &&
        str_contains( $controller, $authority ) &&
        str_contains( $controller, "self::requireCapability( self::ACTIONS['$action'] );" ),
        "The $action correction must stay on its existing authority behind its exact capability and operator form."
    );
}

// Term administration supports first and successor canonical periods and preserves lifecycle
// concurrency guards by passing expected predecessor/state inputs into the existing authority.
foreach ( array(
    'close_canonical_term' => array( 'dzn_manage_canonical_terms', 'closeCanonicalTerm(', 'CanonicalTermAuthorityService() )->close' ),
    'cancel_canonical_term' => array( 'dzn_manage_canonical_terms', 'cancelCanonicalTerm(', 'CanonicalTermAuthorityService() )->cancel' ),
) as $action => $contract ) {
    [ $capability, $wrapper, $authority ] = $contract;
    $method = strstr( $wrapper, '(', true );
    dzn_core_operator_entrypoint_assert(
        str_contains( $controller, "'$action' => '$capability'" ) &&
        str_contains( $controller, "'" . $action . "' => self::" . $method . "(" ) &&
        substr_count( $controller, $wrapper ) >= 2 && str_contains( $controller, $authority ) &&
        str_contains( $controller, "self::requireCapability( self::ACTIONS['" . $action . "'] );" ),
        "The $action lifecycle command must remain on the canonical Term authority and its narrow capability."
    );
}
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "self::optionalPositiveInt( \$post, 'expected_latest_term_id' )") &&
    str_contains( $controller, "self::nullableText( \$post, 'expected_latest_term_state' )") &&
    str_contains( $controller, "create( \$enrolmentId, \$expectedLatestTermId, \$expectedLatestTermState, \$evidence, \$key )") &&
    str_contains( $controller, "array( 'expected_latest_term_id', 'Latest closed/cancelled Term ID (leave blank for first Term)', false )") &&
    str_contains( $controller, "array( 'expected_latest_term_state', 'Latest Term state: closed or cancelled (leave blank for first Term)', false )" ),
    'Successor Term creation must supply the canonical authority with the expected terminal predecessor and state.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "'create_canonical_term' => self::createCanonicalTerm(") &&
    str_contains( $controller, "current_user_can( self::ACTIONS['create_canonical_term'] )") &&
    str_contains( $controller, "self::requireCapability( self::ACTIONS['create_canonical_term'] );" ),
    'First and successor Term creation must remain reachable only through the existing Term capability and nonce-bound dispatcher.'
);
$authorization = strpos( $controller, "current_user_can( self::ACTIONS[ \$action ] )" );
$nonce = strpos( $controller, "check_admin_referer( 'dzn_core_dataset_' . \$action )" );
$dispatch = strpos( $controller, 'match ( $action )' );
dzn_core_operator_entrypoint_assert(
    $authorization !== false && $nonce !== false && $dispatch !== false && $authorization < $nonce && $nonce < $dispatch,
    'Capability authorization and the action-bound nonce must both precede dispatch.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "if ( ! isset( self::ACTIONS[ \$action ] ) ) return;" ),
    'Unknown request actions must not reach a Core authority.'
);

// §3 Dispatch reaches the existing authority wrappers, and the UI only shows each
// command to an actor that holds that command's own capability.
foreach ( array( 'convertEnrolment(', 'issueStudentClaimForEnrolment(', 'assignInitialTeacher(', 'issueCanonicalLesson(' ) as $wrapper ) {
    dzn_core_operator_entrypoint_assert( substr_count( $controller, $wrapper ) >= 2, "The authenticated dispatcher and form must reach $wrapper." );
}
foreach ( $actions as $action => $capability ) {
    dzn_core_operator_entrypoint_assert(
        str_contains( $controller, "current_user_can( self::ACTIONS['$action'] )" ),
        "The $action form must be hidden from actors without its command capability."
    );
}
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "'convert_enrolment' => self::convertEnrolment(") &&
    str_contains( $controller, "'issue_student_claim_for_enrolment' => self::issueStudentClaimForEnrolment(") &&
    str_contains( $controller, "'assign_initial_teacher' => self::assignInitialTeacher(") &&
    str_contains( $controller, "'issue_canonical_lesson' => self::issueCanonicalLesson(" ),
    'Each recognised POST action must reach its corresponding existing wrapper.'
);

// §4 Every wrapper defends the same authority boundary itself, calls the authoritative domain service
// first, then records a digest-only operator receipt and returns that auditable receipt id.
foreach ( $actions as $action => $capability ) {
    dzn_core_operator_entrypoint_assert(
        str_contains( $controller, "self::requireCapability( self::ACTIONS['$action'] );" ),
        "The $action wrapper must retain a direct capability defence."
    );
}
foreach ( array( 'EnrolmentConversionService() )->convert', 'TeacherAssignmentService() )->assignInitial', 'CanonicalLessonAuthorityService() )->createStandard' ) as $authority ) {
    dzn_core_operator_entrypoint_assert( str_contains( $controller, $authority ), "The existing Core authority $authority must remain the domain writer." );
}
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "StudentAccountClaimNotificationService() )->issue(") &&
    str_contains( $controller, "record_model !== 'canonical_student_course_v1'") &&
    str_contains( $controller, "'Issue Student account-claim notification (explicit operator action)'") &&
    str_contains( $controller, 'Enrolment conversion never triggers it automatically.') &&
    str_contains( $controller, 'array( \'enrolment_id\' => (int) $result[\'enrolment_id\'] )') &&
    str_contains( $controller, 'self::contextId( $name )'),
    'Claim notification issuance must be an explicit action for a converted canonical Enrolment through the existing #221 service, never an automatic conversion side effect.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, 'claimNotificationResultFromQuery( wp_unslash( $_GET ) )') &&
    str_contains( $controller, 'lifecycleForGeneration( $invitationId, $generationId )') &&
    str_contains( $controller, 'forNotification( $notificationId )') &&
    str_contains( $controller, 'NotificationRepository() )->find( $notificationId )') &&
    str_contains( $controller, "'Recipient delivery: pending/unconfirmed.'") &&
    !str_contains( $controller, 'claim_delivery_evidence_id') &&
    !str_contains( $controller, 'claim_notification_state') &&
    str_contains( $controller, 'page=dzn-student-identity-authority'),
    'The claim result must ignore GET delivery/state claims and re-read the matching persisted claim, outbox and notification rows.'
);
$authorityCall = strpos( $controller, 'CanonicalLessonAuthorityService() )->createStandard' );
$receipt = strpos( $controller, 'recordOperation(' );
dzn_core_operator_entrypoint_assert(
    $authorityCall !== false && $receipt !== false && $authorityCall < $receipt,
    'A readiness receipt must be recorded only after an authoritative command result exists.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "'operator_entrypoint'") && str_contains( $controller, "'state' => 'completed'") &&
    str_contains( $controller, "'operator_evidence_id' => \$receipt") &&
    str_contains( $controller, "'operator_result_id' => (int) ( \$result[ \$resultIdKey ] ?? 0 )") &&
    str_contains( $controller, "(int) ( \$result['operator_result_id'] ?? 0 )" ),
    'A successful wrapper must return both its canonical result id and durable operator receipt id for the next operator step.'
);
dzn_core_operator_entrypoint_assert(
    ! str_contains( $controller, "'record_correction' =>") && str_contains( $controller, 'cannot create a correction in place of that command' ),
    'The entrypoint must not turn an append-only correction record into an unauthorised domain-command substitute.'
);

echo "Core operator entrypoint contract passed\n";
