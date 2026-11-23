<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only projection for the Enrolment Desk.
 *
 * One list, one detail page, one next action. The Desk deliberately reads through
 * EnrolmentWorkspaceReadService so it can never diverge from the records the rest of the
 * admin uses, and it performs no writes: every command stays with the existing services.
 *
 * See docs/ENROLMENT-DESK-SPEC-2026-11-23.md.
 */
final class EnrolmentDeskReadService {

    /**
     * Plain-language status for a journey stage. Kept as a pure lookup so the contract can
     * pin the vocabulary and the next action rather than the SQL.
     */
    private const STATUSES = array(
        'identity'         => array( 'label' => 'Identity not verified', 'next' => 'Verify the Student identity', 'tone' => 'warn', 'anchor' => '' ),
        'new'              => array( 'label' => 'New request',           'next' => 'Match a Teacher',              'tone' => 'warn', 'anchor' => '' ),
        'awaiting_teacher' => array( 'label' => 'Waiting for a Teacher', 'next' => 'Review the Teacher offer',     'tone' => 'warn', 'anchor' => '' ),
        'trial_scheduled'  => array( 'label' => 'Intro booked',          'next' => 'Open the intro',               'tone' => 'ok',   'anchor' => '' ),
        'provisioning'     => array( 'label' => 'Intro being prepared',  'next' => 'Review the classroom',         'tone' => 'warn', 'anchor' => '' ),
        'outcome_due'      => array( 'label' => 'Outcome due',           'next' => 'Record the outcome',           'tone' => 'warn', 'anchor' => '#dzn-record-outcome' ),
        'continuation'     => array( 'label' => 'Waiting for payment',   'next' => 'Open the payment step',        'tone' => 'warn', 'anchor' => '#dzn-execute-agreement' ),
        'enrolled'         => array( 'label' => 'Active term',           'next' => 'Open the enrolment',           'tone' => 'ok',   'anchor' => '' ),
        'declined'         => array( 'label' => 'Declined',              'next' => 'View history',                 'tone' => 'muted', 'anchor' => '' ),
        'closed'           => array( 'label' => 'Closed',                'next' => 'View history',                 'tone' => 'muted', 'anchor' => '' ),
        'needs_review'     => array( 'label' => 'Needs review',          'next' => 'Review the mixed records',     'tone' => 'warn', 'anchor' => '' ),
    );

    public function __construct( private ?EnrolmentWorkspaceReadService $workspace = null ) {
        $this->workspace ??= new EnrolmentWorkspaceReadService();
    }

    /** @return array{label:string,next:string,tone:string,anchor:string} */
    public static function status( string $stageKey ): array {
        if ( isset( self::STATUSES[ $stageKey ] ) ) {
            return self::STATUSES[ $stageKey ];
        }
        return array(
            'label'  => ucfirst( str_replace( '_', ' ', $stageKey ) ),
            'next'   => 'Open the case',
            'tone'   => 'muted',
            'anchor' => '',
        );
    }

