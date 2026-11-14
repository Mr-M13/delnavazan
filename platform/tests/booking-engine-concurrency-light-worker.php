<?php
/** Standalone high-concurrency worker using the test-only SHORTINIT bootstrap. */
define('WP_CLI', true);
require __DIR__ . '/booking-engine-concurrency-light-bootstrap.php';
require __DIR__ . '/booking-engine-concurrency-worker.php';
