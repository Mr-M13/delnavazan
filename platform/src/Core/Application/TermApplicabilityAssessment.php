<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{EnrolmentRepository,TermRepository};

/** Capability-protected, write-free canonical Term integrity classification. */
final class TermApplicabilityAssessment {
    public const NONE = 'none';
    public const CANONICAL_APPLICABLE = 'canonical_applicable';
    public const CANONICAL_TERMINAL_HISTORY = 'canonical_terminal_history';
    public const LEGACY_REVIEW_REQUIRED = 'legacy_review_required';
    public const DATA_INTEGRITY_CONFLICT = 'data_integrity_conflict';

    private const CANONICAL_MODEL = 'canonical_enrolment_term_v1';
    private const STATES = array('authorised', 'current', 'closed', 'cancelled');
    private const APPLICABLE_STATES = array('authorised', 'current');

    public function __construct(
        private ?TermRepository $terms = null,
        private ?EnrolmentRepository $enrolments = null
    ) {
        $this->terms ??= new TermRepository();
        $this->enrolments ??= new EnrolmentRepository();
    }

    public function inspect(int $enrolmentId): array {
        if (!current_user_can('dzn_manage_terms')) throw new \RuntimeException('Unauthorized');
        return $this->inspectForAuthority($enrolmentId);
    }

    /** Internal integrity seam for capability-protected canonical authority services. */
    public function inspectForAuthority(int $enrolmentId): array {
        if ($enrolmentId < 1) throw new \InvalidArgumentException('Enrolment identity required');
        $enrolment = $this->enrolments->find($enrolmentId);
        if (!$enrolment) return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
        $rows = $this->terms->forEnrolment($enrolmentId);
        if (!$rows) return array('classification' => self::NONE, 'terms' => array());

        $legacy = array(); $canonical = array();
        foreach ($rows as $row) {
            if (($row->record_model ?? null) === 'legacy_phase1') $legacy[] = $row;
            elseif (($row->record_model ?? null) === self::CANONICAL_MODEL) $canonical[] = $row;
            else return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
        }
        if ($legacy && $canonical) return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
        if ($legacy) {
            if (($enrolment->record_model ?? null) !== 'legacy_phase1') return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
            foreach ($legacy as $row) if (!$this->validLegacy($row)) return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
            return array('classification' => self::LEGACY_REVIEW_REQUIRED, 'terms' => array());
        }
        if (!$this->validCanonicalEnrolment($enrolment)) {
            return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
        }

        $expectedSequence = 1; $applicable = 0;
        foreach ($canonical as $row) {
            if ((int) $row->sequence_number !== $expectedSequence++ || !$this->validCanonical($row)) {
                return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
            }
            if (in_array((string) $row->lifecycle_state, self::APPLICABLE_STATES, true)) $applicable++;
        }
        if ($applicable > 1) return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
        if ($applicable === 1 && !$this->canonicalEnrolmentIsApplicable($enrolment)) {
            return array('classification' => self::DATA_INTEGRITY_CONFLICT, 'terms' => array());
        }
        return array(
            'classification' => $applicable === 1 ? self::CANONICAL_APPLICABLE : self::CANONICAL_TERMINAL_HISTORY,
            'terms' => $canonical,
        );
    }

    private function validLegacy(object $row): bool {
        return in_array((string) $row->status, array('draft', 'awaiting_payment', 'active', 'completed', 'cancelled', 'archived'), true)
            && $row->lifecycle_state === null
            && $row->applicable_slot === null;
    }

    private function validCanonicalEnrolment(object $row): bool {
        $state = (string) ($row->lifecycle_state ?? '');
        $expectedSlot = in_array($state, array('authorised', 'current', 'paused'), true) ? 1 : null;
        return ($row->record_model ?? null) === 'canonical_student_course_v1'
            && ($row->status ?? null) === 'canonical'
            && in_array($state, array('authorised', 'current', 'paused', 'closed'), true)
            && (($row->applicable_slot === null ? null : (int) $row->applicable_slot) === $expectedSlot)
            && (int) ($row->accepted_service_arrangement_id ?? 0) > 0
            && $row->archived_at === null;
    }

    private function canonicalEnrolmentIsApplicable(object $row): bool {
        return in_array((string) $row->lifecycle_state, array('authorised', 'current', 'paused'), true)
            && (int) $row->applicable_slot === 1;
    }

    private function validCanonical(object $row): bool {
        $state = (string) $row->lifecycle_state;
        $expectedSlot = in_array($state, self::APPLICABLE_STATES, true) ? 1 : null;
        if ($row->status !== 'canonical' || !in_array($state, self::STATES, true)
            || (($row->applicable_slot === null ? null : (int) $row->applicable_slot) !== $expectedSlot)
            || $row->archived_at !== null || $row->archived_by !== null
            || (int) $row->lesson_allocation !== 12 || (int) $row->replacement_allowance !== 2
            || $row->payment_state !== 'not_applicable'
            || $row->starts_at !== null || $row->ends_at !== null || $row->activated_at !== null || $row->completed_at !== null
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', (string) $row->uid) !== 1
            || preg_match('/^DZN-TRM-[0-9]+$/D', (string) $row->reference_code) !== 1) return false;
        return $this->validHistory($row, $this->terms->lifecycleHistory((int) $row->id));
    }

    private function validHistory(object $term, array $events): bool {
        if (!$events) return false;
        $previous = null;
        foreach ($events as $index => $event) {
            if ((int) $event->term_id !== (int) $term->id || (int) $event->event_sequence !== $index + 1
                || ($event->from_state === null ? null : (string) $event->from_state) !== $previous
                || !in_array((string) $event->to_state, self::STATES, true)
                || !preg_match('/^[a-z0-9_]{3,64}$/D', (string) $event->reason_code)
                || !preg_match('/^[a-z0-9_]{3,32}$/D', (string) $event->evidence_channel)
                || !preg_match('/^[a-f0-9]{64}$/D', (string) $event->evidence_reference_digest)
                || !$this->utc($event->occurred_at) || !$this->utc($event->recorded_at)
                || (string) $event->occurred_at > (string) $event->recorded_at
                || (int) $event->recorded_by < 1 || !$this->utc($event->created_at) || (int) $event->created_by < 1) return false;
            if ($index === 0 && ($event->from_state !== null || $event->to_state !== 'authorised')) return false;
            if ($index > 0 && !$this->validTransition($previous, (string) $event->to_state)) return false;
            $previous = (string) $event->to_state;
        }
        return $previous === (string) $term->lifecycle_state;
    }

    private function validTransition(?string $from, string $to): bool {
        return ($from === 'authorised' && in_array($to, array('current', 'cancelled'), true))
            || ($from === 'current' && in_array($to, array('closed', 'cancelled'), true));
    }

    private function utc(mixed $value): bool {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1 && strtotime($value . ' UTC') !== false;
    }
}
