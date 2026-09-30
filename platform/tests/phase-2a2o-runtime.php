<?php
/** Disposable production-path Phase-O delivery/attendance authority proof; synthetic local data only. */
if(getenv('DZN_PHASE_2A2O_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-O runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalAcademyObligationService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryReadService,CanonicalLessonDeliveryService,CanonicalLessonScheduleReadService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,IdempotencyConflictException,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_o_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_o_key(string $label): string { return 'dzn-2a2o-' . $label . '-' . wp_generate_uuid4(); }
function dzn_o_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_o_rejected(callable $call, string $expected, string $message): void {
    $caught = null;
    try { $call(); } catch (Throwable $exception) { $caught = $exception; }
    dzn_o_assert($caught !== null, $message . ' was accepted');
    dzn_o_assert($caught->getMessage() === $expected, $message . ' rejected with an unexpected error: ' . $caught->getMessage());
}
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_o_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 8, 'Phase-J production fixture required');
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();
$scheduleService = new CanonicalLessonScheduleService(); $scheduleRead = new CanonicalLessonScheduleReadService();
$delivery = new CanonicalLessonDeliveryService(); $deliveryRead = new CanonicalLessonDeliveryReadService();
$obligations = new CanonicalAcademyObligationService();
$availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$allocate = static function () use ($fixture, $wpdb, $p): int {
    foreach ($fixture['sources'] as $source) {
        $enrolmentId = (int) $source['enrolment_id'];
        $state = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId));
        $terms = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d AND record_model='canonical_enrolment_term_v1'", $enrolmentId));
        if ($state === 'authorised' && $terms === 0) return $enrolmentId;
    }
    throw new RuntimeException('no_available_source');
};
/** Each chain receives a dedicated provisioned Teacher: capacity can never contend across chains. */
$chain = function () use ($allocate, $enrolmentService, $termService, $assignmentService, $acceptingService, $availabilityService, $wpdb, $p): array {
    $id = $allocate();
    $enrolmentService->activate($id, 'authorised', dzn_o_evidence('activate'), dzn_o_key('activate'));
    $term = $termService->create($id, null, null, dzn_o_evidence('term'), dzn_o_key('term'));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_o_evidence('term-active'), dzn_o_key('term-active'));
    $assignment = $assignmentService->assignInitial($id, dzn_o_key('assignment'));
    $courseId = (int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d", $id));
    $teacherId = (int) (new TeacherService())->create(array('display_name' => 'Synthetic O Chain Teacher', 'email' => 'o-chain-' . wp_generate_uuid4() . '@phase-2a2o.invalid'));
    $acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_provision'));
    $availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_provision'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_provision'));
    }
    (new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'reason_code' => 'synthetic_provision'));
    $moved = $assignmentService->replace($id, $teacherId, array('expected_assignment_id' => (int) $assignment['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'isolated-chain-' . $teacherId, 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_o_key('isolate'));
    return array('enrolment_id' => $id, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $moved['assignment_id']);
};
/**
 * Issue and schedule a synthetic occurrence. A one-minute duration keeps the owner matrix tractable;
 * the schedule is released immediately so a chain's dedicated Teacher is never occupied twice.
 *
 * The lead is always chosen so the whole synthetic interval stays inside one UTC day: the test
 * provisioner's full-day availability rules meet at midnight, where a genuine one-second coverage
 * gap exists. This keeps the suite independent of the wall-clock time it is run at.
 */
$fitLead = static function (int $durationMinutes, int $minLeadSeconds = 2): string {
    $midnight = strtotime('tomorrow UTC');
    $start = time() + $minLeadSeconds;
    if ($start + $durationMinutes * 60 + 5 >= $midnight) $start = $midnight + 30;
    return '@' . $start;
};
$occurred = function (array $chain, string $label, int $durationMinutes = 1, string $lead = '+2 seconds', bool $waitForEnd = true) use ($lessonService, $scheduleService, $wpdb, $p): array {
    $lessonId = (int) $lessonService->createStandard((int) $chain['term_id'], (int) $chain['assignment_id'], dzn_o_evidence($label), dzn_o_key($label))['lesson_id'];
    $wall = gmdate('Y-m-d H:i:s', strtotime($lead));
    $scheduled = $scheduleService->schedule($lessonId, (int) $chain['assignment_id'], array('schedule_timezone' => 'UTC', 'local_wall_date' => substr($wall, 0, 10), 'local_wall_time' => substr($wall, 11), 'duration_minutes' => $durationMinutes, 'reason_code' => 'synthetic_schedule') + dzn_o_evidence('schedule-' . $label), dzn_o_key('schedule-' . $label));
    $version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $scheduled['schedule_version_id']));
    $scheduleService->release($lessonId, array('expected_schedule_version_id' => (int) $version->id, 'reason_code' => 'synthetic_schedule_release') + dzn_o_evidence('release-' . $label), dzn_o_key('release-' . $label));
    if ($lead === '+2 seconds') { /* settled by the shared settle() call */ }
    elseif ($waitForEnd) { while (time() < strtotime((string) $version->ends_at_utc . ' UTC') + 2) sleep(1); }
    return array('lesson_id' => $lessonId, 'schedule_version_id' => (int) $version->id, 'starts_at_utc' => (string) $version->starts_at_utc, 'ends_at_utc' => (string) $version->ends_at_utc);
};
/** Wait once for every synthetic occurrence to end; the capacity buffer is deliberately ignored. */
$settle = static function (array $occurrences): void {
    $latest = 0;
    foreach ($occurrences as $occurrence) $latest = max($latest, strtotime((string) $occurrence['ends_at_utc'] . ' UTC'));
    while (time() < $latest + 2) sleep(1);
};
$deliveryInput = static function (string $code, string $label, array $extra = array()): array {
    return array('outcome_code' => $code, 'reason_code' => 'synthetic_outcome') + $extra + dzn_o_evidence('outcome-' . $label);
};
$rows = static function (int $lessonId) use ($wpdb, $p): array {
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d ORDER BY outcome_sequence", $lessonId)) ?: array();
};
$lessonState = static function (int $lessonId) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", $lessonId));
};
$obligationRows = static function (int $lessonId) use ($wpdb, $p): array {
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d", $lessonId)) ?: array();
};
$termState = static function (int $termId) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}terms WHERE id=%d", $termId));
};

