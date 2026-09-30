<?php
/** Disposable Schema 9 -> 10, repeat migration and capability-repair validation. */
if ( getenv( 'DZN_PHASE_2A2D_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-D migration runtime refused.\n" ); exit( 1 ); }
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb; $p=$wpdb->prefix.'dzn_';
$before=(string)get_option('dzn_platform_schema_version'); if(!in_array($before,array('9','10'),true))throw new RuntimeException('Runtime must begin at Schema 9 or an already-upgraded Schema 10');
Migrator::maybe_upgrade();
if((string)get_option('dzn_platform_schema_version')!=='10')throw new RuntimeException('Schema 9 -> 10 upgrade failed');
$completed=(array)get_option('dzn_platform_completed_migrations',array()); if(count(array_keys($completed,'010_proposal_foundation',true))!==1)throw new RuntimeException('Migration 010 completion is missing or duplicated');
foreach(array('proposal_families','proposal_options','proposal_versions')as$table)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))!==$p.$table)throw new RuntimeException('Proposal table missing: '.$table);
Migrator::maybe_upgrade();
$completed=(array)get_option('dzn_platform_completed_migrations',array()); if(count(array_keys($completed,'010_proposal_foundation',true))!==1)throw new RuntimeException('Repeated migration changed completion history');
$role=get_role('administrator');if(!$role)throw new RuntimeException('Administrator role unavailable');$role->remove_cap('dzn_issue_booking_request_proposals');update_option('dzn_platform_capability_version','repair-test',false);Migrator::maybe_upgrade();if(!$role->has_cap('dzn_issue_booking_request_proposals'))throw new RuntimeException('Proposal capability repair failed');
echo "Phase 2A.2-D migration and capability runtime passed\n";
