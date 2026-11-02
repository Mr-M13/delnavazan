<?php
/** Source contract for the administrator-only recording-notice publication surface. */
$root = dirname(__DIR__);
$repo = dirname($root);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$controller = $read('src/Admin/Controller/RecordingNoticeAdminController.php');
$service = $read('src/Core/Application/RecordingNoticePublicationService.php');
$plugin = $read('delnavazan-platform.php');
$shell = $read('src/Admin/Controller/AdminShellController.php');
$authority = $read('src/Core/Application/RecordingDecisionAuthorityService.php');
$fail = array();
$need = static function (bool $ok, string $message) use (&$fail): void { if (!$ok) $fail[] = $message; };

$need(str_contains($controller, "public const CAPABILITY = 'manage_options'"), 'publication must be administrator-only');
$need(str_contains($controller, "public const MENU_SLUG = 'dzn-recording-notices'"), 'the publication route must be stable');
$need(str_contains($controller, "public const ACTION = 'dzn_recording_notice_command'"), 'the publication action must be fixed');
$need(str_contains($controller, 'current_user_can(self::CAPABILITY)'), 'the page and command must re-check capability');
$need(str_contains($controller, 'check_admin_referer(self::ACTION)'), 'the command must verify a nonce');
$need(str_contains($controller, "add_action('admin_post_' . self::ACTION"), 'the command must be an authenticated admin-post action');
$need(str_contains($controller, "'POST' !== strtoupper"), 'the command must reject non-POST requests');
$need(!str_contains($controller, 'admin_post_nopriv_'), 'the publication command must never be anonymous');
$need(str_contains($controller, 'wp_safe_redirect(') && !str_contains($controller, 'wp_redirect('), 'the command must use a safe redirect');

foreach (array(
    "_POST['version']", "_POST['version_number']", "_POST['uid']", "_POST['notice_uid']",
    "_POST['created_by']", "_POST['published_at']", "_POST['text_digest']", "_POST['publication_state']",
) as $forbidden) {
    $need(!str_contains($controller, $forbidden), 'the browser must not supply ' . $forbidden);
}
$need(str_contains($controller, 'get_current_user_id()'), 'the actor must be resolved server-side');

$need(str_contains($service, 'Identifier::uid()'), 'every version must receive a server-generated UID');
$need(str_contains($service, "hash('sha256',\$body)"), 'the stored text digest must be derived from the stored text');
$need(str_contains($service, "'publication_state'=>'published'"), 'publishing must set the published state itself');
$need(str_contains($service, "'created_by'=>\$actor"), 'the creating actor must be recorded');
$need(str_contains($service, 'MAX(version_number)'), 'the version number must be derived server-side');
$need(str_contains($service, "WHERE audience_code=%s AND language_code=%s AND publication_state='published' AND withdrawn_at IS NULL"), 'withdrawal must close every published version for the audience and language');
$need(str_contains($service, "SET withdrawn_at=%s"), 'withdrawal must be recorded on the row');
$need(str_contains($service, "recording_notice_body_required"), 'empty approved text must be refused');
$need(str_contains($service, "recording_notice_audience_invalid"), 'unsupported audiences must be refused');
$need(str_contains($service, "recording_decision_language_invalid"), 'unsupported languages must be refused');
$need(str_contains($service, 'wp_kses_post('), 'approved notice text must be sanitized before storage');
$need(str_contains($service, 'RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS'), 'Teacher terms must be a supported audience');
$need(str_contains($service, 'RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING'), 'Student recording must be a supported audience');
$need(str_contains($service, 'RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH'), 'Student research must be a supported audience');

$need(str_contains($plugin, 'RecordingNoticeAdminController::register()'), 'the plugin must register the publication controller');
$need(str_contains($shell, "'dzn-recording-notices'"), 'the administrator must be able to reach the protected publication route');
$need(str_contains($shell, "'dzn-recording-notices' => 'settings'"), 'the page must belong to the Settings area');
$need(!str_contains($shell, "['Recording notices', 'dzn-recording-notices'"), 'recording notices must not be a separate visible Settings entry');

$need(!str_contains($controller, 'recording_provider') && !str_contains($service, 'recording_provider'), 'publication must not add a capture provider');
$need(!str_contains($controller, 'wp_remote_') && !str_contains($service, 'wp_remote_'), 'publication must not transport media');
$need(!str_contains($controller, 'curl_') && !str_contains($service, 'curl_'), 'publication must not transport media');
$need(str_contains($authority, 'CAPTURE_ENABLED=false') && str_contains($authority, 'RESEARCH_EXPORT_ENABLED=false'), 'capture and research export must stay disabled');

if ($fail) {
    echo "Recording notice publication contract failed:\n- " . implode("\n- ", $fail) . "\n";
    exit(1);
}
echo "Recording notice publication contract passed\n";
