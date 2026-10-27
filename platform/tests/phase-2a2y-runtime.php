<?php
if (getenv('DZN_PHASE_2A2Y_RUNTIME_TEST') !== 'runtime' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-Y runtime refused.\n"); exit(1); }
echo "phase-2a2y runtime: OK\n";
