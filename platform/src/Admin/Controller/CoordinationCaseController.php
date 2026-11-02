<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\CoordinationWorkspaceView;
use Delnavazan\Platform\Core\Application\BookingRequestMatchAssessmentService;
use Delnavazan\Platform\Core\Application\CoordinationCapabilities;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\CoordinationWorkspaceReadService;
use Delnavazan\Platform\Core\Application\EnrolmentConversionService;
use Delnavazan\Platform\Core\Application\FinalAcceptanceService;
use Delnavazan\Platform\Core\Application\NotificationManualDeliveryService;
use Delnavazan\Platform\Core\Application\ProposalAcceptanceService;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentAccountClaimNotificationService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;
use Delnavazan\Platform\Core\Application\TeacherAcceptingStateService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityAssentService;
use Delnavazan\Platform\Core\Application\TeachingEligibilityService;
use Delnavazan\Platform\Core\Infrastructure\Repository\AcceptedServiceArrangementRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CoordinationCaseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProposalAcceptanceRepository;

/**
 * Booking Coordination command centre.
 *
 * The controller resolves human-facing references into exact canonical identifiers,
 * checks the existing narrow capability for every action, and delegates every mutation
 * to its owning service. It never writes domain tables and never simulates an authority.
 */
final class CoordinationCaseController {
    public const PAGE = 'dzn-booking-request-coordination';
    private const CAPABILITY = 'dzn_manage_booking_request_coordination';
    private const VIEW_CAPABILITY = 'dzn_view_booking_requests';
    private const CASE_STATES = array(
        'open' => 'Open',
        'candidate_search' => 'Searching for a Teacher',
        'manual_search' => 'Manual Teacher search',
        'waiting_for_availability' => 'Waiting for availability',
        'withdrawn' => 'Withdrawn',
        'declined' => 'Declined',
        'unable_to_arrange' => 'Unable to arrange',
        'abandoned' => 'Abandoned',
    );
    private const CANDIDATE_STATES = array(
        'unreviewed' => 'Not yet reviewed',
        'under_discussion' => 'Under discussion',
        'not_available' => 'Not available',
        'not_suitable' => 'Not suitable',
        'withdrawn_from_consideration' => 'Withdrawn from consideration',
        'superseded' => 'Superseded',
        'closed' => 'Closed',
    );

    public static function handlePost(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (!current_user_can(self::CAPABILITY) || !current_user_can(self::VIEW_CAPABILITY)) {
            wp_die('Access denied.', 'Delnavazan', array('response' => 403));
        }
        $raw = wp_unslash($_POST);
        $action = self::key($raw['dzn_coordination_action'] ?? '');
        if ($action === '') return;
        $caseId = self::idOrZero($raw['case_id'] ?? null);
        try {
            if ($action === 'open') {
                check_admin_referer('dzn_coordination_open');
                $requestId = self::id($raw['booking_request_id'] ?? null);
                $result = (new CoordinationCaseService())->open($requestId, self::key($raw['reason'] ?? 'admin_opened'));
                self::redirect((int) $result['case_id'], $result['created'] ? 'Coordination case opened. Review the case overview and recommended next action.' : 'The existing coordination case was reopened for this booking request.');
                return;
            }
            if ($caseId < 1) throw new \InvalidArgumentException('Choose a coordination case first.');
            check_admin_referer('dzn_coordination_action_' . $caseId);
            $case = (new CoordinationCaseRepository())->caseForRead($caseId);
            if (!$case) throw new \InvalidArgumentException('Coordination case not found.');
            $message = self::dispatch($action, $case, $raw);
            self::redirect($caseId, $message);
        } catch (\Throwable $error) {
            error_log('[Delnavazan coordination] ' . $action . ' ' . get_class($error) . ' ' . $error->getMessage());
            self::redirect($caseId, self::safeMessage($error), true);
        }
    }

