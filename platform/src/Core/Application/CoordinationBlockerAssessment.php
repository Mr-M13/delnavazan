<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure, permission-aware assessment of a coordination case.
 *
 * The read service supplies authoritative facts and the actor's narrow capabilities.
 * This class only explains blockers and orders valid next actions; it never writes,
 * invents eligibility, or turns a missing authority into a frontend-only workaround.
 */
final class CoordinationBlockerAssessment {
    private const SEVERITY_RANK = array('critical' => 0, 'action' => 1, 'warning' => 2, 'info' => 3);

    /**
     * @param array<string,mixed> $facts
     * @param array<string,bool> $permissions
     * @return array{blockers:array<int,array<string,mixed>>,recommended:array<string,mixed>|null,alternatives:array<int,array<string,mixed>>}
     */
    public function assess(array $facts, array $permissions): array {
        $blockers = array();
        $closed = (bool) ($facts['case_closed'] ?? false);
        $erased = (bool) ($facts['privacy_erased'] ?? false);
        $identity = (string) ($facts['identity'] ?? 'unresolved');
        $capacity = (string) ($facts['capacity'] ?? 'missing');
        $authority = (string) ($facts['authority'] ?? 'missing');
        $candidates = (int) ($facts['candidate_count'] ?? 0);
        $viable = (int) ($facts['viable_candidate_count'] ?? 0);
        $assent = (bool) ($facts['current_assent'] ?? false);
        $proposal = (bool) ($facts['current_proposal'] ?? false);
        $provisional = (bool) ($facts['provisional_current'] ?? false);
        $arrangement = (bool) ($facts['arrangement'] ?? false);
        $assignment = (bool) ($facts['assignment'] ?? false);
        $claim = (string) ($facts['claim'] ?? 'none');
        $payment = (string) ($facts['payment'] ?? 'none');
        $conversion = (string) ($facts['conversion_state'] ?? 'not_checked');
        $notifications = (int) ($facts['notification_failures'] ?? 0);
        $teacherConflicts = (int) ($facts['teacher_conflicts'] ?? 0);
        $studentConflicts = (int) ($facts['student_conflicts'] ?? 0);
        $calendar = (string) ($facts['calendar_projection'] ?? 'none');
        $exceptions = (int) ($facts['operational_exceptions'] ?? 0);
        $hasTimes = (bool) ($facts['has_requested_times'] ?? false);
        $timeExpired = (bool) ($facts['requested_time_expired'] ?? false);

        if ($erased) {
            $blockers[] = $this->blocker('privacy_erased', 'info', 'Case archived by privacy erasure',
                'Identifying request data has been erased and the coordination case is closed.',
                'No operational decision may be made from erased data.',
                'None. The immutable erasure evidence is the record.',
                'Start a new booking request if the learner contacts the academy again.', array());
            return $this->result($blockers, array('label' => 'No further coordination action', 'reason' => 'This case is archived.', 'available' => true, 'anchor' => '#activity'), array());
        }
        if ($closed) {
            $blockers[] = $this->blocker('case_closed', 'info', 'Case closed',
                'The coordination case is in a terminal state: ' . $this->label((string) ($facts['case_state'] ?? '')) . '.',
                'Closed cases stay readable for audit and recovery review.',
                'The recorded closure reason is the evidence.',
                'The current authority does not support reopening a coordination case.',
                'Open a new coordination case only if the booking request becomes actionable again through an authorised path.', array());
            $alternatives = $this->alternatives($permissions, array('close_case' => false, 'review_history' => true));
            return $this->result($blockers, array('label' => 'Review the closed case history', 'reason' => 'No mutation is supported after closure.', 'available' => true, 'anchor' => '#activity'), $alternatives);
        }

        if (!$hasTimes) {
            $blockers[] = $this->blocker('no_requested_times', 'critical', 'No requested time is recorded',
                'The booking request has no preferred scheduling option.',
                'Without a time there is no bookable interval, and the dashboard must not invent one.',
                'The learner must supply a preferred date, local time and timezone.', 'Use the canonical booking intake route only.', 'Teacher matching and scheduling become possible.', array());
        } elseif ($timeExpired) {
            $blockers[] = $this->blocker('requested_time_passed', 'warning', 'A preferred time has passed',
                'At least one preferred time is now in the past.',
                'Past times cannot be confirmed, and the current authority has no administrator edit command for request preferences.',
                'A fresh learner-confirmed preferred time is required.', 'No supported in-place edit exists. Do not rewrite the request.', 'Matching can use a remaining future preference; otherwise a corrected booking request is required.', array());
        }

        if ($identity === 'unresolved') {
            $blockers[] = $this->blocker('identity_unresolved', 'critical', 'Student identity is unresolved',
                'The booking request is not linked to a verified Student record.',
                'Final acceptance, capacity classification and account authority all depend on the canonical Student identity.',
                'Human-reviewed evidence that identifies the learner.', 'Resolve an existing Student or create a reviewed Student, or record a reviewed identity outcome.',
                'Capacity, guardian authority and final acceptance become available.', $this->action('Resolve identity', 'dzn_resolve_booking_request_student_identity', '#identity', $permissions, 'identity_resolve'));
        } elseif (in_array($identity, array('ambiguous', 'rejected'), true)) {
            $blockers[] = $this->blocker('identity_blocked', 'critical', 'Identity review has an unresolved outcome',
                'The latest identity review outcome is ' . $this->label($identity) . '.',
                'A blocked identity cannot support final acceptance or an enrolment.',
                'New reviewed evidence or a corrected matching Student record.', 'Record a new reviewed outcome only when evidence supports it.',
                'Coordination may continue for advisory matching, but acceptance remains blocked.', $this->action('Review identity evidence', 'dzn_resolve_booking_request_student_identity', '#identity', $permissions, 'identity_resolve'));
        }

        if ((string) ($facts['student_status'] ?? '') !== '' && (string) $facts['student_status'] !== 'active') {
            $blockers[] = $this->blocker('student_inactive', 'critical', 'Student record is not active',
                'The linked Student status is ' . $this->label((string) $facts['student_status']) . '.',
                'Inactive or archived Students cannot complete final acceptance.',
                'The canonical Student lifecycle must be restored by its own authority.', 'No coordination shortcut is permitted.',
                'Final acceptance becomes possible after the Student is active again.', array());
        }

        if ($identity === 'resolved' && in_array($capacity, array('missing', 'unknown'), true)) {
            $blockers[] = $this->blocker('capacity_unresolved', 'critical', 'Acceptance capacity is not classified',
                'The Student is not currently classified as an adult learner or a minor with guardian authority.',
                'Delnavazan must know whose confirmation is authoritative before it can create a final arrangement.',
                'Human-reviewed evidence of adult/minor capacity.', 'Classify adult, minor or keep unknown with attributable evidence.',
                'The correct acceptance route and account authority become available.', $this->action('Resolve acceptance authority', 'dzn_manage_student_acceptance_authority', '#acceptance-authority', $permissions, 'authority_manage'));
        } elseif ($identity === 'resolved' && $authority === 'missing') {
            $blockers[] = $this->blocker('authority_missing', 'critical', 'Acceptance authority is missing',
                'The Student capacity is ' . $this->label($capacity) . ', but no current ' . ($capacity === 'minor' ? 'guardian representative' : 'Student principal') . ' is recorded.',
                'Final acceptance must name the exact person who confirmed the arrangement.',
                $capacity === 'minor' ? 'Verified guardian identity and representative authority for service acceptance.' : 'A verified Student principal account linked to this Student.',
                'Establish the Student principal or grant guardian representative authority from reviewed evidence.',
                'Final acceptance and the accepting-account selector become available.', $this->action('Resolve acceptance authority', 'dzn_manage_student_acceptance_authority', '#acceptance-authority', $permissions, 'authority_manage'));
        }

        if ($candidates === 0) {
            $blockers[] = $this->blocker('no_candidates', 'action', 'No Teacher candidate has been considered',
                'The case has no advisory Teacher candidate.',
                'A Teacher agreement cannot be recorded without a candidate context.',
                'A suitable Teacher for the requested instrument/course.', 'Add one or more candidates and review eligibility, availability and conflicts.',
                'Agreement, Proposal and acceptance workflows become available.', $this->action('Add Teacher candidate', 'dzn_manage_booking_request_coordination', '#teacher-matching', $permissions, 'coordination'));
        } elseif ($viable === 0) {
            $blockers[] = $this->blocker('no_viable_candidates', 'action', 'No current candidate is viable',
                'Every advisory candidate is closed, unavailable, ineligible or has a confirmed scheduling collision.',
                'Treating an advisory candidate as an assignment would bypass eligibility and occupancy rules.',
                'A current, eligible and available Teacher, or a reviewed decision to close the case.',
                'Add another candidate, update eligibility/availability through its own authority, or record a reviewed closure.',
                'Teacher agreement becomes available for a viable candidate.', $this->action('Review Teacher candidates', 'dzn_manage_booking_request_coordination', '#teacher-matching', $permissions, 'coordination'));
        }

        if ($teacherConflicts > 0) {
            $blockers[] = $this->blocker('teacher_conflict', 'critical', 'A Teacher has a real scheduling collision',
                'One or more candidates already have a class or reserved capacity overlapping a requested time.',
                'Coordination must not double-book a Teacher.',
                'A different candidate or another requested time.', 'Choose a Teacher without the collision. The backend will re-check under locks.',
                'A safe booking and agreement become possible.', $this->action('Review teacher conflicts', 'dzn_manage_booking_request_coordination', '#scheduling', $permissions, 'coordination'));
        }
        if ($studentConflicts > 0) {
            $blockers[] = $this->blocker('student_conflict', 'critical', 'The Student already has an overlapping booking or class',
                'Canonical Student occupancy overlaps a preferred time.',
                'Duplicate same-student bookings and overlapping introductory/regular lessons are protected against.',
                'A non-overlapping learner time.', 'Choose another preferred time. The current authority has no in-place request preference editor.',
                'Scheduling becomes possible without a duplicate booking.', array());
        }

        if ($candidates > 0 && $viable > 0 && !$assent) {
            $blockers[] = $this->blocker('assent_missing', 'action', 'Teacher agreement is not recorded',
                'No current, valid Teacher Availability Assent exists for a viable candidate.',
                'A Proposal must freeze attributable Teacher willingness; it is not inferred from a candidate record.',
                'Teacher statement or staff-witnessed evidence with time-bounded facts.', 'Record agreement for a candidate, or replace a withdrawn/expired agreement.',
                'Proposal issuance becomes available.', $this->action('Record Teacher agreement', 'dzn_manage_teacher_availability_assent', '#agreements', $permissions, 'assent'));
        }
        if ($assent && !$proposal) {
            $blockers[] = $this->blocker('proposal_missing', 'action', 'No current Proposal has been issued',
                'The recorded agreement has not been converted into an immutable Proposal Version.',
                'The learner/guardian accepts a specific Teacher-specific offer version, not an advisory candidate.',
                'The current recorded agreement and its frozen arrangement facts.', 'Issue a Proposal from a current agreement.',
                'Provisional and final acceptance become available.', $this->action('Issue Proposal', 'dzn_issue_booking_request_proposals', '#proposals', $permissions, 'proposal'));
        }
        if ($proposal && !$provisional) {
            $blockers[] = $this->blocker('provisional_missing', 'action', 'Student or guardian response is not recorded',
                'No provisional acceptance evidence exists for the current Proposal Version.',
                'Final acceptance requires fresh, attributable confirmation of the exact offer.',
                'The channel and time of the learner/guardian response.', 'Record provisional acceptance evidence against the current version.',
                'Final acceptance becomes available after authority is resolved.', $this->action('Record response', 'dzn_record_booking_request_provisional_acceptance', '#acceptance', $permissions, 'provisional'));
        }
        if ($proposal && $provisional && !$arrangement && $identity === 'resolved' && in_array($capacity, array('adult', 'minor'), true) && $authority !== 'missing') {
            $blockers[] = $this->blocker('arrangement_missing', 'action', 'Final arrangement is not recorded',
                'The exact Proposal Version and provisional evidence are ready.',
                'Coordination is not complete until the final authority creates an immutable Accepted Service Arrangement.',
                'Fresh confirmation from the current accepting principal.', 'Create the final arrangement and re-confirm acceptance authority under locks.',
                'Enrolment conversion and Teacher assignment become available.', $this->action('Finalise arrangement', 'dzn_finalize_service_arrangements', '#acceptance', $permissions, 'final_acceptance'));
        }
        if ($arrangement && !in_array($conversion, array('converted', 'ready'), true)) {
            $blockers[] = $this->blocker('conversion_blocked', 'action', 'Enrolment conversion is not ready',
                'The Accepted Service Arrangement is ' . $this->label($conversion) . '.',
                'Enrolment creation has its own lineage, identity and course invariants.',
                'Resolve the named conversion blocker before retrying.', 'Review conversion readiness; convert only through the canonical service.',
                'An Enrolment and initial Teacher assignment become available.', $this->action('Review conversion', 'dzn_convert_service_arrangements_to_enrolments', '#enrolment', $permissions, 'conversion'));
        }
        if ($arrangement && $conversion === 'ready') {
            $blockers[] = $this->blocker('conversion_pending', 'action', 'Accepted arrangement is ready to convert',
                'No Enrolment exists for the accepted arrangement yet.',
                'The arrangement is evidence, not an Enrolment or a class.',
                'The same accepted arrangement and the current Student/Course identity.', 'Convert through the canonical Enrolment conversion authority.',
                'Initial Teacher assignment and class follow-through become available.', $this->action('Convert to Enrolment', 'dzn_convert_service_arrangements_to_enrolments', '#enrolment', $permissions, 'conversion'));
        }
        if ($arrangement && $conversion === 'converted' && !$assignment) {
            $blockers[] = $this->blocker('assignment_missing', 'action', 'Initial Teacher assignment is missing',
                'The Enrolment exists but the accepted Teacher has not been assigned.',
                'Class scheduling and delivery require a current Teacher Assignment.',
                'The retained final arrangement and a current Teacher record.', 'Assign the accepted Teacher through the canonical assignment authority.',
                'Term and class scheduling become available.', $this->action('Assign accepted Teacher', 'dzn_manage_teacher_assignments', '#enrolment', $permissions, 'assignment'));
        }
        if ($arrangement && $conversion === 'converted' && in_array($claim, array('none', 'revoked', 'expired'), true)) {
            $blockers[] = $this->blocker('account_claim_missing', 'action', 'Student account is not connected',
                'No active or claimed Student account claim is recorded.',
                'The learner cannot manage the class or receive portal communications until the canonical account path is used.',
                'A verified Student email/recipient and the canonical claim authority.', 'Issue a Student account claim invitation.',
                'Portal access and lesson communication become available.', $this->action('Issue account invitation', 'dzn_issue_student_account_claims', '#enrolment', $permissions, 'claim'));
        }
        if (in_array($payment, array('pending', 'failed'), true)) {
            $blockers[] = $this->blocker('payment_open', $payment === 'failed' ? 'critical' : 'warning', $payment === 'failed' ? 'Introductory payment failed' : 'Introductory payment is pending',
                'The introductory payment obligation is ' . $this->label($payment) . '.',
                'Booking projection and learner communication depend on verified payment authority.',
                'Verified provider evidence or attributable offline receipt evidence.', $payment === 'failed' ? 'Retry the existing payment workflow where eligible, or record verified offline settlement.' : 'Wait for provider evidence or record verified offline settlement.',
                'The booking can proceed with an authoritative payment state.', $this->action('Review payment', 'dzn_manage_payment_execution', '#finance', $permissions, 'payment'));
        }
        if ($notifications > 0) {
            $blockers[] = $this->blocker('notification_failed', 'warning', 'A notification needs attention',
                $notifications . ' failed or expired notification' . ($notifications === 1 ? '' : 's') . ' were found for this request.',
                'A queued message is not proof of delivery, and a retry must be bound to the exact original notification.',
                'The original recipient/template window and an eligible failed/expired notification.', 'Review the exact notification; retry only when the existing policy permits it.',
                'Delivery evidence can be separated from the failed attempt.', $this->action('Review notifications', 'dzn_operate_notification_dispatch', '#communications', $permissions, 'notification_retry'));
        }
        if (in_array($calendar, array('pending', 'failed', 'retryable'), true)) {
            $blockers[] = $this->blocker('calendar_pending', 'warning', 'Calendar or Meet projection is not confirmed',
                'The booking confirmation is in ' . $this->label($calendar) . ' state.',
                'A connected provider is not the same as a successfully projected event or meeting.',
                'Provider projection evidence from the existing integration worker.', 'No manual success may be invented. Let the projection worker retry or inspect integration diagnostics.',
                'Confirmed calendar and meeting evidence become visible.', $this->action('Inspect integration evidence', 'dzn_view_diagnostics', '#advanced', $permissions, 'diagnostics'));
        }
        if ($exceptions > 0) {
            $blockers[] = $this->blocker('operational_exception', 'warning', 'An operational exception is open',
                $exceptions . ' active exception' . ($exceptions === 1 ? '' : 's') . ' reference this booking request.',
                'Exceptions are authoritative failure facts and must not be silently cleared from the dashboard.',
                'The recorded exception detail and its owning authority evidence.', 'Review the exception in its existing authority before taking a consequential action.',
                'Coordination can continue on unaffected work.', $this->action('Review exception', 'dzn_manage_exceptions', '#advanced', $permissions, 'exceptions'));
        }

        usort($blockers, static fn(array $a, array $b): int => (self::SEVERITY_RANK[$a['severity']] ?? 9) <=> (self::SEVERITY_RANK[$b['severity']] ?? 9));
        $recommended = $this->recommend($facts, $permissions);
        return $this->result($blockers, $recommended, $this->alternatives($permissions, array(
            'add_candidate' => $candidates > 0,
            'review_identity' => $identity !== 'resolved',
            'record_assent' => $candidates > 0,
            'issue_proposal' => $assent,
            'record_response' => $proposal,
            'finalise' => $provisional && $identity === 'resolved' && in_array($capacity, array('adult', 'minor'), true) && $authority !== 'missing',
            'close_case' => true,
            'review_history' => true,
        )));
    }

