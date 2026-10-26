<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;
use Delnavazan\Platform\Integrations\GoogleCalendarMeetAdapter;
use Delnavazan\Platform\Integrations\GoogleCalendarProjectionService;
use Delnavazan\Platform\Integrations\GoogleOAuthTransport;

/**
 * Activates a verified post-intro purchase through the existing canonical authorities.
 *
 * The automation is deliberately replay-safe: every consequential command uses a deterministic
 * key derived from the preparation UID, so a retry after a provider or notification failure
 * re-enters the same authority and converges on the already committed result.
 */
final class PostIntroActivationService {
    public function __construct(private ?PostIntroRepository $repository = null) {
        $this->repository = $repository ?? new PostIntroRepository();
    }

    /** @return array{state:string,term_id:int,assignment_id:int,lessons:int} */
    public function activate(int $jobId): array {
        $job = $this->repository->job($jobId);
        if (!$job) throw new \InvalidArgumentException('post_intro_job_not_found');
        if ((string) $job->state === 'completed') return array('state' => 'completed', 'term_id' => 0, 'assignment_id' => 0, 'lessons' => 0);
        $preparation = $this->repository->preparation((int) $job->preparation_id);
        if (!$preparation) throw new \RuntimeException('post_intro_preparation_missing');
        $purchase = $this->repository->purchase((int) $job->purchase_id);
        if (!$purchase) throw new \RuntimeException('commercial_purchase_required');
        if ((int) $purchase->offer_id !== (int) $preparation->offer_id) throw new \RuntimeException('post_intro_purchase_mismatch');
        $entitlement = $this->repository->entitlementForPurchase((int) $purchase->id);
        if (!$entitlement) throw new \RuntimeException('commercial_entitlement_required');

        return PostIntroSystemActor::run(function () use ($job, $preparation, $purchase, $entitlement): array {
            $repository = $this->repository;
            $now = gmdate('Y-m-d H:i:s');
            $uid = (string) $preparation->uid;
            $evidenceAt = $this->stableEvidenceAt($job, $repository->paymentOccurredAt((int) $purchase->id));
            $evidence = array(
                'evidence_channel' => 'authenticated_platform',
                'evidence_reference' => 'post-intro:' . $uid,
                'evidence_at' => $evidenceAt,
            );

            $enrolment = $repository->enrolment((int) $preparation->enrolment_id);
            if (!$enrolment) throw new \RuntimeException('canonical_enrolment_required');
            if ((string) $enrolment->lifecycle_state === 'authorised') {
                (new CanonicalEnrolmentLifecycleService())->activate(
                    (int) $enrolment->id,
                    'authorised',
                    $evidence,
                    'post-intro-enrolment-activate-' . $uid
                );
            } elseif ((string) $enrolment->lifecycle_state !== 'current') {
                throw new \RuntimeException('enrolment_not_activatable');
            }

            (new CommercialCapacityService())->handoffFromEntitlement(
                (int) $entitlement->id,
                $evidence,
                'post-intro-handoff-' . $uid
            );

            // A Term may already have been created by an authorised operator action while the
            // payment was unverified. Identify that exact applicable Term and fund it rather than
            // creating a second Term. If it is already funded by this purchase, reuse it.
            $termInspection = (new TermApplicabilityAssessment())->inspectForAuthority((int) $enrolment->id);
            $existingTerm = null;
            $expectedLatestTermId = null;
            $expectedLatestState = null;
            if ((string) ($termInspection['classification'] ?? '') === TermApplicabilityAssessment::CANONICAL_APPLICABLE) {
                foreach ((array) ($termInspection['terms'] ?? array()) as $candidate) {
                    if ((int) ($candidate->applicable_slot ?? 0) === 1) {
                        $existingTerm = $candidate;
                        break;
                    }
                }
                if (!$existingTerm) throw new \RuntimeException('canonical_term_integrity_conflict');
            } elseif ((string) ($termInspection['classification'] ?? '') === TermApplicabilityAssessment::DATA_INTEGRITY_CONFLICT) {
                throw new \RuntimeException('canonical_term_integrity_conflict');
            } elseif ((string) ($termInspection['classification'] ?? '') === TermApplicabilityAssessment::CANONICAL_TERMINAL_HISTORY) {
                $terms = (array) ($termInspection['terms'] ?? array());
                $latest = $terms ? end($terms) : null;
                if (!$latest || !in_array((string) $latest->lifecycle_state, array('closed', 'cancelled'), true)) {
                    throw new \RuntimeException('canonical_term_integrity_conflict');
                }
                $expectedLatestTermId = (int) $latest->id;
                $expectedLatestState = (string) $latest->lifecycle_state;
            }

            if ($existingTerm !== null) {
                $funding = (new CommercialTermFundingService())->fundingPlanForTerm((int) $existingTerm->id);
                if ($funding !== null) {
                    if ((int) ($funding['purchase_id'] ?? 0) !== (int) $purchase->id || (int) ($funding['entitlement_id'] ?? 0) !== (int) $entitlement->id) {
                        throw new \RuntimeException('term_funding_conflict');
                    }
                    $bound = $funding;
                } else {
                    $bound = (new CommercialTermFundingService())->bindEntitlementToExistingTerm(
                        (int) $entitlement->id,
                        (int) $existingTerm->id,
                        $evidence,
                        'post-intro-term-bind-' . $uid
                    );
                }
            } else {
                $bindInput = $evidence;
                if ($expectedLatestTermId !== null) {
                    $bindInput['expected_latest_term_id'] = $expectedLatestTermId;
                    $bindInput['expected_latest_state'] = $expectedLatestState;
                }
                $bound = (new CommercialTermFundingService())->bindEntitlementToTerm(
                    (int) $entitlement->id,
                    $bindInput,
                    'post-intro-term-bind-' . $uid
                );
            }
            $termId = (int) $bound['term_id'];
            $term = $repository->term($termId);
            if (!$term) throw new \RuntimeException('canonical_term_required');
            if ((string) $term->lifecycle_state === 'authorised') {
                (new CanonicalTermAuthorityService())->activate($termId, 'authorised', $evidence, 'post-intro-term-activate-' . $uid);
            } elseif ((string) $term->lifecycle_state !== 'current') {
                throw new \RuntimeException('term_not_activatable');
            }

            $assignment = $repository->assignmentForEnrolment((int) $enrolment->id);
            if (!$assignment) {
                $assigned = (new TeacherAssignmentService())->assignInitial((int) $enrolment->id, 'post-intro-assignment-' . $uid);
                $assignmentId = (int) $assigned['assignment_id'];
            } elseif ((string) $assignment->state === 'assigned') {
                $assignmentId = (int) $assignment->id;
            } else {
                throw new \RuntimeException('teacher_assignment_unavailable');
            }

            $pattern = $repository->patternForSlotAuthority((int) $preparation->slot_authority_id);
            if (!$pattern) {
                $any = $repository->activePatternForAnyTeacher((int) $preparation->student_id, (int) $preparation->standard_course_id);
                if ($any) throw new \RuntimeException('commercial_pattern_teacher_conflict');
                throw new \RuntimeException('commercial_pattern_required');
            }
            if ((int) $pattern->student_id !== (int) $preparation->student_id
                || (int) $pattern->course_id !== (int) $preparation->standard_course_id
                || (int) $pattern->source_slot_authority_id !== (int) $preparation->slot_authority_id) {
                throw new \RuntimeException('commercial_pattern_conflict');
            }
            if ((int) $pattern->teacher_id !== (int) $preparation->teacher_id) throw new \RuntimeException('commercial_pattern_teacher_conflict');
            $count = min(52, max(1, (int) ($bound['effective_sessions'] ?? 0)));
            $intervals = (new CommercialPatternService())->intervalsFor($pattern, $count);

            $lessonIds = array();
            $projections = array();
            foreach ($intervals as $index => $interval) {
                $sequence = $index + 1;
                $lesson = (new CanonicalLessonAuthorityService())->createStandard(
                    $termId,
                    $assignmentId,
                    $evidence,
                    'post-intro-lesson-' . $uid . '-' . $sequence
                );
                $lessonId = (int) $lesson['lesson_id'];
                $lessonIds[] = $lessonId;
                $scheduled = (new CanonicalLessonScheduleService())->schedule(
                    $lessonId,
                    $assignmentId,
                    array(
                        'schedule_timezone' => (string) $interval['schedule_timezone'],
                        'local_wall_date' => (string) $interval['local_wall_date'],
                        'local_wall_time' => (string) $interval['local_wall_time'],
                        'reason_code' => 'confirmed_booking_recurrence',
                        'evidence_channel' => 'authenticated_platform',
                        'evidence_reference' => 'post-intro:' . $uid,
                        'evidence_at' => $evidenceAt,
                    ),
                    'post-intro-schedule-' . $uid . '-' . $sequence
                );
                $projections[] = array('lesson_id' => $lessonId, 'schedule_version_id' => (int) $scheduled['schedule_version_id'], 'sequence' => $sequence);
            }

            if ((string) $preparation->state !== 'activated') {
                $repository->updatePreparation((int) $preparation->id, array(
                    'state' => 'activated',
                    'exception_code' => null,
                    'exception_detail' => null,
                    'updated_at' => $now,
                ));
            }

            $this->notify($preparation, $now);

            // Provider projection is a projection only. All canonical Lessons and schedules are
            // already durable before this loop, so a missing Google connection or transient Google
            // failure is retryable without hiding or duplicating academic truth.
            foreach ($projections as $projection) {
                $this->projectCalendar(
                    (int) $projection['lesson_id'],
                    (int) $projection['schedule_version_id'],
                    $uid,
                    (int) $projection['sequence'],
                    $evidenceAt
                );
            }

            return array('state' => 'activated', 'term_id' => $termId, 'assignment_id' => $assignmentId, 'lessons' => count($lessonIds));
        });
    }