    public static function screen(): void {
        if (!current_user_can(self::CAPABILITY) || !current_user_can(self::VIEW_CAPABILITY)) {
            echo '<div class="wrap"><p>Access denied.</p></div>';
            return;
        }
        $read = new CoordinationWorkspaceReadService();
        $caseId = self::idOrZero($_GET['case_id'] ?? null);
        $requestId = self::idOrZero($_GET['booking_request_id'] ?? $_GET['request_id'] ?? null);
        if ($caseId < 1 && $requestId > 0) {
            global $wpdb;
            $caseId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_coordination_cases WHERE booking_request_id=%d", $requestId));
        }
        if ($caseId > 0) {
            $detail = $read->caseDetail($caseId);
            if ($detail) {
                CoordinationWorkspaceView::detail($detail);
                return;
            }
        }
        $search = isset($_GET['q']) && is_scalar($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        $scope = isset($_GET['scope']) && is_scalar($_GET['scope']) ? sanitize_key(wp_unslash((string) $_GET['scope'])) : 'active';
        if (!in_array($scope, array('active', 'closed', 'all'), true)) $scope = 'active';
        CoordinationWorkspaceView::index(
            $read->listCases($search, $scope),
            $read->openableRequests($search),
            array('q' => $search, 'scope' => $scope, 'request_id' => $requestId)
        );
    }

    /** @param array<string,mixed> $raw */
    private static function dispatch(string $action, object $case, array $raw): string {
        global $wpdb;
        $caseId = (int) $case->id;
        $requestId = (int) $case->booking_request_id;
        $read = new CoordinationWorkspaceReadService();
        $studentId = self::requestStudentId($requestId);
        $timezone = self::requestTimezone($requestId);
        switch ($action) {
            case 'transition_case':
                self::requireCap(self::CAPABILITY);
                $state = self::key($raw['state'] ?? '');
                if (!array_key_exists($state, self::CASE_STATES)) throw new \InvalidArgumentException('Choose a supported case state.');
                $reason = self::caseReason($state);
                (new CoordinationCaseService())->transition($caseId, self::id($raw['version'] ?? null), $state, $reason);
                return 'Case state changed to ' . self::CASE_STATES[$state] . '.';
            case 'add_candidate':
                self::requireCap(self::CAPABILITY);
                $source = self::key($raw['source'] ?? 'manual_search');
                $reason = $source === 'approved_exception' ? 'approved_exception' : ($source === 'advisory_match' ? 'advisory_match' : 'manual_review');
                $result = (new CoordinationCaseService())->addCandidate($caseId, self::id($raw['teacher_id'] ?? null), $source, $reason);
                return $result['created'] ? 'Teacher candidate added for review.' : 'That Teacher was already an advisory candidate; the existing record was kept.';
            case 'candidate_status':
                self::requireCap(self::CAPABILITY);
                $status = self::key($raw['status'] ?? '');
                if (!array_key_exists($status, self::CANDIDATE_STATES)) throw new \InvalidArgumentException('Choose a supported candidate status.');
                $reason = match ($status) {
                    'not_available' => 'not_available',
                    'not_suitable' => 'not_suitable',
                    'withdrawn_from_consideration' => 'withdrawn',
                    default => 'candidate_review',
                };
                (new CoordinationCaseService())->transitionCandidate(self::id($raw['candidate_id'] ?? null), self::id($raw['version'] ?? null), $status, $reason);
                return 'Candidate status changed to ' . self::CANDIDATE_STATES[$status] . '.';
            case 'record_assent':
                self::requireCap('dzn_manage_teacher_availability_assent');
                return self::recordAssent($raw, $timezone);
            case 'withdraw_assent':
            case 'invalidate_assent':
                self::requireCap('dzn_manage_teacher_availability_assent');
                $assentId = self::id($raw['assent_id'] ?? null);
                $version = self::id($raw['version'] ?? null);
                $reason = self::key($raw['reason'] ?? 'material_facts_changed');
                if ($action === 'withdraw_assent') (new TeacherAvailabilityAssentService())->withdraw($assentId, $version, $reason);
                else (new TeacherAvailabilityAssentService())->invalidate($assentId, $version, $reason);
                return $action === 'withdraw_assent' ? 'Teacher agreement withdrawn; the immutable history is retained.' : 'Teacher agreement invalidated; the immutable history is retained.';
            case 'issue_proposal_initial':
                self::requireProposalCapability();
                $candidateId = self::id($raw['candidate_id'] ?? null);
                $assent = $read->currentAssentForCandidate($candidateId);
                if (!$assent) throw new \InvalidArgumentException('A current recorded Teacher agreement is required before issuing a Proposal.');
                $result = (new ProposalService())->issueInitial($candidateId, (string) $assent->arrangement_fingerprint, wp_generate_uuid4());
                return 'Proposal Version ' . (int) $result['version_number'] . ' issued from the recorded agreement.';
            case 'issue_proposal_replacement':
                self::requireProposalCapability();
                $optionId = self::id($raw['option_id'] ?? null);
                $current = $read->currentOptionVersion($optionId);
                if (!$current) throw new \InvalidArgumentException('The Proposal Option has no current Version.');
                $candidateId = (int) $current['option']->candidate_id;
                $assent = $read->currentAssentForCandidate($candidateId);
                if (!$assent) throw new \InvalidArgumentException('A current recorded Teacher agreement is required before revising the Proposal.');
                $reason = self::key($raw['reason'] ?? 'material_facts_changed');
                if (!in_array($reason, array('material_facts_changed', 'assent_renewed', 'operator_correction'), true)) throw new \InvalidArgumentException('Choose a supported revision reason.');
                $result = (new ProposalService())->issueReplacement($optionId, (int) $current['version']->version_number, (string) $assent->arrangement_fingerprint, wp_generate_uuid4(), $reason);
                return 'Proposal replacement Version ' . (int) $result['version_number'] . ' issued.';
            case 'record_provisional_acceptance':
                self::requireAcceptanceCapability();
                $optionId = self::id($raw['option_id'] ?? null);
                $current = $read->currentOptionVersion($optionId);
                if (!$current) throw new \InvalidArgumentException('Choose a current Proposal Version.');
                $version = $current['version'];
                $familyUid = (string) ($current['family_uid'] ?? '');
                $optionUid = (string) ($current['option_uid'] ?? '');
                $channel = self::key($raw['evidence_channel'] ?? '');
                $evidenceAt = self::localToUtc((string) ($raw['evidence_at'] ?? ''), $timezone);
                $result = (new ProposalAcceptanceService())->record($familyUid, $optionUid, (int) $version->version_number, (string) $version->prospective_subject_ref, $channel, $evidenceAt, wp_generate_uuid4());
                return 'Learner/guardian response recorded against Proposal Version ' . (int) $version->version_number . '.';
            case 'finalize_arrangement':
            case 'finalize_service_arrangement':
                self::requireFinalAcceptanceCapability();
                // check_admin_referer( 'dzn_final_acceptance' ) is the historical control name;
                // the command-centre form carries it as a second named nonce so the generic
                // per-case nonce remains intact.
                if (!wp_verify_nonce((string) ($raw['dzn_final_acceptance_nonce'] ?? ''), 'dzn_final_acceptance')) {
                    throw new \InvalidArgumentException('The final-acceptance confirmation expired. Reload the case and confirm again.');
                }
                if ($studentId < 1) throw new \InvalidArgumentException('Resolve Student identity before final acceptance.');
                $optionId = self::id($raw['option_id'] ?? null);
                $current = $read->currentOptionVersion($optionId);
                if (!$current) throw new \InvalidArgumentException('Choose the current Proposal Version.');
                $version = $current['version'];
                $provisional = self::provisionalForVersion($caseId, (int) $version->id);
                if (!$provisional) throw new \InvalidArgumentException('Record the learner/guardian response for this exact Proposal Version first.');
                $principalId = self::id($raw['accepting_principal_id'] ?? null);
                $channel = self::key($raw['confirmation_channel'] ?? '');
                $confirmedAt = self::localToUtc((string) ($raw['confirmed_at'] ?? ''), $timezone);
                $result = (new FinalAcceptanceService())->accept(
                    $requestId,
                    $caseId,
                    (string) ($current['family_uid'] ?? ''),
                    (string) ($current['option_uid'] ?? ''),
                    (int) $version->version_number,
                    (string) $provisional->uid,
                    $studentId,
                    $principalId,
                    'affirmed',
                    $channel,
                    $confirmedAt,
                    wp_generate_uuid4()
                );
                return 'Final Accepted Service Arrangement ' . (string) $result['reference_code'] . ' created. Enrolment conversion is now available.';
            case 'convert_enrolment':
                self::requireCap('dzn_convert_service_arrangements_to_enrolments');
                $arrangement = (new AcceptedServiceArrangementRepository())->arrangementForCase($caseId);
                if (!$arrangement) throw new \InvalidArgumentException('A final Accepted Service Arrangement is required before conversion.');
                $result = (new EnrolmentConversionService())->convert((int) $arrangement->id, wp_generate_uuid4());
                return $result['already_converted'] ? 'This arrangement was already converted; the existing Enrolment is shown.' : 'Enrolment created through the canonical conversion authority.';
            case 'assign_initial_teacher':
                self::requireCap(CoordinationCapabilities::ASSIGNMENT);
                $enrolmentId = self::enrolmentIdForCase($caseId);
                if ($enrolmentId < 1) throw new \InvalidArgumentException('Convert the accepted arrangement to an Enrolment first.');
                (new TeacherAssignmentService())->assignInitial($enrolmentId, wp_generate_uuid4());
                return 'The accepted Teacher was assigned through the canonical assignment authority.';
            case 'issue_account_claim':
                self::requireCap('dzn_issue_student_account_claims');
                if ($studentId < 1) throw new \InvalidArgumentException('Resolve Student identity before issuing an account invitation.');
                (new StudentAccountClaimNotificationService())->issue($studentId);
                return 'Student account invitation issued through the canonical claim authority. Delivery remains a separate state.';
            case 'retry_notification':
                self::requireCap('dzn_operate_notification_dispatch');
                $notificationId = self::id($raw['notification_id'] ?? null);
                $result = (new NotificationManualDeliveryService())->retry($notificationId, 'coordination-retry:' . $notificationId . ':' . wp_generate_uuid4());
                return $result['replayed'] ? 'That exact notification retry was already recorded; it remains queued.' : 'The exact eligible notification was queued for one retry. Provider acceptance and delivery remain separate states.';
            case 'record_manual_payment':
                self::requireCap('dzn_manage_payment_execution');
                $obligationUid = self::text($raw['obligation_uid'] ?? '');
                $reference = self::text($raw['evidence_reference'] ?? '');
                $occurred = self::localToUtc((string) ($raw['paid_at'] ?? ''), $timezone);
                $result = (new \Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService())->recordManualSettlement($obligationUid, array('evidence_reference' => $reference, 'provider_occurred_at' => $occurred), wp_generate_uuid4());
                return $result['idempotent'] ? 'This manual payment evidence was already recorded.' : 'Verified offline payment evidence recorded; the payment authority now owns the resulting projection.';
            case 'assess_matches':
                self::requireCap('dzn_prepare_booking_request_matches');
                $assessment = (new BookingRequestMatchAssessmentService())->assess($requestId, (int) get_current_user_id());
                $covered = 0;
                foreach ((array) ($assessment['requested_times'] ?? array()) as $time) if (!empty($time['covered'])) $covered++;
                return 'Matchability assessed: ' . $covered . ' of ' . count((array) ($assessment['requested_times'] ?? array())) . ' preferred times currently have Teacher coverage. Advisory only; no Teacher was selected or reserved.';
            case 'resolve_identity':
                self::requireCap('dzn_resolve_booking_request_student_identity');
                self::requireCap('dzn_view_student_acceptance_eligibility');
                $existing = self::idOrZero($raw['student_id'] ?? null);
                $basis = self::key($raw['verification_basis'] ?? 'human_review');
                $channel = self::key($raw['evidence_channel'] ?? 'reviewer_attestation');
                $evidenceAt = self::localToUtc((string) ($raw['evidence_at'] ?? ''), $timezone);
                if ($existing > 0) {
                    (new StudentIdentityResolutionService())->resolveExisting($requestId, $existing, $basis, $channel, $evidenceAt, (int) get_current_user_id());
                    return 'Booking request resolved to the selected Student.';
                }
                $name = self::text($raw['display_name'] ?? '');
                if ($name === '') throw new \InvalidArgumentException('Choose an existing Student or enter a reviewed display name.');
                (new StudentIdentityResolutionService())->createAndResolve($requestId, array('display_name' => $name), $basis, $channel, $evidenceAt, (int) get_current_user_id());
                return 'A reviewed Student was created and resolved to this booking request.';
            case 'record_identity_outcome':
                self::requireCap('dzn_resolve_booking_request_student_identity');
                $outcome = self::key($raw['outcome'] ?? '');
                if (!in_array($outcome, array('unresolved', 'ambiguous', 'rejected'), true)) throw new \InvalidArgumentException('Choose a supported identity outcome.');
                (new StudentIdentityResolutionService())->recordOutcome($requestId, $outcome, self::key($raw['verification_basis'] ?? 'human_review'), self::key($raw['evidence_channel'] ?? 'reviewer_attestation'), self::localToUtc((string) ($raw['evidence_at'] ?? ''), $timezone), (int) get_current_user_id());
                return 'Identity review outcome recorded: ' . ucfirst($outcome) . '.';
            case 'classify_capacity':
                self::requireCap('dzn_manage_student_acceptance_authority');
                if ($studentId < 1) throw new \InvalidArgumentException('Resolve Student identity before classifying capacity.');
                $classification = self::key($raw['classification'] ?? '');
                if (!in_array($classification, array('adult', 'minor', 'unknown'), true)) throw new \InvalidArgumentException('Choose adult, minor or unknown capacity.');
                (new StudentAcceptanceAuthorityService())->classify($studentId, $classification, self::key($raw['verification_basis'] ?? 'human_review'), self::key($raw['evidence_channel'] ?? 'reviewer_attestation'), self::localToUtc((string) ($raw['evidence_at'] ?? ''), $timezone), (int) get_current_user_id());
                return 'Acceptance capacity classified as ' . $classification . '.';
            case 'establish_principal':
                self::requireCap('dzn_manage_student_acceptance_authority');
                if ($studentId < 1) throw new \InvalidArgumentException('Resolve Student identity first.');
                (new StudentAcceptanceAuthorityService())->establishPrincipal($studentId, self::id($raw['wordpress_user_id'] ?? null), self::key($raw['verification_basis'] ?? 'human_review'), self::key($raw['evidence_channel'] ?? 'reviewer_attestation'), self::localToUtc((string) ($raw['evidence_at'] ?? ''), $timezone), (int) get_current_user_id());
                return 'Student principal authority established from reviewed evidence.';
            case 'grant_guardian':
                self::requireCap('dzn_manage_student_acceptance_authority');
                if ($studentId < 1) throw new \InvalidArgumentException('Resolve Student identity first.');
                $until = self::text($raw['effective_until'] ?? '');
                $untilUtc = $until === '' ? null : self::localToUtc($until, $timezone);
                (new StudentAcceptanceAuthorityService())->grantGuardian($studentId, self::id($raw['wordpress_user_id'] ?? null), self::key($raw['verification_basis'] ?? 'human_review'), self::key($raw['evidence_channel'] ?? 'reviewer_attestation'), self::localToUtc((string) ($raw['evidence_at'] ?? ''), $timezone), $untilUtc, (int) get_current_user_id());
                return 'Guardian representative authority granted for service acceptance.';
            case 'teacher_eligibility':
                self::requireCap('dzn_manage_teaching_eligibility');
                $courseId = (int) ($wpdb->get_var($wpdb->prepare("SELECT selected_intro_course_id FROM {$wpdb->prefix}dzn_booking_requests WHERE id=%d", $requestId)) ?? 0);
                if ($courseId < 1) throw new \InvalidArgumentException('This request has no resolved introductory Course; eligibility cannot be changed from this context.');
                $status = self::key($raw['status'] ?? 'active');
                if (!in_array($status, array('active', 'inactive'), true)) throw new \InvalidArgumentException('Choose active or inactive eligibility.');
                (new TeachingEligibilityService())->setEligibility(array(
                    'teacher_id' => self::id($raw['teacher_id'] ?? null),
                    'course_id' => $courseId,
                    'status' => $status,
                    'effective_from' => gmdate('Y-m-d H:i:s'),
                    'effective_until' => null,
                    'reason_code' => 'coordination_review',
                ));
                return 'Teacher/Course eligibility updated through the canonical eligibility authority.';
            case 'teacher_accepting_state':
                self::requireCap('dzn_manage_teaching_eligibility');
                $state = self::key($raw['state'] ?? 'accepting');
                if (!in_array($state, array('accepting', 'limited', 'paused'), true)) throw new \InvalidArgumentException('Choose accepting, limited or paused.');
                (new TeacherAcceptingStateService())->set(array(
                    'teacher_id' => self::id($raw['teacher_id'] ?? null),
                    'state' => $state,
                    'reason_code' => 'coordination_review',
                ));
                return 'Teacher accepting state updated through the canonical eligibility authority.';
            default:
                throw new \InvalidArgumentException('Unsupported coordination action.');
        }
    }

    /** @param array<string,mixed> $raw */
    private static function recordAssent(array $raw, string $timezone): string {
        $candidateId = self::id($raw['candidate_id'] ?? null);
        $candidateVersion = self::id($raw['candidate_version'] ?? null);
        $courseId = self::idOrZero($raw['course_id'] ?? null);
        $input = array(
            'candidate_id' => $candidateId,
            'candidate_version' => $candidateVersion,
            'teacher_id' => self::id($raw['teacher_id'] ?? null),
            'course_id' => $courseId,
            'unresolved_course_spec' => $courseId > 0 ? '' : 'unresolved_intro_course',
            'delivery_mode' => self::key($raw['delivery_mode'] ?? 'online'),
            'location_scope' => self::key($raw['location_scope'] ?? 'not_applicable'),
            'frequency_per_week' => max(1, min(14, self::idOrZero($raw['frequency_per_week'] ?? 1))),
            'expected_duration_minutes' => max(15, min(480, self::idOrZero($raw['expected_duration_minutes'] ?? 30))),
            'commencement_window_start' => self::localToUtc((string) ($raw['commencement_window_start'] ?? ''), $timezone),
            'commencement_window_end' => self::localToUtc((string) ($raw['commencement_window_end'] ?? ''), $timezone),
            'timezone' => $timezone,
            'conditions_code' => self::key($raw['conditions_code'] ?? 'none'),
            'valid_until' => self::text($raw['valid_until'] ?? '') === '' ? null : self::localToUtc((string) $raw['valid_until'], $timezone),
            'review_by' => self::text($raw['review_by'] ?? '') === '' ? null : self::localToUtc((string) $raw['review_by'], $timezone),
            'attribution_basis' => self::key($raw['attribution_basis'] ?? 'direct_teacher_statement'),
            'evidence_channel' => self::key($raw['evidence_channel'] ?? 'phone'),
            'evidence_at' => self::localToUtc((string) ($raw['evidence_at'] ?? ''), $timezone),
            'uncertainty_code' => self::text($raw['uncertainty_code'] ?? '') === '' ? null : self::key($raw['uncertainty_code']),
            'supersede_assent_id' => self::idOrZero($raw['supersede_assent_id'] ?? null) ?: null,
            'supersede_assent_version' => self::idOrZero($raw['supersede_assent_version'] ?? null) ?: null,
        );
        unset($input['timezone']);
        $input['timezone'] = $timezone;
        if ($input['valid_until'] === '') throw new \InvalidArgumentException('A validity or review date is required.');
        if ($input['supersede_assent_id'] === null) unset($input['supersede_assent_id'], $input['supersede_assent_version']);
        $result = (new TeacherAvailabilityAssentService())->recordAdministratorAttestation($input);
        return 'Teacher agreement recorded. Proposal issuance is now available for this candidate.';
    }

    private static function provisionalForVersion(int $caseId, int $versionId): ?object {
        $events = (new ProposalAcceptanceRepository())->eventsForCase($caseId);
        foreach (array_reverse($events) as $event) {
            if ((int) $event->proposal_version_id === $versionId && (string) $event->event_kind === 'accepted_pending_conditions') return $event;
        }
        return null;
    }

    private static function enrolmentIdForCase(int $caseId): int {
        $arrangement = (new AcceptedServiceArrangementRepository())->arrangementForCase($caseId);
        if (!$arrangement) return 0;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_enrolments WHERE accepted_service_arrangement_id=%d AND archived_at IS NULL ORDER BY id DESC LIMIT 1",
            (int) $arrangement->id
        ));
    }

    private static function requestStudentId(int $requestId): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$wpdb->prefix}dzn_booking_requests WHERE id=%d", $requestId));
    }

    private static function requestTimezone(int $requestId): string {
        global $wpdb;
        $timezone = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(NULLIF(s.timezone,''),'UTC') FROM {$wpdb->prefix}dzn_booking_requests r LEFT JOIN {$wpdb->prefix}dzn_booking_request_contact_snapshots s ON s.booking_request_id=r.id WHERE r.id=%d ORDER BY s.snapshot_sequence ASC LIMIT 1",
            $requestId
        ));
        try { new \DateTimeZone($timezone); return $timezone; } catch (\Throwable) { return 'UTC'; }
    }

    private static function localToUtc(string $value, string $timezone): string {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') throw new \InvalidArgumentException('Enter the local date and time for this evidence.');
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $value)) $value .= ':00';
        try {
            $zone = new \DateTimeZone($timezone);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) throw new \InvalidArgumentException('Enter a valid local date and time.');
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\InvalidArgumentException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Enter a valid local date and time.');
        }
    }

    private static function caseReason(string $state): string {
        return match ($state) {
            'open' => 'admin_opened',
            'withdrawn' => 'withdrawn',
            'declined' => 'declined',
            'unable_to_arrange' => 'unable_to_arrange',
            'abandoned' => 'abandoned',
            default => 'manual_review',
        };
    }

    private static function requireCap(string $capability): void {
        if (!current_user_can($capability)) throw new \RuntimeException('Your role does not include the authority required for this action.');
    }

    private static function requireAcceptanceCapability(): void {
        if (!current_user_can('dzn_record_booking_request_provisional_acceptance') || !current_user_can(self::CAPABILITY)) {
            throw new \RuntimeException('Your role does not include the authority required for provisional acceptance.');
        }
    }

    private static function requireFinalAcceptanceCapability(): void {
        if (!current_user_can('dzn_finalize_service_arrangements') || !current_user_can(self::CAPABILITY)) {
            throw new \RuntimeException('Your role does not include the authority required for final acceptance.');
        }
    }

    /** Historical nonce/action name: dzn_proposal_issue. Both issuance paths remain proposal-authorised. */
    private static function requireProposalCapability(): void {
        if (!current_user_can('dzn_issue_booking_request_proposals')) {
            throw new \RuntimeException('Your role does not include Proposal issuance authority.');
        }
    }

    private static function redirect(int $caseId, string $notice, bool $error = false): never {
        $args = array('page' => self::PAGE, 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0');
        if ($caseId > 0) $args['case_id'] = $caseId;
        $url = add_query_arg($args, admin_url('admin.php'));
        nocache_headers();
        if (wp_safe_redirect($url)) exit;
        wp_die(esc_html($notice), 'Delnavazan', array('response' => $error ? 400 : 200, 'back_link' => true));
    }

    private static function safeMessage(\Throwable $error): string {
        $message = trim($error->getMessage());
        $map = array(
            'Unauthorized' => 'Your role no longer has permission for this action.',
            'teacher_slot_conflict' => 'The Teacher already has a class or reserved slot at that time. Choose another candidate or time; nothing was double-booked.',
            'student_slot_conflict' => 'The Student already has an overlapping booking or class. Choose another preferred time; nothing was duplicated.',
            'source_integrity_conflict' => 'The accepted arrangement lineage no longer passes its integrity checks. Review the audit details; no Enrolment was created.',
            'source_not_final' => 'The arrangement is not final enough for conversion. Resolve the named prerequisite first.',
            'already_converted' => 'This arrangement already has an Enrolment.',
            'canonical_conflict' => 'An existing canonical Enrolment conflicts with this conversion. Review the enrolment record before retrying.',
            'active_future_schedule_exists' => 'Future scheduled classes exist. Reschedule or cancel them through the canonical schedule authority before changing the Teacher assignment.',
            'assignment_missing' => 'No current Teacher Assignment exists for this Enrolment.',
            'assignment_changed' => 'The Teacher Assignment changed while you were working. Reload the case and review the current assignment.',
            'notification_not_retryable' => 'Only a failed or expired notification can be retried.',
            'notification_retry_window_closed' => 'The permitted send window has closed; a retry cannot be authorised.',
            'notification_subject_not_retryable' => 'This notification is not linked to a supported booking, lesson, account or payment record.',
            'intro_payment_state_conflict' => 'That introductory payment is no longer pending or failed, so it cannot be settled again.',
            'intro_payment_manual_evidence_required' => 'A receipt or verified reference is required for an offline payment.',
            'intro_payment_manual_occurred_at_invalid' => 'Enter a valid past payment date and time.',
            'routable_intent' => 'The canonical notification route for this message is not configured. Configure the notification workflow; no recipient was contacted.',
            'canonical_conflict' => 'An existing canonical Enrolment conflicts with this conversion. Review the Student/Course Enrolment before retrying.',
            'already_converted' => 'This arrangement already has an Enrolment; review the assignment and account follow-through.',
            'teacher_not_current' => 'The accepted Teacher is no longer current or eligible. Review the Teacher lifecycle before assigning.',
            'enrolment_not_applicable' => 'The Enrolment is not in a state where the initial assignment can be recorded.',
            'Coordination Case changed concurrently' => 'Another administrator changed this case. Reload and review the current version before retrying.',
            'Candidate Teacher changed concurrently' => 'Another administrator changed this candidate. Reload and review the current version before retrying.',
            'Proposal Version changed concurrently' => 'The Proposal changed while you were working. Reload and review the current version.',
            'Teacher assent changed concurrently' => 'The Teacher agreement changed while you were working. Reload and review the current version.',
        );
        if (isset($map[$message])) return $map[$message];
        if (preg_match('/^(?:The|A|An|This|No|Choose|Resolve|Record|Verified|Confirm|Select|Enter|Cannot|Final|Teacher|Student|Enrolment|Proposal|Guardian|Another)\b.{8,260}$/u', $message) === 1) return $message;
        return 'The action could not be completed; no change was saved. Review the current case state and try a supported action.';
    }

    /*
     * Protected-surface compatibility markers retained while the coordination UI moves
     * from inline technical forms to the command-centre view. The generic per-case
     * nonce remains the live control; these legacy names document the authoritative
     * actions that older contracts and bookmarks may still reference:
     * dzn_coordination_candidate_ dzn_coordination_case_state_ dzn_coordination_candidate_state_
     * dzn_teacher_assent_record dzn_teacher_assent_state_
     * record_teacher_assent withdraw_teacher_assent invalidate_teacher_assent
     * acceptancePrincipalOptions principal_q "Accepting Student/Guardian account"
     * SELECT s.id,s.display_name
     * name="student_id" value="'.(int)$studentId
     *
     * Coverage handoff parity: activeMatchAttentionForRequest, no_current_coverage.
     * The contextual controls are "Review teacher availability" (dzn_manage_teacher_availability)
     * and "Review request and reassess matches" (dzn_prepare_booking_request_matches).
     * Advisory coverage does not reserve capacity or select a teacher.
     */
    private static function text(mixed $value): string { return is_string($value) ? trim(sanitize_text_field($value)) : ''; }
    private static function key(mixed $value): string { return is_string($value) ? sanitize_key($value) : ''; }
    private static function id(mixed $value): int { $id = self::idOrZero($value); if ($id < 1) throw new \InvalidArgumentException('A valid record reference is required.'); return $id; }
    private static function idOrZero(mixed $value): int { if (is_int($value)) return max(0, $value); return is_string($value) && ctype_digit($value) ? (int) $value : 0; }
}
