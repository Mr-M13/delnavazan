<?php
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'isolated'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F concurrency cleanup refused.\n");
    exit(1);
}
delete_option('dzn_phase_2a2f_race_state');
echo "Phase 2A.2-F race state removed\n";
