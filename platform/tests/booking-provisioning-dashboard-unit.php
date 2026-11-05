<?php
/** Dashboard stages distinguish coordination, Teacher response, provisioning and failure. */
namespace {
    final class DznDashboardWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public ?object $confirmation = null;
        public function prepare(string $sql, mixed ...$args): string {
            $index = 0;
            return (string) preg_replace_callback('/%[ds]/', static function (array $match) use (&$index, $args): string {
                $value = $args[$index++] ?? '';
                return $match[0] === '%d' ? (string) (int) $value : (string) $value;
            }, $sql);
        }
        public function get_row(string $sql): ?object {
            if (str_contains($sql, 'booking_request_confirmations c')) return $this->confirmation;
            return null;
        }
    }
    $GLOBALS['wpdb'] = new DznDashboardWpdb();
    require_once dirname(__DIR__) . '/src/Core/Application/BookingProvisioningRecoveryRule.php';
    require_once dirname(__DIR__) . '/src/Core/Application/EnrolmentWorkspaceReadService.php';

    use Delnavazan\Platform\Core\Application\EnrolmentWorkspaceReadService;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $service = new EnrolmentWorkspaceReadService();
    $method = new ReflectionMethod($service, 'bookingStage');
    $method->setAccessible(true);
    $stage = static function (array $confirmation = null, ?array $pending = null, array $coordination = array()) use ($method, $service): array {
        $row = (object) array(
            'privacy_erased_at' => null,
            'lifecycle_status' => 'submitted',
            'resolution_state' => 'unresolved',
            'student_id' => 12,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        );
        $coordination += array('state' => '', 'candidate_count' => 0, 'has_recorded_assent' => false);
        return $method->invoke($service, $row, array(), $coordination, $confirmation, null, array(), $pending);
    };

    $failed = $stage(array('state' => 'failed', 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600)));
    $assert($failed['key'] === 'provisioning_failed', 'A failed provider projection must use the provisioning-failed stage.');
    $assert($failed['label'] === 'تأیید مدرس انجام شده؛ ساخت کلاس ناموفق بود', 'The failed stage must use the required Persian status.');
    $assert($failed['next_action'] === 'تلاش مجدد برای ساخت کلاس', 'The failed stage must use the required Persian next action.');
    $assert(!str_contains($failed['next_action'], 'Teacher’s agreement'), 'An accepted booking must never ask for Teacher agreement again.');

    $pending = $stage(array('state' => 'retryable', 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600)));
    $assert($pending['key'] === 'provisioning_pending' && $pending['label'] === 'Creating classroom' && $pending['next_action'] === 'Monitor provisioning', 'A retryable projection must show classroom creation and monitoring.');

    $paid = $stage(array('state' => 'awaiting_payment', 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600)));
    $assert($paid['key'] === 'intro_payment_pending', 'A paid acceptance must show the payment gate rather than another Teacher request.');

    $cancelled = $stage(array('state' => 'cancelled', 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600)));
    $assert($cancelled['key'] === 'closed' && $cancelled['next_action'] === 'No further action; retraction and notice are recorded', 'A cancelled confirmation must never be presented as retryable.');

    $confirmed = $stage(array('state' => 'confirmed', 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600)));
    $assert($confirmed['key'] === 'intro_scheduled' && $confirmed['label'] === 'Trial scheduled', 'A confirmed classroom must remain the confirmed state.');

    $pendingDelivery = $stage(null, array('teacher_name' => 'Teacher A'), array('state' => 'open', 'candidate_count' => 2, 'has_recorded_assent' => false));
    $assert($pendingDelivery['key'] === 'teacher_match' && $pendingDelivery['label'] === 'Awaiting teacher response' && $pendingDelivery['next_action'] === 'Monitor the Teacher response', 'A pending delivery must outrank stale coordination candidates.');

    $coordinationOnly = $stage(null, null, array('state' => 'open', 'candidate_count' => 1, 'has_recorded_assent' => false));
    $assert($coordinationOnly['key'] === 'teacher_match' && $coordinationOnly['label'] === 'Awaiting coordination' && $coordinationOnly['next_action'] === 'Complete coordination', 'Unreviewed coordination must say awaiting coordination, not record agreement.');

    // The displayed recovery flag must use the same rule as the mutation.
    $confirmationMethod = new ReflectionMethod($service, 'confirmedBooking');
    $confirmationMethod->setAccessible(true);
    $confirmationRow = (object) array(
        'id' => 70, 'uid' => 'CONFIRM-70', 'booking_request_id' => 12, 'delivery_id' => 41,
        'teacher_id' => 10, 'requested_time_id' => 5, 'version' => 4, 'state' => 'failed',
        'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 86400),
        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 88200),
        'schedule_timezone' => 'Asia/Tehran', 'local_wall_date' => gmdate('Y-m-d'),
        'local_wall_time' => '10:00:00', 'provider_code' => 'google_calendar',
        'confirmed_at' => null, 'failure_reason_code' => 'meet_cohost_assignment_blocked',
        'attempt_count' => 6, 'next_attempt_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s'),
        'teacher_name' => 'Teacher A', 'course_name' => 'Intro',
        'delivery_record_id' => 41, 'delivery_record_state' => 'accepting',
        'delivery_booking_request_id' => 12, 'delivery_teacher_id' => 10,
        'delivery_requested_time_id' => 5,
        'request_lifecycle_status' => 'submitted', 'request_resolution_state' => 'unresolved',
        'request_privacy_erased_at' => null, 'live_offer' => 0,
    );
    $GLOBALS['wpdb']->confirmation = $confirmationRow;
    $eligible = $confirmationMethod->invoke($service, 12);
    $assert($eligible['recovery_eligible'] === true && $eligible['recovery_block_reason'] === null, 'The dashboard must offer retry only when the shared rule is eligible.');

    $GLOBALS['wpdb']->confirmation = (object) (array('live_offer' => 1) + (array) $confirmationRow);
    $liveOffer = $confirmationMethod->invoke($service, 12);
    $assert($liveOffer['recovery_eligible'] === false && $liveOffer['recovery_block_reason'] === 'allocation_superseded', 'A live re-offer must suppress the dashboard retry exactly as the mutation does.');

    $GLOBALS['wpdb']->confirmation = (object) (array('delivery_teacher_id' => 99) + (array) $confirmationRow);
    $mismatch = $confirmationMethod->invoke($service, 12);
    $assert($mismatch['recovery_eligible'] === false && $mismatch['recovery_block_reason'] === 'delivery_ownership_mismatch', 'A reassigned delivery must suppress the dashboard retry exactly as the mutation does.');

    $GLOBALS['wpdb']->confirmation = (object) (array('request_lifecycle_status' => 'closed') + (array) $confirmationRow);
    $inactive = $confirmationMethod->invoke($service, 12);
    $assert($inactive['recovery_eligible'] === false && $inactive['recovery_block_reason'] === 'request_not_active', 'An inactive request must suppress the dashboard retry exactly as the mutation does.');

    echo "Booking provisioning dashboard unit passed\n";
}