// ---------------------------------------------------------------------------
// Fixture: three chains, five genuinely ended occurrences, one single settle wait.
// ---------------------------------------------------------------------------
$one = $chain(); $academyChain = $chain(); $reviewChain = $chain(); $termChain = $chain();
// These six are settled by the single shared settle() wait below, never inside the helper.
$occurrences = array(
    'plain' => $occurred($one, 'plain-delivery', 1, $fitLead(1, 5), false),
    'no_show' => $occurred($one, 'student-no-show', 1, $fitLead(1, 5), false),
    'reconcile' => $occurred($one, 'reconcile-target', 1, $fitLead(1, 5), false),
    'owed' => $occurred($academyChain, 'teacher-non-delivery', 1, $fitLead(1, 5), false),
    'late' => $occurred($academyChain, 'late-cancel', 1, $fitLead(1, 5), false),
    'ambiguous' => $occurred($reviewChain, 'ambiguous', 1, $fitLead(1, 5), false),
);
$settle($occurrences);

// ---------------------------------------------------------------------------
// 1. O-D2: ordinary delivery needs no manual record; completion alone is sufficient.
// ---------------------------------------------------------------------------
$plain = $occurrences['plain'];
dzn_o_assert($deliveryRead->forLesson((int) $plain['lesson_id'])['state'] === 'none', 'ordinary delivery must start with no exceptional outcome');
dzn_o_assert($deliveryRead->forLesson((int) $plain['lesson_id'])['delivery_truth'] === 'none', 'ordinary delivery truth must be implicit');
$lessonService->complete((int) $plain['lesson_id'], 'authorised', dzn_o_evidence('plain-complete'), dzn_o_key('plain-complete'));
dzn_o_assert($lessonState((int) $plain['lesson_id']) === 'completed' && $rows((int) $plain['lesson_id']) === array(), 'ordinary completion must succeed without a delivery outcome row');

