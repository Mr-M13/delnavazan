<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService;
use Delnavazan\Platform\Core\Support\PersianCalendar;
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceStatementRepository;

/**
 * One small, read-only projection for the single-operator follow-up screen.
 *
 * It reuses the existing authorities instead of re-deriving them:
 *  - intro requests come from the enrolment workspace booking case read;
 *  - onboarding progress, blockers and the next action come from the onboarding workspace read;
 *  - unpaid compensation statements come from the issued statement ledger.
 *
 * Each list is independent: the caller renders what is available and shows a short note for a
 * list whose source is unavailable, so one failed read never blanks the screen.
 */
final class OwnerFollowupReadService {
    private const INTRO_STAGES = array('new', 'identity', 'teacher_match', 'teacher_agreed');

    public function __construct(
        private ?EnrolmentWorkspaceReadService $booking = null,
        private ?TeacherOnboardingWorkspaceReadService $onboarding = null,
        private ?FinanceStatementRepository $statements = null
    ) {
        $this->booking ??= new EnrolmentWorkspaceReadService();
        $this->onboarding ??= new TeacherOnboardingWorkspaceReadService();
        $this->statements ??= new FinanceStatementRepository();
    }

    /** @return array<int,array<string,mixed>> */
    public function introsWithoutTeacher(int $limit = 24): array {
        $this->guard();
        $cases = $this->booking->bookingCases(array('per_page' => 40), false);
        $rows = array();
        foreach ((array) ($cases['rows'] ?? array()) as $case) {
            if (!is_array($case) || !in_array((string) ($case['stage_key'] ?? ''), self::INTRO_STAGES, true)) { continue; }
            $first = (array) (($case['requested_times'][0] ?? array()) ?: array());
            $rows[] = array(
                'reference' => (string) ($case['reference'] ?? ''),
                'learner' => (string) ($case['learner_name'] ?? ''),
                'instrument' => (string) ($case['instrument'] ?? ''),
                'course' => (string) ($case['course'] ?? ''),
                'when' => $this->persianInstant((string) ($first['starts_at_utc'] ?? ''), (string) ($first['timezone'] ?? 'Asia/Tehran')),
                'stage_key' => (string) ($case['stage_key'] ?? ''),
                'age' => $this->persianAge((int) ($case['age_seconds'] ?? 0)),
                'contact' => trim((string) ($case['phone'] ?? '')),
                'request_id' => (int) ($case['id'] ?? 0),
            );
        }
        return array_slice($rows, 0, max(1, $limit));
    }

