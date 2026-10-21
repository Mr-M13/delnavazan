<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Prepares the canonical, pre-payment continuation graph after a Teacher has recorded that the
 * introductory lesson was actually delivered.
 *
 * Every consequential write still belongs to the existing canonical authority named here. This
 * service only sequences those authorities for the already-confirmed booking path and records
 * durable workflow checkpoints so a safe retry never repeats a commercial commitment.
 */
final class PostIntroPreparationService {
    public const OUTCOME_DELIVERED = 'delivered';
    public const OUTCOME_STUDENT_NO_SHOW = 'student_no_show';
    public const OUTCOME_TEACHER_CANCELLED = 'teacher_cancelled';
    public const OUTCOME_STUDENT_CANCELLED = 'student_cancelled';

    private PostIntroRepository $repository;

    public function __construct(?PostIntroRepository $repository = null) {
        $this->repository = $repository ?? new PostIntroRepository();
    }

    /** Record a Teacher-facing outcome exactly once, then prepare only a genuinely delivered intro. */
    public function recordTeacherOutcome(string $confirmationUid, int $teacherPrincipalId, string $outcome, string $note = ''): array {
        $confirmation = $this->repository->confirmationByUid($confirmationUid);
        if (!$confirmation) throw new \InvalidArgumentException('intro_booking_not_found');
        if ((int) $confirmation->teacher_id !== $teacherPrincipalId) throw new \RuntimeException('Unauthorized');
        return $this->recordOutcome($confirmation, $outcome, $note, 'teacher_portal', $teacherPrincipalId);
    }

    /** Administrative recovery path for a Teacher who cannot use the portal. */
    public function recordAdministrativeOutcome(string $confirmationUid, int $adminUserId, string $outcome, string $note = ''): array {
        if (!current_user_can('dzn_manage_post_intro_automation')) throw new \RuntimeException('Unauthorized');
        $confirmation = $this->repository->confirmationByUid($confirmationUid);
        if (!$confirmation) throw new \InvalidArgumentException('intro_booking_not_found');
        return $this->recordOutcome($confirmation, $outcome, $note, 'admin', $adminUserId);
    }