// ---------------------------------------------------------------------------
// 2. O-D3: student no-show is separate from Teacher delivery, consumes the Lesson, owes nothing.
// ---------------------------------------------------------------------------
$noShow = $occurrences['no_show'];
$noShowInput = $deliveryInput('student_no_show', 'student-no-show');
$keyNoShow = dzn_o_key('student-no-show');
$recorded = $delivery->record((int) $noShow['lesson_id'], 'authorised', $noShowInput, $keyNoShow);
$effective = $deliveryRead->forLesson((int) $noShow['lesson_id'])['effective'];
dzn_o_assert((string) $effective->outcome_code === 'student_no_show' && (string) $effective->delivery_state === 'delivered', 'student no-show must stay a delivered occurrence');
dzn_o_assert((string) $effective->attendance_state === 'student_absent' && (string) $effective->remedy_class === 'none', 'student no-show must stay absent and remedy-free');
dzn_o_assert((int) $effective->schedule_version_id === (int) $noShow['schedule_version_id'], 'outcome must bind the exact canonical schedule version');
dzn_o_assert((string) $effective->occurrence_ends_at_utc === (string) $noShow['ends_at_utc'], 'outcome must bind the governing occurrence end');
$lessonService->complete((int) $noShow['lesson_id'], 'authorised', dzn_o_evidence('no-show-complete'), dzn_o_key('no-show-complete'));
dzn_o_assert($lessonState((int) $noShow['lesson_id']) === 'completed', 'a student no-show must not prevent completion');
dzn_o_assert($obligationRows((int) $noShow['lesson_id']) === array(), 'a student no-show must create no academy obligation');
dzn_o_rejected(fn() => $lessonService->createReplacement($one['term_id'], $one['assignment_id'], (int) $noShow['lesson_id'], dzn_o_evidence('no-show-replacement'), dzn_o_key('no-show-replacement')), 'replacement_origin_not_eligible', 'student no-show replacement entitlement');

// ---------------------------------------------------------------------------
// 3. Idempotency, replay and conflicting assertions.
// ---------------------------------------------------------------------------
$replay = $delivery->record((int) $noShow['lesson_id'], 'authorised', $noShowInput, $keyNoShow);
dzn_o_assert(!empty($replay['idempotent']) && (int) $replay['outcome_id'] === (int) $recorded['outcome_id'], 'exact delivery replay failed');
dzn_o_assert(count($rows((int) $noShow['lesson_id'])) === 1, 'delivery replay created a second outcome');
$conflict = false;
try { $delivery->record((int) $noShow['lesson_id'], 'authorised', $deliveryInput('interruption', 'student-no-show'), $keyNoShow); }
catch (IdempotencyConflictException $exception) { $conflict = true; }
dzn_o_assert($conflict, 'conflicting delivery payload was accepted under the same key');
dzn_o_rejected(fn() => $delivery->record((int) $noShow['lesson_id'], 'completed', $deliveryInput('interruption', 'second-assertion'), dzn_o_key('second-assertion')), 'delivery_outcome_exists', 'conflicting second assertion');

// ---------------------------------------------------------------------------
// 4. O-D6: correction supersedes history, never mutates it, never reopens the Lesson.
// ---------------------------------------------------------------------------
$beforeCorrection = $rows((int) $noShow['lesson_id'])[0];
$corrected = $delivery->correct((int) $noShow['lesson_id'], (int) $beforeCorrection->id, $deliveryInput('interruption', 'correction'), dzn_o_key('correction'));
$history = $rows((int) $noShow['lesson_id']);
dzn_o_assert(count($history) === 2, 'correction must append exactly one superseding outcome');
dzn_o_assert((string) $history[0]->outcome_code === 'student_no_show' && (string) $history[0]->reason_code === (string) $beforeCorrection->reason_code, 'correction mutated the historical outcome');
dzn_o_assert($history[0]->applicable_slot === null && $history[0]->superseded_at !== null && (int) $history[0]->superseded_by_outcome_id === (int) $corrected['outcome_id'], 'correction supersession lineage failed');
dzn_o_assert($lessonState((int) $noShow['lesson_id']) === 'completed', 'correction reopened the Lesson lifecycle');
dzn_o_rejected(fn() => $delivery->correct((int) $noShow['lesson_id'], (int) $history[1]->id, $deliveryInput('teacher_non_delivery', 'contradictory'), dzn_o_key('contradictory')), 'lesson_completed_reconciliation_required', 'contradictory correction of a completed Lesson outside O-D8');
dzn_o_rejected(fn() => $delivery->correct((int) $noShow['lesson_id'], 999999, $deliveryInput('interruption', 'stale'), dzn_o_key('stale')), 'stale_delivery_outcome', 'correction with a stale expected outcome');

