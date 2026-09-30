<?php
/**
 * Loads one synthetic, disposable Race A-D target prepared by the local fixture
 * builder into the state consumed by the separate WP-CLI workers.
 *
 * The fixture is deliberately an option rather than source data: every value is
 * synthetic and is removed by phase-2a2e-concurrency-cleanup.php.
 */
if(getenv('DZN_PHASE_2A2E_RUNTIME_TEST')!=='isolated'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-E concurrency setup refused.\n");exit(1);}
$mode=(string)getenv('DZN_PHASE_2A2E_MODE');if(!in_array($mode,array('a','b','c1','c2','d1','d2','x'),true))throw new RuntimeException('Unknown Phase 2A.2-E race mode');
$fixture=get_option('dzn_phase_2a2e_concurrency_fixture');if(!is_array($fixture)||($fixture['synthetic_domain']??'')!=='phase-2a2e.invalid')throw new RuntimeException('Only synthetic .invalid Phase 2A.2-E fixtures are accepted');$target=$fixture[$mode]??null;if(!is_array($target))throw new RuntimeException('Synthetic Phase 2A.2-E concurrency fixture unavailable: '.$mode);
foreach(array('family_uid','option_uid','version_number','prospective_subject_ref','request_id')as$field)if(empty($target[$field]))throw new RuntimeException('Concurrency fixture field missing: '.$field);
if(in_array($mode,array('c1','c2'),true))foreach(array('option_id','expected_version','replacement_fingerprint')as$field)if(empty($target[$field]))throw new RuntimeException('Revision fixture field missing: '.$field);
$first=$mode[0].'1';$second=$mode[0].'2';
$state=$target+array('mode'=>$mode,'holder'=>$first,'key'=>'dzn-2a2e-race-'.$mode.'-'.substr(hash('sha256',wp_generate_uuid4()),0,30),'evidence_at'=>gmdate('Y-m-d H:i:s'),'issuance_key'=>'dzn-2a2e-issuance-'.$mode.'-'.substr(hash('sha256',wp_generate_uuid4()),0,30),'subsequent_key'=>'dzn-2a2e-subsequent-'.$mode.'-'.substr(hash('sha256',wp_generate_uuid4()),0,30));
if(!isset($state[$first]))$state[$first]=array('channel'=>'message_reference');if(!isset($state[$second]))$state[$second]=array('channel'=>$mode==='b'||$mode==='x'?'phone':'message_reference');
update_option('dzn_phase_2a2e_race_state',$state,false);echo "Phase 2A.2-E {$mode} race setup passed\n";
