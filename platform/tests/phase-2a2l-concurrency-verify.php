<?php
/** Verify final authority and prove a fresh post-terminal successor remains allowed. */
if(getenv('DZN_PHASE_2A2L_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI)exit(1);
use Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService;
global$wpdb;$p=$wpdb->prefix.'dzn_';$state=get_option('dzn_phase_2a2l_race_state');$mode=(string)($state['mode']??'');
if(in_array($mode,array('close_create','cancel_create'),true)){
    $terms=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}terms WHERE enrolment_id=%d ORDER BY sequence_number,id",(int)$state['one']))?:array();
    if(count($terms)!==1)throw new RuntimeException('Stale successor authority was created');
    $createCommands=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}term_commands WHERE enrolment_id=%d AND operation='create'",(int)$state['one']));
    if($createCommands!==1)throw new RuntimeException('Stale successor command evidence survived');
    $terminal=$mode==='close_create'?'closed':'cancelled';if((string)$terms[0]->lifecycle_state!==$terminal)throw new RuntimeException('Terminal winner missing');
    $evidence=array('evidence_channel'=>'staff_record','evidence_reference'=>'post-terminal-fresh','evidence_at'=>gmdate('Y-m-d H:i:s'));
    $result=(new CanonicalTermAuthorityService())->create((int)$state['one'],(int)$terms[0]->id,$terminal,$evidence,'phase-l-post-terminal-'.wp_generate_uuid4());
    if(!$result['created'])throw new RuntimeException('Fresh post-terminal successor rejected');
    echo "stale_successor_absent=pass\npost_terminal_successor=pass\n";
}
echo "concurrency_final_state=pass\n";