// ---------------------------------------------------------------------------
// 5. O-D4: Teacher non-delivery blocks completion and owes a DISTINCT academy occurrence.
// ---------------------------------------------------------------------------
$owed = $occurrences['owed'];
$nonDelivery = $delivery->record((int) $owed['lesson_id'], 'authorised', $deliveryInput('teacher_non_delivery', 'teacher-non-delivery'), dzn_o_key('teacher-non-delivery'));
dzn_o_assert((string) $deliveryRead->forLesson((int) $owed['lesson_id'])['effective']->remedy_class === 'academy_obligation', 'Teacher non-delivery must record an academy obligation fact');
dzn_o_rejected(fn() => $lessonService->complete((int) $owed['lesson_id'], 'authorised', dzn_o_evidence('owed-complete'), dzn_o_key('owed-complete')), 'lesson_not_delivered', 'completion of a Lesson known not to have been delivered');
dzn_o_rejected(fn() => $lessonService->cancel((int) $owed['lesson_id'], 'authorised', dzn_o_evidence('owed-attested') + array('reason_code' => 'attested_non_delivery'), dzn_o_key('owed-attested')), 'delivery_outcome_exists', 'cancellation that would duplicate an existing non-delivery fact');
dzn_o_rejected(fn() => $delivery->record((int) $owed['lesson_id'], 'authorised', $deliveryInput('delivered', 'owed-second'), dzn_o_key('owed-second')), 'delivery_outcome_exists', 'second effective outcome for one occurrence');
$lessonService->cancel((int) $owed['lesson_id'], 'authorised', dzn_o_evidence('owed-cancel'), dzn_o_key('owed-cancel'));
dzn_o_assert($lessonState((int) $owed['lesson_id']) === 'cancelled', 'a non-delivered occurrence must still be terminalisable');
$owedObligations = $obligations->forLesson((int) $owed['lesson_id']);
dzn_o_assert(count($owedObligations) === 1 && (string) $owedObligations[0]->source_kind === 'teacher_non_delivery' && (string) $owedObligations[0]->state === 'owed', 'academy obligation was not established for the non-delivered occurrence');
dzn_o_assert((int) $owedObligations[0]->source_outcome_id === (int) $nonDelivery['outcome_id'], 'academy obligation must name the exact source outcome');
dzn_o_rejected(fn() => $lessonService->createReplacement($academyChain['term_id'], $academyChain['assignment_id'], (int) $owed['lesson_id'], dzn_o_evidence('owed-replacement'), dzn_o_key('owed-replacement')), 'replacement_origin_not_eligible', 'academy obligation must never become a Phase-M replacement Lesson');

