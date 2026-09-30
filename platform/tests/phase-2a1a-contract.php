<?php
/* Source contract only; runtime semantics require isolated WordPress/MySQL validation. */
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/TeachingEligibilityRepository.php');
$eligibility=file_get_contents($root.'/src/Core/Application/TeachingEligibilityService.php');
$default=file_get_contents($root.'/src/Core/Application/InstrumentIntroCourseDefaultService.php');
$accepting=file_get_contents($root.'/src/Core/Application/TeacherAcceptingStateService.php');
$admin=file_get_contents($root.'/src/Admin/Controller/TeachingEligibilityController.php');
foreach(['004_teaching_eligibility_foundation','teacher_course_eligibilities','instrument_intro_course_defaults','teacher_accepting_states','UNIQUE KEY teacher_course(teacher_id,course_id)','UNIQUE KEY instrument_id(instrument_id)','UNIQUE KEY teacher_id(teacher_id)','verify_teaching_eligibility_schema','dzn_manage_teaching_eligibility'] as $f)if(strpos($migration,$f)===false)throw new RuntimeException('Missing 2A.1-A migration contract: '.$f);
foreach(['effectiveEligibility','t.status=\'active\' AND t.archived_at IS NULL','c.status=\'active\' AND c.archived_at IS NULL','o.state=\'active\' AND o.readiness_state=\'ready\'','a.state IN (\'accepting\',\'limited\')','FOR UPDATE','version']as$f)if(strpos($repo,$f)===false)throw new RuntimeException('Missing eligibility authority contract: '.$f);
foreach(['active','inactive','effective_from','effective_until','dzn_manage_teaching_eligibility']as$f)if(strpos($eligibility,$f)===false)throw new RuntimeException('Missing Teacher/Course contract: '.$f);
foreach(['course_type!==\'introductory\'','instrument_id!==$instrument','Valid active Intro Course for Instrument required','resolveDefault']as$f)if(strpos($default.$repo,$f)===false)throw new RuntimeException('Missing Intro-default contract: '.$f);
foreach(['accepting','limited','paused','Teacher not found']as$f)if(strpos($accepting,$f)===false)throw new RuntimeException('Missing accepting-state contract: '.$f);
foreach(['check_admin_referer','dzn_manage_teaching_eligibility','set_teacher_course_eligibility','set_instrument_intro_default','set_teacher_accepting_state']as$f)if(strpos($admin,$f)===false)throw new RuntimeException('Missing protected admin contract: '.$f);
if(preg_match('/(?:amelia_|wp_amelia|Amelia\\\\)/',$migration.$repo.$eligibility.$default.$accepting.$admin))throw new RuntimeException('2A.1-A must not acquire Amelia dependency');
$phase2a1aSchema=substr($migration,strpos($migration,'private static function install_teaching_eligibility_foundation'),strpos($migration,'private static function install_teacher_availability_foundation')-strpos($migration,'private static function install_teaching_eligibility_foundation'));
foreach(['teacher_availability','booking_requests','teacher_offers','platform_payments']as$f)if(stripos($phase2a1aSchema,$f)!==false)throw new RuntimeException('2A.1-A introduced later-slice schema: '.$f);
echo "Phase 2A.1-A source contract passed\n";
