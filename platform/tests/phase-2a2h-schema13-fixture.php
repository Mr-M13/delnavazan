<?php
/** Prepare one representative legacy Enrolment on the exact Schema 13 base. */
if (getenv('DZN_PHASE_2A2H_RUNTIME_TEST') !== 'schema13_fixture' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    fwrite(STDERR, "Phase 2A.2-H Schema 13 fixture refused.\n"); exit(1);
}
if ((string) DZN_PLATFORM_SCHEMA_VERSION !== '13' || DZN_PLATFORM_BUILD_ID !== 'phase2a2g-final-acceptance-arrangement-20260910.1') throw new RuntimeException('Exact Schema 13 base plugin required');
if ((string) get_option('dzn_platform_schema_version') !== '13') throw new RuntimeException('Schema 13 database required');

use Delnavazan\Platform\Core\Application\{CatalogueService, EnrolmentService, StudentService, TeacherService};

$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 10);
$teacherId = (new TeacherService())->create(['display_name' => 'Synthetic H Legacy Teacher', 'email' => "h-legacy-teacher-{$suffix}@example.invalid"]);
$studentId = (new StudentService())->create(['display_name' => 'Synthetic H Legacy Student', 'email' => "h-legacy-student-{$suffix}@example.invalid", 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected']);
$catalogue = new CatalogueService();
$instrumentId = $catalogue->instrument(['slug' => "h-legacy-{$suffix}", 'name_fa' => 'Synthetic', 'name_en' => 'Synthetic H Instrument', 'status' => 'active']);
$courseId = $catalogue->course(['instrument_id' => $instrumentId, 'name_fa' => 'Synthetic', 'name_en' => 'Synthetic H Course', 'course_type' => 'standard', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15]);
$enrolmentId = (new EnrolmentService())->create(['student_id' => $studentId, 'teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'ending', 'preferred_weekday' => 3, 'preferred_local_time' => '17:30:00', 'schedule_timezone' => 'Australia/Brisbane']);
update_option('dzn_phase_2a2h_schema13_fixture', ['teacher_id' => $teacherId, 'student_id' => $studentId, 'instrument_id' => $instrumentId, 'course_id' => $courseId, 'enrolment_id' => $enrolmentId, 'status' => 'ending'], false);
echo "Phase 2A.2-H Schema 13 legacy fixture prepared\n";
