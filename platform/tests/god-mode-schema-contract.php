<?php
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migrator=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$snapshot=file_get_contents($root.'/src/Core/Application/Finance/LessonFinanceSnapshotService.php');
$finance=file_get_contents($root.'/src/Core/Application/Finance/Read/TeacherFinanceSummaryReadService.php');
if(!is_string($plugin)||!is_string($migrator)||!is_string($snapshot)||!is_string($finance))throw new RuntimeException('Unable to read God Mode schema sources.');
if(!str_contains($plugin,"DZN_PLATFORM_SCHEMA_VERSION', '61'"))throw new RuntimeException('The existing package schema option must remain compatible.');
foreach(array("074_god_mode_super_tester","install_god_mode_super_tester","verify_god_mode_super_tester_schema") as $token)if(!str_contains($migrator,$token))throw new RuntimeException('Migration registration missing: '.$token);
foreach(array(
    'god_mode_authorisations','god_mode_sessions','god_mode_session_events','god_mode_scenario_runs',
    'god_mode_audit_events','god_mode_notification_tests','god_mode_cleanup_receipts','god_mode_participant_mappings',
) as $table)if(!str_contains($migrator,$table))throw new RuntimeException('God Mode table missing: '.$table);
if(substr_count($migrator,'activity_type')<6)throw new RuntimeException('Test classification must be added to lessons, enrolments and booking requests.');
if(substr_count($migrator,'god_mode_session_id')<2)throw new RuntimeException('Test classification must carry the owning test session identity.');
if(substr_count($migrator,"array('lessons','enrolments','booking_requests')")<2)throw new RuntimeException('All three existing aggregates must be classified additively.');
foreach(array('test_session_id','test_session_active','classroom_kind') as $token)if(!str_contains($migrator,$token))throw new RuntimeException('Meet test-session identity missing: '.$token);
foreach(array('god_mode_sessions','activity_type','starts_at_utc','ends_at_utc','meet_state','recording_state','result') as $token)if(!str_contains($migrator,$token))throw new RuntimeException('Session persistence field missing: '.$token);
if(!str_contains($migrator,"state='active'")||!str_contains($migrator,'hamed.moqadasi@yahoo.com'))throw new RuntimeException('Designated account bootstrap must be server-side and active.');
if(!str_contains($snapshot,'GodModeRule::assertBillable'))throw new RuntimeException('Finance snapshot capture must refuse test activity at the domain boundary.');
if(substr_count($finance,"activity_type<>'test'")<4)throw new RuntimeException('Teacher finance statements and KPIs must explicitly exclude test activity.');
if(!str_contains($migrator,"record_model='legacy_phase1'")&&!str_contains($migrator,'legacy_phase1'))throw new RuntimeException('Existing migration contract unexpectedly changed.');
echo "God Mode schema contract passed\n";
