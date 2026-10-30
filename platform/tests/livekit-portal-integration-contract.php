<?php
$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing source file: ' . $relative);
    return (string)file_get_contents($path);
};
$check = static function (string $label, bool $condition): void {
    if (!$condition) throw new RuntimeException('LiveKit contract failed: ' . $label);
};

$plugin = $read('platform/delnavazan-platform.php');
$check('token/session endpoint is registered', str_contains($plugin, 'LiveKitTokenRestController') && str_contains($plugin, "'register'"));
$check('signed webhook endpoint is registered', str_contains($plugin, 'LiveKitWebhookController'));
$check('launch boundary is registered', str_contains($plugin, 'LiveKitLaunchController'));
$check('explicit Meet fallback boundary is registered', str_contains($plugin, 'LiveKitMeetFallbackController'));
$check('canonical lifecycle revocation listener is registered', str_contains($plugin, 'LiveKitRoomRevocationListener'));

$routes = $read('theme/inc/routes.php');
$check('classroom route is a protected virtual route', str_contains($routes, "'music-classroom'") && str_contains($routes, "'music-classroom' === \$route && ! is_user_logged_in()"));
$check('classroom response uses protected no-store/noindex headers', str_contains($routes, "'music-classroom'") && str_contains($routes, 'dzn_theme_send_protected_response_headers'));
$check('classroom session endpoint is localized', str_contains($routes, "delnavazan-platform/v1/livekit/session"));
$check('LiveKit SDK is vendored, not loaded from a third-party CDN', str_contains($routes, "assets/js/vendor/livekit-client.umd.min.js") && !str_contains($read('theme/assets/js/music-classroom.js'), 'cdn.jsdelivr.net'));
$check('SDK is a dependency of the classroom script', str_contains($routes, "array( 'delnavazan-livekit-client' )"));

$bridge = $read('theme/inc/platform-bridge.php');
$check('student and teacher cards expose LiveKit availability', str_contains($bridge, "'livekit_available'") && substr_count($bridge, "'livekit_action_url'") >= 2);
$check('LiveKit does not require a Google Meet mapping', str_contains($bridge, 'function dzn_theme_platform_livekit_available') && !str_contains($bridge, '$livekit_available = $join_available'));
$check('Meet fallback remains a separate server action', str_contains($bridge, 'dzn_student_join_class') && str_contains($bridge, 'dzn_teacher_join_class'));
$check('cancelled and completed lessons are not joinable', str_contains($bridge, "'authorised' === (string) ( \$row['lifecycle_state']") || str_contains($bridge, "'authorised' === (string) ( \$row['lifecycle_state'] ?? '' )"));
$check('schedule-version mismatch is refused', str_contains($read('platform/src/Integrations/LiveKit/LiveKitClassroomAuthorizer.php'), 'requested_schedule_key'));

$component = $read('theme/template-parts/components/classroom-actions.php');
$check('primary Persian action is present exactly once', substr_count($component, '>ورود به کلاس</button>') === 1);
$check('Google Meet backup action is present exactly once', substr_count($component, '>ورود با گوگل میت</button>') === 1);
$check('both actions are nonce-protected POST forms', substr_count($component, 'method="post"') === 2 && substr_count($component, "name=\"_wpnonce\"") === 2);
$check('the component never renders a Meet URL', !str_contains($component, 'meet.google.com'));

foreach (array('theme/template-parts/portal/upcoming-lesson.php', 'theme/template-parts/portal/schedule.php', 'theme/template-parts/teacher-portal/classes.php') as $template) {
    $check('classroom action component is used by ' . $template, str_contains($read($template), "template-parts/components/classroom-actions"));
}
$check('teacher main action is the shared classroom component', str_contains($read('theme/template-parts/teacher-portal/classes.php'), "'role' => 'teacher'"));
$check('student actions are the shared classroom component', str_contains($read('theme/template-parts/portal/upcoming-lesson.php'), "'role' => 'student'"));

$authorizer = $read('platform/src/Integrations/LiveKit/LiveKitClassroomAuthorizer.php');
$check('authorization reuses the canonical portal owner policy', str_contains($authorizer, 'PortalAccessPolicy::assertObject'));
$check('authorization reuses the canonical booking repository', str_contains($authorizer, 'confirmedParticipantContext'));
$check('authorization rejects a non-canonical lifecycle', str_contains($authorizer, 'livekit_lesson_not_joinable'));
$check('authorization enforces the joining window', str_contains($authorizer, 'insideWindow'));
$check('authorization uses no browser-supplied room name', !str_contains($authorizer, '$_POST') && !str_contains($authorizer, 'get_param'));

$session = $read('platform/src/Integrations/LiveKit/LiveKitClassroomSessionService.php');
$check('room is created by the server control plane', str_contains($session, 'ensureRoom'));
$check('session response exposes Meet availability but no Meet URL', str_contains($session, "'meet_available'") && !str_contains($session, 'meet.google.com'));
$check('session token lifetime is five minutes', str_contains($read('platform/src/Integrations/LiveKit/LiveKitJwtIssuer.php'), 'PARTICIPANT_TTL_SECONDS = 300'));

