<?php
/** Phase 2A.2-F static authority and privacy guard. */
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$identity=file_get_contents($root.'/src/Core/Application/StudentIdentityResolutionService.php');
$authority=file_get_contents($root.'/src/Core/Application/StudentAcceptanceAuthorityService.php');
$read=file_get_contents($root.'/src/Core/Application/StudentAcceptanceAuthorityReadService.php');
$privacy=file_get_contents($root.'/src/Core/Application/BookingRequestPrivacyService.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/StudentIdentityAuthorityRepository.php');
$controller=file_get_contents($root.'/src/Admin/Controller/StudentIdentityAuthorityController.php');
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migrationFixture=file_get_contents($root.'/tests/phase-2a2f-schema11-fixture.php');
$migrationRuntime=file_get_contents($root.'/tests/phase-2a2f-migration-runtime.php');
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<12)throw new RuntimeException('Missing compatible Phase 2A.2-F schema marker');
foreach(array('012_student_identity_acceptance_authority','booking_request_identity_resolution_events','student_acceptance_capacity_classifications','student_acceptance_authority_grants','current_identity_resolution_id','current_acceptance_capacity_classification_id','student_sequence','student_active','principal_active','current_authority','verify_student_identity_acceptance_authority_schema')as$needle)if(strpos($migration.$plugin,$needle)===false)throw new RuntimeException('Missing Phase 2A.2-F schema invariant: '.$needle);
foreach(array('resolved','unresolved','ambiguous','rejected','guardian_representative','service_acceptance','eligible_adult_self','eligible_guardian','blocked_identity_unresolved','blocked_capacity_unresolved','blocked_authority_missing','blocked_student_unavailable','blocked_request_privacy_erased','privacyResolutionConsistent')as$needle)if(strpos($identity.$authority.$read.$privacy.$repo,$needle)===false)throw new RuntimeException('Missing Phase 2A.2-F authority invariant: '.$needle);
foreach(array('email','mobile','whatsapp','payer','submitter','address','wordpress_user_id')as$needle)if(stripos($identity,$needle)!==false)throw new RuntimeException('Identity resolution auto-matching or PII copy is prohibited: '.$needle);
foreach(array('accepted_service','final_accept','createEnrolment','createLesson','amelia_','wp_amelia','platform_payments','register_rest_route')as$needle)if(stripos($identity.$authority.$read.$repo,$needle)!==false)throw new RuntimeException('Phase 2A.2-F exceeded its authority: '.$needle);
if(stripos($authority,"'authority_type'=>'adult_delegate'")!==false||stripos($controller,'adult delegate')!==false)throw new RuntimeException('V1 must not create an adult delegate');
if(!str_contains($identity,'requestForUpdate')||!str_contains($authority,'authorityLocks')||!str_contains($repo,'lockPrincipalsForAuthority')||!str_contains($repo,'lockGrantsForAuthority'))throw new RuntimeException('Current-state lock discipline is incomplete');
foreach(array('supersedePrincipal','superseded_by_link_id','principal_supersession','fk_student_principal_superseded_by','lockWordpressUsers','lockPrincipalsForAuthority','lockGrantsForAuthority','expireElapsedGrants','Authority end date must be later than its start date')as$needle)if(strpos($authority.$repo.$migration,$needle)===false)throw new RuntimeException('Reviewed authority correction missing: '.$needle);
foreach(array('schema11_fixture','legacy active principal','legacy revoked principal','012_student_identity_acceptance_authority','student_sequence','principal_active','principal_supersession','Capability repair failed','Repeated upgrader execution changed history')as$needle)if(stripos($migrationFixture.$migrationRuntime,$needle)===false)throw new RuntimeException('Real Schema 11 -> 12 migration evidence missing: '.$needle);
foreach(array('current_user_can','check_admin_referer','wp_safe_redirect')as$needle)if(strpos($controller,$needle)===false)throw new RuntimeException('Protected internal surface invariant missing: '.$needle);
echo "Phase 2A.2-F source contract passed\n";
