<?php
namespace Delnavazan\Platform\Core\Application;

/** Read-only Academy pipeline projection; command authority stays with the domain services. */
final class AcademyJourneyReadService {
    /** The dashboard and the queue share these stage labels and rules. */
    public const PIPELINE_STAGES = array(
        'needs_coordination' => 'Coordination needed',
        'needs_identity' => 'Identity unresolved',
        'needs_acceptance' => 'Acceptance pending',
        'intro_booked' => 'Introduction booked',
        'needs_enrolment' => 'Enrolment conversion',
        'needs_assignment' => 'Teacher assignment',
        'complete' => 'Assignment complete',
    );

    public const QUEUE_STAGES = array(
        'needs_action' => 'Action queue',
        'needs_coordination' => 'Coordination needed',
        'needs_identity' => 'Identity unresolved',
        'needs_acceptance' => 'Acceptance pending',
        'intro_booked' => 'Introduction booked',
        'needs_enrolment' => 'Enrolment conversion',
        'needs_assignment' => 'Teacher assignment',
        'complete' => 'Assignment complete',
    );

    /**
     * Eligible booking scope for every pipeline stage and stage-filtered queue:
     * the original submitted/unresolved intake plus the authorised
     * closed/teacher_confirmed continuation. Privacy-erased and other closed
     * resolutions stay out.
     */
    private const ELIGIBLE_SQL = "(br.privacy_erased_at IS NULL AND ((br.lifecycle_status='submitted' AND br.resolution_state='unresolved') OR (br.lifecycle_status='closed' AND br.resolution_state='teacher_confirmed')))";

    /**
     * Mutually exclusive stage predicates. Each stage names the earliest missing
     * predecessor, so a booking with several missing links counts in exactly one
     * stage. `complete` is the complement of `needs_action`.
     */
    private const STAGE_SQL = array(
        'needs_action' => 'ta.id IS NULL',
        'needs_coordination' => 'ta.id IS NULL AND cc.id IS NULL',
        'needs_identity' => 'ta.id IS NULL AND cc.id IS NOT NULL AND br.student_id IS NULL',
        'needs_acceptance' => 'ta.id IS NULL AND cc.id IS NOT NULL AND br.student_id IS NOT NULL AND asa.id IS NULL',
        'needs_enrolment' => 'ta.id IS NULL AND cc.id IS NOT NULL AND br.student_id IS NOT NULL AND asa.id IS NOT NULL AND e.id IS NULL',
        'needs_assignment' => "ta.id IS NULL AND cc.id IS NOT NULL AND br.student_id IS NOT NULL AND asa.id IS NOT NULL AND e.id IS NOT NULL AND e.lifecycle_state IN ('authorised','current')",
        'complete' => 'ta.id IS NOT NULL',
    );

