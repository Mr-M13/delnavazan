<?php
/**
 * Unit contract for the two-source Finance Snapshot projection.
 *
 * The fake database returns canonical-shaped rows and records every SQL statement. This proves
 * that monthly Stripe activity reads accepted evidence/settlement facts only, groups currencies
 * separately, counts distinct students through a union, and never reads the manual cashbook.
 */
namespace {
    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool { return true; }
    }
    if (!function_exists('wp_timezone')) {
        function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
    }

    final class DznFinanceMonthlyFakeWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<string> */
        public array $queries = [];
        public function prepare(string $query, ...$args): string {
            $this->queries[] = $query;
            return $query;
        }
        public function get_results(string $query): array {
            $this->queries[] = $query;
            if (str_contains($query, "evidence_kind='success'")) {
                return [
                    (object) ['currency' => 'AUD', 'gross_minor' => 10000, 'students' => 2],
                    (object) ['currency' => 'USD', 'gross_minor' => 5000, 'students' => 1],
                ];
            }
            if (str_contains($query, "evidence_kind='refund'")) {
                return [(object) ['currency' => 'AUD', 'refunds_minor' => 1000]];
            }
            if (str_contains($query, "event_kind='payment_succeeded'")) {
                return [(object) ['currency' => 'AUD', 'gross_minor' => 3000, 'students' => 1]];
            }
            if (str_contains($query, "event_kind='refund_recorded'")) {
                return [(object) ['currency' => 'AUD', 'refunds_minor' => 500]];
            }
            if (str_contains($query, "event_type='payment_recorded'")) {
                return [(object) ['currency' => 'AUD', 'amount_minor' => 8000, 'teachers' => 2]];
            }
            return [];
        }
        public function get_var(string $query) {
            $this->queries[] = $query;
            if (str_contains($query, 'paid_students')) return 3;
            return null;
        }
    }

    require_once dirname(__DIR__) . '/src/Core/Application/Finance/FinanceSupport.php';
    require_once dirname(__DIR__) . '/src/Core/Application/Finance/FinanceIdempotency.php';
    require_once dirname(__DIR__) . '/src/Core/Application/Finance/FinanceRule.php';
    require_once dirname(__DIR__) . '/src/Core/Application/Finance/Read/FinanceAdminReadService.php';

    global $wpdb;
    $wpdb = new DznFinanceMonthlyFakeWpdb();
    $service = new \Delnavazan\Platform\Core\Application\Finance\Read\FinanceAdminReadService();
    $activity = $service->monthlyActivity('2026-11');

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $assert($activity['month'] === '2026-11', 'Requested calendar month was not preserved.');
    $assert($activity['period_start_utc'] === '2026-11-01 00:00:00', 'Calendar month start is not a UTC boundary.');
    $assert($activity['period_end_utc'] === '2026-12-01 00:00:00', 'Calendar month end is not a half-open UTC boundary.');
    $assert($activity['students_paid'] === 3, 'Students Paid must count the distinct-student union.');
    $assert(count($activity['student_receipts']) === 2, 'Student receipts must remain separate per currency.');
    $aud = $activity['student_receipts'][0];
    $assert($aud['currency'] === 'AUD' && $aud['gross_minor'] === 13000 && $aud['refunds_minor'] === 1500 && $aud['net_minor'] === 11500, 'AUD receipts or refunds were combined incorrectly.');
    $usd = $activity['student_receipts'][1];
    $assert($usd['currency'] === 'USD' && $usd['gross_minor'] === 5000 && $usd['net_minor'] === 5000, 'USD receipts were added to AUD.');
    $assert($activity['teacher_payments'] === [['currency' => 'AUD', 'amount_minor' => 8000, 'teachers' => 2]], 'Teacher payments must use recorded statement settlements.');
    $assert($activity['teacher_payments_available'] === true, 'Teacher payment availability was not reported.');

    $joined = implode("\n", $wpdb->queries);
    $assert(str_contains($joined, 'paid_students'), 'Students Paid did not use a distinct union.');
    $assert(!str_contains($joined, 'checkout_sessions'), 'Stripe monthly receipts must not count checkout attempts.');
    $assert(!str_contains($joined, 'finance_cash_'), 'Stripe monthly activity must never read manual bookkeeping tables.');
    $assert(!array_key_exists('net_financial_position', $activity), 'Manual balances and Stripe activity must not be merged into one net figure.');

    $wpdb->queries = [];
    $teacher = $service->teacherPaymentsThisMonth('2026-11');
    $assert($teacher['teacher_payments'] === [['currency' => 'AUD', 'amount_minor' => 8000, 'teachers' => 2]], 'Finance authority without payment access cannot read Teacher payments.');
    $teacherSql = implode("\n", $wpdb->queries);
    $assert(str_contains($teacherSql, "event_type='payment_recorded'"), 'Teacher payments must use payment_recorded events.');
    $assert(!str_contains($teacherSql, "event_type='issued'"), 'Teacher payments must not count issued statements.');

    echo "Finance monthly activity unit passed\n";
}
