<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\EnrolmentDeskController;
use Delnavazan\Platform\Core\Application\Finance\ManualPaymentSettlementService;

/**
 * List and detail for the Enrolment Desk. Read-only by design: the one action on each step is
 * a link to the existing authority, never a second implementation of it.
 *
 * See docs/ENROLMENT-DESK-SPEC-2026-11-23.md.
 */
final class EnrolmentDeskView {

    /** @param array<int,array<string,mixed>> $rows */
    public static function index( array $rows, int $total, ?array $notice = null ): void {
        echo '<div class="wrap dzn-enrolment-desk"><h1>Enrolment desk</h1>';
        self::notice( $notice );
        echo '<p class="description">One person, one status, one next action. Statuses are derived from the booking and term records; nothing here is a separate source of truth.</p>';
        if ( $rows === array() ) {
            echo '<div class="notice notice-info"><p>No people need attention right now.</p></div></div>';
            return;
        }
        echo '<p><strong>' . esc_html( (string) $total ) . '</strong> in the current queue.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Learner</th><th>Instrument</th><th>Status</th><th>Next action</th><th>Updated</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $url = (int) ( $row['id'] ?? 0 ) > 0
                ? admin_url( 'admin.php?page=' . EnrolmentDeskController::PAGE . '&request_id=' . (int) $row['id'] )
                : (string) ( $row['desk_href'] ?? admin_url( 'admin.php?page=' . EnrolmentDeskController::PAGE ) );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( (string) ( $row['learner_name'] ?? 'Learner' ) ) . '</strong></a><br><small>' . esc_html( (string) ( $row['reference'] ?? '' ) ) . '</small></td>';
            echo '<td>' . esc_html( (string) ( $row['instrument'] ?? '' ) ) . '</td>';
            echo '<td><span class="dzn-desk-status dzn-desk-status--' . esc_attr( (string) ( $row['desk_tone'] ?? 'muted' ) ) . '">' . esc_html( (string) ( $row['desk_status'] ?? '' ) ) . '</span></td>';
            echo '<td><a class="button button-small" href="' . esc_url( (string) ( $row['desk_href'] ?? $url ) ) . '">' . esc_html( (string) ( $row['desk_next'] ?? 'Open' ) ) . '</a></td>';
            echo '<td>' . esc_html( (string) ( $row['age_label'] ?? '' ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** @param array<string,mixed> $person */
    public static function detail( array $person, ?array $notice = null ): void {
        $case  = (array) $person['case'];
        $desk  = (array) $person['desk'];
        $steps = (array) $person['steps'];
        $alerts = (array) $person['alerts'];

        echo '<div class="wrap dzn-enrolment-desk dzn-enrolment-desk--person">';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . EnrolmentDeskController::PAGE ) ) . '">← Back to the desk</a></p>';
        self::notice( $notice );
        echo '<h1>' . esc_html( (string) ( $case['learner_name'] ?? 'Learner' ) ) . '</h1>';
        echo '<p>' . esc_html( (string) ( $case['instrument'] ?? '' ) ) . ' · ' . esc_html( (string) ( $case['course'] ?? '' ) ) . ' · ' . esc_html( (string) ( $case['timezone'] ?? '' ) ) . ' · <code>' . esc_html( (string) ( $case['reference'] ?? '' ) ) . '</code></p>';
        echo '<p><span class="dzn-desk-status dzn-desk-status--' . esc_attr( (string) ( $desk['tone'] ?? 'muted' ) ) . '">' . esc_html( (string) ( $desk['label'] ?? '' ) ) . '</span></p>';

        echo '<ol class="dzn-desk-steps">';
        foreach ( $steps as $step ) {
            $state = (string) ( $step['state'] ?? 'pending' );
            echo '<li class="dzn-desk-step dzn-desk-step--' . esc_attr( $state ) . '">';
            echo '<h2><span class="dzn-desk-step__number">' . esc_html( (string) ( $step['number'] ?? '' ) ) . '</span> ' . esc_html( (string) ( $step['title'] ?? '' ) ) . '</h2>';
            echo '<p><strong>' . esc_html( (string) ( $step['detail'] ?? '' ) ) . '</strong></p>';
            echo '<p class="description">' . esc_html( (string) ( $step['note'] ?? '' ) ) . '</p>';
            echo '</li>';
        }
        echo '</ol>';

        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=dzn-enrolment-workspace&request_id=' . (int) ( $case['id'] ?? 0 ) ) . (string) ( $desk['anchor'] ?? '' ) ) . '">' . esc_html( (string) ( $desk['next'] ?? 'Open the case' ) ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=dzn-enrolment-workspace&request_id=' . (int) ( $case['id'] ?? 0 ) ) ) . '">Open the full case</a></p>';

        self::paymentActions( (array) ( $person['payment'] ?? array() ), (int) ( $case['id'] ?? 0 ) );

        $timeline = (array) ( $case['timeline'] ?? array() );
        if ( $timeline !== array() ) {
            echo '<h2>Evidence</h2><ul class="dzn-desk-timeline">';
            foreach ( array_slice( $timeline, 0, 12 ) as $event ) {
                echo '<li><time>' . esc_html( (string) ( $event['at'] ?? '' ) ) . '</time> · <strong>' . esc_html( (string) ( $event['label'] ?? '' ) ) . '</strong>';
                if ( (string) ( $event['detail'] ?? '' ) !== '' ) {
                    echo ' — ' . esc_html( (string) $event['detail'] );
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        if ( $alerts !== array() ) {
            echo '<h2>Needs attention</h2>';
            foreach ( $alerts as $alert ) {
                echo '<div class="notice notice-warning inline dzn-desk-alert"><p>' . esc_html( (string) ( $alert['text'] ?? '' ) ) . ' <a href="' . esc_url( (string) ( $alert['href'] ?? '#' ) ) . '">' . esc_html( (string) ( $alert['action'] ?? 'Open' ) ) . '</a></p></div>';
            }
        }

        echo '</div>';
    }

    private static function paymentActions( array $payment, int $requestId ): void {
        echo '<h2>Payment</h2>';
        $prepState = (string) ( $payment['preparation_state'] ?? '' );
        $prepUid   = (string) ( $payment['preparation_uid'] ?? '' );
        $offerUid  = (string) ( $payment['offer_uid'] ?? '' );
        $currency  = (string) ( $payment['currency'] ?? '' );
        $amount    = (int) ( $payment['amount_minor'] ?? 0 );

        if ( $prepUid === '' ) {
            echo '<p class="description">No continuation is prepared yet. Record the introductory stage on the full case first; the payment step follows from it.</p>';
            return;
        }
        echo '<p>Continuation: <strong>' . esc_html( $prepState !== '' ? $prepState : 'unknown' ) . '</strong>';
        if ( $offerUid !== '' ) {
            echo ' · Offer <code>' . esc_html( $offerUid ) . '</code> · ' . esc_html( $currency ) . ' ' . esc_html( number_format_i18n( $amount / 100, 2 ) );
        }
        echo '</p>';

        if ( $offerUid === '' ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( EnrolmentDeskController::ACTION );
            echo '<input type="hidden" name="action" value="' . esc_attr( EnrolmentDeskController::ACTION ) . '">';
            echo '<input type="hidden" name="operation" value="send_payment_link"><input type="hidden" name="request_id" value="' . esc_attr( (string) $requestId ) . '">';
            echo '<p><label>What was agreed<textarea name="note" rows="2" maxlength="1000" required placeholder="Who confirmed the term and plan, how, and when"></textarea></label></p>';
            echo '<p><button class="button button-primary">Send payment link</button> <span class="description">Issues the offer and queues the payment invitation. No class exists until the payment is verified. The Student chooses full payment or instalments in their account; the Desk does not choose the plan.</span></p>';
            echo '</form>';
            return;
        }

        $next = (int) ( $payment['next_sequence'] ?? 0 );
        if ( $next < 1 ) {
            echo '<p class="description">Every obligation on this offer is settled. The activation job creates the term and its classes.</p>';
            return;
        }
        if ( ! ManualPaymentSettlementService::canSettleStandard() ) {
            echo '<p class="description">Obligation ' . esc_html( (string) $next ) . ' is unpaid. Recording an offline payment needs both the payment-execution and the payment-evidence ingestion authorities.</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( EnrolmentDeskController::ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( EnrolmentDeskController::ACTION ) . '">';
        echo '<input type="hidden" name="operation" value="record_offline_payment"><input type="hidden" name="request_id" value="' . esc_attr( (string) $requestId ) . '">';
        echo '<p><label>Receipt or verified reference<input type="text" name="evidence_reference" required maxlength="191"></label></p>';
        echo '<p><label>Received on<input type="date" name="received_on" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label></p>';
        echo '<p><button class="button">Record offline payment</button> <span class="description">Obligation ' . esc_html( (string) $next ) . '. The entry is attributed to you and audited.</span></p>';
        echo '</form>';
    }

    private static function notice( ?array $notice ): void {
        if ( ! is_array( $notice ) || (string) ( $notice['message'] ?? '' ) === '' ) {
            return;
        }
        $class = ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
    }
}
