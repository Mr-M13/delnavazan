<?php
/**
 * Notification admin language separation contract.
 *
 * The WordPress administration surface is English. Notification copy sent to students and teachers,
 * and the portal inbox presentation, stay Persian with RTL metadata. This is a source + pure-render
 * guard; it needs no WordPress or database.
 */
$root = dirname(__DIR__);
$repo = dirname($root);
$controller = (string) file_get_contents($root . '/src/Admin/Controller/NotificationManagementController.php');
$menu = (string) file_get_contents($root . '/src/Admin/Controller/Menu.php');
$shell = (string) file_get_contents($root . '/src/Admin/Controller/AdminShellController.php');
$catalogueSource = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationCatalogue.php');
$studentInbox = (string) file_get_contents($repo . '/theme/template-parts/portal/notifications.php');
$teacherInbox = (string) file_get_contents($repo . '/theme/template-parts/teacher-portal/notifications.php');
$fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Notification admin language contract failed: ' . $name); };
$persian = static fn(string $text): bool => preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;

/* 1. Navigation and page chrome are English. */
$fail('menu label is English', str_contains($menu, "'Notifications & Messaging'"));
$fail('settings tab is English', str_contains($shell, "'Notifications & Messaging'"));
$fail('page heading is English', str_contains($controller, '<h1>Notifications &amp; Messaging</h1>'));
foreach (array(
    'templates' => 'Templates', 'rules' => 'Delivery Rules', 'monitoring' => 'Delivery Monitoring',
    'channels' => 'Channels', 'website' => 'Portal Notifications',
) as $slug => $label) {
    $fail('tab ' . $slug . ' is English', str_contains($controller, "'" . $slug . "'=>'" . $label . "'"));
}

/* 2. The admin surface is LTR and contains no Persian UI labels. */
$fail('admin wrapper is LTR', str_contains($controller, 'dir="ltr"') && str_contains($controller, '.dzn-notif-admin{direction:ltr}'));
$fail('no Persian remains in the admin controller', !$persian($controller));
$fail('no Persian remains in the admin menu', !$persian($menu));
foreach (array('Save Draft', 'Publish New Version', 'Discard Draft', 'Restore Default', 'Version History', 'Preview', 'Record Mapping', 'Prune Expired Notifications', 'Delivery Authority', 'Consent Basis', 'Handed off to provider', 'Delivery confirmed') as $label) {
    $fail('English control or status: ' . $label, str_contains($controller, $label));
}

/* 3. Catalogue identifiers stay stable while display labels are English. */
$fail('event keys are unchanged', str_contains($catalogueSource, "'booking.confirmed.student'") && str_contains($catalogueSource, "'attendance.absence_reported.teacher'"));
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root . '/src/Integrations/Notifications/DelnavazanEmailRenderer.php';
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
foreach (NotificationCatalogue::events() as $key => $event) {
    $fail('event label is English: ' . $key, !$persian((string) $event['label']));
    $fail('event category is valid: ' . $key, isset(NotificationCatalogue::CATEGORIES[(string) $event['category']]));
}
foreach (NotificationCatalogue::CATEGORIES as $key => $category) {
    $fail('category label is English: ' . $key, !$persian((string) $category['label']));
    $fail('category description is English: ' . $key, !$persian((string) $category['description']));
}
foreach (NotificationCatalogue::AUTHORITIES as $key => $label) $fail('authority label is English: ' . $key, !$persian((string) $label));
foreach (NotificationCatalogue::IMPLEMENTATION as $key => $label) $fail('implementation label is English: ' . $key, !$persian((string) $label));

/* 4. The actual notification copy stays Persian. */
foreach (array('booking.confirmed.student', 'account.claim_issued.student', 'attendance.absence_reported.teacher', 'reschedule.change.proposed.student', 'post_intro.activated.student') as $eventKey) {
    $copy = NotificationCatalogue::defaultCopy($eventKey, 'email', 'fa-IR');
    $fail('Persian email copy exists: ' . $eventKey, is_array($copy) && $persian((string) $copy['body']));
    $fail('Persian greeting preserved: ' . $eventKey, str_contains((string) $copy['body'], 'سلام'));
}
$fail('English copy remains available for the booking event', !$persian((string) NotificationCatalogue::defaultCopy('booking.confirmed.student', 'email', 'en')['body']));
$fail('preview notice is Persian content', $persian(NotificationCatalogue::previewNotice()));
$fail('English preview notice is English', !$persian(NotificationCatalogue::previewNotice('en')));

/* 5. RTL is scoped to Persian content; the email shell keeps its Persian layout. */
$fa = DelnavazanEmailRenderer::render('عنوان', 'متن پیام');
$fail('Persian email is RTL', str_contains($fa['html'], 'dir="rtl"') && str_contains($fa['html'], 'lang="fa"'));
$en = DelnavazanEmailRenderer::render('Subject', 'Body', array('locale' => 'en'));
$fail('English email is LTR', str_contains($en['html'], 'dir="ltr"') && str_contains($en['html'], 'lang="en"'));
$fail('editor subject is RTL Persian', str_contains($controller, 'id="dzn-notif-subject"') && str_contains($controller, 'dir="rtl" lang="fa"'));
$fail('editor body is RTL Persian', str_contains($controller, 'id="dzn-notif-body"') && str_contains($controller, 'dir="rtl" lang="fa"'));
$fail('text preview is RTL Persian', str_contains($controller, 'dzn-notif-preview--text" dir="rtl" lang="fa"'));

/* 6. Portal notification presentation is unchanged and Persian. */
$fail('student portal inbox stays Persian', $persian($studentInbox) && str_contains($studentInbox, 'dzn-notifications'));
$fail('teacher portal inbox stays Persian', $persian($teacherInbox) && str_contains($teacherInbox, 'dzn-notifications'));

/* 7. Existing authority, nonce and version-history behaviour is untouched. */
foreach (array('check_admin_referer(', 'current_user_can(', 'wp_safe_redirect(', 'Version History', 'Restore Default') as $needle) {
    $fail('preserved admin behaviour: ' . $needle, str_contains($controller, $needle));
}
$fail('template editing still uses the canonical capability', str_contains($controller, "CAP_EDIT='dzn_manage_notification_templates'"));

echo "Notification admin language contract passed\n";
