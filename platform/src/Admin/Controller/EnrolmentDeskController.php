<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\EnrolmentDeskView;
use Delnavazan\Platform\Core\Application\EnrolmentDeskReadService;
use Delnavazan\Platform\Core\Application\Finance\ManualPaymentSettlementService;
use Delnavazan\Platform\Core\Application\PostIntroContinuationService;

/**
 * The Enrolment Desk: one list, one detail page, one next action per person.
 *
 * Commands here are thin: every one calls an existing authority with server-resolved
 * identifiers, never a value from the form. See docs/ENROLMENT-DESK-SPEC-2026-11-23.md.
 */
final class EnrolmentDeskController {
    public const PAGE = 'dzn-enrolment-desk';
    public const CAP  = 'dzn_use_enrolment_desk';
    public const ACTION = 'dzn_enrolment_desk';

    public static function register(): void {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handlePost' ) );
    }

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
            EnrolmentDeskView::detail( $person, self::notice() );
            return;
        }

        $list = $read->people( 60 );
        EnrolmentDeskView::index( $list['rows'], $list['total'], self::notice() );
    }

    public static function handlePost(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Forbidden', 'Delnavazan', array( 'response' => 403 ) );
        }
        check_admin_referer( self::ACTION );

        $operation = sanitize_key( (string) ( $_POST['operation'] ?? '' ) );
        $requestId = absint( $_POST['request_id'] ?? 0 );
        $return    = admin_url( 'admin.php?page=' . self::PAGE . '&request_id=' . $requestId );

        try {
            $message = self::dispatch( $operation, $requestId, (array) wp_unslash( $_POST ) );
            self::redirect( $return, $message, false );
        } catch ( \Throwable $error ) {
            self::redirect( $return, self::message( $error ), true );
        }
    }

    /** @param array<string,mixed> $post */
    private static function dispatch( string $operation, int $requestId, array $post ): string {
        if ( $requestId < 1 ) {
            throw new \InvalidArgumentException( 'Choose a person first.' );
        }
        switch ( $operation ) {
            case 'send_payment_link':
                self::requireCap( 'dzn_manage_post_intro_automation' );
                $note = trim( sanitize_textarea_field( (string) ( $post['note'] ?? '' ) ) );
                if ( $note === '' ) {
                    throw new \InvalidArgumentException( 'Record what was agreed and by whom before sending the link.' );
                }
                $read = new EnrolmentDeskReadService();
                $facts = $read->paymentFacts( $requestId );
                if ( $facts['preparation_uid'] === '' ) {
                    throw new \InvalidArgumentException( 'post_intro_invitation_not_found' );
                }
                if ( ! in_array( (string) $facts['preparation_state'], array( 'prepared', 'checkout_pending' ), true ) ) {
                    throw new \InvalidArgumentException( 'post_intro_invitation_not_ready' );
                }
                $result = ( new PostIntroContinuationService() )->continueAsAdministrator(
                    (string) $facts['preparation_uid'],
                    (int) get_current_user_id(),
                    array(
                        'plan_kind'    => 'full',
                        'payment_mode' => 'pending',
                        'note'         => $note,
                    )
                );
                $state = (string) ( $result['state'] ?? 'unknown' );
                return 'Payment step opened (' . $state . '). The offer is issued and the payment link is queued; no class exists until the payment is verified. The Student chooses full payment or instalments in their account.';

            case 'record_offline_payment':
                self::requireCap( 'dzn_manage_payment_execution' );
                if ( ! ManualPaymentSettlementService::canSettleStandard() ) {
                    throw new \RuntimeException( 'manual_payment_ingest_authority_required' );
                }
                $receipt = trim( sanitize_text_field( (string) ( $post['evidence_reference'] ?? '' ) ) );
                if ( $receipt === '' ) {
                    throw new \InvalidArgumentException( 'Record the receipt or verified reference for an offline payment.' );
                }
                $receivedOn = trim( sanitize_text_field( (string) ( $post['received_on'] ?? '' ) ) );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $receivedOn ) ) {
                    // Never substitute today: a wrong date is a wrong audit fact. See OPS-05.
                    throw new \InvalidArgumentException( 'manual_payment_received_on_required' );
                }
                $read  = new EnrolmentDeskReadService();
                $facts = $read->paymentFacts( $requestId );
                if ( $facts['offer_uid'] === '' || $facts['next_sequence'] < 1 ) {
                    throw new \InvalidArgumentException( 'manual_payment_obligation_required' );
                }
                ( new ManualPaymentSettlementService() )->markStandardPaid(
                    array(
                        'offer_uid'          => (string) $facts['offer_uid'],
                        'obligation_sequence'=> (int) $facts['next_sequence'],
                        'evidence_reference' => $receipt,
                        'received_on'        => $receivedOn,
                    ),
                    'desk-offline-payment-' . (string) $facts['offer_uid'] . '-' . (int) $facts['next_sequence']
                );
                return 'Offline payment recorded against obligation ' . (int) $facts['next_sequence'] . '. The activation job now creates the term and its classes.';

            default:
                throw new \InvalidArgumentException( 'Unsupported action.' );
        }
    }

    private static function requireCap( string $capability ): void {
        if ( ! current_user_can( $capability ) ) {
            throw new \RuntimeException( 'Unauthorized' );
        }
    }

    private static function message( \Throwable $error ): string {
        if ( $error instanceof \Error ) {
            return 'The platform failed internally while processing this action. Nothing was saved.';
        }
        return match ( $error->getMessage() ) {
            'Unauthorized'                          => 'You do not have the authority for that action.',
            'post_intro_invitation_not_found'        => 'No continuation is prepared for this person yet. Record the introductory stage first.',
            'post_intro_invitation_not_ready'        => 'The continuation is not ready. Review the case before sending a link.',
            'post_intro_invitation_expired'          => 'The continuation window has expired. A new request is required.',
            'plan_unavailable'                       => 'No full-term plan is available for this person. Check the catalogue and region price.',
            'manual_payment_obligation_required'     => 'There is no unpaid obligation on this offer.',
            'manual_payment_offer_required'          => 'The offer could not be found. Send the payment link first.',
            'manual_payment_ingest_authority_required' => 'Recording an offline payment needs both the payment-execution and the payment-evidence ingestion authorities.',
            'manual_payment_received_on_required'       => 'Record the date the payment was received, as YYYY-MM-DD.',
            default                                  => ucfirst( str_replace( '_', ' ', $error->getMessage() ) ) . '.',
        };
    }

    private static function redirect( string $url, string $message, bool $error ): never {
        set_transient( 'dzn_desk_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 120 );
        wp_safe_redirect( $url );
        exit;
    }

    /** @return array{message:string,error:bool}|null */
    private static function notice(): ?array {
        $key    = 'dzn_desk_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) ) {
            return null;
        }
        delete_transient( $key );
        return array( 'message' => (string) ( $notice['message'] ?? '' ), 'error' => ! empty( $notice['error'] ) );
    }

    private static function notFound(): void {
        echo '<div class="wrap"><h1>Enrolment desk</h1><div class="notice notice-error"><p>That person could not be found.</p></div>';
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Back to the desk</a></p></div>';
    }
}
