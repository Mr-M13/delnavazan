<?php
/**
 * Regression + contract coverage for the Teacher finance monthly reconciliation.
 *
 * The reported regression was "about 1.5 hours, 3 scheduled, 0 completed" even though the three
 * Lessons had been delivered. These assertions run without WordPress by exercising the read
 * service's pure reconciliation/remuneration projections and by checking the SQL contract that
 * feeds them.
 */
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherFinanceSummaryReadService;

$root = dirname(__DIR__);
$servicePath = $root . '/src/Core/Application/Finance/Read/TeacherFinanceSummaryReadService.php';
require_once $servicePath;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$delivered = static function (int $id, int $studentId, string $state = 'authorised'): array {
    return array(
        'lesson_id' => $id,
        'student_id' => $studentId,
        'lifecycle_state' => $state,
        'starts_at_utc' => '2026-10-10 10:00:00',
        'ends_at_utc' => '2026-10-10 10:30:00',
        'duration_minutes' => 30,
        'outcome_id' => 900 + $id,
        'delivery_state' => 'delivered',
        'attendance_state' => 'attended',
        'outcome_code' => 'delivered',
        'outcome_starts_at_utc' => '2026-10-10 10:00:00',
        'outcome_ends_at_utc' => '2026-10-10 10:30:00',
    );
};

// The exact reported regression: three scheduled occurrences of 30 minutes, each with an effective
// delivered outcome but a lifecycle row that has not yet moved to `completed`.
$regression = TeacherFinanceSummaryReadService::reconcileMonthRows(array(
    $delivered(1, 41),
    $delivered(2, 42),
    $delivered(3, 43),
));
$assert($regression['scheduled_lessons'] === 3, 'Three canonical occurrences must be scheduled.');
$assert($regression['completed_lessons'] === 3, 'Three effective delivered outcomes must count as completed teaching, not zero.');
$assert($regression['completed_minutes'] === 90, 'The regression month must report 90 taught minutes (1.5 hours), not scheduled time.');
$assert($regression['scheduled_minutes'] === 90, 'Scheduled minutes must remain separately identifiable.');
$assert($regression['attended_lessons'] === 3 && $regression['students'] === 3, 'Attendance and student counts must reconcile with the delivered occurrences.');

// Future authorised occurrences are scheduled, never taught.
$future = $delivered(4, 44);
$future = array_merge($future, array(
    'lifecycle_state' => 'authorised',
    'starts_at_utc' => '2026-10-20 10:00:00',
    'ends_at_utc' => '2026-10-20 10:30:00',
    'outcome_id' => 0,
    'delivery_state' => null,
    'attendance_state' => null,
    'outcome_code' => null,
    'outcome_starts_at_utc' => null,
    'outcome_ends_at_utc' => null,
));
// A lifecycle completion without an outcome is a canonical completion fact and contributes its duration.
$presumedComplete = array(
    'lesson_id' => 5, 'student_id' => 45, 'lifecycle_state' => 'completed',
    'starts_at_utc' => '2026-10-11 10:00:00', 'ends_at_utc' => '2026-10-11 10:45:00', 'duration_minutes' => 45,
    'outcome_id' => 0, 'delivery_state' => null, 'attendance_state' => null, 'outcome_code' => null,
    'outcome_starts_at_utc' => null, 'outcome_ends_at_utc' => null,
);
// A reconciled non-delivery is never fabricated into teaching just because the lifecycle says completed.
$nonDelivery = array(
    'lesson_id' => 6, 'student_id' => 46, 'lifecycle_state' => 'completed',
    'starts_at_utc' => '2026-10-12 10:00:00', 'ends_at_utc' => '2026-10-12 11:00:00', 'duration_minutes' => 60,
    'outcome_id' => 906, 'delivery_state' => 'not_delivered', 'attendance_state' => 'unknown', 'outcome_code' => 'teacher_non_delivery',
    'outcome_starts_at_utc' => '2026-10-12 10:00:00', 'outcome_ends_at_utc' => '2026-10-12 11:00:00',
);
$cancelled = array_merge($future, array('lesson_id' => 7, 'student_id' => 47, 'lifecycle_state' => 'cancelled'));
$mixed = TeacherFinanceSummaryReadService::reconcileMonthRows(array(
    $delivered(1, 41), $delivered(2, 42), $delivered(3, 43),
    $future, $presumedComplete, $nonDelivery, $cancelled,
));
$assert($mixed['scheduled_lessons'] === 6, 'Cancelled occurrences must not be scheduled; future authorised occurrences must be.');
$assert($mixed['completed_lessons'] === 4, 'Delivered and lifecycle-completed occurrences count; an explicit non-delivery does not.');
$assert($mixed['completed_minutes'] === 135, 'Completed minutes must be 90 delivered + 45 lifecycle-completed, excluding future and non-delivery time.');
$assert($mixed['scheduled_minutes'] === 225, 'Scheduled minutes remain the full scheduled duration of the month.');
$assert($mixed['students'] === 6, 'Cancelled-only students must not inflate the month student count.');

