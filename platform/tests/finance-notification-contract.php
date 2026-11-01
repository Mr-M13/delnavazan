<?php
/** Source contract for durable Student finance notifications. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void { foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle); };
$service = $read('src/Core/Application/Finance/FinanceNotificationService.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$plugin = $read('delnavazan-platform.php');
$contains($migration, array('063_finance_notification_outbox','finance_notification_intents','event_key'), 'Schema 063 must persist finance notification intents');
$contains($service, array('event_key','STATE_PENDING','STATE_LEASED','STATE_TRANSPORT_ACCEPTED','STATE_RETRYABLE','STATE_FAILED','transport_accepted_at','delivered_at','DelnavazanEmailRenderer','payment_received','refund_recorded','refund_in_review','refund_completed','refund_dismissed','dzn_phase_2a2r1_after_settlement','dzn_phase_2a2r2_after_refund_review_event_insert'), 'Finance notifications must be durable, idempotent, retryable and use the existing branded renderer');
$assert(!str_contains($service, "'delivered_at'=>\$now") && !str_contains($service, "'delivered_at' => \$now"), 'A successful mail call must not be recorded as delivered');
$assert(str_contains($service, 'catch (\\Throwable)') && str_contains($service, 'never reverse'), 'Notification failures must never reverse a financial transaction');
$contains($plugin, array('FinanceNotificationService::register'), 'The finance notification worker must be registered');
echo "finance-notification-contract: OK\n";
