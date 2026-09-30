<?php
/**
 * WordPress-free behavioural proof for the Core operator dispatcher.
 *
 * The fakes replace only the three existing authority boundaries and the append-only readiness
 * recorder. They prove a denied request cannot reach either writer, while an authorised, nonce-bound
 * request invokes the existing conversion authority and returns its durable operator receipt. They
 * also reproduce WordPress' submenu-capability behaviour, so they prove the entrypoint registers for
 * each exact narrow command capability on its own and never for the unrelated diagnostics capability.
 */
namespace Delnavazan\Platform\Core\Application {
    final class EnrolmentConversionService {
        public static array $calls = array();
        public function convert( int $arrangementId, string $key ): array {
            self::$calls[] = array( $arrangementId, $key );
            return array( 'enrolment_id' => 41, 'created' => true );
        }
    }

    final class TeacherAssignmentService {
        public function assignInitial( int $enrolmentId, string $key ): array {
            return array( 'assignment_id' => 42, 'created' => true );
        }
    }

    final class CanonicalLessonAuthorityService {
        public function createStandard( int $termId, int $assignmentId, array $evidence, string $key ): array {
            return array( 'lesson_id' => 43, 'created' => true );
        }
    }

    final class CoreDatasetReadinessService {
        public static array $calls = array();
        public function recordOperation( string $operation, string $targetKind, ?int $targetId, string $reason, string $channel, string $reference, string $key, array $result ): int {
            self::$calls[] = array( $operation, $targetKind, $targetId, $reason, $channel, $reference, $key, $result );
            return 71;
        }
        public function recordCorrection( string $kind, int $id, string $prior, string $corrected, string $reason, string $channel, string $reference, string $note = '' ): int {
            return 0;
        }
    }
}

namespace {
    final class DznCoreOperatorEntrypointDenied extends \RuntimeException {}