    /**
     * A confirmed (or awaiting-payment) introduction is booked work, not a missing acceptance:
     * the term arrangement is created after the lesson. Without this, a booked intro surfaced as
     * "Service arrangements awaiting acceptance" with "No Teacher candidate is recorded", which
     * reads as a contradiction to the operator.
     */
    public static function stageSql(string $stage): string {
        $sql = self::STAGE_SQL[$stage] ?? '';
        if ($stage === 'intro_booked') {
            global $wpdb;
            return "ta.id IS NULL AND EXISTS (SELECT 1 FROM {$wpdb->prefix}dzn_booking_request_confirmations ic WHERE ic.booking_request_id=br.id AND ic.state IN ('confirmed','awaiting_payment'))";
        }
        if ($stage === 'needs_acceptance') {
            global $wpdb;
            $sql .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}dzn_booking_request_confirmations ac WHERE ac.booking_request_id=br.id AND ac.state IN ('confirmed','awaiting_payment'))";
        }
        return $sql;
    }

    /** Bounded, searchable operator queue. PII is limited to the current booking contact snapshot. */
    public function search(string $term = '', string $stage = '', int $page = 1): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        $page=max(1,$page); $limit=25; $offset=($page-1)*$limit;
        if ($stage!==''&&!array_key_exists($stage,self::QUEUE_STAGES)) $stage='';
        $where="br.privacy_erased_at IS NULL"; $args=array();
        $term=function_exists('mb_substr')?mb_substr($term,0,100):substr($term,0,100);
        if($term!==''){
            $like='%'.$wpdb->esc_like($term).'%';
            $where.=" AND (br.reference_code LIKE %s OR s.display_name LIKE %s OR contact.email LIKE %s OR contact.full_name LIKE %s OR i.name_en LIKE %s OR i.name_fa LIKE %s OR c.name_en LIKE %s OR c.name_fa LIKE %s)";
            array_push($args,$like,$like,$like,$like,$like,$like,$like,$like);
        }
        if($stage!==''){
            // The pipeline and every stage queue share one eligible-booking scope.
            $where.=' AND '.self::ELIGIBLE_SQL.' AND '.self::stageSql($stage);
        }
        $joins=" FROM {$p}booking_requests br LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id LEFT JOIN {$p}students s ON s.id=br.student_id LEFT JOIN {$p}booking_request_contact_snapshots contact ON contact.id=(SELECT cs.id FROM {$p}booking_request_contact_snapshots cs WHERE cs.booking_request_id=br.id ORDER BY cs.snapshot_sequence DESC,cs.id DESC LIMIT 1) LEFT JOIN {$p}operational_exceptions oe ON oe.entity_type='booking_request' AND oe.entity_id=br.id AND oe.exception_type='booking_request_match_attention' AND oe.status IN ('open','acknowledged') LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id LEFT JOIN {$p}teachers accepted_teacher ON accepted_teacher.id=asa.teacher_id LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL LEFT JOIN {$p}student_account_claim_invitations claim ON claim.student_id=br.student_id LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1 LEFT JOIN {$p}teachers assigned_teacher ON assigned_teacher.id=ta.teacher_id LEFT JOIN {$p}terms t ON t.enrolment_id=e.id AND t.applicable_slot=1 AND t.archived_at IS NULL LEFT JOIN {$p}lessons l ON l.enrolment_id=e.id AND l.archived_at IS NULL";
        $countSql="SELECT COUNT(DISTINCT br.id)".$joins." WHERE {$where}";
        $total=(int)$wpdb->get_var($args?$wpdb->prepare($countSql,...$args):$countSql);
        $pages=(int)ceil($total/$limit);$page=$pages?min($page,$pages):1;$offset=($page-1)*$limit;
        $sql="SELECT br.id request_id,br.reference_code request_ref,br.student_id,br.lifecycle_status request_state,br.resolution_state,contact.full_name contact_name,contact.email contact_email,i.name_fa instrument_fa,i.name_en instrument_en,c.name_fa course_fa,c.name_en course_en,s.display_name student_name,oe.error_code match_attention_code,cc.id case_id,asa.id arrangement_id,accepted_teacher.display_name accepted_teacher_name,e.id enrolment_id,e.lifecycle_state enrolment_state,claim.id claim_invitation_id,claim.state claim_state,ta.id assignment_id,assigned_teacher.display_name assigned_teacher_name,t.id term_id,t.lifecycle_state term_state,COUNT(DISTINCT l.id) lesson_count,SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed_lessons".$joins." WHERE {$where} GROUP BY br.id,contact.id,i.id,c.id,s.id,oe.id,cc.id,asa.id,accepted_teacher.id,e.id,claim.id,ta.id,assigned_teacher.id,t.id ORDER BY br.id DESC LIMIT %d OFFSET %d";
        $rows=$wpdb->get_results($wpdb->prepare($sql,...array_merge($args,array($limit,$offset))))?:array();
        return array('rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$limit,'pages'=>$pages,'stage'=>$stage,'query'=>$term);
    }

    /**
     * Counts for the dashboard pipeline using the same mutually exclusive SQL as
     * the queue. DISTINCT prevents duplicate joined arrangements/enrolments from
     * inflating a stage.
     *
     * @return array<string, int>
     */
    public function stageCounts(): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        $selects=array();
        foreach(self::PIPELINE_STAGES as $stage=>$label){
            $selects[]='COUNT(DISTINCT CASE WHEN '.self::stageSql($stage).' THEN br.id END) AS '.$stage;
        }
        $joins=" FROM {$p}booking_requests br
            LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id
            LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id
            LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL
            LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1";
        $row=$wpdb->get_row('SELECT '.implode(',',$selects).$joins.' WHERE '.self::ELIGIBLE_SQL);
        $counts=array();
        foreach(self::PIPELINE_STAGES as $stage=>$label)$counts[$stage]=(int)($row->{$stage}??0);
        return $counts;
    }

    public function recent(): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        return $wpdb->get_results("SELECT br.id request_id,br.reference_code request_ref,br.student_id,i.name_fa instrument_fa,i.name_en instrument_en,c.name_fa course_fa,c.name_en course_en,s.display_name student_name,oe.error_code match_attention_code,cc.id case_id,asa.id arrangement_id,accepted_teacher.display_name accepted_teacher_name,e.id enrolment_id,ta.id assignment_id,assigned_teacher.display_name assigned_teacher_name,t.id term_id,COUNT(DISTINCT l.id) lesson_count,SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed_lessons,claim.id claim_invitation_id,claim.state claim_state FROM {$p}booking_requests br LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id LEFT JOIN {$p}students s ON s.id=br.student_id LEFT JOIN {$p}operational_exceptions oe ON oe.entity_type='booking_request' AND oe.entity_id=br.id AND oe.exception_type='booking_request_match_attention' AND oe.status IN ('open','acknowledged') LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id LEFT JOIN {$p}teachers accepted_teacher ON accepted_teacher.id=asa.teacher_id LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL LEFT JOIN {$p}student_account_claim_invitations claim ON claim.student_id=br.student_id LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1 LEFT JOIN {$p}teachers assigned_teacher ON assigned_teacher.id=ta.teacher_id LEFT JOIN {$p}terms t ON t.enrolment_id=e.id AND t.applicable_slot=1 AND t.archived_at IS NULL LEFT JOIN {$p}lessons l ON l.enrolment_id=e.id AND l.archived_at IS NULL WHERE br.privacy_erased_at IS NULL GROUP BY br.id,i.id,c.id,s.id,oe.id,cc.id,asa.id,accepted_teacher.id,e.id,claim.id,ta.id,assigned_teacher.id,t.id ORDER BY br.id DESC LIMIT 100") ?: array();
    }

    /**
     * Bounded detail for one pipeline stage.
     *
     * The Overview attention cards count a stage with the same ELIGIBLE_SQL and
     * STAGE_SQL predicates used here, so the detail list can never count a
     * different set than the badge. Each row names the missing link and links at
     * the existing authority that resolves it: Class admin for a coordination
     * case, or the read-only Enrolment request when the operator cannot
     * coordinate. This projection never writes and never resolves anything.
     *
     * @return list<array<string,mixed>>
     */
    public function stageQueue(string $stage = 'needs_acceptance', int $limit = 5): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        if (!in_array($stage, array('needs_coordination', 'needs_identity', 'needs_acceptance', 'intro_booked', 'needs_enrolment', 'needs_assignment'), true)) {
            throw new \InvalidArgumentException('Unknown pipeline stage');
        }
        $limit = max(1, min(10, $limit));
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $sql = "SELECT br.id request_id,br.reference_code,br.created_at,
                       contact.full_name,contact.email,contact.mobile,contact.country,contact.city,contact.timezone,contact.submission_source,
                       COALESCE(NULLIF(s.display_name,''),NULLIF(contact.full_name,''),'Learner') learner_name,
                       COALESCE(NULLIF(i.name_en,''),NULLIF(i.name_fa,''),'') instrument_name,
                       COALESCE(NULLIF(c.name_en,''),NULLIF(c.name_fa,''),'') course_name,
                       rt.local_date,rt.local_start_time,rt.timezone requested_timezone,
                       cc.id case_id,cc.reference_code case_reference,cc.state case_state,
                       asa.reference_code arrangement_reference,
                       e.reference_code enrolment_reference,
                       ta.id assignment_id,
                       COALESCE(NULLIF(assigned_teacher.display_name,''),NULLIF(accepted_teacher.display_name,''),'') teacher_name,
                       (SELECT COUNT(*) FROM {$p}coordination_case_candidates candidate WHERE candidate.coordination_case_id=cc.id AND candidate.status NOT IN ('not_available','not_suitable','withdrawn_from_consideration','superseded','closed')) active_candidates,
                       (SELECT COUNT(*) FROM {$p}teacher_availability_assents assent WHERE assent.coordination_case_id=cc.id AND assent.state='recorded' AND (assent.valid_until IS NULL OR assent.valid_until>UTC_TIMESTAMP()) AND (assent.review_by IS NULL OR assent.review_by>UTC_TIMESTAMP())) current_assents,
                       (SELECT COUNT(*) FROM {$p}proposal_families family INNER JOIN {$p}proposal_options option_row ON option_row.proposal_family_id=family.id WHERE family.coordination_case_id=cc.id AND option_row.current_version_id IS NOT NULL) current_proposals,
                       (SELECT COUNT(*) FROM {$p}proposal_acceptance_events acceptance WHERE acceptance.coordination_case_id=cc.id) acceptance_events
                FROM {$p}booking_requests br
                LEFT JOIN {$p}students s ON s.id=br.student_id
                LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id
                LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id
                LEFT JOIN {$p}booking_request_contact_snapshots contact ON contact.id=(SELECT cs.id FROM {$p}booking_request_contact_snapshots cs WHERE cs.booking_request_id=br.id ORDER BY cs.snapshot_sequence DESC,cs.id DESC LIMIT 1)
                LEFT JOIN {$p}booking_request_requested_times rt ON rt.id=(SELECT rt2.id FROM {$p}booking_request_requested_times rt2 WHERE rt2.booking_request_id=br.id ORDER BY rt2.sequence_number ASC,rt2.id ASC LIMIT 1)
                LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id
                LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id
                LEFT JOIN {$p}teachers accepted_teacher ON accepted_teacher.id=asa.teacher_id
                LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL
                LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1
                LEFT JOIN {$p}teachers assigned_teacher ON assigned_teacher.id=ta.teacher_id
                WHERE " . self::ELIGIBLE_SQL . " AND " . self::stageSql($stage) . "
                ORDER BY br.created_at DESC,br.id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit)) ?: array();
        return array_map(function (object $row) use ($stage): array {
            return $this->composeStageRow($stage, $row);
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function composeStageRow(string $stage, object $row): array {
        $requestId = (int) ($row->request_id ?? 0);
        $caseId = (int) ($row->case_id ?? 0);
        $created = (string) ($row->created_at ?? '');
        $source = (string) ($row->submission_source ?? '');
        $location = implode(', ', array_filter(array(trim((string) ($row->city ?? '')), trim((string) ($row->country ?? '')))));
        $requested = trim(trim((string) ($row->local_date ?? '')) . ' ' . substr((string) ($row->local_start_time ?? ''), 0, 5));
        if ($requested !== '' && (string) ($row->requested_timezone ?? '') !== '') $requested .= ' · ' . (string) $row->requested_timezone;
        $anchor = $this->stageAnchor($stage, $row);
        $canCoordinate = current_user_can('dzn_manage_booking_request_coordination') && current_user_can('dzn_view_booking_requests');
        if ($canCoordinate && $caseId > 0) {
            $url = admin_url('admin.php?page=dzn-booking-request-coordination&case_id=' . $caseId . ($anchor !== '' ? '#' . $anchor : ''));
        } elseif ($canCoordinate) {
            $url = admin_url('admin.php?page=dzn-booking-request-coordination&booking_request_id=' . $requestId);
        } else {
            $url = admin_url('admin.php?page=dzn-enrolment-workspace&request_id=' . $requestId);
        }
        return array(
            'request_id' => $requestId,
            'reference' => (string) ($row->reference_code ?? ''),
            'learner_name' => trim((string) ($row->learner_name ?? '')) !== '' ? (string) $row->learner_name : 'Learner',
            'instrument' => (string) ($row->instrument_name ?? ''),
            'course' => (string) ($row->course_name ?? ''),
            'contact' => trim((string) ($row->email ?? '')) !== '' ? (string) $row->email : (string) ($row->mobile ?? ''),
            'location' => $location,
            'requested_time' => $requested,
            'source' => $source !== '' && str_contains($source, 'public_rest') ? 'Public booking form' : ($source !== '' ? ucfirst(str_replace('_', ' ', $source)) : 'Not recorded'),
            'age' => $this->ageLabel($created),
            'case_id' => $caseId,
            'case_reference' => (string) ($row->case_reference ?? ''),
            'case_state' => (string) ($row->case_state ?? ''),
            'teacher_name' => (string) ($row->teacher_name ?? ''),
            'readiness' => $this->stageReadiness($stage, $row),
            'next_step' => $this->stageNextStep($stage),
            'anchor' => $anchor,
            'url' => $url,
        );
    }

    /** The case section that owns the missing prerequisite, so a deep link lands on the work. */
    private function stageAnchor(string $stage, object $row): string {
        return match ($stage) {
            'needs_identity' => 'identity',
            'needs_acceptance' => $this->acceptanceAnchor($row),
            'intro_booked' => '',
            'needs_enrolment', 'needs_assignment' => 'enrolment',
            default => '',
        };
    }

    private function acceptanceAnchor(object $row): string {
        if ((int) ($row->active_candidates ?? 0) < 1) return 'teacher-matching';
        if ((int) ($row->current_assents ?? 0) < 1) return 'agreements';
        if ((int) ($row->current_proposals ?? 0) < 1) return 'proposals';
        if ((int) ($row->acceptance_events ?? 0) < 1) return 'provisional';
        return 'acceptance';
    }

    private function stageReadiness(string $stage, object $row): string {
        return match ($stage) {
            'needs_coordination' => 'No coordination case is open',
            'needs_identity' => 'The Student identity is unresolved',
            'needs_acceptance' => $this->acceptanceReadiness($row),
            'intro_booked' => 'The introduction is booked and awaiting delivery',
            'needs_enrolment' => 'The accepted arrangement has no Enrolment',
            'needs_assignment' => 'The Enrolment has no current Teacher assignment',
            default => '',
        };
    }

    private function acceptanceReadiness(object $row): string {
        if ((int) ($row->active_candidates ?? 0) < 1) return 'No Teacher candidate is recorded';
        if ((int) ($row->current_assents ?? 0) < 1) return 'Teacher agreement is not recorded';
        if ((int) ($row->current_proposals ?? 0) < 1) return 'No current Proposal is issued';
        if ((int) ($row->acceptance_events ?? 0) < 1) return 'The learner response is not recorded';
        return 'Ready for final acceptance';
    }

    private function stageNextStep(string $stage): string {
        return match ($stage) {
            'needs_coordination' => 'Open a case, contact the learner and propose a Teacher',
            'needs_identity' => 'Resolve identity or record an identity outcome',
            'needs_acceptance' => 'Complete the missing acceptance prerequisite in Class admin',
            'intro_booked' => 'Record the outcome after the lesson',
            'needs_enrolment' => 'Convert the accepted arrangement to an Enrolment',
            'needs_assignment' => 'Assign the accepted Teacher',
            default => 'Open the record',
        };
    }

    private function ageLabel(string $createdAt): string {
        $timestamp = strtotime(trim($createdAt) . ' UTC');
        if ($timestamp === false) return 'Age unavailable';
        $seconds = max(0, time() - $timestamp);
        if ($seconds < 3600) return max(1, intdiv($seconds, 60)) . ' min ago';
        if ($seconds < 86400) return intdiv($seconds, 3600) . ' h ago';
        return intdiv($seconds, 86400) . ' d ago';
    }
}
