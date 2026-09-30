<?php
// Database/WordPress execution fixture list for Phase 1B. Run under the WP test suite.
$root=dirname(__DIR__);$files=array('TeacherService','StudentService','CatalogueService','EnrolmentService','TermService','LessonService','Normalizer','Creator');
foreach($files as $file)if(!is_file("$root/src/Core/Application/$file.php"))throw new RuntimeException("Missing $file");
$lesson=file_get_contents("$root/src/Core/Application/LessonService.php");$term=file_get_contents("$root/src/Core/Application/TermService.php");
foreach(array('introductory','standard','replacement','snapshot mismatch','Invalid Term/Enrolment','replacement_for_lesson_id')as$fragment)if(!str_contains($lesson,$fragment))throw new RuntimeException("Lesson rule missing: $fragment");
foreach(array('Term requires Enrolment','if($enrolment)','if($term)')as$fragment)if(!str_contains($lesson,$fragment))throw new RuntimeException("Intro relationship rule missing: $fragment");
$catalogue=file_get_contents("$root/src/Core/Application/CatalogueService.php");foreach(array("instrument_id']=Normalizer::id","name_fa","name_en","Instrument name required")as$fragment)if(!str_contains($catalogue,$fragment))throw new RuntimeException("Catalogue rule missing: $fragment");
$repository=file_get_contents("$root/src/Core/Infrastructure/Repository/BaseRepository.php");if(!str_contains($repository,"status <> 'archived'"))throw new RuntimeException('Archived parent guard missing');
foreach(array('sequenceExists','Term sequence already exists')as$fragment)if(!str_contains($term,$fragment))throw new RuntimeException("Term sequence rule missing: $fragment");
echo "Phase 1B source contract passed\n";
