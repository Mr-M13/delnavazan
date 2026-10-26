<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence for the post-introductory continuation automation.
 *
 * This repository deliberately holds only automation workflow state. Canonical Lesson,
 * Enrolment, Term, commercial, continuation and provider truth remain owned by their existing
 * authorities; nothing here writes those tables.
 */
final class PostIntroRepository {
    /** Only intros completed on or after the automation release are eligible. */
    public const ELIGIBLE_FROM = '2026-10-21 00:00:00';
    private string $p;

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    public function begin(): void { global $wpdb; $wpdb->query('START TRANSACTION'); }
    public function commit(): void { global $wpdb; $wpdb->query('COMMIT'); }
    public function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }

    public function confirmationByUid(string $uid, bool $lock = false): ?object {
        global $wpdb;
        $sql = "SELECT c.*, d.booking_request_id, d.state delivery_state,
                       r.student_id, r.requested_instrument_id, r.selected_intro_course_id, r.reference_code request_reference,
                       s.full_name, s.email, s.country, s.timezone contact_timezone, s.communication_language,
                       s.adult_self_attested, r.privacy_erased_at,
                       t.status teacher_status, t.archived_at teacher_archived_at
                FROM {$this->p}booking_request_confirmations c
                INNER JOIN {$this->p}booking_request_teacher_deliveries d ON d.id=c.delivery_id
                INNER JOIN {$this->p}booking_requests r ON r.id=d.booking_request_id
                LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
                LEFT JOIN {$this->p}teachers t ON t.id=c.teacher_id
                WHERE c.uid=%s LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        return $wpdb->get_row($wpdb->prepare($sql, $uid));
    }

    public function confirmationById(int $id, bool $lock = false): ?object {
        global $wpdb;
        $sql = "SELECT c.*, d.booking_request_id, d.state delivery_state,
                       r.student_id, r.requested_instrument_id, r.selected_intro_course_id, r.reference_code request_reference,
                       s.full_name, s.email, s.country, s.timezone contact_timezone, s.communication_language,
                       s.adult_self_attested, r.privacy_erased_at
                FROM {$this->p}booking_request_confirmations c
                INNER JOIN {$this->p}booking_request_teacher_deliveries d ON d.id=c.delivery_id
                INNER JOIN {$this->p}booking_requests r ON r.id=d.booking_request_id
                LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
                WHERE c.id=%d LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        return $wpdb->get_row($wpdb->prepare($sql, $id));
    }

    public function outcomeByBooking(int $bookingRequestId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_request_intro_outcomes WHERE booking_request_id=%d" . ($lock ? ' FOR UPDATE' : ''), $bookingRequestId));
    }

    public function confirmationByBooking(int $bookingRequestId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, s.adult_self_attested, r.privacy_erased_at,
                    COALESCE(NULLIF(course.name_fa,''),course.name_en) course_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}booking_request_confirmations c
             LEFT JOIN {$this->p}booking_requests r ON r.id=c.booking_request_id
             LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
             LEFT JOIN {$this->p}courses course ON course.id=COALESCE(r.selected_intro_course_id,0)
             LEFT JOIN {$this->p}teachers t ON t.id=c.teacher_id
             WHERE c.booking_request_id=%d AND c.state='confirmed' ORDER BY c.id DESC LIMIT 1",
            $bookingRequestId
        ));
    }

    public function outcomeByUid(string $uid): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_request_intro_outcomes WHERE uid=%s", $uid));
    }

    public function insertOutcome(array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->p . 'booking_request_intro_outcomes', $data) === false) throw new \RuntimeException('intro_outcome_persistence_failed');
        return (int) $wpdb->insert_id;
    }

    public function preparation(int $id, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_preparations WHERE id=%d" . ($lock ? ' FOR UPDATE' : ''), $id));
    }

    public function preparationByUid(string $uid, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_preparations WHERE uid=%s" . ($lock ? ' FOR UPDATE' : ''), $uid));
    }

    public function preparationByBooking(int $bookingRequestId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_preparations WHERE booking_request_id=%d" . ($lock ? ' FOR UPDATE' : ''), $bookingRequestId));
    }

    public function preparationByArrangement(int $arrangementId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_preparations WHERE arrangement_id=%d" . ($lock ? ' FOR UPDATE' : ''), $arrangementId));
    }

    public function preparationByEnrolment(int $enrolmentId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_preparations WHERE enrolment_id=%d ORDER BY id DESC LIMIT 1", $enrolmentId));
    }

    /** @return list<object> */
    public function recentPreparations(int $limit = 25): array {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        return $wpdb->get_results("SELECT * FROM {$this->p}post_intro_preparations ORDER BY id DESC LIMIT {$limit}") ?: array();
    }

    public function purchaseForOffer(int $offerId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_purchases WHERE offer_id=%d ORDER BY id DESC LIMIT 1", $offerId));
    }

    public function insertPreparation(array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->p . 'post_intro_preparations', $data) === false) throw new \RuntimeException('post_intro_preparation_persistence_failed');
        return (int) $wpdb->insert_id;
    }

    public function updatePreparation(int $id, array $changes): void {
        global $wpdb;
        if ($wpdb->update($this->p . 'post_intro_preparations', $changes, array('id' => $id)) === false) throw new \RuntimeException('post_intro_preparation_update_failed');
    }

    /** Persist the Student's first action instant once and return that immutable value. */
    public function claimStudentActionAt(int $id, string $now): string {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}post_intro_preparations SET student_action_at=%s,updated_at=%s WHERE id=%d AND student_action_at IS NULL",
            $now, $now, $id
        ));
        $row = $this->preparation($id);
        if (!$row || trim((string) ($row->student_action_at ?? '')) === '') throw new \RuntimeException('post_intro_action_time_unavailable');
        return (string) $row->student_action_at;
    }

    /** @return list<object> */
    public function preparationsForStudent(int $studentId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.* FROM {$this->p}post_intro_preparations p
             INNER JOIN {$this->p}booking_requests r ON r.id=p.booking_request_id
             WHERE p.student_id=%d AND r.privacy_erased_at IS NULL AND p.state NOT IN ('closed','cancelled')
             ORDER BY p.id DESC LIMIT 20",
            $studentId
        )) ?: array();
    }

    /** @return list<object> */
    public function exceptionPreparations(int $limit = 100): array {
        global $wpdb;
        $limit = max(1, min(250, $limit));
        return $wpdb->get_results("SELECT * FROM {$this->p}post_intro_preparations WHERE state='exception' OR exception_code IS NOT NULL ORDER BY id DESC LIMIT {$limit}") ?: array();
    }

    public function activeJobForPurchase(int $purchaseId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_activation_jobs WHERE purchase_id=%d" . ($lock ? ' FOR UPDATE' : ''), $purchaseId));
    }

    public function job(int $id, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_activation_jobs WHERE id=%d" . ($lock ? ' FOR UPDATE' : ''), $id));
    }

    public function insertJob(array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->p . 'post_intro_activation_jobs', $data) === false) throw new \RuntimeException('post_intro_activation_job_persistence_failed');
        return (int) $wpdb->insert_id;
    }

    public function updateJob(int $id, array $changes): void {
        global $wpdb;
        if ($wpdb->update($this->p . 'post_intro_activation_jobs', $changes, array('id' => $id)) === false) throw new \RuntimeException('post_intro_activation_job_update_failed');
    }

    /** @return list<object> */
    public function dueJobs(int $limit = 10): array {
        global $wpdb;
        $limit = max(1, min(50, $limit));
        $now = gmdate('Y-m-d H:i:s');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}post_intro_activation_jobs
             WHERE (state IN ('queued','retryable') AND (next_attempt_at IS NULL OR next_attempt_at<=%s))
                OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<=%s)
             ORDER BY id LIMIT {$limit}",
            $now, $now
        )) ?: array();
    }

    public function claimJob(int $id, string $leaseDigest, string $now, string $leaseUntil): bool {
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}post_intro_activation_jobs
             SET state='running',attempt_count=attempt_count+1,lease_token_digest=%s,lease_expires_at=%s,updated_at=%s
             WHERE id=%d AND (state IN ('queued','retryable') OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<=%s))",
            $leaseDigest, $leaseUntil, $now, $id, $now
        ));
        return $changed === 1;
    }

    /** Atomically requeue an unleased failed/retryable job; never clear a live worker's lease. */
    public function requeueJob(int $id, string $now): bool {
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}post_intro_activation_jobs
             SET state='queued',attempt_count=0,next_attempt_at=%s,last_error_code=NULL,last_error_detail=NULL,
                 lease_token_digest=NULL,lease_expires_at=NULL,updated_at=%s
             WHERE id=%d AND state IN ('failed','retryable') AND lease_token_digest IS NULL",
            $now, $now, $id
        ));
        return $changed === 1;
    }

    public function releaseJob(int $id, string $state, array $changes, ?string $leaseDigest = null): void {        global $wpdb;
        $changes = array_merge($changes, array(
            'state' => $state,
            'lease_token_digest' => null,
            'lease_expires_at' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $where = array('id' => $id);
        if ($leaseDigest !== null) $where['lease_token_digest'] = $leaseDigest;
        $updated = $wpdb->update($this->p . 'post_intro_activation_jobs', $changes, $where);
        if ($updated === false) throw new \RuntimeException('post_intro_activation_job_release_failed');
        if ($leaseDigest !== null && $updated !== 1) throw new \RuntimeException('post_intro_job_lease_lost');
    }

    /** @return list<object> */
    public function failedJobs(int $limit = 100): array {
        global $wpdb;
        $limit = max(1, min(250, $limit));
        return $wpdb->get_results("SELECT * FROM {$this->p}post_intro_activation_jobs WHERE state IN ('failed','retryable') ORDER BY id DESC LIMIT {$limit}") ?: array();
    }

    public function purchase(int $purchaseId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_purchases WHERE id=%d", $purchaseId));
    }

    /** The immutable provider occurrence instant for the purchase's verified payment. */
    public function paymentOccurredAt(int $purchaseId): ?string {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(occurred_at) FROM {$this->p}commercial_payment_facts WHERE purchase_id=%d",
            $purchaseId
        ));
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function offer(int $offerId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_offers WHERE id=%d", $offerId));
    }

    public function offerByContinuationCase(int $caseId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_offers WHERE continuation_case_id=%d ORDER BY id DESC LIMIT 1", $caseId));
    }

    public function entitlementForPurchase(int $purchaseId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_entitlements WHERE purchase_id=%d", $purchaseId));
    }

    /** @return list<object> */
    public function obligationsForOffer(int $offerId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_offer_obligations WHERE offer_id=%d ORDER BY obligation_sequence,id", $offerId)) ?: array();
    }

    public function arrangement(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}accepted_service_arrangements WHERE id=%d", $id));
    }

    public function arrangementForFamily(int $familyId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}accepted_service_arrangements WHERE proposal_family_id=%d ORDER BY id DESC LIMIT 1", $familyId));
    }

    public function enrolmentForArrangement(int $arrangementId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}enrolments WHERE accepted_service_arrangement_id=%d AND archived_at IS NULL ORDER BY id DESC LIMIT 1",
            $arrangementId
        ));
    }

    public function continuationCaseForIntro(int $introLessonId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}canonical_continuation_cases WHERE intro_lesson_id=%d LIMIT 1", $introLessonId));
    }

    public function reservationForCase(int $caseId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}canonical_continuation_reservations WHERE continuation_case_id=%d LIMIT 1", $caseId));
    }

    public function provisionalForVersion(int $versionId, string $subjectRef): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}proposal_acceptance_events WHERE proposal_version_id=%d AND prospective_subject_ref=%s AND event_kind='accepted_pending_conditions' ORDER BY id DESC LIMIT 1",
            $versionId, $subjectRef
        ));
    }

    public function proposalVersion(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}proposal_versions WHERE id=%d", $id));
    }

    public function proposalVersionByCandidate(int $candidateId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT v.* FROM {$this->p}proposal_versions v INNER JOIN {$this->p}proposal_options o ON o.id=v.proposal_option_id WHERE v.candidate_id=%d ORDER BY v.id DESC LIMIT 1",
            $candidateId
        ));
    }

    public function proposalAcceptanceByCommand(string $keyDigest): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}proposal_acceptance_events WHERE command_key_digest=%s", $keyDigest));
    }

    public function course(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}courses WHERE id=%d", $id));
    }

    /** @return list<object> */
    public function standardCoursesForInstrument(int $instrumentId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}courses WHERE instrument_id=%d AND course_type='standard' AND status='active' AND archived_at IS NULL ORDER BY id",
            $instrumentId
        )) ?: array();
    }

    /** @return list<object> */
    public function productsForCourse(int $courseId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}commercial_products WHERE course_id=%d AND status='active' AND archived_at IS NULL ORDER BY id",
            $courseId
        )) ?: array();
    }

    /** @return list<object> */
    public function activePricesForProduct(int $productId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}commercial_prices WHERE product_id=%d AND active_slot=1 AND status='active' AND archived_at IS NULL ORDER BY region_code,id DESC",
            $productId
        )) ?: array();
    }

    public function priceForProductRegion(int $productId, string $region): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}commercial_prices WHERE product_id=%d AND region_code=%s AND active_slot=1 AND status='active' AND archived_at IS NULL LIMIT 1",
            $productId, $region
        ));
    }

    public function teacherEligibleForCourse(int $teacherId, int $courseId): bool {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}teacher_course_eligibilities WHERE teacher_id=%d AND course_id=%d AND status='active' AND (effective_from IS NULL OR effective_from<=%s) AND (effective_until IS NULL OR effective_until>%s) LIMIT 1",
            $teacherId, $courseId, $now, $now
        ));
    }

    public function student(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}students WHERE id=%d", $id));
    }

    public function teacher(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teachers WHERE id=%d", $id));
    }

    public function activeStudentPrincipal(int $studentId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1 ORDER BY id DESC LIMIT 1",
            $studentId
        ));
    }

    public function lessonForBooking(int $bookingRequestId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}lessons WHERE booking_request_id=%d", $bookingRequestId));
    }

    public function currentScheduleVersion(int $lessonId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT v.* FROM {$this->p}lesson_schedule_versions v INNER JOIN {$this->p}lessons l ON l.current_schedule_version_id=v.id WHERE l.id=%d AND v.superseded_at IS NULL LIMIT 1",
            $lessonId
        ));
    }

    public function slotAuthorityForIntro(int $introLessonId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d", $introLessonId));
    }

    public function currentAssentForCandidate(int $candidateId): ?object {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}teacher_availability_assents WHERE candidate_id=%d AND state='recorded' AND (valid_until IS NULL OR valid_until>%s) AND (review_by IS NULL OR review_by>%s) ORDER BY id DESC LIMIT 1",
            $candidateId, $now, $now
        ));
    }

    public function currentAssentSnapshot(int $snapshotId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teacher_availability_assent_snapshots WHERE id=%d", $snapshotId));
    }

    public function proposalUids(int $versionId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT f.uid family_uid,o.uid option_uid,v.uid version_uid,v.version_number
             FROM {$this->p}proposal_versions v
             INNER JOIN {$this->p}proposal_options o ON o.id=v.proposal_option_id
             INNER JOIN {$this->p}proposal_families f ON f.id=o.proposal_family_id
             WHERE v.id=%d LIMIT 1",
            $versionId
        ));
    }

    public function provisionalEvent(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}proposal_acceptance_events WHERE id=%d", $id));
    }

    public function studentCapacity(int $studentId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.* FROM {$this->p}student_acceptance_capacity_classifications c
             INNER JOIN {$this->p}students s ON s.current_acceptance_capacity_classification_id=c.id
             WHERE s.id=%d LIMIT 1",
            $studentId
        ));
    }

    public function updateStudentCapacity(int $studentId, int $classificationId): void {
        global $wpdb;
        $wpdb->update($this->p . 'students', array('current_acceptance_capacity_classification_id' => $classificationId, 'updated_at' => gmdate('Y-m-d H:i:s')), array('id' => $studentId));
    }

    public function snapshotAdultAttestation(int $bookingRequestId, bool $attested): void {
        global $wpdb;
        // Never re-populate an attestation after the owning request has been privacy-erased.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}booking_request_contact_snapshots s
             INNER JOIN {$this->p}booking_requests r ON r.id=s.booking_request_id
             SET s.adult_self_attested=%d
             WHERE s.booking_request_id=%d AND s.snapshot_sequence=1 AND r.privacy_erased_at IS NULL",
            $attested ? 1 : 0, $bookingRequestId
        ));
    }

    public function activePatternFor(int $studentId, int $courseId, int $teacherId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}commercial_recurring_patterns WHERE student_id=%d AND course_id=%d AND teacher_id=%d AND state='active' ORDER BY id DESC LIMIT 1",
            $studentId, $courseId, $teacherId
        ));
    }

    /** The exact recurring pattern materialised from this continuation's slot authority. */
    public function patternForSlotAuthority(int $slotAuthorityId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}commercial_recurring_patterns WHERE source_slot_authority_id=%d AND state='active' LIMIT 1",
            $slotAuthorityId
        ));
    }

    public function pattern(int $patternId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_recurring_patterns WHERE id=%d", $patternId));
    }

    public function activePatternForAnyTeacher(int $studentId, int $courseId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}commercial_recurring_patterns WHERE student_id=%d AND course_id=%d AND state='active' ORDER BY id DESC LIMIT 1",
            $studentId, $courseId
        ));
    }

    public function term(int $termId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}terms WHERE id=%d", $termId));
    }

    public function enrolment(int $enrolmentId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}enrolments WHERE id=%d", $enrolmentId));
    }

    public function assignmentForEnrolment(int $enrolmentId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teacher_assignments WHERE enrolment_id=%d ORDER BY id DESC LIMIT 1", $enrolmentId));
    }

    public function preparationByOffer(int $offerId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_preparations WHERE offer_id=%d" . ($lock ? ' FOR UPDATE' : ''), $offerId));
    }

    public function preparationByContinuationCase(int $caseId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_preparations WHERE continuation_case_id=%d" . ($lock ? ' FOR UPDATE' : ''), $caseId));
    }

    /** @return list<object> */
    public function pendingOutcomeConfirmationsForTeacher(int $teacherId, int $limit = 20): array {
        global $wpdb;
        $limit = max(1, min(50, $limit));
        $now = gmdate('Y-m-d H:i:s');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.uid confirmation_uid,c.starts_at_utc,c.ends_at_utc,c.schedule_timezone,c.local_wall_date,c.local_wall_time,
                    r.reference_code,s.full_name student_name,COALESCE(NULLIF(course.name_fa,''),course.name_en) course_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}booking_request_confirmations c
             INNER JOIN {$this->p}booking_request_teacher_deliveries d ON d.id=c.delivery_id
             INNER JOIN {$this->p}booking_requests r ON r.id=c.booking_request_id
             INNER JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
             LEFT JOIN {$this->p}courses course ON course.id=COALESCE(r.selected_intro_course_id,0)
             LEFT JOIN {$this->p}teachers t ON t.id=c.teacher_id
             LEFT JOIN {$this->p}booking_request_intro_outcomes o ON o.confirmation_id=c.id
             WHERE c.teacher_id=%d AND c.state='confirmed' AND c.ends_at_utc<=%s AND c.ends_at_utc>=%s AND o.id IS NULL
               AND r.privacy_erased_at IS NULL
             ORDER BY c.ends_at_utc DESC LIMIT {$limit}",
            $teacherId, $now, self::ELIGIBLE_FROM
        )) ?: array();
    }

    /** @return list<object> */
    public function pendingOutcomeConfirmations(int $limit = 100): array {
        global $wpdb;
        $limit = max(1, min(250, $limit));
        $now = gmdate('Y-m-d H:i:s');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.uid confirmation_uid,c.starts_at_utc,c.ends_at_utc,c.schedule_timezone,c.teacher_id,
                    r.reference_code,s.full_name student_name,COALESCE(NULLIF(course.name_fa,''),course.name_en) course_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name
             FROM {$this->p}booking_request_confirmations c
             INNER JOIN {$this->p}booking_request_teacher_deliveries d ON d.id=c.delivery_id
             INNER JOIN {$this->p}booking_requests r ON r.id=c.booking_request_id
             INNER JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
             LEFT JOIN {$this->p}courses course ON course.id=COALESCE(r.selected_intro_course_id,0)
             LEFT JOIN {$this->p}teachers t ON t.id=c.teacher_id
             LEFT JOIN {$this->p}booking_request_intro_outcomes o ON o.confirmation_id=c.id
             WHERE c.state='confirmed' AND c.ends_at_utc<=%s AND c.ends_at_utc>=%s AND o.id IS NULL AND r.privacy_erased_at IS NULL
             ORDER BY c.ends_at_utc DESC LIMIT {$limit}",
            $now, self::ELIGIBLE_FROM
        )) ?: array();
    }

    public function insertNotificationIfMissing(int $deliveryId, int $confirmationId, string $kind, string $now): void {
        global $wpdb;
        $table = $this->p . 'booking_request_student_notifications';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE delivery_id=%d AND notification_kind=%s", $deliveryId, $kind));
        if ($existing) return;
        if ($wpdb->insert($table, array(
            'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(),
            'delivery_id' => $deliveryId,
            'confirmation_id' => $confirmationId,
            'notification_kind' => $kind,
            'state' => 'queued',
            'attempt_count' => 0,
            'next_attempt_at' => $now,
            'lease_token_digest' => null,
            'lease_expires_at' => null,
            'last_error_code' => null,
            'handed_off_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        )) === false) throw new \RuntimeException('post_intro_notification_persistence_failed');
    }

    public function studentPortalUrl(): string {
        return home_url('/student-portal/');
    }
}
