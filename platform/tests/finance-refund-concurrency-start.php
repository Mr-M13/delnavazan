<?php
if (getenv('DZN_FINANCE_REFUND_CONCURRENCY_TEST') !== 'start' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Refund concurrency start refused.\n"); exit(1); }
$file = '/var/www/html/refund-results/start';
if (file_put_contents($file, '1') === false) throw new RuntimeException('Refund start barrier unavailable');
echo "Refund concurrency start barrier released\n";
