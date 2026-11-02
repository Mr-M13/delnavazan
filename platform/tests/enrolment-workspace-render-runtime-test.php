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
EnrolmentWorkspaceView::newBookings($read, array());
$intro = (string) ob_get_clean();
$checks = array(
    '<h1>Intro</h1>' => 'The first tab must be titled Intro',
    '<nav class="nav-tab-wrapper dzn-enrolment__tabs"' => 'The sub-tab navigation must render',
    '>Review</a>' => 'The Review tab must render',
    '>Enrolled classes</a>' => 'The Enrolled classes tab must render',
    'Local start time' => 'Create intro must render the local start time field',
    'Local timezone (service area)' => 'Create intro must render the service-area timezone field',
    '<button class="button button-primary">Create</button>' => 'Create intro must use the single Create button',
    'Intro conversion overview' => 'The conversion overview must render',
    '<th>Student</th><th>Course</th><th>Teacher</th><th>Class date</th><th>Time elapsed</th>' => 'The conversion overview must include the requested columns',
);
foreach ($checks as $needle => $message) {
    if (!str_contains($intro, $needle)) throw new RuntimeException($message . ': ' . $needle);
}
if (str_contains($intro, 'Enrolment administration')) throw new RuntimeException('The old Enrolment administration card text is still rendered');
if (str_contains($intro, 'Check this slot')) throw new RuntimeException('The separate preview/check action is still rendered');

ob_start();
EnrolmentWorkspaceView::review($read, array());
$review = (string) ob_get_clean();
foreach (array(
    '<h1>Review</h1>' => 'The Review view must render',
    '<th>Date</th><th>Instrument</th><th>Teacher</th><th>Student</th><th>Status</th><th>Country</th><th>Attended</th><th>Payment link sent</th><th>Reminder</th><th>Follow-up completed</th>' => 'The Review table must include every requested column',
    'removed from this active queue' => 'The Review view must explain the fourteen-day removal rule',
) as $needle => $message) {
    if (!str_contains($review, $needle)) throw new RuntimeException($message . ': ' . $needle);
}

ob_start();
dzn_theme_component('notification-banner', array('items' => array(array('id' => 7, 'title' => 'Synthetic notice', 'message' => 'Synthetic message', 'is_unread' => true, 'date' => 'today', 'link_url' => '')), 'actions' => array(), 'role' => 'student'));
$banner = (string) ob_get_clean();
if (!str_contains($banner, 'data-dzn-notification-banner') || !str_contains($banner, 'data-dzn-notification-close')) throw new RuntimeException('Notification banner did not render for an unread notification');
ob_start();
dzn_theme_component('notification-banner', array('items' => array(), 'actions' => array(), 'role' => 'student'));
$emptyBanner = trim((string) ob_get_clean());
if ($emptyBanner !== '') throw new RuntimeException('Notification banner rendered when no notification exists');

echo "Enrolment workspace render proof passed\n";
