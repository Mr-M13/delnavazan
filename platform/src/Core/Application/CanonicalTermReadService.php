<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TermRepository;

/** Stable, privacy-minimised canonical Term foundation read seam. */
final class CanonicalTermReadService {
    public function __construct(
        private ?TermApplicabilityAssessment $assessment = null,
        private ?TermRepository $repository = null
    ) {
        $this->assessment ??= new TermApplicabilityAssessment();
        $this->repository ??= new TermRepository();
    }

    public function forEnrolment(int $enrolmentId): array {
        if (!current_user_can('dzn_manage_terms')) throw new \RuntimeException('Unauthorized');
        $inspection = $this->assessment->inspect($enrolmentId);
        $terms = array();
        foreach ($inspection['terms'] as $row) {
            $terms[] = array(
                'term_id' => (int) $row->id,
                'term_uid' => (string) $row->uid,
                'reference_code' => (string) $row->reference_code,
                'enrolment_id' => (int) $row->enrolment_id,
                'sequence_number' => (int) $row->sequence_number,
                'lifecycle_state' => (string) $row->lifecycle_state,
                'lesson_allocation' => (int) $row->lesson_allocation,
                'replacement_allowance' => (int) $row->replacement_allowance,
                'created_at' => (string) $row->created_at,
            );
        }
        return array('classification' => $inspection['classification'], 'terms' => $terms);
    }

    public function history(int $termId): array {
        if (!current_user_can('dzn_manage_terms')) throw new \RuntimeException('Unauthorized');
        if ($termId < 1) throw new \InvalidArgumentException('Term identity required');
        $term = $this->repository->findAny($termId);
        if (!$term || ($term->record_model ?? null) !== 'canonical_enrolment_term_v1') throw new \InvalidArgumentException('Canonical Term required');
        $inspection = $this->assessment->inspect((int) $term->enrolment_id);
        $validated = false;
        foreach ($inspection['terms'] as $candidate) {
            if ((int) $candidate->id === $termId) {
                $validated = true;
                break;
            }
        }
        if (!$validated) throw new \RuntimeException('Canonical Term integrity conflict');
        return array_map(static fn(object $event): array => array(
            'event_uid' => (string) $event->uid,
            'term_id' => (int) $event->term_id,
            'event_sequence' => (int) $event->event_sequence,
            'from_state' => $event->from_state === null ? null : (string) $event->from_state,
            'to_state' => (string) $event->to_state,
            'reason_code' => (string) $event->reason_code,
            'evidence_channel' => (string) $event->evidence_channel,
            'occurred_at' => (string) $event->occurred_at,
            'recorded_at' => (string) $event->recorded_at,
            'recorded_by' => (int) $event->recorded_by,
        ), $this->repository->lifecycleHistory($termId));
    }
}
