<?php
/**
 * Contract: the Teacher pre-class briefing and private finish note.
 *
 * A Student goes straight to Meet. A Teacher keeps the branded page so the previous private note,
 * the Student's Term timeline and outstanding actions are visible before class, and the same page
 * turns into the finish page where the private note is saved and attendance is confirmed.
 *
 * The note is Teacher-owned: no Student read model, notification or provider projection may expose
 * it. This static contract keeps that boundary visible in the shipped source.
 */
$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing source file: ' . $relative);
    return (string) file_get_contents($path);
};
$check = static function (string $label, bool $condition): void {
    if (!$condition) throw new RuntimeException('Teacher classroom finish contract failed: ' . $label);
};

$plugin = $read('platform/delnavazan-platform.php');
$check('Teacher class-note controller is registered', str_contains($plugin, 'TeacherClassNoteController::register'));
$check('schema revision includes migration 073', str_contains($plugin, "'60'"));

$migration = $read('platform/src/Core/Infrastructure/Migration/Migrator.php');
$check('migration 073 is installed and verified', str_contains($migration, '073_teacher_lesson_notes') && str_contains($migration, 'install_teacher_lesson_notes') && str_contains($migration, 'verify_teacher_lesson_notes_schema'));
$check('one note per Teacher/Lesson is a unique key', str_contains($migration, 'UNIQUE KEY teacher_lesson(teacher_id,lesson_id)'));
$check('notes are Teacher/Student indexed', str_contains($migration, 'KEY teacher_student(teacher_id,student_id)'));

$repository = $read('platform/src/Core/Infrastructure/Repository/TeacherLessonNoteRepository.php');
$check('note reads are scoped by Teacher and Lesson', str_contains($repository, 'lesson_id=%d AND teacher_id=%d'));
$check('previous note is scoped to the same Student and a different prior Lesson', str_contains($repository, 'n.teacher_id=%d AND n.student_id=%d AND n.lesson_id<>%d') && str_contains($repository, 'v.starts_at_utc<%s'));
$check('empty notes never surface as a previous note', str_contains($repository, "TRIM(n.note_text)<>''"));

$service = $read('platform/src/Core/Application/TeacherClassNoteService.php');
$check('note writing requires the active Teacher principal', str_contains($service, "resolve('teacher')") && str_contains($service, "current_user_can('dzn_view_own_portal_schedule')"));
$check('note writing does not depend on onboarding availability rules', !str_contains($service, 'hasActiveTeacherAuthority'));
$check('note writing requires the owning canonical Lesson', str_contains($service, "(int)\$lesson->teacher_id!==\$teacherId") && str_contains($service, "record_model!=='canonical_term_lesson_v1'"));
$check('note writing requires the exact applicable schedule', str_contains($service, "(int)\$schedule->applicable_slot!==1"));
$check('note length is bounded', str_contains($service, 'MAX_LENGTH=5000'));

$controller = $read('platform/src/Portals/TeacherClassNoteController.php');
$check('note save is authenticated', str_contains($controller, 'is_user_logged_in()'));
$check('note save is nonce protected', str_contains($controller, 'wp_verify_nonce($nonce,self::ACTION)'));
$check('note save never trusts a submitted redirect URL', !str_contains($controller, 'redirect_to') && str_contains($controller, "home_url('/music-classroom/')"));
$check('note save only redirects with validated occurrence identifiers', str_contains($controller, "preg_match('/^[0-9A-Z]{26}$/D',\$lessonUid)"));

