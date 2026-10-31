<?php
/** Regression contracts for the second-phase teacher self-service portal. */
$root=dirname(__DIR__);$theme=dirname($root);
$files=[
 'eligibility'=>file_get_contents($root.'/src/Core/Application/TeachingEligibilityService.php'),
 'availability'=>file_get_contents($root.'/src/Core/Application/TeacherAvailabilityService.php'),
 'availability_repo'=>file_get_contents($root.'/src/Core/Infrastructure/Repository/TeacherAvailabilityRepository.php'),
 'controller'=>file_get_contents($root.'/src/Portals/TeacherAvailabilitySelfServiceController.php'),
 'onboarding_controller'=>file_get_contents($root.'/src/Portals/TeacherOnboardingController.php'),
 'finance'=>file_get_contents($root.'/src/Core/Application/Finance/Read/TeacherStatementReadService.php'),
 'read'=>file_get_contents($root.'/src/Portals/PortalAuthenticatedReadService.php'),
 'bridge'=>file_get_contents($theme.'/theme/inc/platform-bridge.php'),
 'routes'=>file_get_contents($theme.'/theme/inc/routes.php'),
 'shell'=>file_get_contents($theme.'/theme/template-parts/teacher-portal/shell.php'),
 'settings'=>file_get_contents($theme.'/theme/template-parts/teacher-portal/account.php'),
 'onboarding'=>file_get_contents($theme.'/theme/template-parts/teacher-portal/onboarding.php'),
 'finance_ui'=>file_get_contents($theme.'/theme/template-parts/teacher-portal/finance.php'),
 'demand'=>file_get_contents($root.'/src/Core/Application/AcademyDemandWindowRule.php'),
];
$checks=[
 'catalogue-only instrument self-selection uses canonical eligibility service'=>str_contains($files['eligibility'],'setOwnInstruments')&&str_contains($files['eligibility'],'ownCourseCatalogue')&&str_contains($files['eligibility'],'saveEligibility')&&str_contains($files['settings'],'instrument_ids[]')&&str_contains($files['onboarding'],'instrument_ids[]'),
 'own eligibility bounded to authenticated linked teacher'=>str_contains($files['eligibility'],'activeTeacherIdForUser($user)??$availabilityRepo->onboardingTeacherIdForUser($user)')&&str_contains($files['eligibility'],'count($ids)>30'),
 'dated day-off uses canonical exception authority and own teacher scope'=>str_contains($files['availability'],'setOwnDayOff')&&str_contains($files['availability'],'setOwnDatedException')&&str_contains($files['availability'],'setDatedException(array $input)')&&str_contains($files['availability'],'retireActiveOwnException')&&str_contains($files['availability_repo'],'retireOwnException'),
 'day-off is all-day blocked in teacher availability timezone'=>str_contains($files['availability'],"'all_day'=>1,'state'=>'blocked'")&&str_contains($files['availability'],'profileForMutation($teacher,$d[\'timezone\'])'),
 'exception remains in effective availability resolution'=>str_contains($files['availability'],'$this->repo->activeExceptions')&&str_contains($files['availability'],"'exception'"),
 'three post-onboarding views and global logout'=>str_contains($files['bridge'],"'screen' => 'finance'")&&str_contains($files['bridge'],"'screen' => 'settings'")&&str_contains($files['shell'],'wp_logout_url')&&str_contains($files['routes'],"'home', 'classes', 'finance', 'settings', 'onboarding'"),
 'settings exposes self-service essentials and password recovery'=>str_contains($files['settings'],'dzn_teacher_instruments')&&str_contains($files['settings'],'dzn_teacher_day_off')&&!str_contains($files['settings'],'dzn_teacher_accepting')&&str_contains($files['settings'],'wp_lostpassword_url')&&str_contains($files['settings'],'google-settings'),
 'finance is current teacher scoped and empty/unavailable states are honest'=>str_contains($files['finance'],'ownStatements')&&str_contains($files['finance'],'PortalPrincipalResolver')&&str_contains($files['bridge'],'finance_state')&&str_contains($files['finance_ui'],'در حال حاضر در دسترس نیست'),
 'teacher-local golden windows and course lesson-plus-buffer estimates'=>str_contains($files['onboarding'],"golden-hours")&&str_contains($files['settings'],"golden-hours")&&str_contains($files['onboarding'],'default_duration_minutes')&&str_contains($files['onboarding'],'default_buffer_minutes'),
 'onboarding exposes nonce-protected submit-for-review route'=>str_contains($files['onboarding_controller'],"admin_post_dzn_teacher_onboarding_submit")&&str_contains($files['onboarding_controller'],'dzn_teacher_onboarding_submit')&&str_contains($files['onboarding'],'ارسال برای بررسی مدیر'),
 'automatic readiness redirects to Your Account'=>str_contains($files['onboarding_controller'],'$ready ?')&&str_contains($files['routes'],'dzn_theme_teacher_requires_onboarding'),
];
foreach($checks as$name=>$ok)if(!$ok)throw new RuntimeException('Teacher portal phase 2 contract failed: '.$name);
echo 'Teacher portal phase 2 contracts passed ('.count($checks).").\n";
