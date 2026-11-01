<?php
/**
 * Source-level contract for the synchronised classroom controls added in the classroom-sync slice.
 *
 * Behavioural proof still needs the disposable browser/staging harness; these assertions pin the
 * collaboration topic, the four-way local rotation control, the window-close fallback, and the
 * separation between Student Exit and the teacher's canonical delivery report.
 */
$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing source file: ' . $relative);
    return (string)file_get_contents($path);
};
$check = static function (string $label, bool $condition): void {
    if (!$condition) throw new RuntimeException('LiveKit classroom sync contract failed: ' . $label);
};
$between = static function (string $source, string $start, string $end): string {
    $offset = strpos($source, $start);
    if ($offset === false) return '';
    $from = $offset + strlen($start);
    $to = strpos($source, $end, $from);
    if ($to === false) return '';
    return substr($source, $from, $to - $from);
};

$js = $read('theme/assets/js/music-classroom.js');
$css = $read('theme/assets/css/music-classroom.css');
$template = $read('theme/template-parts/classroom/music.php');
$routes = $read('theme/inc/routes.php');
$session = $read('platform/src/Integrations/LiveKit/LiveKitClassroomSessionService.php');

/* -------------------------------------------------------------------------
 * Shared room-level music mode
 * ---------------------------------------------------------------------- */
$check('music mode uses the dedicated data topic', str_contains($js, "'dzn-music-mode'"));
$check('music messages carry a protocol version', str_contains($js, 'MUSIC_MESSAGE_VERSION = 1'));
$check('music messages identify their sender', str_contains($js, 'sender: room.localParticipant.identity'));
$check('music messages are reliable and topic-scoped', str_contains($js, 'publishData(') && str_contains($js, 'reliable: true') && str_contains($js, 'topic: MUSIC_TOPIC'));
$check('the classroom subscribes to LiveKit data messages', str_contains($js, 'LK.RoomEvent.DataReceived'));
$check('unsupported message versions are ignored', str_contains($js, 'message.version !== MUSIC_MESSAGE_VERSION'));
$check('own and mismatched senders are ignored', str_contains($js, 'sender === localIdentity') && str_contains($js, 'String(participant.identity) !== sender'));
$check('a joining participant receives the current shared state', str_contains($js, 'RoomEvent.ParticipantConnected') && str_contains($js, 'broadcastMusicMode();'));
$check('a reconnecting participant re-announces the shared state', str_contains($js, 'RoomEvent.Reconnected') && str_contains($js, 'broadcastMusicMode();'));

$receive = $between($js, 'function handleMusicModeMessage', 'function encodeDataMessage');
$check('the receive path is a distinct function', $receive !== '');
$check('receiving a music state never re-broadcasts (no echo loop)', !str_contains($receive, 'broadcastMusicMode('));
$check('receiving a music state applies local audio configuration', str_contains($receive, 'reconfigureAudioForMusic()'));
$check('receiving a music state updates the checkbox', str_contains($receive, 'music.checked = enabled'));
$check('the shared indicator describes a request, not a device guarantee', str_contains($js, 'این یک درخواست است') && str_contains($js, 'ممکن است همهٔ تنظیمات اعمال نشود'));
$check('music mode never marks attendance', !str_contains($js, 'attendance') && !str_contains($js, 'LiveKitAttendance'));

/* -------------------------------------------------------------------------
 * Student exit versus teacher finish
 * ---------------------------------------------------------------------- */
$check('the teacher finish button exists once and is hidden by default', substr_count($template, 'id="dzn-classroom-finish"') === 1 && str_contains($template, 'id="dzn-classroom-finish"') && str_contains($template, '>پایان کلاس<') && str_contains($template, 'disabled hidden'));
$check('the finish button is gated on canonical identifiers', str_contains($js, 'function teacherFinishAvailable') && str_contains($js, "session.role !== 'teacher'") && str_contains($js, 'UID_PATTERN.test(String(session.lesson_uid') && str_contains($js, 'UID_PATTERN.test(String(session.schedule_version_uid'));
$check('a session without identifiers does not render a fake finish action', str_contains($js, 'finishButton.hidden = !teacherFinishAvailable(session)'));
$check('the classroom template never embeds canonical identifiers', !str_contains($template, 'lesson_uid') && !str_contains($template, 'schedule_version_uid'));

$exit = $between($js, 'function exitClassroom()', 'async function finishClass()');
$check('the Student Exit path is separate from teacher finish', $exit !== '');
$check('Student Exit performs cleanup through endClassroom', str_contains($exit, 'endClassroom(portalUrlForRole()'));
$check('Student Exit never posts delivery evidence', !str_contains($exit, 'finishActionUrl') && !str_contains($exit, 'dzn_teacher_report_delivery') && !str_contains($exit, 'FormData') && !str_contains($exit, 'class_status=delivery_recorded'));