    /**
     * A durable timestamp for every canonical command in this activation.
     *
     * Canonical authorities include the evidence instant in their idempotency payload, so a
     * retry minutes later must rebuild exactly the same command. The verified provider payment
     * instant is the authoritative activation checkpoint; the job's immutable creation time is
     * the fallback for a settlement without a provider occurrence time.
     *
     * Preparation timestamps are deliberately not candidates. A preparation is created before
     * the Student action converts the Enrolment, so using it could stamp canonical activation
     * evidence before the Enrolment's own conversion event and fail the lifecycle history's
     * non-decreasing-time invariant.
     */
    private function stableEvidenceAt(object $job, ?string $paymentOccurredAt = null): string {
        $candidates = array();
        foreach (array((string) ($paymentOccurredAt ?? ''), (string) ($job->created_at ?? '')) as $candidate) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $candidate) === 1) $candidates[] = $candidate;
        }
        if (!$candidates) throw new \RuntimeException('post_intro_activation_evidence_time_unavailable');
        // Choose one durable instant once and forever: the earliest of the verified payment
        // occurrence and the settlement job creation. A provider timestamp that is briefly in
        // the future cannot make a later retry switch to a different payload.
        sort($candidates, SORT_STRING);
        return $candidates[0];
    }

    private function projectCalendar(int $lessonId, int $scheduleVersionId, string $uid, int $sequence, string $evidenceAt): void {
        $projection = (new ProviderIntegrationService(
            null,
            null,
            new GoogleOAuthTransport(),
            new GoogleCalendarMeetAdapter(),
            new GoogleCalendarMeetAdapter()
        ))->projectCalendarEvent(array(
            'lesson_id' => $lessonId,
            'schedule_version_id' => $scheduleVersionId,
            'projection_reference' => 'post-intro:' . $uid . '-' . $sequence,
            'evidence_channel' => 'authenticated_platform',
            'evidence_reference' => 'post-intro:' . $uid,
            'evidence_at' => $evidenceAt,
        ), 'post-intro-calendar-' . $uid . '-' . $sequence);

        $mappingId = (int) ($projection['mapping_id'] ?? 0);
        if ($mappingId < 1) throw new \RuntimeException('provider_projection_mapping_required');
        $mapping = (new ProviderIntegrationRepository())->calendarMapping($mappingId);
        if (!$mapping) throw new \RuntimeException('provider_projection_mapping_required');
        if ((string) $mapping->projection_state !== 'pending') return;
        (new GoogleCalendarProjectionService())->dispatch(
            $mappingId,
            'post-intro-calendar-dispatch-' . $uid . '-' . $sequence
        );
    }

    private function notify(object $preparation, string $now): void {
        $teacher = $this->repository->teacher((int) $preparation->teacher_id);
        if ($teacher && trim((string) ($teacher->email ?? '')) !== '' && empty($preparation->teacher_notified_at)) {
            $subject = 'کلاس شما با هنرجوی جدید فعال شد';
            $message = "ثبت‌نام هنرجو پس از پرداخت تأیید شد و کلاس‌های آینده در پرتال مدرس نمایش داده می‌شوند.";
            $sent = wp_mail((string) $teacher->email, $subject, $message);
            if (!$sent) throw new \RuntimeException('teacher_notification_failed');
            $this->repository->updatePreparation((int) $preparation->id, array('teacher_notified_at' => $now, 'updated_at' => $now));
        }

        $confirmation = $this->repository->confirmationByBooking((int) $preparation->booking_request_id);
        if ($confirmation) {
            $this->repository->insertNotificationIfMissing(
                (int) $confirmation->delivery_id,
                (int) $confirmation->id,
                'post_intro_activated',
                $now
            );
        }
    }
}