    private function recordOutcome(object $confirmation, string $outcome, string $note, string $source, int $recordedBy): array {
        if (!in_array($outcome, array(self::OUTCOME_DELIVERED, self::OUTCOME_STUDENT_NO_SHOW, self::OUTCOME_TEACHER_CANCELLED, self::OUTCOME_STUDENT_CANCELLED), true)) {
            throw new \InvalidArgumentException('intro_outcome_invalid');
        }
        if ($confirmation->privacy_erased_at !== null) throw new \InvalidArgumentException('intro_booking_unavailable');
        if ($confirmation->state !== 'confirmed') throw new \InvalidArgumentException('intro_booking_not_confirmed');
        if ((string) $confirmation->ends_at_utc > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('intro_not_finished');
        if ((int) ($confirmation->student_id ?? 0) < 1) throw new \InvalidArgumentException('student_identity_unresolved');

        $existing = $this->repository->outcomeByBooking((int) $confirmation->booking_request_id);
        if ($existing) return array('outcome_id' => (int) $existing->id, 'created' => false);

        $now = gmdate('Y-m-d H:i:s');
        try {
            $id = $this->repository->insertOutcome(array(
                'uid' => Identifier::uid(),
                'booking_request_id' => (int) $confirmation->booking_request_id,
                'confirmation_id' => (int) $confirmation->id,
                'teacher_id' => (int) $confirmation->teacher_id,
                'student_id' => (int) $confirmation->student_id,
                'outcome' => $outcome,
                'note' => $note === '' ? null : mb_substr($note, 0, 500),
                'occurred_at' => (string) $confirmation->ends_at_utc,
                'source' => $source,
                'recorded_by' => $recordedBy,
                'recorded_at' => $now,
                'created_at' => $now,
            ));
        } catch (\Throwable $error) {
            $winner = $this->repository->outcomeByBooking((int) $confirmation->booking_request_id);
            if ($winner) return array('outcome_id' => (int) $winner->id, 'created' => false);
            throw $error;
        }

        $this->prepare($id);
        return array('outcome_id' => $id, 'created' => true);
    }

    /**
     * Run or resume preparation for one recorded outcome.
     *
     * @return array{preparation_id:int,state:string,created:bool}
     */
    public function prepare(int $outcomeId): array {
        $outcome = $this->outcome($outcomeId);
        $confirmation = $this->repository->confirmationById((int) $outcome->confirmation_id);
        if (!$confirmation) throw new \RuntimeException('intro_booking_missing');
        $preparation = $this->preparationFor($outcome, $confirmation);
        if ($preparation === null) throw new \RuntimeException('post_intro_preparation_unavailable');

        if (in_array((string) $preparation->state, array('prepared', 'checkout_pending', 'payment_received', 'activated'), true)) {
            return array('preparation_id' => (int) $preparation->id, 'state' => (string) $preparation->state, 'created' => false);
        }

        if ((string) $outcome->outcome !== self::OUTCOME_DELIVERED) {
            $code = (string) $outcome->outcome === self::OUTCOME_STUDENT_NO_SHOW ? 'intro_student_no_show' : 'intro_cancelled';
            $this->repository->updatePreparation((int) $preparation->id, array(
                'state' => 'exception',
                'exception_code' => $code,
                'exception_detail' => 'The introductory lesson was not delivered; automatic continuation was not started.',
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            return array('preparation_id' => (int) $preparation->id, 'state' => 'exception', 'created' => false);
        }

        $this->repository->updatePreparation((int) $preparation->id, array(
            'state' => 'preparing',
            'exception_code' => null,
            'exception_detail' => null,
            'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));

        try {
            $preparation = PostIntroSystemActor::run(fn() => $this->advance($preparation, $confirmation));
            return array('preparation_id' => (int) $preparation->id, 'state' => (string) $preparation->state, 'created' => false);
        } catch (\Throwable $error) {
            $code = $this->controlledCode($error);
            $this->repository->updatePreparation((int) $preparation->id, array(
                'state' => 'exception',
                'exception_code' => $code,
                'exception_detail' => mb_substr($error->getMessage(), 0, 500),
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            return array('preparation_id' => (int) $preparation->id, 'state' => 'exception', 'created' => false);
        }
    }

    /** Retry only the preparation workflow; the recorded outcome is never replaced. */
    public function retry(int $preparationId): array {
        $preparation = $this->repository->preparation($preparationId);
        if (!$preparation) throw new \InvalidArgumentException('post_intro_preparation_not_found');
        if (in_array((string) $preparation->state, array('payment_received', 'activated'), true)) {
            return array('preparation_id' => $preparationId, 'state' => (string) $preparation->state, 'created' => false);
        }
        return $this->prepare((int) $preparation->outcome_id);
    }

    private function advance(object $preparation, object $confirmation): object {
        $repository = $this->repository;
        $now = gmdate('Y-m-d H:i:s');
        $prepId = (int) $preparation->id;
        $studentId = (int) $preparation->student_id;
        $teacherId = (int) $preparation->teacher_id;
        $bookingId = (int) $preparation->booking_request_id;

        $student = $repository->student($studentId);
        $teacher = $repository->teacher($teacherId);
        if (!$student || $student->status !== 'active' || $student->archived_at !== null) throw new \RuntimeException('student_not_active');
        if (!$teacher || $teacher->status !== 'active' || $teacher->archived_at !== null) throw new \RuntimeException('teacher_not_available');
        if ($confirmation->privacy_erased_at !== null) throw new \InvalidArgumentException('intro_booking_unavailable');

        [$course, $product, $region] = $this->resolveCommercialContext($preparation, $confirmation, $teacherId);
        $preparation = $this->save($prepId, array(
            'standard_course_id' => (int) $course->id,
            'product_id' => (int) $product->id,
            'region_code' => $region,
            'updated_at' => $now,
        ));

        $lesson = $repository->lessonForBooking($bookingId);
        if (!$lesson) {
            $lessonId = (new LessonService())->create(array(
                'student_id' => $studentId,
                'teacher_id' => $teacherId,
                'course_id' => (int) $course->id,
                'lesson_type' => 'introductory',
                'status' => 'draft',
                'booking_request_id' => $bookingId,
            ));
            $lesson = $repository->lessonForBooking($bookingId);
            if (!$lesson || (int) $lesson->id !== $lessonId) throw new \RuntimeException('intro_lesson_persistence_failed');
        }
        if ((int) $lesson->student_id !== $studentId || (int) $lesson->teacher_id !== $teacherId || (int) $lesson->course_id !== (int) $course->id || (string) $lesson->lesson_type !== 'introductory' || (int) ($lesson->booking_request_id ?? 0) !== $bookingId) {
            throw new \RuntimeException('intro_lesson_integrity_conflict');
        }
        $preparation = $this->save($prepId, array('intro_lesson_id' => (int) $lesson->id, 'updated_at' => $now));

        $schedule = $repository->currentScheduleVersion((int) $lesson->id);
        if (!$schedule) {
            (new LessonScheduleService())->recordConfirmedBookingOccurrence((int) $lesson->id, array(
                'booking_request_id' => $bookingId,
                'starts_at_utc' => (string) $confirmation->starts_at_utc,
                'ends_at_utc' => (string) $confirmation->ends_at_utc,
                'schedule_timezone' => (string) $confirmation->schedule_timezone,
                'local_wall_date' => (string) $confirmation->local_wall_date,
                'local_wall_time' => (string) $confirmation->local_wall_time,
            ));
            $schedule = $repository->currentScheduleVersion((int) $lesson->id);
        }
        if (!$schedule) throw new \RuntimeException('intro_occurrence_unavailable');
        (new LessonService())->completeIntroductory((int) $lesson->id, (int) $schedule->id);

        $slot = $repository->slotAuthorityForIntro((int) $lesson->id);
        if (!$slot) {
            $next = $this->nextWeeklyWallClock((string) $confirmation->local_wall_date, (string) $confirmation->local_wall_time, (string) $confirmation->schedule_timezone);
            (new CanonicalContinuationService())->recordFirstRegularSlot((int) $lesson->id, array(
                'schedule_timezone' => (string) $confirmation->schedule_timezone,
                'local_wall_date' => $next['date'],
                'local_wall_time' => $next['time'],
                'authority_basis' => 'confirmed_booking_recurrence',
                'reason_code' => 'confirmed_booking_weekly_slot',
                'evidence_channel' => 'authenticated_platform',
                'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                'evidence_at' => $now,
            ), 'post-intro-slot-' . (string) $preparation->uid);
            $slot = $repository->slotAuthorityForIntro((int) $lesson->id);
        }
        if (!$slot) throw new \RuntimeException('continuation_slot_unavailable');
        $preparation = $this->save($prepId, array('slot_authority_id' => (int) $slot->id, 'updated_at' => $now));

        $coordination = (new CoordinationCaseService())->open($bookingId, 'confirmed_booking');
        $caseId = (int) $coordination['case_id'];
        $candidate = (new CoordinationCaseService())->addCandidate($caseId, $teacherId, 'advisory_match', 'confirmed_booking');
        $candidateId = (int) $candidate['candidate_id'];
        $preparation = $this->save($prepId, array('coordination_case_id' => $caseId, 'candidate_id' => $candidateId, 'updated_at' => $now));

        $proposal = $repository->proposalVersionByCandidate($candidateId);
        if (!$proposal) {
            $assent = $repository->currentAssentForCandidate($candidateId);
            if (!$assent) {
                $assentResult = (new TeacherAvailabilityAssentService())->recordAdministratorAttestation(array(
                    'candidate_id' => $candidateId,
                    'candidate_version' => (int) $candidate['version'],
                    'teacher_id' => $teacherId,
                    'course_id' => (int) $course->id,
                    'delivery_mode' => 'online',
                    'location_scope' => 'not_applicable',
                    'frequency_per_week' => 1,
                    'expected_duration_minutes' => (int) $course->default_duration_minutes,
                    'commencement_window_start' => (string) $slot->starts_at_utc,
                    'commencement_window_end' => gmdate('Y-m-d H:i:s', strtotime((string) $slot->starts_at_utc . ' UTC +7 days')),
                    'timezone' => (string) $confirmation->schedule_timezone,
                    'conditions_code' => 'none',
                    'attribution_basis' => 'confirmed_booking_teacher_acceptance',
                    'evidence_channel' => 'authenticated_platform',
                    'evidence_reference' => 'post-intro:' . (string) $preparation->uid,
                    'evidence_at' => $now,
                    'valid_until' => gmdate('Y-m-d H:i:s', strtotime($now . ' UTC +14 days')),
                ));
                $assentId = (int) $assentResult['assent_id'];
                $fingerprint = (string) $assentResult['fingerprint'];
            } else {
                $assentId = (int) $assent->id;
                $snapshot = $repository->currentAssentSnapshot((int) $assent->snapshot_id);
                if (!$snapshot) throw new \RuntimeException('teacher_assent_snapshot_missing');
                $fingerprint = (string) $snapshot->arrangement_fingerprint;
            }
            $preparation = $this->save($prepId, array('assent_id' => $assentId, 'updated_at' => $now));
            $issued = (new ProposalService())->issueInitial($candidateId, $fingerprint, 'post-intro-proposal-' . (string) $preparation->uid);
            $proposal = $repository->proposalVersion($issued['version_id']);
            if (!$proposal) throw new \RuntimeException('proposal_persistence_failed');
        }
        $preparation = $this->save($prepId, array(
            'proposal_family_id' => (int) $proposal->proposal_family_id,
            'proposal_option_id' => (int) $proposal->proposal_option_id,
            'proposal_version_id' => (int) $proposal->id,
            'state' => 'prepared',
            'prepared_at' => $now,
            'updated_at' => $now,
        ));

        $repository->insertNotificationIfMissing((int) $confirmation->delivery_id, (int) $confirmation->id, 'post_intro_invitation', $now);
        return $preparation;
    }

    private function preparationFor(object $outcome, object $confirmation): ?object {
        $repository = $this->repository;
        $preparation = $repository->preparationByBooking((int) $outcome->booking_request_id);
        if ($preparation) return $preparation;
        $now = gmdate('Y-m-d H:i:s');
        try {
            $id = $repository->insertPreparation(array(
                'uid' => Identifier::uid(),
                'booking_request_id' => (int) $outcome->booking_request_id,
                'outcome_id' => (int) $outcome->id,
                'student_id' => (int) $outcome->student_id,
                'teacher_id' => (int) $outcome->teacher_id,
                'state' => 'preparing',
                'last_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            return $repository->preparation($id);
        } catch (\Throwable $error) {
            $winner = $repository->preparationByBooking((int) $outcome->booking_request_id);
            if ($winner) return $winner;
            throw $error;
        }
    }

    private function outcome(int $outcomeId): object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_booking_request_intro_outcomes WHERE id=%d", $outcomeId));
        if (!$row) throw new \InvalidArgumentException('intro_outcome_not_found');
        return $row;
    }

    private function resolveCommercialContext(object $preparation, object $confirmation, int $teacherId): array {
        $repository = $this->repository;
        $instrumentId = (int) ($confirmation->requested_instrument_id ?? 0);
        if ($instrumentId < 1) throw new \RuntimeException('standard_course_unresolved');
        $courses = $repository->standardCoursesForInstrument($instrumentId);
        if (count($courses) !== 1) throw new \RuntimeException('standard_course_unresolved');
        $course = $courses[0];
        if (!$repository->teacherEligibleForCourse($teacherId, (int) $course->id)) throw new \RuntimeException('teacher_not_eligible_for_standard_course');

        $products = $repository->productsForCourse((int) $course->id);
        if (count($products) !== 1) throw new \RuntimeException('commercial_product_unresolved');
        $product = $products[0];
        $prices = $repository->activePricesForProduct((int) $product->id);
        if (!$prices) throw new \RuntimeException('commercial_price_unresolved');

        $country = strtoupper(trim((string) ($confirmation->country ?? '')));
        $regions = array('AU', 'NZ', 'US', 'CA', 'GB');
        $region = null;
        if (in_array($country, $regions, true)) {
            foreach ($prices as $price) if (strtoupper((string) $price->region_code) === $country) { $region = $country; break; }
        }
        if ($region === null && count($prices) === 1) $region = strtoupper((string) $prices[0]->region_code);
        if ($region === null) throw new \RuntimeException('commercial_region_unresolved');
        if (!in_array($region, array('AU', 'NZ', 'US', 'CA', 'EU', 'GB'), true)) throw new \RuntimeException('commercial_region_unresolved');
        $price = $repository->priceForProductRegion((int) $product->id, $region);
        if (!$price) throw new \RuntimeException('commercial_price_unresolved');

        return array($course, $product, $region);
    }

    private function nextWeeklyWallClock(string $date, string $time, string $timezone): array {
        $zone = new \DateTimeZone($timezone);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $zone);
        if (!$start) throw new \RuntimeException('continuation_slot_wall_clock_invalid');
        $next = $start->modify('+7 days');
        return array('date' => $next->format('Y-m-d'), 'time' => $next->format('H:i:s'));
    }

    private function save(int $id, array $changes): object {
        $this->repository->updatePreparation($id, $changes);
        $row = $this->repository->preparation($id);
        if (!$row) throw new \RuntimeException('post_intro_preparation_missing');
        return $row;
    }

    private function controlledCode(\Throwable $error): string {
        $message = trim($error->getMessage());
        if (preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1) return $message;
        return 'post_intro_preparation_failed';
    }
}
