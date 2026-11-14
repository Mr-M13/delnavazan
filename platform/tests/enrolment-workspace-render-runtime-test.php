<?php
/** Disposable render check for the Intro / Review Enrolment workspace tabs. */
if (getenv('DZN_ENROLMENT_RENDER_RUNTIME_TEST') !== 'local_only' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Enrolment workspace render test refused.\n");
    exit(1);
}
wp_set_current_user(1);
use Delnavazan\Platform\Admin\View\EnrolmentWorkspaceView;
use Delnavazan\Platform\Core\Application\EnrolmentWorkspaceReadService;

$read = new EnrolmentWorkspaceReadService();
ob_start();
EnrolmentWorkspaceView::dashboard($read, array());
$intro = (string) ob_get_clean();
$checks = array(
    '<h1>Enrolment</h1>' => 'The unified Enrolment surface must render',
    'Overview snapshot' => 'The overview snapshot must render',
    'Needs attention first' => 'The class queue must put attention first',
    'Create standard class' => 'Manual standard class creation must render',
    'Local start time' => 'Create intro must render the local start time field',
    'Local timezone (service area)' => 'Create intro must render the service-area timezone field',
    '<button class="button button-primary">Create</button>' => 'Create intro must use the single Create button',
    'Class history' => 'The class history disclosure must render',
    'Offered instruments' => 'The public offer must render',
    'data-dzn-open-dialog="dzn-create-intro"' => 'The intro action must open a dialog',
);
foreach ($checks as $needle => $message) {
    if (!str_contains($intro, $needle)) throw new RuntimeException($message . ': ' . $needle);
}
if (str_contains($intro, '<nav class="nav-tab-wrapper dzn-enrolment__tabs"')) throw new RuntimeException('Enrolment must not render sub-tabs');
if (str_contains($intro, '>Enrolled classes</a>')) throw new RuntimeException('The old Enrolled classes tab is still rendered');
if (str_contains($intro, 'Enrolment administration')) throw new RuntimeException('The old Enrolment administration card text is still rendered');
if (str_contains($intro, 'Check this slot')) throw new RuntimeException('The separate preview/check action is still rendered');

ob_start();
dzn_theme_component('notification-banner', array('items' => array(array('id' => 7, 'title' => 'Synthetic notice', 'message' => 'Synthetic message', 'is_unread' => true, 'date' => 'today', 'link_url' => '')), 'actions' => array(), 'role' => 'student'));
$banner = (string) ob_get_clean();
if (!str_contains($banner, 'data-dzn-notification-banner') || !str_contains($banner, 'data-dzn-notification-close')) throw new RuntimeException('Notification banner did not render for an unread notification');
ob_start();
dzn_theme_component('notification-banner', array('items' => array(), 'actions' => array(), 'role' => 'student'));
$emptyBanner = trim((string) ob_get_clean());
if ($emptyBanner !== '') throw new RuntimeException('Notification banner rendered when no notification exists');

echo "Enrolment workspace render proof passed\n";
