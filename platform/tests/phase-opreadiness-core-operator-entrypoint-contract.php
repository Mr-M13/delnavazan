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
    str_contains( $controller, "add_submenu_page(\n            'dzn-platform'") && str_contains( $controller, "self::MENU_SLUG" ),
    'The operator entrypoint must be the Core readiness submenu under the authenticated Platform admin menu.'
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

// §2 The only reachable mutations map one-for-one to their pre-existing narrow Core capabilities.
$actions = array(
    'convert_enrolment' => 'dzn_convert_service_arrangements_to_enrolments',
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

// §3 Dispatch reaches precisely the existing three authority wrappers, and the UI only shows each
// command to an actor that holds that command's own capability.
foreach ( array( 'convertEnrolment(', 'assignInitialTeacher(', 'issueCanonicalLesson(' ) as $wrapper ) {
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
$authorityCall = strpos( $controller, 'CanonicalLessonAuthorityService() )->createStandard' );
$receipt = strpos( $controller, 'recordOperation(' );
dzn_core_operator_entrypoint_assert(
    $authorityCall !== false && $receipt !== false && $authorityCall < $receipt,
    'A readiness receipt must be recorded only after an authoritative command result exists.'
);
dzn_core_operator_entrypoint_assert(
    str_contains( $controller, "'operator_entrypoint'") && str_contains( $controller, "'state' => 'completed'") &&
    str_contains( $controller, "return \$result + array( 'operator_evidence_id' => \$receipt );" ),
    'A successful wrapper must produce a completed operator-entrypoint receipt and return its durable id.'
);
dzn_core_operator_entrypoint_assert(
    ! str_contains( $controller, "'record_correction' =>") && str_contains( $controller, 'cannot create a correction in place of that command' ),
    'The entrypoint must not turn an append-only correction record into an unauthorised domain-command substitute.'
);

echo "Core operator entrypoint contract passed\n";