    /** @param array<string,mixed> $facts @param array<string,bool> $permissions */
    private function recommend(array $facts, array $permissions): array {
        if ((string) ($facts['conversion_state'] ?? '') === 'converted' && (bool) ($facts['assignment'] ?? false)) {
            if (in_array((string) ($facts['claim'] ?? 'none'), array('none', 'revoked', 'expired'), true)) {
                return array('label' => 'Issue the Student account invitation', 'reason' => 'The Enrolment and Teacher assignment are recorded; the canonical account connection is the remaining follow-through.', 'available' => (bool) ($permissions['claim'] ?? false), 'anchor' => '#enrolment', 'capability' => 'dzn_issue_student_account_claims');
            }
            return array('label' => 'Coordination complete — continue in Enrolled classes', 'reason' => 'The arrangement, Enrolment, Teacher assignment and account connection are recorded.', 'available' => true, 'anchor' => '#enrolment', 'capability' => '');
        }
        if ((string) ($facts['conversion_state'] ?? '') === 'converted' && !(bool) ($facts['assignment'] ?? false)) {
            return array('label' => 'Assign the accepted Teacher', 'reason' => 'The Enrolment exists; the initial assignment is the next canonical step.', 'available' => (bool) ($permissions['assignment'] ?? false), 'anchor' => '#enrolment', 'capability' => CoordinationCapabilities::ASSIGNMENT);
        }
        if ((string) ($facts['identity'] ?? 'unresolved') !== 'resolved') {
            return array('label' => 'Resolve Student identity', 'reason' => 'Identity is the earliest authoritative blocker for capacity, authority and final acceptance.', 'available' => (bool) ($permissions['identity_resolve'] ?? false), 'anchor' => '#identity', 'capability' => 'dzn_resolve_booking_request_student_identity');
        }
        if (in_array((string) ($facts['capacity'] ?? ''), array('missing', 'unknown'), true) || (string) ($facts['authority'] ?? '') === 'missing') {
            return array('label' => 'Resolve acceptance authority', 'reason' => 'Final acceptance needs a classified capacity and the correct accepting principal.', 'available' => (bool) ($permissions['authority_manage'] ?? false), 'anchor' => '#acceptance-authority', 'capability' => 'dzn_manage_student_acceptance_authority');
        }
        if ((bool) ($facts['arrangement'] ?? false) && (string) ($facts['conversion_state'] ?? '') !== 'converted') {
            return array('label' => 'Convert the accepted arrangement', 'reason' => 'The final arrangement exists; the next authoritative step is Enrolment creation.', 'available' => (bool) ($permissions['conversion'] ?? false), 'anchor' => '#enrolment', 'capability' => 'dzn_convert_service_arrangements_to_enrolments');
        }
        if ((bool) ($facts['provisional_current'] ?? false) && !(bool) ($facts['arrangement'] ?? false)) {
            return array('label' => 'Finalise the arrangement', 'reason' => 'The exact Proposal Version and provisional response are recorded.', 'available' => (bool) ($permissions['final_acceptance'] ?? false), 'anchor' => '#acceptance', 'capability' => 'dzn_finalize_service_arrangements');
        }
        if ((bool) ($facts['current_proposal'] ?? false) && !(bool) ($facts['provisional_current'] ?? false)) {
            return array('label' => 'Record the student or guardian response', 'reason' => 'A current Proposal exists and needs attributable provisional evidence.', 'available' => (bool) ($permissions['provisional'] ?? false), 'anchor' => '#acceptance', 'capability' => 'dzn_record_booking_request_provisional_acceptance');
        }
        if ((bool) ($facts['current_assent'] ?? false) && !(bool) ($facts['current_proposal'] ?? false)) {
            return array('label' => 'Issue the Proposal', 'reason' => 'A current Teacher agreement is available for Proposal issuance.', 'available' => (bool) ($permissions['proposal'] ?? false), 'anchor' => '#proposals', 'capability' => 'dzn_issue_booking_request_proposals');
        }
        if ((int) ($facts['viable_candidate_count'] ?? 0) > 0) {
            return array('label' => 'Record Teacher agreement', 'reason' => 'A viable candidate needs attributable, time-bounded willingness before a Proposal.', 'available' => (bool) ($permissions['assent'] ?? false), 'anchor' => '#agreements', 'capability' => 'dzn_manage_teacher_availability_assent');
        }
        if ((int) ($facts['candidate_count'] ?? 0) === 0) {
            return array('label' => 'Add a Teacher candidate', 'reason' => 'No advisory candidate exists yet.', 'available' => (bool) ($permissions['coordination'] ?? false), 'anchor' => '#teacher-matching', 'capability' => 'dzn_manage_booking_request_coordination');
        }
        return array('label' => 'Review the case and choose a supported action', 'reason' => 'No single prerequisite dominates the current state.', 'available' => true, 'anchor' => '#workspace', 'capability' => '');
    }

