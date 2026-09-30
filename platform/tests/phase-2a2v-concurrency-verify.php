<?php
/**
 * Disposable Phase-V concurrency verifier: reads both worker outcomes and the shared durable state.
 *
 * The verifier never re-runs a command. It proves the mode's invariant from the recorded rows and the
 * workers' own outcomes: exactly one active connection, exactly-once authorization consumption, a
 * projection that either completed against the applicable version or failed closed, a Lesson whose
 * canonical state is exactly what the canonical authority recorded, one provider-event receipt for
 * one provider key with at most one conflict receipt, a mapping revocation that cannot widen any
 * authority, an archived Teacher that can never be given a new provider write, and full independence
 * between two unrelated Teachers.
 */
if(getenv('DZN_PHASE_2A2V_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-V concurrency verifier refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_vcv_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$mode=(string)getenv('DZN_PHASE_2A2V_MODE');
$gate=(string)getenv('DZN_PHASE_2A2V_GATE_DIR');
$state=get_option('dzn_phase_2a2v_concurrency');
dzn_vcv_assert(is_array($state)&&(string)$state['mode']===$mode,'Phase V concurrency setup state missing');
$outcome=static function(string $worker) use($gate):array{
    $file=$gate.'/'.$worker.'.result';
    dzn_vcv_assert(is_file($file),'Missing '.$worker.' outcome');
    $decoded=json_decode((string)file_get_contents($file),true);
    dzn_vcv_assert(is_array($decoded),'Malformed '.$worker.' outcome');
    return $decoded;
};
$w1=$outcome('w1');$w2=$outcome('w2');
$count=static function(string $table,string $where='1=1',array $args=array()) use($wpdb,$p):int{
    $sql="SELECT COUNT(*) FROM {$p}{$table} WHERE ".$where;
    return(int)($args?$wpdb->get_var($wpdb->prepare($sql,...$args)):$wpdb->get_var($sql));
};
$activeFor=static function(int $teacher) use($count):int{return $count('integration_connections','teacher_id=%d AND active_slot=1',array($teacher));};
$teacherId=(int)$state['teacher_id'];

if($mode==='connect_vs_revoke'){
    dzn_vcv_assert($activeFor($teacherId)===1,'exactly one active connection may exist after connect vs revoke');
    dzn_vcv_assert($count('integration_credentials','state=%s',array('active'))<=$count('integration_connections'),'every active credential must belong to an existing connection');
    dzn_vcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT connection_state FROM {$p}integration_connections WHERE id=%d",(int)$state['connection_id']))!=='connected','the revoked connection must never stay connected');
}elseif($mode==='authorization_replay'){
    dzn_vcv_assert($activeFor($teacherId)===1,'exactly one active connection may exist after an authorization replay');
    dzn_vcv_assert($count('provider_integration_commands',"operation='complete_authorization'")===1,'an authorization state may be consumed exactly once');
    dzn_vcv_assert($count('integration_credentials','state=%s',array('active'))===1,'an authorization replay must not mint a second active credential');
    dzn_vcv_assert($count('integration_oauth_authorizations',"authorization_state='consumed'")===1,'exactly one authorization intent may be consumed');
    dzn_vcv_assert($count('integration_oauth_authorizations',"authorization_state='issued'")===0,'a consumed authorization may never stay pending');
    dzn_vcv_assert($w1['ok']===true&&$w2['ok']===true,'both contenders must converge on the recorded completion');
    dzn_vcv_assert((int)($w2['outcome']['connection_id']??0)===(int)($w1['outcome']['connection_id']??0),'a replayed completion must report the identical connection');
}elseif($mode==='projection_vs_release'){
    dzn_vcv_assert(\Delnavazan\Platform\Core\Application\CanonicalLessonScheduleValidator::validForLesson((int)$state['lesson_id']),'the canonical schedule aggregate must remain valid after the race');
    dzn_vcv_assert($count('canonical_lesson_schedule_versions','lesson_id=%d',array((int)$state['lesson_id']))>=(int)$state['baseline']['versions'],'the release must never remove a canonical schedule version');
    $mapping=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_calendar_event_mappings WHERE lesson_id=%d AND active_slot=1",(int)$state['lesson_id']));
    if($mapping){
        $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$mapping->schedule_version_id));
        dzn_vcv_assert($version!==null,'a projection may only reference an existing canonical schedule version');
        dzn_vcv_assert((string)$mapping->starts_at_utc===(string)$version->starts_at_utc&&(string)$mapping->ends_at_utc===(string)$version->ends_at_utc,'a projection must mirror the exact interval of the version it names');
    }else{
        dzn_vcv_assert($w1['ok']===false,'a projection against a stale version must fail closed rather than disappear silently');
    }
    dzn_vcv_assert((int)$count('provider_integration_commands',"lesson_id=%d AND operation='project_calendar_event'",array((int)$state['lesson_id']))<=1,'at most one calendar projection command may be recorded for the occurrence');
}elseif($mode==='projection_vs_completion'){
    $lesson=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",(int)$state['lesson_id']));
    dzn_vcv_assert($lesson!==null,'the canonical Lesson must still exist');
    $projectionCommands=$count('provider_integration_commands',"lesson_id=%d AND operation IN ('project_calendar_event','project_meeting_conference')",array((int)$state['lesson_id']));
    dzn_vcv_assert($projectionCommands<=1,'an integration layer must never record more than one projection command per occurrence');
    // The integration layer owns no Lesson column: a completed Lesson must be exactly what the
    // canonical authority recorded, and no V row may claim a Lesson-lifecycle operation.
    dzn_vcv_assert((int)$count('provider_integration_commands',"operation LIKE %s",array('%lesson%'))===0,'the integration layer may never record a Lesson-lifecycle operation');
    dzn_vcv_assert(in_array((string)$lesson->lifecycle_state,array('scheduled','completed','cancelled'),true),'the canonical Lesson state must be a controlled canonical state');
}elseif($mode==='duplicate_vs_conflicting_event'){
    $receipts=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}provider_ingest_events WHERE lesson_id=%d",(int)$state['lesson_id']),ARRAY_A)?:array();
    dzn_vcv_assert(count($receipts)===1,'one provider event key must leave exactly one integration receipt');
    $receiptId=(int)$receipts[0]['id'];
    $conflicts=$count('provider_event_conflicts','lesson_id=%d',array((int)$state['lesson_id']));
    $conflicted=$w2['ok']===true&&is_array($w2['outcome']??null)&&!empty($w2['outcome']['conflict']);
    if($conflicted)dzn_vcv_assert($conflicts===1,'a conflicting duplicate must leave exactly one conflict receipt');
    else dzn_vcv_assert($conflicts===0,'an exact duplicate must converge without a conflict receipt');
    dzn_vcv_assert((int)$receipts[0]['event_sequence']>0,'a converged provider event must keep its original receipt fields');
    // Duplicate-retry invariant: handoff-outcome allocation is serialised on the parent receipt, so two
    // concurrent retries of one received event can never choose the same attempt number — each contender
    // is answered from its own contiguous attempt, and none may receive a duplicate-key persistence
    // failure in place of converging on the outcome the other contender recorded.
    $attempts=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT handoff_attempt FROM {$p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d ORDER BY handoff_attempt",$receiptId))?:array());
    if($attempts)dzn_vcv_assert($attempts===range(1,count($attempts)),'handoff-outcome attempts must be allocated contiguously for one receipt');
    foreach(array('w1'=>$w1,'w2'=>$w2) as $worker=>$contender)
        dzn_vcv_assert(!str_contains((string)($contender['message']??''),'Duplicate entry'),$worker.' must never be answered with a duplicate-key persistence failure');
    if($w1['ok']===true&&$w2['ok']===true&&!$conflicted){
        dzn_vcv_assert($count('provider_ingest_outcomes','provider_ingest_event_id=%d AND outcome=%s',array($receiptId,'admitted'))>=1,'converged duplicate retries must leave a recorded admission');
        dzn_vcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT outcome FROM {$p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d ORDER BY handoff_attempt DESC,id DESC LIMIT 1",$receiptId))==='admitted','the effective outcome of a converged duplicate must be the recorded admission');
    }
}elseif($mode==='cross_lesson_event_key_race'){
    // One provider event key delivered twice — same instant, materially changed context, two different
    // Lessons. The delivery that lost the race may never be answered with a rejection or a duplicate-key
    // persistence failure: exactly one immutable receipt survives, for the Lesson that won, and the
    // loser's changed context must be durably recorded as the one conflict receipt for that key.
    $lessonA=(int)$state['lesson_id'];$lessonB=(int)($state['occurrence_b']['lesson_id']??0);
    dzn_vcv_assert($lessonB>0&&$lessonB!==$lessonA,'the race must name two distinct canonical Lessons');
    $receipts=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}provider_ingest_events WHERE provider_code=%s AND lesson_id=%d",'google_meet',$lessonA),ARRAY_A)?:array();
    $conflicts=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}provider_event_conflicts WHERE provider_code=%s AND lesson_id=%d",'google_meet',$lessonB),ARRAY_A)?:array();
    dzn_vcv_assert(count($receipts)===1,'one provider event key must leave exactly one immutable receipt');
    dzn_vcv_assert($count('provider_ingest_events','lesson_id=%d',array($lessonB))===0,'the losing delivery may never add a second receipt for the same provider event key');
    dzn_vcv_assert(count($conflicts)===1,'a materially changed context must leave exactly one durable conflict receipt');
    dzn_vcv_assert((int)$conflicts[0]['provider_ingest_event_id']===(int)$receipts[0]['id'],'the conflict receipt must name the immutable receipt it diverged from');
    dzn_vcv_assert(hash_equals((string)$receipts[0]['provider_event_key_digest'],(string)$conflicts[0]['provider_event_key_digest']),'the conflict receipt must belong to the provider event key of that receipt');
    dzn_vcv_assert((string)$conflicts[0]['conflict_kind']==='cross_lesson','the conflict receipt must classify the divergence as cross_lesson');
    dzn_vcv_assert((int)$receipts[0]['event_sequence']>0,'a converged provider event must keep its original receipt fields');
    dzn_vcv_assert($w2['ok']===true&&is_array($w2['outcome']??null)&&!empty($w2['outcome']['conflict']),'a changed context must be durably recorded as a conflict, never merely rejected');
    dzn_vcv_assert((string)($w2['outcome']['processing_state']??'')==='conflicted','the losing delivery must report the conflict the contract requires');
    dzn_vcv_assert((int)($w2['outcome']['conflict_id']??0)===(int)$conflicts[0]['id'],'the losing delivery must report the conflict receipt it recorded');
    dzn_vcv_assert((int)($w2['outcome']['ingest_event_id']??0)===(int)$receipts[0]['id'],'the losing delivery must report the immutable receipt it diverged from');
    dzn_vcv_assert(empty($w1['outcome']['conflict']),'the holder must not be answered with a conflict for its own first delivery');
    foreach(array('w1'=>$w1,'w2'=>$w2) as $worker=>$contender){
        $message=(string)($contender['message']??'');
        dzn_vcv_assert(!str_contains($message,'Duplicate entry'),$worker.' must never be answered with a duplicate-key persistence failure');
        dzn_vcv_assert(stripos($message,'idempotency')===false,$worker.' must never reduce a materially changed context to a bare idempotency conflict');
    }
}elseif($mode==='mapping_revoke_vs_ingest'){
    dzn_vcv_assert($count('integration_connections','teacher_id=%d AND active_slot=1',array($teacherId))===1,'a mapping revocation must never disturb the active connection');
    dzn_vcv_assert($count('provider_identity_mappings','id=%d AND mapping_state=%s',array((int)$state['identity_mapping_id'],'revoked'))===1,'the identity mapping must be recorded as revoked');
    dzn_vcv_assert($count('provider_identity_mappings','teacher_id=%d AND mapping_state=%s',array($teacherId,'verified'))===0,'a revoked mapping may never stay verified');
    dzn_vcv_assert($count('provider_ingest_events','lesson_id=%d',array((int)$state['lesson_id']))<=1,'the concurrent ingest must leave at most one integration receipt');
}elseif($mode==='provider_event_sequence_race'){
    // Two distinct event keys for two different Lessons, delivered together: the canonical chains are
    // disjoint, so the only shared resource is the provider-scoped receipt sequence. Both immutable
    // receipts must exist, each with its own sequence, and neither contender may be answered with a
    // duplicate-key persistence failure for a delivery that never collided on its own event key.
    $lessonA=(int)$state['lesson_id'];$lessonB=(int)($state['occurrence_b']['lesson_id']??0);
    dzn_vcv_assert($lessonB>0&&$lessonB!==$lessonA,'the race must name two distinct canonical Lessons');
    $receipts=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}provider_ingest_events WHERE provider_code=%s AND lesson_id IN (%d,%d) ORDER BY event_sequence,id",'google_meet',$lessonA,$lessonB),ARRAY_A)?:array();
    dzn_vcv_assert(count($receipts)===2,'each distinct provider event key must leave exactly one immutable receipt');
    $sequences=array_map(static fn(array $row)=>(int)$row['event_sequence'],$receipts);
    dzn_vcv_assert(!in_array(0,$sequences,true)&&count(array_unique($sequences))===2,'concurrent distinct deliveries must take distinct provider-scoped sequences');
    dzn_vcv_assert(count(array_unique(array_map(static fn(array $row)=>(int)$row['lesson_id'],$receipts)))===2,'the two receipts must belong to the two raced Lessons');
    sort($sequences);
    dzn_vcv_assert($sequences===range(1,2),'the raced receipts must take the two next provider sequences without a gap or a collision');
    foreach(array('w1'=>$w1,'w2'=>$w2) as $worker=>$contender){
        $message=(string)($contender['message']??'');
        dzn_vcv_assert(!str_contains($message,'Duplicate entry'),$worker.' must never be answered with a duplicate-key persistence failure');
        dzn_vcv_assert(!str_contains($message,'provider_event_sequence_lock_unavailable'),$worker.' must never fail to serialise its provider-scoped receipt allocation');
    }
}elseif($mode==='ingest_vs_canonical_authority'){
    // The canonical schedule authority held the complete canonical chain while the provider ingest took
    // the same chain in the same declared order. The canonical operation named a version that is not
    // applicable, so it must fail closed with its own controlled reason and move nothing; the ingest
    // must still leave its immutable receipt, and neither contender may report a deadlock or a lock-wait
    // timeout in place of the outcome its own authority recorded.
    $lessonId=(int)$state['lesson_id'];
    dzn_vcv_assert($w1['ok']===false,'the competing canonical operation must fail closed');
    dzn_vcv_assert((string)($w1['message']??'')==='stale_schedule_version','the canonical authority must refuse the stale release with its own controlled reason');
    dzn_vcv_assert((int)$count('canonical_lesson_schedule_versions','lesson_id=%d',array($lessonId))===(int)$state['baseline']['versions'],'a failed canonical operation must never change the canonical schedule aggregate');
    dzn_vcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1",$lessonId))===(int)$state['baseline']['applicable_version'],'the applicable canonical version must remain exactly the one the baseline recorded');
    dzn_vcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d",$lessonId))===(string)$state['baseline']['lesson_state'],'the canonical Lesson state must remain exactly what the canonical authority recorded');
    dzn_vcv_assert((int)$count('provider_integration_commands','lesson_id=%d',array($lessonId))===0,'the integration layer must never record a Lesson-lifecycle or schedule operation');
    $receipts=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}provider_ingest_events WHERE lesson_id=%d",$lessonId),ARRAY_A)?:array();
    dzn_vcv_assert(count($receipts)===1&&(int)$receipts[0]['event_sequence']>0,'the cross-authority ingest must still leave exactly one immutable receipt with its own sequence');
    foreach(array('w1'=>$w1,'w2'=>$w2) as $worker=>$contender){
        $message=(string)($contender['message']??'');
        dzn_vcv_assert(!str_contains($message,'Deadlock found')&&!str_contains($message,'Lock wait timeout'),$worker.' must never be answered with a lock-order deadlock or a lock-wait timeout');
    }
}elseif($mode==='teacher_archival_vs_connection'){
    $teacher=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teachers WHERE id=%d",$teacherId));
    dzn_vcv_assert($teacher!==null,'the Teacher must still exist');
    if($teacher->archived_at!==null){
        dzn_vcv_assert($count('integration_connections','teacher_id=%d AND created_at>%s',array($teacherId,(string)$teacher->archived_at))===0,'no connection may be created after the Teacher was archived');
    }
}elseif($mode==='unrelated_teacher'){
    $other=(int)$state['other_teacher_id'];
    dzn_vcv_assert($activeFor($teacherId)===1,'Teacher A must keep exactly one active connection');
    dzn_vcv_assert($activeFor($other)===1,'Teacher B must keep exactly one active connection');
    dzn_vcv_assert($count('provider_integration_commands','teacher_id=%d',array($teacherId))>0,'Teacher A must have recorded its own integration commands');
    dzn_vcv_assert($count('provider_integration_commands','teacher_id=%d',array($other))>0,'Teacher B must have recorded its own integration commands');
    dzn_vcv_assert(is_file($gate.'/w2.independent'),'an unrelated Teacher must complete without waiting on the held transaction');
}else{
    throw new RuntimeException('Unknown Phase V concurrency mode');
}
echo "Phase 2A.2-V concurrency verified: ".$mode."\n";
