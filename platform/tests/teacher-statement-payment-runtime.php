<?php
/**
 * Disposable runtime proof for a real completed-period statement and its settlement.
 *
 * Run only inside the offline WordPress/MariaDB acceptance harness:
 *   DZN_TEACHER_STATEMENT_RUNTIME=authority wp eval-file .../teacher-statement-payment-runtime.php --user=1
 *
 * It activates one Phase-J source, records a Teacher rate and the statement-timezone policy,
 * issues and confirms one one-minute Lesson, drafts and issues the statement for a completed
 * period, records the settlement, and proves the Teacher Portal read reports paid/up-to-date.
 */
if (getenv('DZN_TEACHER_STATEMENT_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local','development'), true)) {
    fwrite(STDERR, "Teacher statement runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService};
use Delnavazan\Platform\Core\Application\Finance\LessonFinanceOrchestrationService;
use Delnavazan\Platform\Core\Application\Finance\{FinancePolicyService,TeacherRateService};
use Delnavazan\Platform\Core\Application\Finance\Read\{TeacherFinanceSummaryReadService,TeacherStatementReadService};
use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementPaymentService;
use Delnavazan\Platform\Core\Application\Finance\TeacherStatementService;

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_ts_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_ts_key(string $label): string { return 'dzn-teacher-statement-' . $label . '-' . wp_generate_uuid4(); }
function dzn_ts_evidence(string $reference): array { return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s')); }

$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_ts_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 1, 'Phase-J production fixture required');
$source = $fixture['sources'][0];
$enrolmentId = (int) $source['enrolment_id'];
$teacherId = (int) $source['teacher_id'];
$teacherUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1", $teacherId));
dzn_ts_assert($teacherUser > 0, 'An active Teacher principal link is required');

wp_set_current_user(1);
$lifecycle = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId));
if ($lifecycle !== 'current') {
    (new CanonicalEnrolmentLifecycleService())->activate($enrolmentId, $lifecycle, dzn_ts_evidence('activate'), dzn_ts_key('activate'));
}
$terms = new CanonicalTermAuthorityService();
$term = $terms->create($enrolmentId, null, null, dzn_ts_evidence('term'), dzn_ts_key('term'));
$termId = (int) $term['term_id'];
$terms->activate($termId, 'authorised', dzn_ts_evidence('term-active'), dzn_ts_key('term-active'));

