<?php
/** Disposable proof for durable finance notification intents, retries and transport semantics. */
if (getenv('DZN_FINANCE_NOTIFICATION_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) { fwrite(STDERR, "Finance notification runtime refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\Finance\FinanceNotificationService;
function dzn_fin_notify_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
global $wpdb; $p = $wpdb->prefix . 'dzn_';
wp_set_current_user(1);
$wpdb->query("DELETE FROM {$p}finance_notification_intents");
$service = new FinanceNotificationService();
$first = $service->enqueue('runtime:payment:1', 'payment_received', 1, 'finance-runtime@example.com', 'fa', 'پرداخت ثبت شد', 'متن آزمایشی');
dzn_fin_notify_assert(!empty($first['queued']) && (string)$first['state'] === 'pending', 'A notification must be queued as pending');
$duplicate = $service->enqueue('runtime:payment:1', 'payment_received', 1, 'finance-runtime@example.com', 'fa', 'پرداخت ثبت شد', 'متن آزمایشی');
dzn_fin_notify_assert(!empty($duplicate['queued']) && (string)$duplicate['reason'] === 'already_queued', 'A duplicate event key must not create a second notification');
$ok = static fn() => true; $fail = static fn() => false;
add_filter('pre_wp_mail', $ok, 10, 1);
$run = $service->processDue(5);
remove_filter('pre_wp_mail', $ok, 10);
dzn_fin_notify_assert((int)($run['transport_accepted'] ?? 0) === 1, 'The worker must record transport acceptance');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_notification_intents WHERE event_key=%s", 'runtime:payment:1'));
dzn_fin_notify_assert($row !== null && (string)$row->state === 'transport_accepted' && $row->delivered_at === null, 'Transport acceptance is not confirmed delivery');
$wpdb->update($p.'finance_notification_intents', array('state'=>'retryable','next_attempt_at'=>gmdate('Y-m-d H:i:s'),'transport_accepted_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null), array('event_key'=>'runtime:payment:1'));
add_filter('pre_wp_mail', $fail, 10, 1);
$failed = $service->processDue(5);
remove_filter('pre_wp_mail', $fail, 10);
dzn_fin_notify_assert((int)($failed['retryable'] ?? 0) === 1, 'A transport failure must remain retryable');
$wpdb->update($p.'finance_notification_intents', array('next_attempt_at'=>gmdate('Y-m-d H:i:s')), array('event_key'=>'runtime:payment:1'));
add_filter('pre_wp_mail', $ok, 10, 1);
$retry = $service->processDue(5);
remove_filter('pre_wp_mail', $ok, 10);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_notification_intents WHERE event_key=%s", 'runtime:payment:1'));
dzn_fin_notify_assert((int)($retry['transport_accepted'] ?? 0) === 1 && (string)$row->state === 'transport_accepted' && (int)$row->attempt_count === 3, 'A retry must converge on transport acceptance and retain the attempt count');
echo "Finance notification runtime: PASS\n";
