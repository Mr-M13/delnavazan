<?php
/** Database assertions for the deterministic local Race A-D controller. */
if(getenv('DZN_PHASE_2A2E_RUNTIME_TEST')!=='isolated'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-E concurrency verification refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\AcceptanceIdempotency;
use Delnavazan\Platform\Core\Application\ProposalAcceptanceService;
function dzn_2a2e_race_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
global $wpdb;$p=$wpdb->prefix.'dzn_';$state=get_option('dzn_phase_2a2e_race_state');if(!is_array($state))throw new RuntimeException('Concurrency state unavailable');$mode=(string)$state['mode'];$digest=AcceptanceIdempotency::keyDigest((string)$state['key']);$events=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}proposal_acceptance_events WHERE command_key_digest=%s",$digest));
if(in_array($mode,array('a','b','x'),true))dzn_2a2e_race_assert($events===1,'Command-key race did not leave exactly one authoritative event');
if(in_array($mode,array('c1','d1'),true))dzn_2a2e_race_assert($events===0,'Invalidated or erased Request accepted new evidence');
if($mode==='c1'||$mode==='c2'){ $versions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}proposal_versions WHERE proposal_option_id=%d",(int)$state['option_id']));dzn_2a2e_race_assert($versions===2,'Proposal revision race did not produce one linear successor'); }
if($mode==='d1'||$mode==='d2'){ $request=$wpdb->get_row($wpdb->prepare("SELECT privacy_erased_at,resolution_state FROM {$p}booking_requests WHERE id=%d",(int)$state['request_id']));dzn_2a2e_race_assert($request&&$request->privacy_erased_at!==null&&$request->resolution_state==='privacy_erased','Privacy erasure did not commit'); }
if($mode==='d2'){ $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_acceptance_events WHERE command_key_digest=%s",$digest));dzn_2a2e_race_assert($event&&$event->event_kind==='accepted_pending_conditions'&&$event->accepting_subject_state==='authority_unresolved','Pre-erasure acceptance evidence changed');$rejected=false;try{(new ProposalAcceptanceService())->record($state['family_uid'],$state['option_uid'],(int)$state['version_number'],$state['prospective_subject_ref'],'message_reference',$state['evidence_at'],$state['subsequent_key']);}catch(Throwable){$rejected=true;}dzn_2a2e_race_assert($rejected,'New acceptance bypassed erased Request authority');}
echo "Phase 2A.2-E {$mode} concurrency passed\n";