$assignmentId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1 AND state='assigned' LIMIT 1", $enrolmentId));
if ($assignmentId < 1) {
    $assignment = (new TeacherAssignmentService())->assignInitial($enrolmentId, dzn_ts_key('assignment'));
    $assignmentId = (int) $assignment['assignment_id'];
}
dzn_ts_assert($assignmentId > 0, 'Canonical Teacher assignment required');
$assignedTeacherId = (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE id=%d", $assignmentId));
$wpdb->update($p.'teachers', array('timezone'=>'UTC'), array('id'=>$assignedTeacherId));
(new TeacherAcceptingStateService())->set(array('teacher_id'=>$assignedTeacherId,'state'=>'accepting','reason_code'=>'synthetic_fixture'));
(new TeacherAvailabilityService())->setProfile(array('teacher_id'=>$assignedTeacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_fixture'));
for ($weekday=1; $weekday<=7; $weekday++) {
    (new TeacherAvailabilityService())->setRecurringRule(array('teacher_id'=>$assignedTeacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_fixture'));
}

$effective = gmdate('Y-m-d H:i:s', strtotime('-2 days'));
(new FinancePolicyService())->record('FINANCE_STATEMENT_TIMEZONE', array(
    'policy_value'=>'UTC','value_type'=>'timezone','effective_from'=>$effective,
    'evidence_channel'=>'staff_record','evidence_reference'=>'synthetic-statement-timezone','evidence_at'=>gmdate('Y-m-d H:i:s'),
), dzn_ts_key('timezone-policy'));
(new TeacherRateService())->record($assignedTeacherId, array(
    'scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>5000,'currency'=>'AUD',
    'effective_from'=>$effective,'compensation_basis'=>'per_session','rate_origin'=>'explicit',
    'evidence_channel'=>'staff_record','evidence_reference'=>'synthetic-teacher-rate','evidence_at'=>gmdate('Y-m-d H:i:s'),
), dzn_ts_key('rate'));

$lesson = (new CanonicalLessonAuthorityService())->createStandard($termId, $assignmentId, dzn_ts_evidence('lesson'), dzn_ts_key('lesson'));
$lessonId = (int) $lesson['lesson_id'];
$start = gmdate('Y-m-d H:i:s', strtotime('+3 seconds'));
$schedule = (new CanonicalLessonScheduleService())->schedule($lessonId, $assignmentId, array(
    'schedule_timezone'=>'UTC','local_wall_date'=>substr($start,0,10),'local_wall_time'=>substr($start,11),
    'duration_minutes'=>1,'reason_code'=>'synthetic_statement',
) + dzn_ts_evidence('schedule'), dzn_ts_key('schedule'));
$versionUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $schedule['schedule_version_id']));
$end = strtotime($start.' UTC') + 60;
while (time() <= $end) { sleep(5); }

wp_set_current_user(1);
(new CanonicalLessonDeliveryService())->record($lessonId, 'authorised', array(
    'outcome_code'=>'delivered','reason_code'=>'synthetic_delivered',
    'evidence_channel'=>'staff_record','evidence_reference'=>'synthetic-delivered-'.$lessonId,
    'evidence_at'=>gmdate('Y-m-d H:i:s'),
), dzn_ts_key('delivered'));
(new CanonicalLessonAuthorityService())->complete($lessonId, 'authorised', array(
    'evidence_channel'=>'staff_record','evidence_reference'=>'synthetic-completed-'.$lessonId,
    'evidence_at'=>gmdate('Y-m-d H:i:s'),
), dzn_ts_key('complete'));
(new LessonFinanceOrchestrationService())->captureAfterCompletion($lessonId, 'synthetic_statement');

$periodStart = gmdate('Y-m-d H:i:s', strtotime($start.' UTC') - 3600);
$periodEnd = gmdate('Y-m-d H:i:s', $end + 1);
$statement = (new TeacherStatementService())->draft($assignedTeacherId, $periodStart, $periodEnd, dzn_ts_key('draft'));
$statementId = (int) $statement['statement_id'];
dzn_ts_assert($statementId > 0, 'Statement draft required');
$issued = (new TeacherStatementService())->issue($statementId, dzn_ts_key('issue'));
dzn_ts_assert((string) $issued['state'] === 'issued', 'Statement must issue');

$adminStatements = (new TeacherStatementReadService())->statements($assignedTeacherId);
$issuedRow = null;
foreach ($adminStatements as $row) if ((int) $row['statement_id'] === $statementId) { $issuedRow = $row; break; }
dzn_ts_assert($issuedRow !== null && (string) $issuedRow['state'] === 'issued', 'Issued completed-period statement must be readable');
dzn_ts_assert((int) $issuedRow['payable_amount_minor'] > 0, 'The statement must contain a payable Lesson amount');

$paymentKey = dzn_ts_key('payment');
$paid = (new TeacherStatementPaymentService())->record($statementId, array(
    'paid_at'=>gmdate('Y-m-d H:i:s'),
    'method_code'=>'manual_bank_transfer',
    'evidence_channel'=>'authenticated_platform',
    'evidence_reference'=>'synthetic-settlement-'.$statementId,
    'reason'=>'operator_decision',
), $paymentKey);
dzn_ts_assert((string) $paid['state'] === 'paid', 'Settlement must record paid state');
$paidAgain = (new TeacherStatementPaymentService())->record($statementId, array(
    'paid_at'=>(string) $paid['paid_at'],
    'method_code'=>'manual_bank_transfer',
    'evidence_channel'=>'authenticated_platform',
    'evidence_reference'=>'synthetic-settlement-'.$statementId,
    'reason'=>'operator_decision',
), $paymentKey);
dzn_ts_assert(!empty($paidAgain['idempotent']), 'A repeated settlement must converge');
dzn_ts_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_statement_events WHERE statement_id=%d AND event_type='payment_recorded'", $statementId)) === 1, 'A repeated settlement must not duplicate its event');

wp_set_current_user($teacherUser);
$own = (new TeacherStatementReadService())->ownStatements();
$ownRow = null;
foreach ($own as $row) if ((int) $row['statement_id'] === $statementId) { $ownRow = $row; break; }
dzn_ts_assert($ownRow !== null && (string) $ownRow['state'] === 'paid' && !empty($ownRow['paid_at']), 'The Teacher must see the paid statement and its settlement date');
$summary = (new TeacherFinanceSummaryReadService())->own('UTC');
dzn_ts_assert((string) ($summary['remuneration']['payout']['state'] ?? '') === 'available', 'The Teacher summary must expose settlement status');
dzn_ts_assert((int) ($summary['remuneration']['payout']['paid_minor'] ?? 0) > 0, 'The Teacher summary must expose actual paid amount');
dzn_ts_assert((int) ($summary['remuneration']['payout']['unpaid_minor'] ?? 0) === 0, 'A settled statement must leave no unpaid balance');
dzn_ts_assert((string) ($summary['remuneration']['payout']['status'] ?? '') === 'up_to_date', 'A settled statement must report up-to-date');

echo "Teacher statement and settlement runtime passed\n";