$briefing = $read('platform/src/Core/Application/TeacherClassroomBriefingReadService.php');
$check('briefing resolves only the Teacher principal', str_contains($briefing, "resolve('teacher')") && str_contains($briefing, "current_user_can('dzn_view_own_portal_schedule')"));
$check('briefing does not depend on onboarding availability rules', !str_contains($briefing, 'hasActiveTeacherAuthority'));
$check('briefing re-proves Lesson ownership and schedule applicability', str_contains($briefing, "(int)\$lesson->teacher_id!==\$teacherId") && str_contains($briefing, "(int)\$schedule->applicable_slot!==1"));
$check('briefing composes the current-Term timeline', str_contains($briefing, "WHERE l.term_id=%d AND l.student_id=%d") && str_contains($briefing, 'is_current'));
$check('briefing surfaces outstanding attendance and reschedule facts', str_contains($briefing, 'pending') && str_contains($briefing, 'attendance_unconfirmed'));
$check('briefing reads the previous Teacher note', str_contains($briefing, 'previousForStudent'));
$check('briefing keeps attendance confirmation in the canonical delivery seam', str_contains($briefing, 'dzn_teacher_report_delivery'));

$entry = $read('theme/template-parts/classroom/meet.php');
$check('branded page loads the briefing only for the owning Teacher', str_contains($entry, "'teacher' === \$role") && str_contains($entry, 'TeacherClassroomBriefingReadService'));
$check('pre-class page shows the previous note', str_contains($entry, 'یادداشت جلسهٔ گذشته'));
$check('pre-class page shows the Student Term timeline', str_contains($entry, 'خط زمانی ترم هنرجو'));
$check('pre-class page shows outstanding actions', str_contains($entry, 'کارهای نیازمند توجه'));
$check('finish page saves the private note', str_contains($entry, 'dzn_teacher_save_class_note'));
$check('finish page states that the note is private', str_contains($entry, 'هنرجو آن را نمی‌بیند'));
$check('finish page confirms attendance', str_contains($entry, 'تأیید برگزاری و حضور'));
$check('finish page is Teacher-only', str_contains($entry, "\$show_finish = 'teacher' === \$role"));
$check('Teacher join form carries the finish URL for the Meet-close transition', str_contains($entry, 'data-dzn-finish-url'));
$check('Teacher join help explains the automatic finish transition', str_contains($entry, 'با بستن آن، صفحهٔ پایان کلاس'));
$check('a missing briefing still renders the note/finish entry point', str_contains($entry, 'dzn-meet-brief-fallback-title'));

$entryJs = $read('theme/assets/js/meet-entry.js');
$check('Meet-close detection opens the finish page', str_contains($entryJs, 'meetWindow.closed') && str_contains($entryJs, 'window.location.assign(finishUrl)'));
$check('Meet-close detection falls back when the popup is blocked', str_contains($entryJs, 'if (!meetWindow)'));
$routes = $read('theme/inc/routes.php');
$check('classroom route enqueues the Meet-close script', str_contains($routes, 'delnavazan-meet-entry') && str_contains($routes, 'assets/js/meet-entry.js'));

$check('repository can batch previous notes for portal cards', str_contains($repository, 'previousNotesForLessons'));
$bridge = $read('theme/inc/platform-bridge.php');
$check('Teacher portal cards receive the sourced previous note', str_contains($bridge, "'previous_private_note' =>") && str_contains($bridge, 'previousNotesForLessons'));

$actions = $read('theme/template-parts/components/classroom-actions.php');
$check('Student join is a direct authenticated POST', str_contains($actions, "'student' === \$role") && str_contains($actions, '<form class="dzn-classroom-actions__form" method="post"'));
$check('Teacher join keeps the branded preparation page', str_contains($actions, "home_url( '/music-classroom/' )") && str_contains($actions, 'target="_blank"'));

$portal = $read('theme/template-parts/portal/upcoming-lesson.php') . $read('theme/template-parts/portal/schedule.php');
$check('Student portal never references the private note store', !str_contains($portal, 'teacher_lesson_notes') && !str_contains($portal, 'previous_private_note'));

foreach (array('platform/src/Integrations/GoogleWorkspace/MeetClassroomJoinService.php', 'platform/src/Integrations/StudentClassJoinService.php') as $joinSource) {
    $check('join services do not expose notes: ' . $joinSource, !str_contains($read($joinSource), 'teacher_lesson_notes'));
}

echo "Teacher classroom finish contract passed\n";
