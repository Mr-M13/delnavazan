<?php
if (getenv('DZN_PHASE_2A2Y_RUNTIME_TEST') !== 'failure' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-Y failure runtime refused.\n"); exit(1); }
echo "phase-2a2y failure-runtime: OK\n";
