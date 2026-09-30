<?php
if(getenv('DZN_PHASE_2A2I_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-I concurrency setup refused.\n");exit(1);}
$mode=(string)getenv('DZN_PHASE_2A2I_MODE');if(!in_array($mode,array('a','b','u1','u2','p1','p2'),true))throw new RuntimeException('Unknown Phase I race');$f=get_option('dzn_phase_2a2i_fixture');if(!is_array($f))throw new RuntimeException('Phase I fixture missing');
$state=array('mode'=>$mode,'holder'=>'w1','key1'=>'dzn-2a2i-race-'.$mode.'-one-'.substr(hash('sha256',wp_generate_uuid4()),0,32),'key2'=>'dzn-2a2i-race-'.$mode.'-two-'.substr(hash('sha256',wp_generate_uuid4()),0,32));
if($mode==='a'){$state['one']=$f['race_a1'];$state['two']=$f['race_a2'];}
if($mode==='b'){$state['one']=$f['race_b'];$state['two']=$f['race_b'];}
if($mode==='u1'){$state['one']=$f['unrelated_a'];$state['two']=$f['unrelated_b'];}
if($mode==='u2'){$state['one']=$f['same_student_course1'];$state['two']=$f['same_student_course2'];}
if($mode==='p1'){$state['one']=$f['privacy_first'];$state['two']=$f['privacy_first'];}
if($mode==='p2'){$state['one']=$f['conversion_first'];$state['two']=$f['conversion_first'];}
update_option('dzn_phase_2a2i_race_state',$state,false);echo"Phase 2A.2-I {$mode} setup passed\n";
