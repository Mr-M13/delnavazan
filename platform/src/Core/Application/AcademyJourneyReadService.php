<?php
namespace Delnavazan\Platform\Core\Application;

/** Read-only Academy pipeline projection; command authority stays with the domain services. */
final class AcademyJourneyReadService {
    /** The dashboard and the queue share these stage labels and rules. */
    public const PIPELINE_STAGES = array(
        'needs_coordination' => 'Coordination needed',
        'needs_identity' => 'Identity unresolved',
        'needs_acceptance' => 'Acceptance pending',
        'needs_enrolment' => 'Enrolment conversion',
        'needs_assignment' => 'Teacher assignment',
        'complete' => 'Assignment complete',
    );

    public const QUEUE_STAGES = array(
        'needs_action' => 'Action queue',
        'needs_coordination' => 'Coordination needed',
        'needs_identity' => 'Identity unresolved',
        'needs_acceptance' => 'Acceptance pending',
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

    public static function stageSql(string $stage): string {
        return self::STAGE_SQL[$stage] ?? '';
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
     * Newest submitted bookings that still have no coordination case. The
     * dashboard card uses this bounded projection so an administrator can see
     * who asked, for which instrument/course and from where without opening the
     * full Enrolment workspace first. It remains read-only and capability-gated.
     *
     * @return list<array<string,mixed>>
     */
    public function coordinationQueue(int $limit = 5): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        $limit = max(1, min(10, $limit));
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $sql = "SELECT br.id request_id,br.reference_code,br.created_at,
                       contact.full_name,contact.email,contact.mobile,contact.country,contact.city,contact.timezone,contact.submission_source,
                       COALESCE(NULLIF(i.name_en,''),NULLIF(i.name_fa,''),'') instrument_name,
                       COALESCE(NULLIF(c.name_en,''),NULLIF(c.name_fa,''),'') course_name,
                       rt.local_date,rt.local_start_time,rt.timezone requested_timezone
                FROM {$p}booking_requests br
                LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id
                LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id
                LEFT JOIN {$p}booking_request_contact_snapshots contact ON contact.id=(SELECT cs.id FROM {$p}booking_request_contact_snapshots cs WHERE cs.booking_request_id=br.id ORDER BY cs.snapshot_sequence DESC,cs.id DESC LIMIT 1)
                LEFT JOIN {$p}booking_request_requested_times rt ON rt.id=(SELECT rt2.id FROM {$p}booking_request_requested_times rt2 WHERE rt2.booking_request_id=br.id ORDER BY rt2.sequence_number ASC,rt2.id ASC LIMIT 1)
                LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id
                LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id
                LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL
                LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1
                WHERE " . self::ELIGIBLE_SQL . " AND " . self::stageSql('needs_coordination') . "
                ORDER BY br.created_at DESC,br.id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit)) ?: array();
        return array_map(function (object $row): array {
            $created = (string) ($row->created_at ?? '');
            $source = (string) ($row->submission_source ?? '');
            $contact = trim((string) ($row->email ?? '')) !== '' ? (string) $row->email : (string) ($row->mobile ?? '');
            $location = implode(', ', array_filter(array(trim((string) ($row->city ?? '')), trim((string) ($row->country ?? '')))));
            $requested = trim(trim((string) ($row->local_date ?? '')) . ' ' . substr((string) ($row->local_start_time ?? ''), 0, 5));
            if ($requested !== '' && (string) ($row->requested_timezone ?? '') !== '') $requested .= ' · ' . (string) $row->requested_timezone;
            return array(
                'request_id' => (int) ($row->request_id ?? 0),
                'reference' => (string) ($row->reference_code ?? ''),
                'learner_name' => trim((string) ($row->full_name ?? '')) !== '' ? (string) $row->full_name : 'Learner',
                'instrument' => (string) ($row->instrument_name ?? ''),
                'course' => (string) ($row->course_name ?? ''),
                'contact' => $contact,
                'location' => $location,
                'requested_time' => $requested,
                'source' => $source !== '' && str_contains($source, 'public_rest') ? 'Public booking form' : ($source !== '' ? ucfirst(str_replace('_', ' ', $source)) : 'Not recorded'),
                'age' => $this->ageLabel($created),
                'url' => admin_url('admin.php?page=dzn-booking-requests&request_id=' . (int) ($row->request_id ?? 0)),
            );
        }, $rows);
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
