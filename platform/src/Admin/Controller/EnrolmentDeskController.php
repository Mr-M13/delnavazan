<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\EnrolmentDeskView;
use Delnavazan\Platform\Core\Application\EnrolmentDeskReadService;

/**
 * The Enrolment Desk: one list, one detail page, one next action per person.
 *
 * This controller is read-only. Every command keeps its existing service and screen; the Desk
 * shows what is true and links to the single control that changes it. See
 * docs/ENROLMENT-DESK-SPEC-2026-11-23.md.
 */
final class EnrolmentDeskController {
    public const PAGE = 'dzn-enrolment-desk';
    public const CAP  = 'dzn_use_enrolment_desk';

    public static function screen(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Forbidden', 'Delnavazan', array( 'response' => 403 ) );
        }
        $requestId = absint( $_GET['request_id'] ?? 0 );
        $read      = new EnrolmentDeskReadService();

        if ( $requestId > 0 ) {
            $person = $read->person( $requestId );
            if ( $person === null ) {
                self::notFound();
                return;
            }
            EnrolmentDeskView::detail( $person );
            return;
        }

        $list = $read->people( 60 );
        EnrolmentDeskView::index( $list['rows'], $list['total'] );
    }

    private static function notFound(): void {
        echo '<div class="wrap"><h1>Enrolment desk</h1><div class="notice notice-error"><p>That person could not be found.</p></div>';
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Back to the desk</a></p></div>';
    }
}
