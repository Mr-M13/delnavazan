<?php
/** Read-only recovery discovery classifies inconsistent accepted bookings without mutating them. */
namespace Delnavazan\Platform\Integrations\GoogleWorkspace {
    final class GoogleWorkspaceOAuthService {
        public function health(): array { return array('state' => 'connected', 'scope_match' => true); }
    }
}
namespace {
    function current_user_can(string $capability): bool { return $capability === 'dzn_manage_booking_request_coordination'; }

    final class DznAuditWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public array $rows = array();
        public int $queries = 0;
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function get_results(string $sql): array { $this->queries++; return $this->rows; }
    }

    $GLOBALS['wpdb'] = new DznAuditWpdb();
    require_once dirname(__DIR__) . '/src/Core/Application/BookingRecoveryAuditService.php';

    use Delnavazan\Platform\Core\Application\BookingRecoveryAuditService;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $start = gmdate('Y-m-d H:i:s', time() + 86400);
    $base = array(
        'confirmation_id' => 70, 'confirmation_uid' => 'CONFIRM-70', 'booking_request_id' => 12,
        'delivery_id' => 41, 'teacher_id' => 10, 'requested_time_id' => 5,
        'confirmation_state' => 'failed', 'confirmation_version' => 4,
        'starts_at_utc' => $start, 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 88200),
        'schedule_timezone' => 'Asia/Tehran', 'failure_reason_code' => 'meet_cohost_assignment_blocked',
        'attempt_count' => 6, 'next_attempt_at' => null, 'confirmation_updated_at' => gmdate('Y-m-d H:i:s'),
        'confirmed_at' => null, 'delivery_state' => 'accepting', 'delivery_teacher_id' => 10,
        'delivery_requested_time_id' => 5, 'request_reference' => 'REQ-12',
        'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'privacy_erased_at' => null,
        'offer_id' => 8, 'offer_state' => 'accepted', 'offer_active_slot' => null, 'offer_teacher_id' => 10,
        'classroom_id' => 9, 'classroom_state' => 'blocked', 'cohost_state' => 'blocked',
        'classroom_failure_reason_code' => 'meet_cohost_assignment_blocked', 'classroom_updated_at' => gmdate('Y-m-d H:i:s'),
    );

    $GLOBALS['wpdb']->rows = array(
        (object) $base,
        (object) (array('confirmation_id' => 71, 'confirmation_uid' => 'CONFIRM-71', 'confirmation_version' => 2, 'offer_id' => 9, 'offer_state' => 'pending', 'offer_active_slot' => 1, 'offer_teacher_id' => 99) + $base),
        (object) (array('confirmation_id' => 72, 'confirmation_uid' => 'CONFIRM-72', 'confirmation_state' => 'retryable', 'confirmation_version' => 1, 'failure_reason_code' => 'meet_calendar_projection_retryable') + $base),
        (object) (array('confirmation_id' => 73, 'confirmation_uid' => 'CONFIRM-73', 'delivery_state' => 'pending_request') + $base),
    );

    $service = new BookingRecoveryAuditService();
    $rows = $service->inconsistent(50);
    $assert(count($rows) === 4, 'Discovery must return every inconsistent accepted booking.');
    $assert($rows[0]['administrator_retry_eligible'] === true && $rows[0]['recommended_action'] === 'retry_booking_projection', 'A terminal, active, future booking must be eligible for one administrator retry.');
    $assert($rows[0]['booking_reference'] === 'REQ-12' && $rows[0]['teacher_id'] === 10 && $rows[0]['confirmation_id'] === 70, 'The report must identify the exact request, Teacher and confirmation.');
    $assert($rows[0]['failure_reason_code'] === 'meet_cohost_assignment_blocked' && $rows[0]['attempt_count'] === 6, 'The report must expose failure diagnostics.');
    $assert($rows[0]['organizer_state'] === 'connected' && $rows[0]['organizer_scope_match'] === true, 'The report must expose organizer readiness.');
    $assert($rows[1]['offer_conflicts'] === true && $rows[1]['administrator_retry_eligible'] === false && $rows[1]['recommended_action'] === 'manual_review', 'A superseded allocation must be refused for automatic retry.');
    $assert($rows[2]['automatic_retry_eligible'] === true && $rows[2]['recommended_action'] === 'monitor_automatic_retry', 'A retryable booking must be monitored rather than replayed.');
    $assert($rows[3]['administrator_retry_eligible'] === true && $rows[3]['delivery_state'] === 'pending_request', 'A historical pending_request record must remain recoverable.');
    $assert($service->assess(72)['confirmation_id'] === 72, 'Single-record assessment must select exactly that confirmation.');
    $assert($service->assess(999) === null, 'An unknown confirmation must not be invented.');
    $assert($GLOBALS['wpdb']->queries >= 2, 'Discovery must remain read-only.');

    echo "Booking recovery audit unit passed\n";
}