    $GLOBALS['dzn_core_operator_caps'] = array();
    $GLOBALS['dzn_core_operator_nonces'] = array();
    function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['dzn_core_operator_caps'], true ); }
    function check_admin_referer( string $action ): void { $GLOBALS['dzn_core_operator_nonces'][] = $action; }
    function wp_die( string $message = '', string $title = '', array $args = array() ): never { throw new DznCoreOperatorEntrypointDenied( $message, (int) ( $args['response'] ?? 0 ) ); }
    function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
    function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
    function absint( mixed $value ): int { return abs( (int) $value ); }
    // WordPress registers a submenu page only for a capability the actor holds and returns false
    // otherwise, which is why an actor without the page capability got neither the page nor its
    // load-$hook handler.
    function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '', $position = null ): string|false {
        if ( ! current_user_can( $capability ) ) return false;
        $GLOBALS['dzn_core_operator_page_registrations'][] = array( $parent_slug, $capability, $menu_slug );
        return $parent_slug . '_page_' . $menu_slug;
    }
    function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['dzn_core_operator_action_registrations'][] = $hook_name;
    }

    require dirname( __DIR__ ) . '/src/Admin/Controller/CoreDatasetReadinessController.php';

    function dzn_core_operator_entrypoint_unit_assert( bool $ok, string $message ): void {
        if ( ! $ok ) throw new \RuntimeException( $message );
    }

    $controller = \Delnavazan\Platform\Admin\Controller\CoreDatasetReadinessController::class;
    $post = array(
        'dzn_action' => 'convert_enrolment',
        'accepted_service_arrangement_id' => '9',
        'evidence_reference' => 'operator-ticket-91',
        'operator_key' => 'operator-key-that-is-long-enough',
    );

    // A role with an unrelated Core capability cannot get through the dispatcher, nonce, authority or recorder.
    $GLOBALS['dzn_core_operator_caps'] = array( 'dzn_manage_teacher_assignments' );
    try {
        $controller::dispatchOperatorAction( $post );
        throw new \RuntimeException( 'Denied operator request unexpectedly completed.' );
    } catch ( DznCoreOperatorEntrypointDenied $denied ) {
        dzn_core_operator_entrypoint_unit_assert( $denied->getCode() === 403, 'Denied operator request must return a 403.' );
    }
    dzn_core_operator_entrypoint_unit_assert( \Delnavazan\Platform\Core\Application\EnrolmentConversionService::$calls === array(), 'Denied operator request must not invoke the domain authority.' );
    dzn_core_operator_entrypoint_unit_assert( \Delnavazan\Platform\Core\Application\CoreDatasetReadinessService::$calls === array(), 'Denied operator request must not write an operator receipt.' );
    dzn_core_operator_entrypoint_unit_assert( $GLOBALS['dzn_core_operator_nonces'] === array(), 'Denied operator request must stop before nonce verification.' );

    // The exact conversion capability plus the action-bound nonce reaches the existing authority and durable receipt.
    $GLOBALS['dzn_core_operator_caps'] = array( 'dzn_convert_service_arrangements_to_enrolments' );
    $result = $controller::dispatchOperatorAction( $post );
    dzn_core_operator_entrypoint_unit_assert( $result['enrolment_id'] === 41 && $result['operator_evidence_id'] === 71, 'Authorised conversion must return the authority result and durable operator receipt id.' );
    dzn_core_operator_entrypoint_unit_assert( \Delnavazan\Platform\Core\Application\EnrolmentConversionService::$calls === array( array( 9, 'operator-key-that-is-long-enough' ) ), 'The dispatcher must reach the existing conversion authority with the supplied idempotency key.' );
    dzn_core_operator_entrypoint_unit_assert( $GLOBALS['dzn_core_operator_nonces'] === array( 'dzn_core_dataset_convert_enrolment' ), 'The authorised request must verify the action-bound admin nonce.' );
    dzn_core_operator_entrypoint_unit_assert(
        \Delnavazan\Platform\Core\Application\CoreDatasetReadinessService::$calls === array( array(
            'enrolment_conversion',
            'accepted_service_arrangement',
            9,
            'operator_entrypoint',
            'staff_record',
            'operator-ticket-91',
            'operator-key-that-is-long-enough',
            array( 'state' => 'completed', 'id' => 41 ),
        ) ),
        'A successful conversion must append the complete operator-entrypoint audit receipt after the authority result.'
    );

    // The entrypoint itself registers for each exact command capability on its own, because WordPress
    // only registers the submenu page and its load-$hook handler for a capability the actor holds.
    $entrypoint_page = array( 'dzn-platform', null, 'dzn-core-dataset-readiness' );
    foreach ( array( 'dzn_convert_service_arrangements_to_enrolments', 'dzn_manage_teacher_assignments', 'dzn_manage_canonical_lessons' ) as $command_capability ) {
        $GLOBALS['dzn_core_operator_caps'] = array( $command_capability );
        $GLOBALS['dzn_core_operator_page_registrations'] = array();
        $GLOBALS['dzn_core_operator_action_registrations'] = array();
        $controller::menu();
        $entrypoint_page[1] = $command_capability;
        dzn_core_operator_entrypoint_unit_assert(
            $GLOBALS['dzn_core_operator_page_registrations'] === array( $entrypoint_page ),
            'An actor holding only ' . $command_capability . ' must reach the readiness submenu behind exactly that capability.'
        );
        dzn_core_operator_entrypoint_unit_assert(
            $GLOBALS['dzn_core_operator_action_registrations'] === array( 'load-dzn-platform_page_dzn-core-dataset-readiness' ),
            'An actor holding only ' . $command_capability . ' must get the entrypoint pre-render load hook.'
        );
    }

    // The unrelated diagnostics capability is neither required nor sufficient for the entrypoint.
    foreach ( array( array( 'dzn_view_diagnostics' ), array() ) as $no_command_caps ) {
        $GLOBALS['dzn_core_operator_caps'] = $no_command_caps;
        $GLOBALS['dzn_core_operator_page_registrations'] = array();
        $GLOBALS['dzn_core_operator_action_registrations'] = array();
        $controller::menu();
        dzn_core_operator_entrypoint_unit_assert(
            $GLOBALS['dzn_core_operator_page_registrations'] === array() && $GLOBALS['dzn_core_operator_action_registrations'] === array(),
            'An actor holding no Core operator command capability must get no entrypoint page and no POST hook.'
        );
    }

    echo "Core operator entrypoint unit passed\n";
}