    /** @return array<int,array<string,mixed>> */
    public function onboardingOutstanding(int $limit = 30): array {
        $this->guard();
        $rows = array();
        $state_labels = array(
            'linked_pending' => 'دعوت‌شده، در انتظار ورود',
            'in_progress' => 'در حال تکمیل پرونده',
            'returned' => 'برگشت‌خورده برای اصلاح',
            'pending_review' => 'در انتظار بررسی شما',
            'rejected' => 'ردشده',
            'reset_pending' => 'نیازمند راه‌اندازی دوباره',
            'active' => 'فعال',
            'offboarded' => 'خارج‌شده',
        );
        foreach ($this->onboarding->rows(100) as $entry) {
            $teacher = (object) ($entry['teacher'] ?? array());
            $state = (string) (($teacher->state ?? '') ?: '');
            if ('offboarded' === $state) { continue; }
            $progress = (array) ($entry['progress'] ?? array());
            $ready = !empty($progress['ready_for_activation']) || ('active' === $state && 'ready' === (string) ($teacher->readiness_state ?? ''));
            if ('active' === $state && $ready) { continue; }
            $outstanding = array();
            if ('active' !== (string) ($teacher->principal_status ?? '')) { $outstanding[] = 'حساب کاربری به پرتال وصل نشده است'; }
            if ('complete' !== (string) ($teacher->profile_state ?? '')) { $outstanding[] = 'اطلاعات پروفایل کامل نیست'; }
            if ('complete' !== (string) ($teacher->availability_state ?? '')) { $outstanding[] = 'برنامهٔ هفتگی ثبت نشده است'; }
            if ('pending_review' === $state) { $outstanding[] = 'در انتظار بررسی و تأیید شما'; }
            if ('returned' === $state) { $outstanding[] = 'برای اصلاح به مدرس برگردانده شده است'; }
            if ('linked_pending' === $state) { $outstanding[] = 'منتظر ورود مدرس با دعوت‌نامه'; }
            if (!$outstanding) { $outstanding[] = 'آمادهٔ تأیید نهایی'; }
            $rows[] = array(
                'teacher_id' => (int) ($teacher->teacher_id ?? 0),
                'name' => trim((string) ($teacher->display_name ?? '')) ?: 'مدرس بدون نام',
                'reference' => (string) ($teacher->reference_code ?? ''),
                'state' => $state_labels[$state] ?? 'بدون پرونده',
                'outstanding' => $outstanding,
            );
        }
        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Issued statements that still need a payment, oldest period first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unpaidStatements(int $limit = 40): array {
        $this->guard();
        $rows = array();
        foreach ($this->statements->issuedWithTeacher(max(1, $limit)) as $row) {
            $rows[] = array(
                'statement_id' => (int) ($row->statement_id ?? 0),
                'teacher' => trim((string) ($row->teacher_name ?? '')) ?: 'مدرس',
                'teacher_reference' => (string) ($row->teacher_reference ?? ''),
                'period' => $this->persianMonth((string) ($row->period_start_utc ?? ''), (string) ($row->period_timezone ?? 'Asia/Tehran')),
                'amount_minor' => (int) ($row->payable_amount_minor ?? 0),
                'currency' => (string) ($row->currency ?? ''),
                'issued_at' => (string) ($row->issued_at ?? ''),
            );
        }
        return $rows;
    }

    /** The statement read is the finance authority's own vocabulary; reuse its shape when needed. */
    public function statement(int $statementId): ?array {
        $this->guard();
        if ($statementId < 1) { return null; }
        try { return (new TeacherStatementReadService())->statement($statementId); } catch (\Throwable $error) { return null; }
    }

    private function digits(string $value): string {
        return strtr($value, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }

    /** Persian weekday, Jalali date and 24h time in the instant's own timezone. */
    private function persianInstant(string $utc, string $timezone): string {
        if ('' === trim($utc)) { return ''; }
        try {
            $zone = new \DateTimeZone('' !== trim($timezone) ? $timezone : 'Asia/Tehran');
            $instant = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zone);
        } catch (\Throwable $error) {
            return '';
        }
        if (!class_exists(PersianCalendar::class)) {
            return $this->digits($instant->format('Y-m-d H:i'));
        }
        $jalali = PersianCalendar::toJalali((int) $instant->format('Y'), (int) $instant->format('n'), (int) $instant->format('j'));
        $year = $jalali[0];
        $month = $jalali[1];
        $day = $jalali[2];
        $months = array(1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند');
        $weekdays = array(0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه');
        return trim(
            ($weekdays[(int) $instant->format('w')] ?? '') . ' ' .
            $this->digits((string) $day) . ' ' . ($months[$month] ?? '') . ' ' .
            $this->digits((string) $year) . '، ' . $this->digits($instant->format('H:i'))
        );
    }

    private function persianMonth(string $utc, string $timezone): string {
        if ('' === trim($utc)) { return ''; }
        try {
            $zone = new \DateTimeZone('' !== trim($timezone) ? $timezone : 'Asia/Tehran');
            $instant = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zone);
        } catch (\Throwable $error) {
            return '';
        }
        if (!class_exists(PersianCalendar::class)) {
            return $this->digits($instant->format('Y-m'));
        }
        [$year, $month] = PersianCalendar::toJalali((int) $instant->format('Y'), (int) $instant->format('n'), (int) $instant->format('j'));
        return $this->digits(PersianCalendar::monthLabel($year, $month, true));
    }

    private function persianAge(int $seconds): string {
        $seconds = max(0, $seconds);
        if ($seconds < 3600) { return $this->digits((string) max(1, (int) floor($seconds / 60))) . ' دقیقه پیش'; }
        if ($seconds < 172800) { return $this->digits((string) max(1, (int) floor($seconds / 3600))) . ' ساعت پیش'; }
        return $this->digits((string) max(1, (int) floor($seconds / 86400))) . ' روز پیش';
    }

    private function guard(): void {
        if (!OwnerFollowupAuthority::isOperator()) { throw new \RuntimeException('Unauthorized'); }
        if (function_exists('current_user_can') && !current_user_can(OwnerFollowupAuthority::CAPABILITY)) { throw new \RuntimeException('Unauthorized'); }
    }
}
