<?php
/** Dashboard stages distinguish coordination, Teacher response, provisioning and failure. */
namespace {
    $GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
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

    echo "Booking provisioning dashboard unit passed\n";
}
