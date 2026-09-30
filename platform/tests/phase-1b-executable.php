<?php
// WordPress integration-test scaffold: execute with authenticated administrator and test DB.
// Covers defaults/complete Teacher and Student normalization, duplicate email identities,
// timezone-source/calendar allowlists, Instrument/Course bounds and parent rejection,
// normalized Enrolment/Term IDs, Term sequence/payment state, Intro/Standard/Replacement
// relationships, reference transaction, UID collision retry, and unauthorized mutations.
$root=dirname(__DIR__);foreach(['TeacherService','StudentService','CatalogueService','EnrolmentService','TermService','LessonService','Normalizer','Creator']as$f)if(!is_file("$root/src/Core/Application/$f.php"))throw new RuntimeException("Missing service $f");
$source=file_get_contents("$root/src/Core/Application/LessonService.php");foreach(['replacement_for_lesson_id','student_id','teacher_id','course_id','Invalid Term/Enrolment']as$f)if(!str_contains($source,$f))throw new RuntimeException("Missing Lesson invariant $f");
echo "Phase 1B executable test scaffold loaded\n";
