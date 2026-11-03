<?php
/** Booking notification health must surface verified mobile receipts without rewriting booking state. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$status = $read('src/Core/Application/NotificationDeliveryStatusReadService.php');
$workspace = $read('src/Core/Application/EnrolmentWorkspaceReadService.php');
$view = $read('src/Admin/View/EnrolmentWorkspaceView.php');
$runtime = $read('tests/booking-release-integration-runtime.php');
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$expect(str_contains($status, 'telnyx_messages') && str_contains($status, 'provider_receipt_state') && str_contains($status, 'provider_receipt_at') && str_contains($status, 'provider_channel'), 'Booking notification health must read the verified Telnyx message receipt.');
$expect(str_contains($status, "ORDER BY tm.id DESC LIMIT 1") && str_contains($status, 'tm.attempt_id=0'), 'Receipt lookup must select the latest booking-mobile correlation row.');
$expect(str_contains($workspace, "'provider_receipt_state'") && str_contains($workspace, "'provider_receipt_at'") && str_contains($workspace, "'provider_channel'"), 'The workspace projection must include provider receipt fields.');
$expect(str_contains($view, 'Provider receipt') && str_contains($view, 'no email delivery receipt is available'), 'The workspace must distinguish a verified mobile receipt from an email hand-off.');
$expect(str_contains($runtime, 'NotificationDeliveryStatusReadService') && str_contains($runtime, 'A provider receipt must not rewrite the booking notification state'), 'The receipt path needs a runtime proof that booking state is unchanged.');
echo "Booking notification receipt contract passed\n";
