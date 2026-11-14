<?php
/** The shared modal and embedded coordination stages stay connected. */
use Delnavazan\Platform\Admin\View\EnrolmentWorkspaceView;

require_once __DIR__ . '/../src/Admin/View/EnrolmentWorkspaceView.php';
ob_start();
EnrolmentWorkspaceView::modal();
$modal = (string) ob_get_clean();
foreach (array('<dialog', 'aria-labelledby="dzn-class-admin-title"', 'data-dzn-class-admin-frame', 'data-dzn-class-admin-close') as $needle) {
    if (!str_contains($modal, $needle)) throw new RuntimeException('Class admin modal is missing ' . $needle);
}

$coordination = (string) file_get_contents(__DIR__ . '/../src/Admin/View/CoordinationWorkspaceView.php');
foreach (array('function embeddedDetail', 'data-dzn-stage="prepare"', 'data-dzn-stage="acceptance"', 'data-dzn-stage="enrolment"', 'data-dzn-stage="evidence"', 'dzn_record_request_id') as $needle) {
    if (!str_contains($coordination, $needle)) throw new RuntimeException('Embedded coordination lost ' . $needle);
}

$script = (string) file_get_contents(__DIR__ . '/../src/Admin/Assets/admin.js');
foreach (array('dzn-class-admin-updated', 'dzn-class-admin-external', '[data-dzn-history]', 'data-dzn-stage', 'event.metaKey', 'revealFrameAnchor') as $needle) {
    if (!str_contains($script, $needle)) throw new RuntimeException('Class admin modal behavior lost ' . $needle);
}

echo "Class admin modal contract passed\n";