    /** @param array<string,bool> $permissions @param array<string,bool> $availability */
    private function alternatives(array $permissions, array $availability): array {
        $actions = array(
            'add_candidate' => array('label' => 'Add or replace a Teacher candidate', 'anchor' => '#teacher-matching', 'capability' => 'dzn_manage_booking_request_coordination', 'permission' => 'coordination'),
            'review_identity' => array('label' => 'Review student identity', 'anchor' => '#identity', 'capability' => 'dzn_resolve_booking_request_student_identity', 'permission' => 'identity_resolve'),
            'record_assent' => array('label' => 'Record or replace Teacher agreement', 'anchor' => '#agreements', 'capability' => 'dzn_manage_teacher_availability_assent', 'permission' => 'assent'),
            'issue_proposal' => array('label' => 'Issue or revise a Proposal', 'anchor' => '#proposals', 'capability' => 'dzn_issue_booking_request_proposals', 'permission' => 'proposal'),
            'record_response' => array('label' => 'Record learner/guardian response', 'anchor' => '#acceptance', 'capability' => 'dzn_record_booking_request_provisional_acceptance', 'permission' => 'provisional'),
            'finalise' => array('label' => 'Finalise the arrangement', 'anchor' => '#acceptance', 'capability' => 'dzn_finalize_service_arrangements', 'permission' => 'final_acceptance'),
            'close_case' => array('label' => 'Close or retire the case', 'anchor' => '#case-state', 'capability' => 'dzn_manage_booking_request_coordination', 'permission' => 'coordination'),
            'review_history' => array('label' => 'Inspect activity and audit details', 'anchor' => '#activity', 'capability' => '', 'permission' => ''),
        );
        $out = array();
        foreach ($actions as $key => $action) {
            if (array_key_exists($key, $availability) && !$availability[$key]) continue;
            $permission = (string) $action['permission'];
            $out[] = array(
                'label' => $action['label'],
                'anchor' => $action['anchor'],
                'capability' => $action['capability'],
                'available' => $permission === '' || (bool) ($permissions[$permission] ?? false),
            );
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $blockers @param array<string,mixed>|null $recommended @param array<int,array<string,mixed>> $alternatives */
    private function result(array $blockers, ?array $recommended, array $alternatives): array {
        return array('blockers' => $blockers, 'recommended' => $recommended, 'alternatives' => $alternatives);
    }

    /** @param array<int,array<string,mixed>> $actions */
    private function blocker(string $code, string $severity, string $title, string $what, string $why, string $evidence, string $resolve, string $unlocks, array $actions): array {
        return array(
            'code' => $code,
            'severity' => $severity,
            'title' => $title,
            'what' => $what,
            'why' => $why,
            'evidence' => $evidence,
            'resolve' => $resolve,
            'unlocks' => $unlocks,
            'actions' => $actions,
        );
    }

    /** @param array<string,bool> $permissions */
    private function action(string $label, string $capability, string $anchor, array $permissions, string $permission): array {
        return array(
            'label' => $label,
            'anchor' => $anchor,
            'capability' => $capability,
            'available' => (bool) ($permissions[$permission] ?? false),
        );
    }

    private function label(string $value): string {
        return $value === '' ? 'not recorded' : ucfirst(str_replace('_', ' ', $value));
    }
}
