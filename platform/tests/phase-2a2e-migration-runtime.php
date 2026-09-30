<?php
/** Disposable Schema 10 -> 11 and capability-repair validation. */
if(getenv('DZN_PHASE_2A2E_RUNTIME_TEST')!=='isolated'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-E migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
if((string)get_option('dzn_platform_schema_version')!=='10')throw new RuntimeException('Runtime must begin at Schema 10');
Migrator::maybe_upgrade();if((string)get_option('dzn_platform_schema_version')!=='11')throw new RuntimeException('Schema 10 -> 11 upgrade failed');
$done=(array)get_option('dzn_platform_completed_migrations',array());if(count(array_keys($done,'011_provisional_acceptance_evidence',true))!==1)throw new RuntimeException('Migration 011 completion is missing or duplicated');
if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.'proposal_acceptance_events'))!==$p.'proposal_acceptance_events')throw new RuntimeException('Acceptance table missing');
$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.'proposal_acceptance_events'));if(strcasecmp((string)$engine,'InnoDB')!==0)throw new RuntimeException('Acceptance table is not InnoDB');
Migrator::maybe_upgrade();$done=(array)get_option('dzn_platform_completed_migrations',array());if(count(array_keys($done,'011_provisional_acceptance_evidence',true))!==1)throw new RuntimeException('Repeated migration changed completion history');
$role=get_role('administrator');$role->remove_cap('dzn_record_booking_request_provisional_acceptance');update_option('dzn_platform_capability_version','repair-test',false);Migrator::maybe_upgrade();if(!$role->has_cap('dzn_record_booking_request_provisional_acceptance'))throw new RuntimeException('Acceptance capability repair failed');
echo "Phase 2A.2-E migration and capability runtime passed\n";
