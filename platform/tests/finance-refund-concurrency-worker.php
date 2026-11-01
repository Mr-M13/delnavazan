<?php
/** One real database-backed refund attempt in a separate PHP process. */
if (getenv('DZN_FINANCE_REFUND_CONCURRENCY_TEST') !== 'worker' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Refund concurrency worker refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\Finance\ManualRefundService;
global $wpdb;
$fixture = get_option('dzn_finance_refund_concurrency_fixture');
if (!is_array($fixture)) throw new RuntimeException('Refund concurrency fixture required');
$start = (string) getenv('DZN_REFUND_START_FILE');
$limit = microtime(true) + 30;
while ($start !== '' && !file_exists($start)) {
    if (microtime(true) > $limit) throw new RuntimeException('Refund concurrency start barrier timed out');
    usleep(50000);
}
wp_set_current_user(1);
$scenario = (string) getenv('DZN_REFUND_SCENARIO');
$sequence = $scenario === 'same' ? 1 : 2;
$input = array(
    'offer_uid' => (string) $fixture['offer_uid'],
    'obligation_sequence' => $sequence,
    'amount' => (string) getenv('DZN_REFUND_AMOUNT'),
    'evidence_reference' => (string) getenv('DZN_REFUND_EVIDENCE'),
    'refunded_on' => gmdate('Y-m-d'),
    'note' => 'Synthetic concurrency proof',
);
$out = array();
try {
    $result = (new ManualRefundService())->record($input, (string) getenv('DZN_REFUND_KEY'));
    $out = array('ok' => true, 'result' => $result);
} catch (\Throwable $error) {
    $out = array('ok' => false, 'error' => $error->getMessage());
}
$file = (string) getenv('DZN_REFUND_RESULT_FILE');
if ($file === '') throw new RuntimeException('Refund worker result file required');
file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES));
echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