// ---------------------------------------------------------------------------
// 6. O-D5/O-D9: advance cancellation responsibility.
// ---------------------------------------------------------------------------
$academyCancel = $occurred($academyChain, 'academy-advance-cancel', 1, $fitLead(1, 600), false);
$studentCancel = $occurred($academyChain, 'student-advance-cancel', 1, $fitLead(1, 600), false);
$lessonService->cancel((int) $academyCancel['lesson_id'], 'authorised', dzn_o_evidence('academy-cancel') + array('reason_code' => 'academy_unavailable'), dzn_o_key('academy-cancel'));
$academyObligation = $obligationRows((int) $academyCancel['lesson_id']);
dzn_o_assert(count($academyObligation) === 1 && (string) $academyObligation[0]->source_kind === 'academy_cancellation', 'Teacher/academy advance cancellation must establish an academy obligation');
dzn_o_assert((string) $wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence DESC LIMIT 1", (int) $academyCancel['lesson_id'])) === 'canonical_lesson_cancelled_academy_unavailable', 'academy cancellation reason was not recorded');
dzn_o_rejected(fn() => $lessonService->createReplacement($academyChain['term_id'], $academyChain['assignment_id'], (int) $academyCancel['lesson_id'], dzn_o_evidence('academy-replacement'), dzn_o_key('academy-replacement')), 'replacement_origin_not_eligible', 'academy obligation consumed the Phase-M replacement mechanism');
$lessonService->cancel((int) $studentCancel['lesson_id'], 'authorised', dzn_o_evidence('student-cancel'), dzn_o_key('student-cancel'));
dzn_o_assert($obligationRows((int) $studentCancel['lesson_id']) === array(), 'a Student-requested cancellation must never create an academy obligation');
$advance = $occurred($academyChain, 'advance-non-delivery', 1, $fitLead(1, 600), false);
$lessonService->cancel((int) $advance['lesson_id'], 'authorised', dzn_o_evidence('advance') + array('reason_code' => 'attested_non_delivery'), dzn_o_key('advance'));
dzn_o_assert($obligationRows((int) $advance['lesson_id']) === array(), 'historical Phase-M non-delivery attestation must not be reinterpreted as a Phase-O academy obligation');
$late = $occurrences['late'];
dzn_o_rejected(fn() => $lessonService->cancel((int) $late['lesson_id'], 'authorised', dzn_o_evidence('late') + array('reason_code' => 'attested_non_delivery'), dzn_o_key('late')), 'occurrence_already_started_use_delivery_outcome', 'post-occurrence cancellation reusing the advance non-delivery meaning');
dzn_o_rejected(fn() => $lessonService->cancel((int) $late['lesson_id'], 'authorised', dzn_o_evidence('late-academy') + array('reason_code' => 'academy_unavailable'), dzn_o_key('late-academy')), 'occurrence_already_started_use_delivery_outcome', 'post-occurrence cancellation reusing the academy advance meaning');

// ---------------------------------------------------------------------------
// 7. Temporal honesty: the occurrence must have ended, and the capacity buffer is not the wait.
// ---------------------------------------------------------------------------
$future = $occurred($academyChain, 'future-lesson', 1, $fitLead(45, 600), false);
dzn_o_rejected(fn() => $delivery->record((int) $future['lesson_id'], 'authorised', $deliveryInput('student_no_show', 'future'), dzn_o_key('future-outcome')), 'occurrence_not_started', 'outcome recorded before the occurrence began');
$minutesLeftToday = (int) floor((strtotime('tomorrow UTC') - time()) / 60);
$unendedDuration = (int) max(5, min(600, $minutesLeftToday - 6));
$unended = $occurred($academyChain, 'unended-lesson', $unendedDuration, $fitLead($unendedDuration), false);
while (time() < strtotime((string) $unended['starts_at_utc'] . ' UTC') + 1) sleep(1);
sleep(2);
dzn_o_rejected(fn() => $delivery->record((int) $unended['lesson_id'], 'authorised', $deliveryInput('student_no_show', 'unended'), dzn_o_key('unended-outcome')), 'occurrence_not_ended', 'final no-show recorded before the governing occurrence ended');
$unscheduled = (int) $lessonService->createStandard((int) $academyChain['term_id'], (int) $academyChain['assignment_id'], dzn_o_evidence('unscheduled'), dzn_o_key('unscheduled'))['lesson_id'];
dzn_o_rejected(fn() => $delivery->record($unscheduled, 'authorised', $deliveryInput('student_no_show', 'unscheduled'), dzn_o_key('unscheduled-outcome')), 'schedule_required_for_delivery_outcome', 'outcome recorded for an occurrence that was never scheduled');
dzn_o_rejected(fn() => $scheduleService->schedule((int) $owed['lesson_id'], $academyChain['assignment_id'], array('schedule_timezone' => 'UTC', 'local_wall_date' => gmdate('Y-m-d', strtotime('+9 days')), 'local_wall_time' => '10:00:00', 'reason_code' => 'synthetic_schedule') + dzn_o_evidence('reschedule-recorded'), dzn_o_key('reschedule-recorded')), 'lesson_not_schedulable', 'rescheduling a terminal Lesson');

// ---------------------------------------------------------------------------
// 8. O-D8: explicit append-only reconciliation of a historical completion.
// ---------------------------------------------------------------------------
$reconcile = $occurrences['reconcile'];
$lessonService->complete((int) $reconcile['lesson_id'], 'authorised', dzn_o_evidence('reconcile-complete'), dzn_o_key('reconcile-complete'));
$completionEvent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='completed' ORDER BY event_sequence DESC LIMIT 1", (int) $reconcile['lesson_id']));
dzn_o_assert($completionEvent !== null, 'completion lifecycle evidence missing before reconciliation');
$reconcileEvidence = dzn_o_evidence('reconcile-non-delivery') + array('reason_code' => 'reconciliation_confirmed_non_delivery');
$keyReconcile = dzn_o_key('reconcile-non-delivery');
$reconciled = $delivery->reconcile((int) $reconcile['lesson_id'], $reconcileEvidence, $keyReconcile);
dzn_o_assert(!empty($reconciled['reconciled']), 'explicit reconciliation did not report its reconciled state');
$readBack = $deliveryRead->forLesson((int) $reconcile['lesson_id']);
dzn_o_assert((string) $readBack['delivery_truth'] === 'not_delivered' && $readBack['completion_reconciled'] === true, 'protected read did not return the reconciled effective truth');
dzn_o_assert((int) $readBack['effective']->reconciles_completion_event_id === (int) $completionEvent->id, 'reconciliation lineage does not name the historical completion event');
$historical = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_lifecycle_events WHERE id=%d", (int) $completionEvent->id));
dzn_o_assert($historical !== null && (string) $historical->to_state === 'completed' && (string) $historical->reason_code === (string) $completionEvent->reason_code, 'reconciliation mutated the immutable historical completion event');
dzn_o_assert($lessonState((int) $reconcile['lesson_id']) === 'completed', 'reconciliation reopened the Lesson lifecycle');
dzn_o_assert((string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d", (int) $reconcile['lesson_id'])) === '1', 'reconciliation created schedule authority');
dzn_o_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE canonical_replacement_origin_lesson_id=%d", (int) $reconcile['lesson_id'])) === 0, 'reconciliation automatically created a remedial Lesson');
$reconcileObligation = $obligationRows((int) $reconcile['lesson_id']);
dzn_o_assert(count($reconcileObligation) === 1 && (string) $reconcileObligation[0]->source_kind === 'teacher_non_delivery', 'reconciliation did not establish the academy obligation');
dzn_o_assert((int) $reconcileObligation[0]->source_event_id === (int) $completionEvent->id, 'reconciliation obligation must name the historical completion event');
$reconcileReplay = $delivery->reconcile((int) $reconcile['lesson_id'], $reconcileEvidence, $keyReconcile);
dzn_o_assert(!empty($reconcileReplay['idempotent']), 'exact reconciliation replay failed');
dzn_o_rejected(fn() => $delivery->reconcile((int) $reconcile['lesson_id'], dzn_o_evidence('reconcile-again') + array('reason_code' => 'reconciliation_confirmed_non_delivery'), dzn_o_key('reconcile-again')), 'already_reconciled_non_delivery', 'duplicate reconciliation of the same completion');
dzn_o_rejected(fn() => $delivery->correct((int) $reconcile['lesson_id'], (int) $readBack['effective']->id, $deliveryInput('delivered', 'undo-reconciliation'), dzn_o_key('undo-reconciliation')), 'completion_reconciliation_cannot_be_undone', 'un-reconciling a reconciled completion');

// ---------------------------------------------------------------------------
// 9. Ambiguity blocks completion until an authorised correction resolves it.
// ---------------------------------------------------------------------------
$ambiguous = $occurrences['ambiguous'];
$delivery->record((int) $ambiguous['lesson_id'], 'authorised', $deliveryInput('review_required', 'ambiguous'), dzn_o_key('ambiguous'));
dzn_o_rejected(fn() => $lessonService->complete((int) $ambiguous['lesson_id'], 'authorised', dzn_o_evidence('ambiguous-complete'), dzn_o_key('ambiguous-complete')), 'lesson_delivery_review_required', 'completion while the occurrence outcome is unresolved');
$delivery->correct((int) $ambiguous['lesson_id'], (int) $rows((int) $ambiguous['lesson_id'])[0]->id, $deliveryInput('delivered', 'resolved'), dzn_o_key('resolved'));
$lessonService->complete((int) $ambiguous['lesson_id'], 'authorised', dzn_o_evidence('resolved-complete'), dzn_o_key('resolved-complete'));
dzn_o_assert($lessonState((int) $ambiguous['lesson_id']) === 'completed', 'resolved occurrence could not complete');

// ---------------------------------------------------------------------------
// 10. Academy obligation is independent of Term lifecycle and never silently disappears.
// ---------------------------------------------------------------------------
// The advance cancellation must genuinely precede its occurrence.
$terminal = $occurred($termChain, 'term-close-obligation', 1, $fitLead(1, 600), false);
$lessonService->cancel((int) $terminal['lesson_id'], 'authorised', dzn_o_evidence('term-close-cancel') + array('reason_code' => 'academy_unavailable'), dzn_o_key('term-close-cancel'));
$termService->close((int) $termChain['term_id'], 'current', dzn_o_evidence('term-close'), dzn_o_key('term-close'));
dzn_o_assert($termState((int) $termChain['term_id']) === 'closed', 'Term close did not complete for a terminal Lesson');
$surviving = $obligations->outstandingForTerm((int) $termChain['term_id']);
dzn_o_assert(count($surviving) === 1 && (string) $surviving[0]->state === 'owed', 'Term closure silently removed or deactivated the academy obligation');
dzn_o_assert($obligations->outstandingCountForTerm((int) $termChain['term_id']) === 1, 'academy obligation count was not preserved across Term closure');

// ---------------------------------------------------------------------------
// 11. Capability boundary and provider-neutral evidence.
// ---------------------------------------------------------------------------
$subscriber = wp_insert_user(array('user_login' => 'dzn-2a2o-sub-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'sub-' . wp_generate_uuid4() . '@phase-2a2o.invalid', 'role' => 'subscriber'));
dzn_o_assert(!is_wp_error($subscriber), 'Synthetic subscriber creation failed');
$previousUser = get_current_user_id();
// Capture the authorized baseline BEFORE switching principal, so the denied write can be compared.
$owedBefore = $obligationRows((int) $owed['lesson_id']);
$lessonBefore = $lessonState((int) $owed['lesson_id']);
$obligationsBefore = count($obligations->outstandingForTerm((int) $academyChain['term_id']));
wp_set_current_user((int) $subscriber);
try {
    dzn_o_rejected(fn() => $delivery->record((int) $plain['lesson_id'], 'authorised', $deliveryInput('student_no_show', 'subscriber'), dzn_o_key('subscriber')), 'Unauthorized', 'non-administrator delivery recording');
    $denied = false;
    try { $deliveryRead->forLesson((int) $plain['lesson_id']); } catch (RuntimeException $exception) { $denied = $exception->getMessage() === 'Unauthorized'; }
    dzn_o_assert($denied, 'non-administrator protected delivery read');
    $obligationDenied = false;
    try { $obligations->forLesson((int) $owed['lesson_id']); } catch (RuntimeException $exception) { $obligationDenied = $exception->getMessage() === 'Unauthorized'; }
    dzn_o_assert($obligationDenied, 'non-administrator academy obligation read');
    // O-1: the public obligation WRITE seam must fail closed for a principal without the Phase-O
    // capability, with no obligation row and no partial authority persisted.
    $writeDenied = false;
    try { $obligations->owe((int) $owed['lesson_id'], 'teacher_non_delivery', (int) $nonDelivery['outcome_id'], null, array('reason_code' => 'unauthorized_attempt', 'evidence_channel' => 'staff_record', 'evidence_reference_digest' => str_repeat('a', 64), 'evidence_at' => gmdate('Y-m-d H:i:s'))); }
    catch (RuntimeException $exception) { $writeDenied = $exception->getMessage() === 'Unauthorized'; }
    dzn_o_assert($writeDenied, 'non-administrator academy obligation write seam was not capability-gated');
} finally { wp_set_current_user($previousUser); }
dzn_o_assert(count($obligationRows((int) $owed['lesson_id'])) === count($owedBefore), 'denied obligation write created an obligation row');
dzn_o_assert($lessonState((int) $owed['lesson_id']) === $lessonBefore, 'denied obligation write changed canonical Lesson state');
dzn_o_assert(count($obligations->outstandingForTerm((int) $academyChain['term_id'])) === $obligationsBefore, 'denied obligation write changed the canonical obligation aggregate');
// Exact same-kind replay of the real source evidence stays idempotent.
$exactReplayEvidence = array('reason_code' => (string) $owedObligations[0]->reason_code, 'evidence_channel' => (string) $owedObligations[0]->evidence_channel, 'evidence_reference_digest' => (string) $owedObligations[0]->evidence_reference_digest, 'evidence_at' => (string) $owedObligations[0]->evidence_at);
$duplicateOwe = $obligations->owe((int) $owed['lesson_id'], 'teacher_non_delivery', (int) $nonDelivery['outcome_id'], null, $exactReplayEvidence);
dzn_o_assert((int) $duplicateOwe === (int) $owedObligations[0]->id && count($obligationRows((int) $owed['lesson_id'])) === 1, 'exact same-kind obligation replay was not idempotent');
$conflictingReplay = false;
try { $obligations->owe((int) $owed['lesson_id'], 'teacher_non_delivery', (int) $nonDelivery['outcome_id'], null, array('reason_code' => 'synthetic_outcome', 'evidence_channel' => 'staff_record', 'evidence_reference_digest' => str_repeat('c', 64), 'evidence_at' => gmdate('Y-m-d H:i:s'))); }
catch (\InvalidArgumentException $exception) { $conflictingReplay = $exception->getMessage() === 'obligation_replay_conflict'; }
dzn_o_assert($conflictingReplay && count($obligationRows((int) $owed['lesson_id'])) === 1, 'same-kind replay with conflicting evidence was accepted');
$conflictingSource = false;
try { $obligations->owe((int) $owed['lesson_id'], 'teacher_non_delivery', (int) $nonDelivery['outcome_id'] + 100000, null, $exactReplayEvidence); }
catch (\InvalidArgumentException $exception) { $conflictingSource = in_array($exception->getMessage(), array('obligation_replay_conflict', 'academy_obligation_source_missing'), true); }
dzn_o_assert($conflictingSource && count($obligationRows((int) $owed['lesson_id'])) === 1, 'same-kind replay with a conflicting source identifier was accepted');
$conflictOwe = false;
try { $obligations->owe((int) $owed['lesson_id'], 'academy_cancellation', null, null, array('reason_code' => 'canonical_lesson_cancelled_academy_unavailable', 'evidence_channel' => 'staff_record', 'evidence_reference_digest' => str_repeat('d', 64), 'evidence_at' => gmdate('Y-m-d H:i:s'))); }
catch (\InvalidArgumentException $exception) { $conflictOwe = $exception->getMessage() === 'obligation_source_conflict'; }
dzn_o_assert($conflictOwe && count($obligationRows((int) $owed['lesson_id'])) === 1, 'conflicting obligation source was accepted');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE id=%d", (int) $nonDelivery['outcome_id']));
dzn_o_assert(preg_match('/^[a-f0-9]{64}$/D', (string) $row->evidence_reference_digest) === 1, 'delivery evidence reference was not reduced to a keyed digest');
dzn_o_assert((string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_commands WHERE command_key_digest=%s", 'outcome-teacher-non-delivery')) === '0', 'raw command key persisted');

echo "ordinary_delivery=pass\nstudent_no_show=pass\nidempotency=pass\ncorrection_supersession=pass\nteacher_non_delivery_obligation=pass\nadvance_cancellation_responsibility=pass\ntemporal_end_rule=pass\ncompletion_reconciliation=pass\nreview_required=pass\nobligation_survives_term_closure=pass\ncapability_boundary=pass\nPhase 2A.2-O authority runtime passed\n";