$client = $read('platform/src/Integrations/LiveKit/LiveKitRoomServiceClient.php');
$check('server enforces two participants', str_contains($client, 'MAX_PARTICIPANTS = 2') && str_contains($client, "'max_participants' => self::MAX_PARTICIPANTS"));
$check('RoomService calls are HTTPS Twirp calls with an admin token', str_contains($client, '/twirp/livekit.RoomService/') && str_contains($client, 'Bearer '));
$check('RoomService errors are sanitized', str_contains($client, 'livekit_room_api_unavailable') && !str_contains($client, 'error_log'));

$webhook = $read('platform/src/Integrations/LiveKit/LiveKitWebhookController.php');
$check('webhook validates its signature before acting', str_contains($webhook, 'LiveKitWebhookVerifier'));
$check('webhook removes an unauthorized identity', str_contains($webhook, 'removed_unauthorized_identity'));
$check('webhook never records attendance', !str_contains($webhook, 'CanonicalAttendance') && !str_contains($webhook, 'complete('));
$check('webhook ignores non-classroom rooms', str_contains($webhook, "str_starts_with(\$room, 'dzn-')"));

$ticket = $read('platform/src/Integrations/LiveKit/LiveKitLaunchTicket.php');
$check('launch ticket is signed and user-bound', str_contains($ticket, 'hash_hmac') && str_contains($ticket, 'livekit_ticket_user_mismatch'));
$check('launch ticket is not a token replacement', !str_contains($ticket, 'livekit-client') && !str_contains($ticket, 'API_SECRET'));

$rest = $read('platform/src/Integrations/LiveKit/LiveKitTokenRestController.php');
$check('session endpoint requires an authenticated WordPress user', str_contains($rest, 'is_user_logged_in()'));
$check('session endpoint is rate limited', str_contains($rest, 'RATE_LIMIT') && str_contains($rest, 'get_transient'));
$check('session endpoint returns no credentials or room name', !str_contains($rest, 'api_secret') && !str_contains($rest, "'room' =>"));

$js = $read('theme/assets/js/music-classroom.js');
$check('frontend never contains a LiveKit API secret', !str_contains($js, 'api_secret') && !str_contains($js, 'API_SECRET'));
$check('Music Mode uses the 128 kbps stereo preset', str_contains($js, 'musicHighQualityStereo'));
$check('Music Mode disables DTX and RED', str_contains($js, 'dtx: false') && str_contains($js, 'red: false'));
$check('Music Mode requests 48 kHz stereo capture', str_contains($js, 'sampleRate: 48000') && str_contains($js, 'channelCount: 2'));
$check('Music Mode disables AGC, noise suppression, and echo cancellation', str_contains($js, 'echoCancellation: false') && str_contains($js, 'noiseSuppression: false') && str_contains($js, 'autoGainControl: false'));
$check('mode switch re-publishes without a classroom reload', str_contains($js, 'changeMusicMode') && str_contains($js, 'setMicrophoneEnabled(false)'));
$check('reconnect is bounded and backed off', str_contains($js, 'state.reconnectAttempt >= 4') && str_contains($js, 'Math.pow(2'));
$check('duplicate identities do not cause a reconnect loop', str_contains($js, 'DUPLICATE_IDENTITY'));
$check('permission denial has a Persian message', str_contains($js, 'NotAllowedError') && str_contains($js, 'دسترسی'));
$check('LiveKit failure offers the manual Meet fallback', str_contains($js, 'showMeetFallback'));

$template = $read('theme/template-parts/classroom/music.php');
$check('classroom shows Delnavazan branding', str_contains($template, 'دلنوازان') && str_contains($template, 'delnavazan-logo.png'));
$check('classroom shows teacher, student, and course', str_contains($template, 'dzn-classroom-teacher') && str_contains($template, 'dzn-classroom-student') && str_contains($template, 'dzn-classroom-course'));
$check('classroom shows connection and lesson status', str_contains($template, 'dzn-classroom-connection') && str_contains($template, 'dzn-classroom-lesson-status'));
$check('classroom has a watermark in every video tile', substr_count($template, 'dzn-classroom-watermark') === 2);
$check('classroom exposes the explicit Meet fallback form', str_contains($template, 'dzn_livekit_meet_fallback') && str_contains($template, 'ورود با گوگل میت'));
$check('classroom has no internal booking reference in the DOM', !str_contains($template, 'confirmation_uid') && !str_contains($template, 'lesson_uid'));

$css = $read('theme/assets/css/music-classroom.css');
$check('watermark is positioned in a video corner with logical RTL properties', str_contains($css, '.dzn-classroom-watermark') && str_contains($css, 'inset-inline-end'));
$check('layout is responsive for phone and tablet', str_contains($css, '@media (max-width: 900px)') && str_contains($css, '@media (max-width: 640px)') && str_contains($css, 'pointer: coarse'));
$check('RTL and reduced motion are supported', str_contains($css, '[dir="rtl"]') && str_contains($css, 'prefers-reduced-motion'));

$docs = $read('platform/docs/LIVEKIT-MUSIC-CLASSROOM-PROTOTYPE.md');
foreach (array('Architecture', 'Authorization', 'Google Meet', 'Music Mode', 'Watermark', 'Deployment', 'Troubleshooting', 'Rollback', 'Known limitations', 'Recording') as $topic) {
    $check('documentation covers ' . $topic, stripos($docs, $topic) !== false);
}
$check('recording is explicitly out of scope', stripos($docs, 'recording is not implemented') !== false || stripos($docs, 'out of scope') !== false);

echo "LiveKit portal integration contract passed\n";
