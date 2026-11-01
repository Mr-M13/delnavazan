<?php
if (getenv('DZN_FINANCE_REFUND_CONCURRENCY_TEST') !== 'prepare' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Refund concurrency prepare refused.\n"); exit(1); }
$dir = '/var/www/html/refund-results';
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) throw new RuntimeException('Refund result directory unavailable');
foreach (glob($dir . '/*.json') ?: array() as $file) @unlink($file);
@unlink($dir . '/start');
echo "Refund concurrency result directory ready\n";
