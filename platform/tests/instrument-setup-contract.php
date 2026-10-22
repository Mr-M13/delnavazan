<?php
$root=dirname(__DIR__);
$catalogue=file_get_contents($root.'/src/Core/Application/CatalogueService.php');$screen=file_get_contents($root.'/src/Admin/Controller/ScreenController.php');$courses=file_get_contents($root.'/src/Core/Infrastructure/Repository/CourseRepository.php');$instruments=file_get_contents($root.'/src/Core/Infrastructure/Repository/InstrumentRepository.php');$eligibility=file_get_contents($root.'/src/Core/Application/TeachingEligibilityService.php');
foreach(array('setupInstrument','CommercialCatalogueService','InstrumentIntroCourseDefaultService','activeForInstrumentAndType','productsForCourse','paid_introductory_lesson_unsupported','reconcileCourse','count($existing)>1','GET_LOCK','instrument-setup:') as $required)if(!str_contains($catalogue,$required))throw new RuntimeException('Missing reuse-first setup safeguard: '.$required);
foreach(array('name','standard_duration_minutes','introductory_lesson_billing','gap_between_lessons_minutes','setupInstrument') as $required)if(!str_contains($screen,$required))throw new RuntimeException('Instrument screen did not use the simplified setup field: '.$required);
if(str_contains($catalogue,'setPrice('))throw new RuntimeException('Instrument setup must not invent regional prices');
if(!str_contains($courses,'activeForInstrumentAndType')||!str_contains($instruments,'bySlug'))throw new RuntimeException('Instrument setup reconciliation reads are missing');
foreach(array('setOwnInstruments','ownCourseCatalogue','setOwnCourses','Active ready Teacher required') as $required)if(!str_contains($eligibility,$required))throw new RuntimeException('Teacher instrument selection no longer connects approved teachers to catalogue courses');
echo "Instrument setup contract passed.\n";
