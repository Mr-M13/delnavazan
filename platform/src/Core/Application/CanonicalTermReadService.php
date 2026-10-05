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

    /** Human-labeled, integrity-checked Enrolments that can receive a successor Term. */
    public function createOptions(): array {
        if (!current_user_can('dzn_manage_canonical_terms')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        $candidates=$wpdb->get_results("SELECT e.id,e.lifecycle_state,COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student,COALESCE(NULLIF(c.name_en,''),c.name_fa) course FROM {$p}enrolments e LEFT JOIN {$p}students s ON s.id=e.student_id LEFT JOIN {$p}courses c ON c.id=e.course_id WHERE e.record_model='canonical_student_course_v1' AND e.status='canonical' AND e.archived_at IS NULL AND e.lifecycle_state IN ('authorised','current','paused') ORDER BY e.id DESC LIMIT 100")?:array();
        $options=array();
        foreach($candidates as$candidate){
            $inspection=$this->assessment->inspect((int)$candidate->id);
            $terms=$inspection['terms'];$latest=$terms?$terms[count($terms)-1]:null;
            if($inspection['classification']===TermApplicabilityAssessment::NONE){$expectedId=0;$expectedState='';$sequence=1;$prior='';}
            elseif($inspection['classification']===TermApplicabilityAssessment::CANONICAL_TERMINAL_HISTORY&&$latest&&in_array((string)$latest->lifecycle_state,array('closed','cancelled'),true)){$expectedId=(int)$latest->id;$expectedState=(string)$latest->lifecycle_state;$sequence=(int)$latest->sequence_number+1;$prior=(string)$latest->reference_code;}
            else continue;
            $state=ucfirst(str_replace('_',' ',(string)$candidate->lifecycle_state));
            $label=(string)($candidate->student?:'Student').' · '.(string)($candidate->course?:'Course').' · '.$state.' · Term '.$sequence;
            if($prior!=='')$label.=' after '.$prior;
            $options[]=array('enrolment_id'=>(int)$candidate->id,'label'=>$label,'expected_latest_term_id'=>$expectedId,'expected_latest_state'=>$expectedState);
        }
        return $options;
    }
}