$rate = array(
    'id' => 11, 'scope_kind' => 'teacher', 'course_scope_id' => 0, 'compensation_basis' => 'per_session',
    'amount_minor' => 4000, 'currency' => 'AUD', 'effective_from' => '2026-09-01 00:00:00', 'effective_until' => null,
);
$issued = array(
    // A real completed-period issued statement, exactly as the existing TeacherStatementReadService exposes it.
    array('id' => 21, 'state' => 'issued', 'currency' => 'AUD', 'period_end_utc' => '2026-09-30 23:59:59', 'payable_amount_minor' => 10000, 'payable_line_count' => 2),
    array('id' => 22, 'state' => 'draft', 'currency' => 'AUD', 'period_end_utc' => '2026-10-31 23:59:59', 'payable_amount_minor' => 5000, 'payable_line_count' => 1),
);
$golden = array(
    array('statement_id' => 21, 'lesson_id' => 1, 'currency' => 'AUD', 'disposition' => 'payable', 'eligible_minutes' => 60, 'total_bonus_minor' => 800, 'window_definitions' => '[{"bonus_basis_points":2000}]'),
    array('statement_id' => 21, 'lesson_id' => 2, 'currency' => 'AUD', 'disposition' => 'payable', 'eligible_minutes' => 30, 'total_bonus_minor' => 300, 'window_definitions' => '[{"bonus_basis_points":1000}]'),
);
$remuneration = TeacherFinanceSummaryReadService::remunerationModel(array($rate), $issued, $golden);
$assert($remuneration['rate']['state'] === 'available' && $remuneration['rate']['amount_minor'] === 4000 && $remuneration['rate']['currency'] === 'AUD', 'The single active rate must be returned with its exact unit and currency.');
$assert($remuneration['rate']['unit_label'] === 'هر جلسه', 'The per-session rate unit must be labelled for the Persian portal.');
$assert($remuneration['earned']['state'] === 'available' && $remuneration['earned']['amount_minor'] === 10000 && $remuneration['earned']['statement_count'] === 1, 'Only issued statements may contribute to earned pay; a draft must not.');
$assert($remuneration['golden_hour']['eligible_minutes'] === 90 && $remuneration['golden_hour']['bonus_minor'] === 1100, 'Golden-hour duration and bonus must come from recorded details.');
$assert($remuneration['golden_hour']['bonus_basis_points_mixed'] === true && $remuneration['golden_hour']['bonus_basis_points_min'] === 1000 && $remuneration['golden_hour']['bonus_basis_points_max'] === 2000, 'Different recorded bonus percentages must be reported honestly instead of guessed.');
$assert($remuneration['expected_total']['amount_minor'] === 10000 && $remuneration['expected_total']['includes_golden_hour_bonus'] === true, 'Expected earnings are the issued statement total, explicitly including recorded bonuses.');
$assert($remuneration['payout']['state'] === 'unavailable' && $remuneration['payout']['reason'] === 'payout_ledger_unavailable', 'With no payout ledger the payment status must be unavailable.');
$assert($remuneration['payout']['paid_minor'] === null && $remuneration['payout']['unpaid_minor'] === null && $remuneration['payout']['actual_payments'] === null, 'No amount may be fabricated as paid or unpaid.');

$ambiguous = TeacherFinanceSummaryReadService::remunerationModel(array($rate, array_merge($rate, array('id' => 12, 'amount_minor' => 5000))), $issued, $golden);
$assert($ambiguous['rate']['state'] === 'unavailable' && $ambiguous['rate']['reason'] === 'rate_ambiguous', 'Multiple active rates must resolve to unavailable, never a guessed rate.');
$noStatements = TeacherFinanceSummaryReadService::remunerationModel(array($rate), array(), array());
$assert($noStatements['earned']['state'] === 'unavailable' && $noStatements['earned']['reason'] === 'no_issued_statement', 'Without a completed issued statement, earned pay must be unavailable.');
$assert($noStatements['golden_hour']['state'] === 'unavailable' && $noStatements['golden_hour']['reason'] === 'no_golden_hour_record', 'Without recorded golden-hour details, bonus must be unavailable.');
$assert($noStatements['payout']['state'] === 'unavailable' && $noStatements['payout']['paid_minor'] === null, 'Missing payout records must never be reported as paid.');

$service = (string) file_get_contents($servicePath);
$assert(str_contains($service, 'canonical_lesson_delivery_outcomes') && str_contains($service, 'delivery_state'), 'The monthly reconciliation must read canonical delivery outcomes.');
$assert(str_contains($service, 'COALESCE(o.occurrence_starts_at_utc,v.starts_at_utc)'), 'The month boundary must follow the same occurrence anchor Finance uses.');
$assert(str_contains($service, "'minutes_month'=>\$month['completed_minutes']") && str_contains($service, "'scheduled_minutes_month'=>\$month['scheduled_minutes']"), 'Taught minutes must come from completed facts while scheduled minutes stay separate.');
$assert(str_contains($service, "state='issued' AND period_end_utc<=%s"), 'Only issued statements for completed periods may feed the remuneration read.');
$assert(str_contains($service, 'payout_ledger_unavailable') && !str_contains($service, 'finance_teacher_payouts'), 'The payout state must be honest and must not invent a payout table.');
$assert(substr_count($service, '$wpdb->prepare(') >= 6, 'Every Finance fact query must remain parameterized.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $service), 'The Teacher summary read service must remain read-only.');

$statementRead = (string) file_get_contents($root . '/src/Core/Application/Finance/Read/TeacherStatementReadService.php');
$assert(str_contains($statementRead, 'assertTotals') && str_contains($statementRead, 'statementsFor'), 'Completed-period statements must continue to be reviewable through the existing read path.');

echo "teacher-finance-summary-reconciliation-unit: OK\n";
