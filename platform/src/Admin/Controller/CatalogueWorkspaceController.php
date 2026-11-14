<?php
namespace Delnavazan\Platform\Admin\Controller;

/** Protected legacy bookmark; catalogue operations now live on Enrolment. */
final class CatalogueWorkspaceController {
    public static function screen(): void {
        if (!current_user_can(Menu::CATALOGUE_WORKSPACE_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        echo '<div class="wrap"><h1>Instruments</h1><p>Instrument offers and their courses are managed on the Enrolment page.</p>';
        if (current_user_can(Menu::ENROLMENT_WORKSPACE_CAP)) echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . EnrolmentWorkspaceController::PAGE)) . '">Open Enrolment</a></p>';
        echo '</div>';
    }
}
