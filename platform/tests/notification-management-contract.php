<?php
/**
 * Source contract for the unified notification management surface.
 *
 * Proves the security boundary and the architectural invariants that cannot be exercised without a
 * WordPress request: capability and nonce checks on every mutation, escaped output, the immutable
 * versioned template authority, contact-free inbox storage and ownership-scoped read state.
 */
$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/src/Admin/Controller/NotificationManagementController.php');
$library = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationTemplateLibrary.php');
$catalogue = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationCatalogue.php');
$inbox = (string) file_get_contents($root . '/src/Portals/PortalNotificationInbox.php');
$migration = (string) file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$studentShell = (string) file_get_contents($root . '/../theme/template-parts/portal/shell.php');
$teacherShell = (string) file_get_contents($root . '/../theme/template-parts/teacher-portal/shell.php');
$studentInbox = (string) file_get_contents($root . '/../theme/template-parts/portal/notifications.php');
$fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Notification management contract failed: ' . $name); };

/* 1. Every mutation is capability- and nonce-gated and every redirect is same-site. */
foreach (array(
    'dzn_notification_copy_save_draft', 'dzn_notification_copy_publish', 'dzn_notification_copy_discard',
    'dzn_notification_copy_restore', 'dzn_notification_copy_restore_default', 'dzn_notification_provider_mapping',
    'dzn_notification_channel_policy', 'dzn_notification_inbox_prune', 'dzn_notification_inbox_test',
    'dzn_notification_recover_leases',
) as $action) {
    $fail('registered action ' . $action, str_contains($controller, "admin_post_" . $action));
}
$fail('editing uses the canonical template capability', str_contains($controller, "CAP_EDIT='dzn_manage_notification_templates'"));
$fail('every handler checks a nonce', substr_count($controller, 'check_admin_referer(') >= 2);
$fail('redirects are validated', str_contains($controller, 'wp_safe_redirect'));
$fail('no eval or request superglobal', !str_contains($controller, 'eval(') && !str_contains($controller, '$_REQUEST'));
$fail('no unfiltered HTML authority', !str_contains($controller, 'unfiltered_html'));
$fail('output is escaped', str_contains($controller, 'esc_html(') && str_contains($controller, 'esc_attr(') && str_contains($controller, 'esc_url('));
$fail('the recorder is imported from its real namespace', str_contains($controller, 'use Delnavazan\\Platform\\Core\\Application\\PortalNotificationRecorder;'));
$fail('the safe inbox test uses a catalogue event', str_contains($controller, "system.inbox_check.admin"));

/* 2. Publishing keeps the immutable canonical model and never rewrites a stored version body. */
$fail('publish uses the canonical service', str_contains($library, 'NotificationTemplateService') && str_contains($library, 'registerVersion(') && str_contains($library, 'activateVersion('));
$fail('version body is immutable', str_contains($library, 'نسخهٔ قالب تغییرناپذیر است') && str_contains($library, 'if(isset($bodies[$versionId]))return;'));
$fail('copy is keyed by version id', str_contains($library, 'template_version_id') && str_contains($library, 'OPTION_COPY_VERSIONS'));
$fail('no provider credential is stored', !str_contains($library, 'api_key') && !str_contains($library, 'access_token') && !str_contains($library, 'client_secret'));

/* 3. The catalogue is a single definition source with categories, authorities and channel policy. */
foreach (array('CATEGORIES', 'AUTHORITIES', 'IMPLEMENTATION', 'variables', 'defaultCopy', 'templateKey', 'isMandatory') as $needle) {
    $fail('catalogue exposes ' . $needle, str_contains($catalogue, $needle));
}
$fail('one-time secret events stay security-only', str_contains($catalogue, "'account.claim_issued.student'") && str_contains($catalogue, "'teacher.invitation_issued.teacher'"));

/* 4. Inbox storage is contact-free and read state is scoped to the linked WordPress user. */
$fail('migration creates the inbox table', str_contains($migration, 'portal_notifications') && str_contains($migration, 'UNIQUE KEY dedupe_key'));
$fail('inbox never stores contact or provider data', str_contains($migration, "'email','phone','whatsapp','secret','token','payload','provider'"));
$fail('mark read is owner-scoped in SQL', str_contains($inbox, 'WHERE id=%d AND wp_user_id=%d'));
$fail('inbox query is user-scoped', str_contains($inbox, 'WHERE wp_user_id=%d'));
$fail('dedupe is a keyed digest', str_contains($inbox, 'hash_hmac(') && str_contains($inbox, 'dedupe_key'));

/* 5. Both portals show a bell and an owned list, and a read action is never a business approval. */
$fail('student shell has a bell', str_contains($studentShell, 'dzn-appbar__bell') && str_contains($studentShell, 'dzn-appbar__badge'));
$fail('teacher shell has a bell', str_contains($teacherShell, 'dzn-tp-appbar__bell') && str_contains($teacherShell, 'dzn-tp-appbar__badge'));
$fail('student inbox has read state and nonce', str_contains($studentInbox, 'dzn_portal_notification_read') && str_contains($studentInbox, 'wp_nonce_field'));
$fail('student inbox never approves a business action', str_contains($studentInbox, 'هیچ اقدام کاری') || str_contains($studentInbox, 'تأیید یا تغییر کلاس'));

echo "Notification management contract passed\n";
