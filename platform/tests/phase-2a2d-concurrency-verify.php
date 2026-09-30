<?php
/** Verifies that revision has no fork and invalidation cannot race past authority. */
if ( getenv( 'DZN_PHASE_2A2D_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-D concurrency verification refused.\n" ); exit( 1 ); }
global $wpdb; $p=$wpdb->prefix.'dzn_'; $state=get_option('dzn_phase_2a2d_race_state'); if(!is_array($state))throw new RuntimeException('Race state unavailable');
$versions=$wpdb->get_results($wpdb->prepare("SELECT id,version_number,supersedes_proposal_version_id FROM {$p}proposal_versions WHERE proposal_option_id=%d ORDER BY version_number",$state['option_id']));
if(count($versions)>2)throw new RuntimeException('Proposal revision fork created extra Versions');
if(count($versions)===2 && ((int)$versions[1]->version_number!==2 || (int)$versions[1]->supersedes_proposal_version_id!==(int)$versions[0]->id))throw new RuntimeException('Proposal revision lineage invalid');
if($state['mode']==='revision' && count($versions)!==2)throw new RuntimeException('Concurrent revision produced no winner');
if($state['mode']==='invalidation'){
    $assent=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}teacher_availability_assents WHERE id=%d",$state['replacement_assent_id']));
    if(!$assent||$assent->state!=='invalidated')throw new RuntimeException('Assent invalidation did not commit');
    $invalidAudit=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}platform_audit_events WHERE aggregate_type='teacher_availability_assent' AND aggregate_id=%d AND event_type='teacher_availability_assent.invalidated' ORDER BY id DESC LIMIT 1",$state['replacement_assent_id']));
    if(count($versions)===2){$proposalAudit=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}platform_audit_events WHERE aggregate_type='proposal_version' AND aggregate_id=%d ORDER BY id DESC LIMIT 1",$versions[1]->id));if($proposalAudit<1||$invalidAudit<1||$proposalAudit>=$invalidAudit)throw new RuntimeException('Proposal was issued after Assent invalidation won the lock');}
}
echo "Phase 2A.2-D {$state['mode']} concurrency passed\n";
