<?php
/** Phase 2A.2-G static final-acceptance, immutability, privacy and lock guard. */
$root=dirname(__DIR__);
$service=file_get_contents($root.'/src/Core/Application/FinalAcceptanceService.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/AcceptedServiceArrangementRepository.php');
$proposal=file_get_contents($root.'/src/Core/Application/ProposalService.php');
$proposalRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/ProposalRepository.php');
$authorityRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/StudentIdentityAuthorityRepository.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$controller=file_get_contents($root.'/src/Admin/Controller/CoordinationCaseController.php');
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$raceSetup=file_get_contents($root.'/tests/phase-2a2g-concurrency-setup.php');
$raceWorker=file_get_contents($root.'/tests/phase-2a2g-concurrency-worker.php');
$raceVerify=file_get_contents($root.'/tests/phase-2a2g-concurrency-verify.php');
$raceRunner=file_get_contents($root.'/tests/phase-2a2g-concurrency-runner.sh');
function phase_2a2g_method_body(string $source,string $name):string{
    $tokens=token_get_all($source);$named=false;$waiting=false;$depth=0;$body='';
    foreach($tokens as$i=>$token){
        if(is_array($token)&&$token[0]===T_FUNCTION){$named=false;$waiting=true;continue;}
        if($waiting&&is_array($token)&&$token[0]===T_STRING){$named=$token[1]===$name;$waiting=false;continue;}
        if(!$named)continue;$text=is_array($token)?$token[1]:$token;
        if($text==='{'){$depth++;if($depth===1)continue;}
        if($depth>0)$body.=$text;
        if($text==='}'&&--$depth===0)return substr($body,0,-1);
    }
    return '';
}
function phase_2a2g_compact_body(string $source,string $name):string{return preg_replace('/\s+/','',phase_2a2g_method_body($source,$name))??'';}
function phase_2a2g_assert_authority_semantics(string $authorityRepo,string $service):void{
    $membership=phase_2a2g_compact_body($authorityRepo,'revalidateAuthorityDiscovery');
    $membershipGuard='if((int)$row->student_id!==$studentId&&!in_array((int)$row->{$userColumn},$userIds,true))thrownew\\RuntimeException';
    if($membership===''||!str_contains($membership,$membershipGuard))throw new RuntimeException('Locked authority rows must remain within the discovered Student-or-user membership');
    $principal=phase_2a2g_compact_body($service,'activePrincipal');
    foreach(array('(int)$row->student_id===$studentId','(int)$row->wordpress_user_id===$userId','$row->status===\'active\'','(int)$row->active_slot===1')as$predicate)if(!str_contains($principal,$predicate))throw new RuntimeException('Active principal semantic predicate missing: '.$predicate);
    $guardian=phase_2a2g_compact_body($service,'activeGuardian');
    foreach(array('(int)$row->student_id===$studentId','(int)$row->acting_wordpress_user_id===$userId','$row->authority_type===\'guardian_representative\'','$row->authority_scope===\'service_acceptance\'','$row->state===\'active\'','(int)$row->active_slot===1','$row->effective_from<=$now','($row->effective_until===null||$row->effective_until>$now)')as$predicate)if(!str_contains($guardian,$predicate))throw new RuntimeException('Active guardian semantic predicate missing: '.$predicate);
    $accept=phase_2a2g_compact_body($service,'acceptLocked');
    if(!str_contains($accept,'if($capacity->classification===\'adult\'){if(!$principal)thrownew\\InvalidArgumentException(\'Adultselfauthorityisnolongercurrent\')'))throw new RuntimeException('Adult final acceptance must reject absent current principal authority');
    if(!str_contains($accept,'else{if(!$grant)thrownew\\InvalidArgumentException(\'Guardianrepresentativeauthorityisnolongercurrent\')'))throw new RuntimeException('Minor final acceptance must reject absent current guardian authority');
}
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<13)throw new RuntimeException('Missing compatible Phase 2A.2-G+ schema marker');
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing compatible Phase 2A.2-G+ build identity');
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing compatible Phase 2A.2-G+ build marker');
foreach(array('013_final_acceptance_arrangement_foundation','accepted_service_arrangements','proposal_option_outcome_events','verify_final_acceptance_arrangement_schema','dzn_finalize_service_arrangements','ENGINE=InnoDB')as$n)if(!str_contains($migration.$plugin,$n))throw new RuntimeException('Missing Phase 2A.2-G foundation: '.$n);
foreach(array('affirmed','accepted_pending_conditions','authority_unresolved','adult_self','guardian_representative','service_acceptance','arrangementForCommand','command_payload_digest','IdempotencyConflictException','ProposalFamilyAlreadyAcceptedException','closed_competing','dzn_phase_2a2g_proposal_locks_held','dzn_phase_2a2g_authority_locks_held')as$n)if(!str_contains($service.$repo,$n))throw new RuntimeException('Missing final-acceptance invariant: '.$n);
foreach(array('requestForUpdate','caseForUpdate','familyForCaseForUpdate','familyOptionsForUpdate','currentVersionsForOptionsForUpdate','provisionalForUidForUpdate','studentForUpdate','resolutionByIdForUpdate','capacityByIdForUpdate','wordpressUserForUpdate','lockPrincipalsForAuthority','lockGrantsForAuthority')as$n)if(!str_contains($service.$authorityRepo,$n))throw new RuntimeException('Missing canonical authority lock: '.$n);
foreach(array('proposal_family_id','proposal_option_id','proposal_version_id','provisional_acceptance_event_id','identity_resolution_event_id','capacity_classification_id','authority_route','confirmation_evidence_reference','arrangement_fingerprint','version_fingerprint')as$n)if(!str_contains($migration,$n))throw new RuntimeException('Missing immutable arrangement evidence: '.$n);
$schema=substr($migration,strpos($migration,'CREATE TABLE {$p}accepted_service_arrangements'));
$schema=substr($schema,0,strpos($schema,'private static function private_digest'));
foreach(array('first_name','last_name','display_name','email','mobile','whatsapp','phone','address','payer','submitter')as$n)if(stripos($schema,$n)!==false)throw new RuntimeException('Accepted arrangement stores prohibited PII: '.$n);
if(stripos($repo,'raw idempotency')!==false||preg_match('/function\s+(?:update|delete).*Arrangement/i',$repo))throw new RuntimeException('Arrangement persistence is not immutable or digest-only');
foreach(array('createEnrolment','createLesson','amelia_','wp_amelia','platform_payments','platform_outbox','register_rest_route')as$n)if(stripos($service.$repo,$n)!==false)throw new RuntimeException('Final acceptance exceeded bounded authority: '.$n);
if(!str_contains($proposal,'acceptedArrangementForFamily')||str_contains($proposal,'acceptedArrangementForFamilyForUpdate'))throw new RuntimeException('Later Proposal issuance finality check is missing or locking an absence range');
$finalityRead=phase_2a2g_method_body($proposalRepo,'acceptedArrangementForFamily');
if($finalityRead===''||!str_contains($finalityRead,'accepted_service_arrangements')||stripos($finalityRead,'FOR UPDATE')!==false)throw new RuntimeException('Proposal issuance finality lookup must be non-locking under the Proposal Family lock');
foreach(array('versionForCommand','versionForOptionNumber','versionForOptionFingerprint')as$method){$body=phase_2a2g_method_body($proposalRepo,$method);if($body===''||stripos($body,'FOR UPDATE')!==false)throw new RuntimeException('Proposal absence lookup must not take a gap lock: '.$method);}
foreach(array(array($proposalRepo,'familyForCaseForUpdate'),array($proposalRepo,'optionForFamilyCandidateForUpdate'),array($proposalRepo,'optionForFamilyTeacherForUpdate'),array($proposalRepo,'currentVersionForOption'),array($repo,'familyOptionsForUpdate'),array($repo,'currentVersionsForOptionsForUpdate'),array($repo,'provisionalForUidForUpdate'))as[$source,$method]){
    $body=phase_2a2g_method_body($source,$method);if($body===''||stripos($body,'FOR UPDATE')===false)throw new RuntimeException('Required concrete-row lock missing: '.$method);
    if(preg_match_all('/"([^"]*FOR UPDATE[^"]*)"/i',$body,$queries))foreach($queries[1]as$query)if(stripos($query,'WHERE id=%d')===false)throw new RuntimeException('FOR UPDATE must lock only a concrete primary-key row: '.$method);
}
if(!preg_match_all('/function\s+(lock\w*ForAuthority)\s*\(/',$authorityRepo,$authorityMethods)||!in_array('lockPrincipalsForAuthority',$authorityMethods[1],true)||!in_array('lockGrantsForAuthority',$authorityMethods[1],true))throw new RuntimeException('Authority collection lock inventory is incomplete');
foreach($authorityMethods[1]as$method){$body=phase_2a2g_method_body($authorityRepo,$method);if($body===''||!str_contains($body,'discoverAuthorityIds')||!str_contains($body,'lockAuthorityRows')||!str_contains($body,'revalidateAuthorityDiscovery')||stripos($body,'FOR UPDATE')!==false)throw new RuntimeException('Authority collection must discover, concretely lock, and revalidate: '.$method);}
$authorityDiscovery=phase_2a2g_method_body($authorityRepo,'discoverAuthorityIds');
if($authorityDiscovery===''||!str_contains($authorityDiscovery,'WHERE student_id=%d')||!str_contains($authorityDiscovery,' IN (')||stripos($authorityDiscovery,'FOR UPDATE')!==false)throw new RuntimeException('Authority discovery must be non-locking');
$authorityConcreteLock=phase_2a2g_method_body($authorityRepo,'lockAuthorityRows');
if($authorityConcreteLock===''||!str_contains($authorityConcreteLock,'if(!$ids)return array()')||!str_contains($authorityConcreteLock,'WHERE id IN (')||!str_contains($authorityConcreteLock,'ORDER BY id ASC FOR UPDATE')||str_contains($authorityConcreteLock,'student_id')||str_contains($authorityConcreteLock,'user_id')||str_contains($authorityConcreteLock,'FORCE INDEX'))throw new RuntimeException('Authority rows must be locked only by discovered concrete primary keys');
if(!str_contains($authorityConcreteLock,'$locked!==$ids')||!str_contains($authorityConcreteLock,'Authority collection changed concurrently'))throw new RuntimeException('Concrete authority lock must reject a changed discovered set');
if(preg_match_all('/"([^"]*FOR UPDATE[^"]*)"/i',$authorityRepo,$authorityQueries))foreach($authorityQueries[1]as$query)if((str_contains($query,'student_principal_links')||str_contains($query,'student_acceptance_authority_grants'))&&!str_contains($query,'WHERE id IN ('))throw new RuntimeException('Direct authority child-table range locking is prohibited');
$principalLocks=strpos($service,'lockPrincipalsForAuthority');$grantLocks=strpos($service,'lockGrantsForAuthority');$principalCheck=strpos($service,'activePrincipal',$principalLocks?:0);$guardianCheck=strpos($service,'activeGuardian',$grantLocks?:0);
if($principalLocks===false||$grantLocks===false||$principalCheck===false||$guardianCheck===false||$principalCheck<$principalLocks||$guardianCheck<$grantLocks)throw new RuntimeException('Final acceptance must revalidate authority predicates from concretely locked rows');
phase_2a2g_assert_authority_semantics($authorityRepo,$service);
if(str_contains($service,'arrangementForFamilyForUpdate')||str_contains($service,'outcomesForFamilyForUpdate')||str_contains($service,'arrangementForCommandForUpdate'))throw new RuntimeException('Final acceptance reacquired an empty child-table gap lock');
if(!str_contains($controller,'finalize_service_arrangement')||!str_contains($controller,"check_admin_referer( 'dzn_final_acceptance' )")||!str_contains($controller,'requireFinalAcceptanceCapability'))throw new RuntimeException('Protected internal final-acceptance surface missing');
foreach(array("array('a', 'o', 'p', 'e', 'c', 'pr', 'g', 'x', 'u', 'i', 'rp', 'rg')",'phase-2a2g.invalid','dzn_phase_2a2g_proposal_locks_held','dzn_phase_2a2g_family_finality_checked','dzn_phase_2a2g_authority_locks_held','dzn_phase_2a2f_authority_locks_held','SELECT CONNECTION_ID()','w1.locked','w2.started','w2.finished','w2.blocked','release','assert_distinct_connections','phase-2a2g-concurrency-wait.php','performance_schema.data_lock_waits','independent_completed_before_release=1','authority_committed_first=1','guardian_rows=0','outcome=idempotency_conflict','closed_competing','proposal_option_outcome_events')as$n)if(!str_contains($raceSetup.$raceWorker.$raceVerify.$raceRunner.file_get_contents($root.'/tests/phase-2a2g-concurrency-wait.php').$service,$n))throw new RuntimeException('Missing deterministic final-acceptance race invariant: '.$n);
echo "Phase 2A.2-G source contract passed\n";