    /**
     * One row per person. Rows are the composed booking summaries, decorated with the Desk
     * status, so the list and the case page can never disagree about the underlying facts.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function people( int $limit = 60 ): array {
        $limit = max( 20, min( 60, $limit ) );
        $rows = array();
        $seen = array();

        // 1. The intake queue: requests that still need a Teacher, a slot or an identity.
        $page = $this->workspace->bookingCases( array( 'per_page' => $limit ), false );
        foreach ( (array) ( $page['rows'] ?? array() ) as $row ) {
            $row = (array) $row;
            $id  = (int) ( $row['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $seen[ $id ] = true;
            $rows[] = $this->decorate( $row );
        }

        // 2. Confirmed and awaiting-payment introductions. Their request lifecycle is already
        //    closed/teacher_confirmed, so the intake queue never lists them; without this the
        //    Desk would be empty exactly when a person is booked and waiting.
        foreach ( $this->confirmedIntros( $limit, $seen ) as $row ) {
            $rows[] = $this->decorate( $row );
        }

        usort( $rows, static function ( array $a, array $b ): int {
            $left  = (string) ( $a['sort_at'] ?? $a['created_at'] ?? '' );
            $right = (string) ( $b['sort_at'] ?? $b['created_at'] ?? '' );
            return strcmp( $right, $left );
        } );

        return array( 'rows' => array_slice( $rows, 0, $limit ), 'total' => count( $rows ) );
    }

    /**
     * Requests whose latest confirmation is confirmed or awaiting payment, with the
     * authoritative journey status so the Desk cannot invent its own vocabulary.
     *
     * @param array<int,bool> $exclude
     * @return array<int,array<string,mixed>>
     */
    private function confirmedIntros( int $limit, array $exclude ): array {
        global $wpdb;
        $p   = $wpdb->prefix . 'dzn_';
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT r.id
               FROM {$p}booking_requests r
               INNER JOIN {$p}booking_request_confirmations c ON c.id=(
                   SELECT MAX(cf.id) FROM {$p}booking_request_confirmations cf WHERE cf.booking_request_id=r.id
               )
              WHERE r.privacy_erased_at IS NULL
                AND c.state IN ('confirmed','awaiting_payment')
              ORDER BY c.starts_at_utc DESC
              LIMIT %d",
            $limit
        ) );
        if ( ! is_array( $ids ) ) {
            return array();
        }
        $journey = new BookingJourneyReadService();
        $rows    = array();
        foreach ( $ids as $rawId ) {
            $requestId = (int) $rawId;
            if ( $requestId < 1 || isset( $exclude[ $requestId ] ) ) {
                continue;
            }
            $case = $this->workspace->bookingCase( $requestId );
            if ( ! $case ) {
                continue;
            }
            $status    = $journey->status( $requestId );
            $stageKey  = is_array( $status ) ? (string) ( $status['status'] ?? '' ) : '';
            if ( $stageKey !== '' ) {
                $case['stage_key'] = $stageKey;
            }
            $rows[] = (array) $case;
        }
        return $rows;
    }

    /**
     * One person: the composed booking case plus the Desk status. The detail view renders the
     * five steps from these facts; it does not query anything itself.
     *
     * @return array<string,mixed>|null
     */
    public function person( int $requestId ): ?array {
        if ( $requestId < 1 ) {
            return null;
        }
        $case = $this->workspace->bookingCase( $requestId );
        if ( ! $case ) {
            return null;
        }
        return array(
            'case'    => $case,
            'desk'    => self::status( (string) ( $case['stage_key'] ?? '' ) ),
            'steps'   => $this->steps( $case ),
            'alerts'  => $this->alerts( $case ),
            'payment' => $this->paymentFacts( $requestId ),
        );
    }

    /**
     * Payment facts for step 4. Read-only: the preparation and the offer are the records the
     * continuation writes; the Desk only displays them and never computes an amount itself.
     *
     * @return array<string,mixed>
     */
    public function paymentFacts( int $requestId ): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $facts = array(
            'preparation_uid'   => '',
            'preparation_state' => '',
            'offer_uid'         => '',
            'offer_state'       => '',
            'amount_minor'      => 0,
            'currency'          => '',
            'next_sequence'     => 0,
            'next_amount_minor' => 0,
            'obligations'       => array(),
        );
        if ( $requestId < 1 ) {
            return $facts;
        }
        $prep = $wpdb->get_row( $wpdb->prepare(
            "SELECT uid,state,offer_id FROM {$p}post_intro_preparations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
            $requestId
        ) );
        if ( ! $prep ) {
            return $facts;
        }
        $facts['preparation_uid']   = (string) $prep->uid;
        $facts['preparation_state'] = (string) $prep->state;
        if ( (int) $prep->offer_id < 1 ) {
            return $facts;
        }
        $offer = $wpdb->get_row( $wpdb->prepare(
            "SELECT uid,state,amount_due_minor,currency FROM {$p}commercial_offers WHERE id=%d",
            (int) $prep->offer_id
        ) );
        if ( $offer ) {
            $facts['offer_uid']    = (string) $offer->uid;
            $facts['offer_state']  = (string) $offer->state;
            $facts['amount_minor'] = (int) $offer->amount_due_minor;
            $facts['currency']     = (string) $offer->currency;
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.obligation_sequence,o.amount_minor,o.currency,CASE WHEN s.id IS NULL THEN 0 ELSE 1 END settled
               FROM {$p}commercial_offer_obligations o
               LEFT JOIN {$p}commercial_obligation_settlements s ON s.obligation_id=o.id
              WHERE o.offer_id=%d ORDER BY o.obligation_sequence ASC",
            (int) $prep->offer_id
        ) );
        foreach ( (array) $rows as $row ) {
            $settled = (int) $row->settled === 1;
            $facts['obligations'][] = array(
                'sequence'     => (int) $row->obligation_sequence,
                'amount_minor' => (int) $row->amount_minor,
                'currency'     => (string) $row->currency,
                'settled'      => $settled,
            );
            if ( ! $settled && $facts['next_sequence'] === 0 ) {
                $facts['next_sequence']     = (int) $row->obligation_sequence;
                $facts['next_amount_minor'] = (int) $row->amount_minor;
            }
        }
        return $facts;
    }

    /** @param array<string,mixed> $row */
    private function decorate( array $row ): array {
        $desk = self::status( (string) ( $row['stage_key'] ?? '' ) );
        return $row + array(
            'desk_status' => $desk['label'],
            'desk_next'   => $desk['next'],
            'desk_tone'   => $desk['tone'],
            'desk_href'   => $this->caseUrl( (int) ( $row['id'] ?? 0 ), $desk['anchor'] ),
        );
    }

    /**
     * The five steps, each with a state and a human sentence. Read-only: the Desk shows what
     * is true and points at the one control that changes it.
     *
     * @param array<string,mixed> $case
     * @return array<int,array<string,mixed>>
     */
    private function steps( array $case ): array {
        $identity = (array) ( $case['identity_state'] ?? array() );
        $confirmed = $case['confirmed'] ?? null;
        $outcome = $case['outcome'] ?? null;
        $postIntro = $case['post_intro'] ?? null;
        $teacher = trim( (string) ( $case['teacher_name'] ?? '' ) );
        $slot = 'Not chosen yet';
        if ( is_array( $confirmed ) && ! empty( $confirmed['starts_at_utc'] ) ) {
            $slot = (string) ( $confirmed['local_label'] ?? $confirmed['starts_at_utc'] );
        } elseif ( ! empty( $case['requested_times'][0] ) ) {
            $first = (array) $case['requested_times'][0];
            $slot = trim( (string) ( $first['local_date'] ?? '' ) . ' ' . substr( (string) ( $first['local_time'] ?? '' ), 0, 5 ) . ' ' . ( string ) ( $first['timezone'] ?? '' ) );
        }

        return array(
            array(
                'number' => 1,
                'title'  => 'Contact & identity',
                'state'  => (string) ( $identity['key'] ?? '' ) === 'verified' ? 'done' : 'current',
                'detail' => (string) ( $identity['label'] ?? 'Unknown' ) . ' · ' . (string) ( $case['email'] ?? '' ) . ' · ' . (string) ( $case['phone'] ?? '' ),
                'note'   => 'Collected by the intake form. Only verify when the identity is ambiguous.',
            ),
            array(
                'number' => 2,
                'title'  => 'Teacher & schedule',
                'state'  => $teacher !== '' ? 'done' : 'current',
                'detail' => $teacher !== '' ? $teacher . ' · ' . $slot : 'No Teacher booked yet',
                'note'   => 'One picker: Teacher, weekly slot, and whether an intro is needed.',
            ),
            array(
                'number' => 3,
                'title'  => 'Price & plan',
                'state'  => is_array( $postIntro ) && (string) ( $postIntro['state'] ?? '' ) !== '' ? 'done' : ( $teacher !== '' ? 'current' : 'pending' ),
                'detail' => is_array( $postIntro ) && (string) ( $postIntro['state'] ?? '' ) !== ''
                    ? 'Plan: ' . (string) ( $postIntro['plan_label'] ?? $postIntro['state'] )
                    : 'Set when the continuation is prepared',
                'note'   => 'From the catalogue and region. An override needs a recorded reason.',
            ),
            array(
                'number' => 4,
                'title'  => 'Payment',
                'state'  => is_array( $outcome ) ? 'current' : 'pending',
                'detail' => is_array( $outcome )
                    ? 'Intro outcome recorded (' . (string) ( $outcome['label'] ?? $outcome['outcome'] ?? '' ) . '). The payment link is issued from the agreement step.'
                    : 'Nothing is due until the introductory stage is completed.',
                'note'   => 'No class exists before the payment is verified.',
            ),
            array(
                'number' => 5,
                'title'  => 'Term',
                'state'  => (int) ( $case['enrolment_id'] ?? 0 ) > 0 ? 'done' : 'pending',
                'detail' => (int) ( $case['enrolment_id'] ?? 0 ) > 0
                    ? 'Enrolment #' . (int) $case['enrolment_id']
                    : 'Created automatically when the payment settles, then classes are generated',
                'note'   => 'The first regular class is the next weekly occurrence after the intro slot.',
            ),
        );
    }

    /**
     * Exceptions as single cards: one sentence and one action, never a blocker count.
     *
     * @param array<string,mixed> $case
     * @return array<int,array<string,string>>
     */
    private function alerts( array $case ): array {
        $alerts = array();
        $summary = (array) ( $case['notification_summary'] ?? array() );
        if ( (int) ( $summary['failed'] ?? 0 ) > 0 ) {
            $alerts[] = array(
                'text'   => (int) $summary['failed'] . ' message(s) failed to hand off. A queued message is not delivery.',
                'action' => 'Review notifications',
                'href'   => $this->caseUrl( (int) ( $case['id'] ?? 0 ), '' ),
            );
        }
        $identity = (array) ( $case['identity_state'] ?? array() );
        if ( (string) ( $identity['key'] ?? '' ) !== 'verified' ) {
            $alerts[] = array(
                'text'   => 'The Student identity is not verified, so no agreement can be recorded.',
                'action' => 'Verify the identity',
                'href'   => $this->caseUrl( (int) ( $case['id'] ?? 0 ), '' ),
            );
        }
        return $alerts;
    }

    private function caseUrl( int $requestId, string $anchor ): string {
        if ( $requestId < 1 ) {
            return admin_url( 'admin.php?page=dzn-enrolment-desk' );
        }
        return admin_url( 'admin.php?page=dzn-enrolment-workspace&request_id=' . $requestId ) . $anchor;
    }
}
