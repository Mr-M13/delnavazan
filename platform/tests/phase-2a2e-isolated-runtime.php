<?php
/** Disposable runtime checks using the established synthetic Proposal fixture. */
if(getenv('DZN_PHASE_2A2E_RUNTIME_TEST')!=='isolated'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-E runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\ProposalAcceptanceService;
use Delnavazan\Platform\Core\Application\IdempotencyConflictException;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_2a2e_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$current=$wpdb->get_row("SELECT f.uid family_uid,o.uid option_uid,v.* FROM {$p}proposal_versions v INNER JOIN {$p}proposal_options o ON o.id=v.proposal_option_id AND o.current_version_id=v.id INNER JOIN {$p}proposal_families f ON f.id=o.proposal_family_id WHERE v.version_number=1 ORDER BY v.id DESC LIMIT 1");
$stale=$wpdb->get_row("SELECT f.uid family_uid,o.uid option_uid,v.* FROM {$p}proposal_versions v INNER JOIN {$p}proposal_options o ON o.id=v.proposal_option_id AND o.current_version_id<>v.id INNER JOIN {$p}proposal_families f ON f.id=o.proposal_family_id WHERE v.version_number=1 ORDER BY v.id DESC LIMIT 1");
dzn_2a2e_assert($current&&$stale,'Prepared Proposal lineage unavailable');$service=new ProposalAcceptanceService();$key='dzn-provisional-runtime-'.substr(hash('sha256',wp_generate_uuid4()),0,30);$at=gmdate('Y-m-d H:i:s');
$event=$service->record($current->family_uid,$current->option_uid,(int)$current->version_number,(string)$current->prospective_subject_ref,'message_reference',$at,$key);$replay=$service->record($current->family_uid,$current->option_uid,(int)$current->version_number,(string)$current->prospective_subject_ref,'message_reference',$at,$key);
dzn_2a2e_assert($event['created']&&!$replay['created']&&$replay['idempotent']&&$event['event_id']===$replay['event_id'],'Exact acceptance replay failed');
$conflict=false;try{$service->record($current->family_uid,$current->option_uid,(int)$current->version_number,(string)$current->prospective_subject_ref,'phone',$at,$key);}catch(IdempotencyConflictException){$conflict=true;}dzn_2a2e_assert($conflict,'Conflicting acceptance key was accepted');
$staleRejected=false;try{$service->record($stale->family_uid,$stale->option_uid,(int)$stale->version_number,(string)$stale->prospective_subject_ref,'phone',$at,'dzn-provisional-stale-'.substr(hash('sha256',wp_generate_uuid4()),0,30));}catch(Throwable){$staleRejected=true;}dzn_2a2e_assert($staleRejected,'Replaced Proposal Version accepted as new evidence');
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_acceptance_events WHERE id=%d",$event['event_id']),ARRAY_A);dzn_2a2e_assert($row['event_kind']==='accepted_pending_conditions'&&$row['accepting_subject_state']==='authority_unresolved'&&$row['version_fingerprint']===$current->version_fingerprint,'Immutable acceptance facts are incomplete');foreach(array('full_name','email','mobile','whatsapp','student_id','updated_at')as$field)dzn_2a2e_assert(!array_key_exists($field,$row),'Acceptance event contains prohibited field '.$field);
echo "Phase 2A.2-E isolated runtime passed\n";
