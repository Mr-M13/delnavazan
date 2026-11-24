<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;

/**
 * One derived status per booking request, shared by the case page, the Enrolment
 * queue and the Overview attention cards.
 *
 * The individual authorities keep owning their records; this projection only
 * reads them and returns a single status vocabulary plus one primary action,
 * so screens stop deriving contradictory next steps independently. Read-only.
 */
final class BookingJourneyReadService {
    /** @var array<string,array{0:string,1:string}> label and tone per status key. */
    public const STATUSES = array(
        'identity' => array('Identity needed', 'warn'),
        'new' => array('New request', 'teal'),
        'awaiting_teacher' => array('Waiting for teacher', 'gold'),
        'trial_scheduled' => array('Intro scheduled', 'teal'),
        'provisioning' => array('Creating classroom', 'gold'),
        'outcome_due' => array('Outcome due', 'gold'),
        'continuation' => array('Decision & payment', 'gold'),
        'enrolled' => array('Enrolled', 'ok'),
        'declined' => array('Declined', 'neutral'),
        'closed' => array('Closed', 'neutral'),
        'needs_review' => array('Needs review', 'danger'),
    );

    public static function label(string $key): string {
        return self::STATUSES[$key][0] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function tone(string $key): string {
        return self::STATUSES[$key][1] ?? 'soft';
    }

    /**
     * Map a legacy booking-stage key onto the shared status vocabulary, so the
     * Enrolment queue and the case page can label the same state identically
     * without a per-row query.
     *
     * @return array{key:string,label:string,tone:string}
     */
    public static function statusForStage(string $stageKey): array {
        $key = match ($stageKey) {
            // Journey keys are accepted unchanged, so the mapping is idempotent when a caller
            // (for example the Enrolment Desk) already holds a journey status.
            'identity', 'new', 'awaiting_teacher', 'trial_scheduled', 'provisioning',
            'outcome_due', 'continuation', 'enrolled', 'declined', 'closed', 'needs_review' => $stageKey,
            'teacher_match', 'teacher_agreed' => 'awaiting_teacher',
            'intro_scheduled' => 'trial_scheduled',
            'provisioning_pending', 'provisioning_failed', 'intro_payment_pending' => 'provisioning',
            'intro_outcome' => 'outcome_due',
            'post_intro' => 'continuation',
            'converted' => 'enrolled',
            'expired', 'archived' => 'closed',
            // An unmapped stage is a projection defect, not a new request. Say so.
            default => 'needs_review',
        };
        return array('key' => $key, 'label' => self::label($key), 'tone' => self::tone($key));
    }

    /**
     * @return array<string,mixed>|null status + primary action + supporting facts
     */
    public function status(int $requestId): ?array {
        $this->requireAny(array('dzn_view_booking_requests', 'dzn_manage_booking_request_coordination', 'dzn_manage_lessons', 'dzn_manage_canonical_lessons', 'dzn_manage_enrolments', 'dzn_use_enrolment_desk'));
        if ($requestId < 1) return null;
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,s.full_name,s.timezone contact_timezone,
                    COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
               FROM {$p}booking_requests r
               LEFT JOIN {$p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
               LEFT JOIN {$p}instruments i ON i.id=r.requested_instrument_id
               LEFT JOIN {$p}courses c ON c.id=r.selected_intro_course_id
              WHERE r.id=%d",
            $requestId
        ));
        if (!$request) return null;
        $facts = array(
            'identity_resolved' => (int) ($request->student_id ?? 0) > 0,
            'lifecycle' => (string) $request->lifecycle_status,
            'resolution' => (string) $request->resolution_state,
            'erased' => $request->privacy_erased_at !== null,
            'offered_teacher_count' => 0,
            'active_offer' => null,
            'coordination' => $wpdb->get_row($wpdb->prepare(
                "SELECT state,state_reason_code,closed_at FROM {$p}coordination_cases WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
                $requestId
            )) ?: null,
            'assent' => $wpdb->get_row($wpdb->prepare(
                "SELECT state,valid_until FROM {$p}teacher_availability_assents WHERE booking_request_id=%d AND state='recorded' AND (valid_until IS NULL OR valid_until>UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 1",
                $requestId
            )) ?: null,
            'arrangement' => $wpdb->get_row($wpdb->prepare(
                "SELECT id,confirmation_value,accepted_at FROM {$p}accepted_service_arrangements WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
                $requestId
            )) ?: null,
            'confirmation' => $wpdb->get_row($wpdb->prepare(
                "SELECT c.id,c.state,c.starts_at_utc,c.ends_at_utc,c.teacher_id,
                        COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
                   FROM {$p}booking_request_confirmations c LEFT JOIN {$p}teachers t ON t.id=c.teacher_id
                  WHERE c.booking_request_id=%d ORDER BY c.id DESC LIMIT 1",
                $requestId
            )) ?: null,
            'outcome' => $wpdb->get_row($wpdb->prepare(
                "SELECT outcome,recorded_at FROM {$p}booking_request_intro_outcomes WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
                $requestId
            )) ?: null,
            'post_intro' => $wpdb->get_row($wpdb->prepare(
                "SELECT state,enrolment_id FROM {$p}post_intro_preparations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1",
                $requestId
            )) ?: null,
            'skip_intro' => $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='booking_request.skip_intro' LIMIT 1",
                $requestId
            )) !== null,
        );
        foreach ((new BookingAllocationRepository())->offerSummaries(array($requestId)) as $summary) {
            $facts['offered_teacher_count'] = (int) ($summary['offered_teacher_count'] ?? 0);
            $facts['active_offer'] = $summary['response_deadline_at'] ?? null;
        }
        $facts['enrolment_id'] = $facts['post_intro'] !== null ? (int) $facts['post_intro']->enrolment_id : 0;
        if ($facts['enrolment_id'] < 1 && $facts['arrangement'] !== null) {
            $facts['enrolment_id'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}enrolments WHERE accepted_service_arrangement_id=%d AND archived_at IS NULL LIMIT 1",
                (int) $facts['arrangement']->id
            ));
        }
        $derived = $this->derive($request, $facts);
        return array(
            'request_id' => $requestId,
            'reference' => (string) ($request->reference_code ?: ''),
            'learner' => (string) ($request->full_name ?: 'Learner'),
            'instrument' => (string) $request->instrument_name,
            'course' => (string) $request->course_name,
            'facts' => $facts,
        ) + $derived;
    }

    /**
     * @param object $request
     * @param array<string,mixed> $facts
     * @return array{status:string,status_label:string,status_tone:string,note:string,primary:array<string,string>,more:list<string>}
     */
    private function derive(object $request, array $facts): array {
        $confirmation = $facts['confirmation'];
        $outcome = $facts['outcome'];
        $postIntro = $facts['post_intro'];
        $now = gmdate('Y-m-d H:i:s');

        if (!empty($facts['erased'])) return $this->make('closed', 'Privacy-erased history.', array('label' => 'View history', 'operation' => 'history'));
        if ($confirmation !== null) {
            $state = (string) $confirmation->state;
            if ($state === 'cancelled') return $this->make('closed', 'The intro was cancelled. Start a new request when the Student is ready to rebook.', array('label' => 'View history', 'operation' => 'history'));
            if ($state === 'confirmed') {
                $ended = (string) $confirmation->ends_at_utc < $now;
                if ($ended && $outcome === null) return $this->make('outcome_due', 'The intro has ended; record what happened.', array('label' => 'Record the outcome', 'operation' => 'record_outcome'), array('Reschedule / cancel', 'Classroom & actions'));
                if ($ended && $outcome !== null) return $this->continuation($facts);
                return $this->make('trial_scheduled', 'Upcoming confirmed intro.', array('label' => 'Classroom & actions', 'operation' => 'classroom'), array('Reschedule / cancel', 'Record the outcome'));
            }
            if ($state === 'awaiting_payment') return $this->make('provisioning', 'The Teacher accepted; the introductory payment is pending.', array('label' => 'Review payment', 'operation' => 'continuation'), array('Reschedule / cancel'));
            return $this->make('provisioning', 'The classroom is being created; retry is available on failure.', array('label' => 'Retry or review projection', 'operation' => 'classroom'), array('Reschedule / cancel'));
        }

        if ((string) $request->lifecycle_status !== 'submitted' || (string) $request->resolution_state !== 'unresolved') {
            if ((string) $request->resolution_state === 'declined') {
                return $this->make('declined', 'This booking request was declined.', array('label' => 'View history', 'operation' => 'history'));
            }
            return $this->make('closed', 'The request is no longer active.', array('label' => 'View history', 'operation' => 'history'));
        }
        if ($facts['coordination'] !== null && in_array((string) $facts['coordination']->state, array('closed', 'declined'), true)) {
            return $this->make('needs_review', 'The coordination case is closed but the booking request is still open. Confirm the intro or decline the request to resolve the mismatch.', array('label' => 'Review the mixed records', 'operation' => 'review'), array('Confirm intro now', 'Decline this request', 'Coordinate Teacher and acceptance'));
        }
        if ($facts['arrangement'] !== null) {
            $introComplete = !empty($facts['skip_intro']) || ($outcome !== null && (string) $outcome->outcome === 'delivered');
            if ($introComplete) {
                if ((int) $facts['enrolment_id'] > 0) return $this->make('enrolled', 'The student is enrolled; manage classes and terms from Enrolment.', array('label' => 'Open Enrolment', 'operation' => 'enrolment'), array('Class history'));
                return $this->make('continuation', 'Skip intro (or delivered outcome) recorded; convert the accepted arrangement to an Enrolment.', array('label' => 'Convert to enrolment', 'operation' => 'convert_enrolment'), array('Decline this request'));
            }
            return $this->make('needs_review', 'An accepted arrangement exists but the intro was never confirmed. Confirm the intro, record a skip, or decline the request.', array('label' => 'Review arrangement vs intro', 'operation' => 'review'), array('Confirm intro now', 'Skip the intro', 'Convert to enrolment', 'Decline this request'));
        }
        if ($facts['active_offer'] !== null) return $this->make('awaiting_teacher', 'A Teacher has been offered this intro and has time left to answer.', array('label' => 'Confirm intro now', 'operation' => 'total_override_intro'), array('Manual Teacher match', 'Coordinate Teacher and acceptance'));
        if ($facts['assent'] !== null) return $this->make('awaiting_teacher', 'A Teacher agreement is recorded; confirm the intro now.', array('label' => 'Confirm intro now', 'operation' => 'total_override_intro'), array('Manual Teacher match', 'Coordinate Teacher and acceptance'));
        if (empty($facts['identity_resolved'])) return $this->make('identity', 'Resolve the Student identity first.', array('label' => 'Resolve identity', 'operation' => 'identity_resolve'), array('Record a reviewed blocker'));
        return $this->make('new', 'New request; start coordination.', array('label' => 'Open coordination', 'operation' => 'open_coordination'), array('Confirm intro now', 'Manual Teacher match'));
    }

    /** @param list<string> $more */
    private function make(string $status, string $note, array $primary, array $more = array()): array {
        return array(
            'status' => $status,
            'status_label' => self::label($status),
            'status_tone' => self::tone($status),
            'note' => $note,
            'primary' => $primary,
            'more' => $more,
        );
    }

    /** @param array<string,mixed> $facts */
    private function continuation(array $facts): array {
        $postIntro = $facts['post_intro'];
        $state = $postIntro !== null ? (string) $postIntro->state : '';
        if ($state === 'activated' || (int) $facts['enrolment_id'] > 0) {
            return $this->make('enrolled', 'The student is enrolled; manage classes and terms from Enrolment.', array('label' => 'Open Enrolment', 'operation' => 'enrolment'), array('Class history'));
        }
        if (in_array($state, array('prepared', 'checkout_pending', 'payment_received'), true)) {
            return $this->make('continuation', 'The continuation decision or payment is open.', array('label' => 'Open decision & payment', 'operation' => 'continuation'), array('Convert to enrolment', 'Send payment link'));
        }
        return $this->make('continuation', 'Preparing the continuation.', array('label' => 'Open decision & payment', 'operation' => 'continuation'), array('Convert to enrolment'));
    }

    /** @param list<string> $capabilities */
    private function requireAny(array $capabilities): void {
        foreach ($capabilities as $capability) if (current_user_can($capability)) return;
        throw new \RuntimeException('Unauthorized');
    }
}
