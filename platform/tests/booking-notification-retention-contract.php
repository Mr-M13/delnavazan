<?php
/** Historical failed notifications must close by audit only, never by replay. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$service = $read('src/Core/Application/BookingNotificationRetentionService.php');
$runtime = $read('tests/booking-notification-recovery-runtime.php');
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$expect(str_contains($service, "CAPABILITY = 'dzn_operate_notification_dispatch'") && str_contains($service, 'current_user_can'), 'Historical closure must be capability-gated.');
$expect(str_contains($service, "HISTORICAL_CUTOFF = '2026-11-03 05:22:00'") && str_contains($service, "notification_not_historical") && str_contains($service, "notification_not_failed"), 'Historical closure must be limited to pre-fix failed notifications.');
$expect(str_contains($service, "'state' => 'closed'") && str_contains($service, "'last_error_code' => 'historical_stale'") && str_contains($service, 'notification_closed') && str_contains($service, 'insertAudit'), 'Historical closure must record a closed state and an audit event.');
$expect(!str_contains($service, 'queueNotification') && !str_contains($service, 'wp_mail') && !str_contains($service, 'sendWhatsApp') && !str_contains($service, 'sendSms') && !str_contains($service, 'transitionDelivery') && !str_contains($service, 'cancelRequest'), 'Historical closure must never send or change booking/delivery state.');
$expect(str_contains($runtime, 'historical_close') && str_contains($runtime, 'notification_not_historical') && str_contains($runtime, 'notification_not_failed'), 'Historical closure needs a DB-backed refusal and no-send proof.');
echo "Booking notification retention contract passed\n";