$finish = $between($js, 'async function finishClass()', 'async function endClassroom(');
$check('teacher finish posts to the existing canonical delivery endpoint', str_contains($finish, 'config.finishActionUrl') && str_contains($routes, 'action=dzn_teacher_report_delivery'));
$check('teacher finish sends the canonical nonce and identifiers', str_contains($finish, "body.append('_wpnonce'") && str_contains($finish, "body.append('lesson_uid'") && str_contains($finish, "body.append('schedule_version_uid'"));
$check('teacher finish only cleans up after the server confirms the report', str_contains($finish, 'class_status=delivery_recorded') && str_contains($finish, 'throw new Error'));
$check('a failed teacher finish keeps the call connected and shows an error', str_contains($finish, "setConnection('ثبت پایان کلاس انجام نشد.', 'error')") && str_contains($finish, 'showAlert(') && str_contains($finish, 'finishButton.disabled = false'));

$check('the session response exposes canonical lesson identifiers', str_contains($session, "'lesson_uid'") && str_contains($session, "'schedule_version_uid'"));
$check('booking sessions do not masquerade as a canonical lesson', str_contains($session, "(string)\$grant['kind'] === 'lesson'"));
$check('the theme localises both portal fallbacks', str_contains($routes, "'teacherPortalUrl'") && str_contains($routes, "'studentPortalUrl'"));
$check('the theme localises the finish endpoint and nonce', str_contains($routes, "'finishActionUrl'") && str_contains($routes, "'finishNonce'"));

$close = $between($js, 'function closeOrRedirect(destination)', 'function exitClassroom()');
$check('window close is attempted before the portal fallback', $close !== '' && str_contains($close, 'window.close()'));
$check('the fallback returns to the configured portal URL', str_contains($close, 'window.location.replace(url)') && str_contains($close, "window.location.href = url"));
$check('the fallback is timer-based so a blocked close cannot strand the participant', str_contains($close, 'window.setTimeout(go, 350)'));

/* -------------------------------------------------------------------------
 * Four-way local presentation rotation
 * ---------------------------------------------------------------------- */
$check('rotation cycles in four steps', str_contains($js, 'state.rotation = (state.rotation + 90) % 360'));
$check('rotation is driven by the CSS custom property', str_contains($js, "stage.style.setProperty('--dzn-rotation', angle + 'deg')") && str_contains($css, '--dzn-rotation'));
$check('rotation is exposed as a data attribute', str_contains($js, "stage.setAttribute('data-rotation', String(angle))"));
$check('geometry swaps only for quarter turns', str_contains($js, 'if (angle !== 90 && angle !== 270)') && str_contains($js, "tile.style.setProperty('--dzn-rotate-width', rect.height + 'px')"));
$check('the button label shows the current angle', str_contains($js, "'چرخش: ' + toPersianDigits(state.rotation) + '°'"));
$check('the button exposes aria-pressed and an aria-label', str_contains($js, "rotateButton.setAttribute('aria-pressed'") && str_contains($js, "rotateButton.setAttribute('aria-label'"));
$check('the initial Persian label is rendered server-side', str_contains($template, 'چرخش: ۰°') && str_contains($template, 'aria-label="چرخش نمایش ویدئو: بدون چرخش'));
foreach (array(90, 180, 270) as $angle) {
    $check('the stylesheet implements ' . $angle . ' degrees', str_contains($css, '.dzn-classroom__stage[data-rotation="' . $angle . '"] .dzn-classroom__tile video'));
}
$check('both the local preview and the remote tile rotate locally', str_contains($css, '.dzn-classroom__stage[data-rotation="90"] .dzn-classroom__tile--local video') && str_contains($css, '.dzn-classroom__stage[data-rotation="180"] .dzn-classroom__tile--local video'));
$check('fullscreen keeps the quarter-turn geometry', str_contains($css, ':is(:fullscreen, .is-faux-fullscreen) .dzn-classroom__stage[data-rotation="90"] .dzn-classroom__tile video') && str_contains($css, ':is(:fullscreen, .is-faux-fullscreen) .dzn-classroom__stage[data-rotation="270"] .dzn-classroom__tile video'));
$check('rotation never claims the transmitted camera orientation changed', str_contains($js, 'جهت دوربین ارسالی تغییر نمی‌کند') && str_contains($template, 'جهت دوربین ارسالی را تغییر نمی‌دهد'));

echo "LiveKit classroom sync contract passed\n";
