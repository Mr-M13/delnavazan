<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;

/**
 * Brand-aligned, email-safe teacher statement.
 *
 * The exact same palette and typography as the Academy site, but delivered with a fixed,
 * table-based, inline-styled layout: no external stylesheet, no web font request, no script and no
 * tracking pixel. Gmail, Apple Mail, Outlook and mobile clients therefore render the same structure.
 * Only recorded statement and line values are substituted; the admin-editable template is not used
 * here, so an email can never contain arbitrary administrator HTML.
 */
final class TeacherStatementEmailRenderer {
    public const IVORY = '#FBF8F2';
    public const SURFACE = '#F2ECE1';
    public const CARD = '#FFFFFF';
    public const INK = '#252726';
    public const MUTED = '#5F625F';
    public const TEAL = '#104A4D';
    public const PRIMARY = '#1E7B70';
    public const POMEGRANATE = '#A33E48';
    public const SAFFRON = '#D4AB4F';
    public const BORDER = '#DDD6CA';
    public const SUPPORT_EMAIL = 'support@delnavazan.com';
    public const SITE_URL = 'https://delnavazan.com';

    /**
     * @param array<string,mixed> $statement
     * @param list<array<string,mixed>> $lines
     * @param array{name?:string,reference?:string,email?:string,locale?:string} $teacher
     * @param array{locale?:string,logo_url?:string,academy_name?:string} $options
     * @return array{subject:string,html:string,text:string}
     */
    public function render(array $statement, array $lines, array $teacher, array $options = array()): array {
        $locale = strtolower((string) ($options['locale'] ?? $teacher['locale'] ?? 'fa')) === 'en' ? 'en' : 'fa';
        $english = $locale === 'en';
        $direction = $english ? 'ltr' : 'rtl';
        $academy = trim((string) ($options['academy_name'] ?? 'Delnavazan')) ?: 'Delnavazan';
        $brand = $english ? 'Delnavazan' : 'دلنوازان';
        $currency = (string) ($statement['currency'] ?? 'TOM');
        $period = (string) ($statement['period_label'] ?? trim((string) ($statement['period_start_utc'] ?? '') . ' – ' . (string) ($statement['period_end_utc'] ?? '')));
        $teacherName = trim((string) ($teacher['name'] ?? $statement['teacher_name'] ?? ''));
        $teacherReference = trim((string) ($teacher['reference'] ?? $statement['teacher_reference'] ?? ''));
        $payable = PricingMoney::format((int) ($statement['payable_amount_minor'] ?? 0), $currency, !$english);
        $payableLines = (int) ($statement['payable_line_count'] ?? 0);
        $totalLines = (int) ($statement['total_line_count'] ?? count($lines));
        $statementId = (string) ($statement['statement_id'] ?? '');
        $version = (string) ($statement['statement_version'] ?? '');
        $issuedAt = (string) ($statement['issued_at'] ?? '');
        $logo = $this->logoUrl((string) ($options['logo_url'] ?? ''));
        $subject = $english
            ? 'Delnavazan teacher statement — ' . $period
            : 'صورت‌حساب حقوق مدرس — ' . $period;

        $title = $english ? 'Teacher compensation statement' : 'صورت‌حساب حقوق مدرس';
        $eyebrow = $english ? 'Delnavazan · Music academy' : 'دلنوازان · آکادمی موسیقی';
        $summaryLabels = $english
            ? array('Payable', 'Payable lessons', 'Lessons in period')
            : array('مبلغ قابل پرداخت', 'جلسات قابل پرداخت', 'کل جلسات دوره');
        $teacherLabel = $english ? 'Teacher' : 'مدرس';
        $referenceLabel = $english ? 'Reference' : 'شناسه';
        $periodLabel = $english ? 'Period' : 'دوره';
        $issuedLabel = $english ? 'Issued' : 'تاریخ صدور';
        $statementLabel = $english ? 'Statement' : 'صورت‌حساب';
        $headers = $english
            ? array('Date', 'Course', 'Duration', 'Base rate', 'Golden hour', 'Total', 'Status')
            : array('تاریخ', 'دوره', 'مدت', 'نرخ پایه', 'فوق‌العاده', 'مبلغ نهایی', 'وضعیت');
        $totalLabel = $english ? 'Total payable' : 'جمع قابل پرداخت';
        $note = $english
            ? 'This statement records lesson compensation only. It is not a payout receipt, a tax invoice or a bank instruction. Every amount and line comes from the recorded and approved lesson facts.'
            : 'این صورت‌حساب فقط حقوق جلسات را ثبت می‌کند و رسید واریز، فاکتور رسمی یا دستور بانکی نیست. همهٔ مبلغ‌ها و ردیف‌ها از سوابق مالی تأییدشدهٔ کلاس‌ها آمده‌اند.';
        $footerNote = $english
            ? 'This message was sent because of teacher compensation activity at Delnavazan.'
            : 'این پیام به دلیل فعالیت مالی و حقوق مدرس در دلنوازان فرستاده شده است.';

        $rows = '';
        $textLines = array();
        foreach ($lines as $line) {
            $date = (string) ($line['occurrence_starts_at_utc'] ?? '');
            $course = (string) ($line['course'] ?? '');
            $duration = $this->digits((string) (int) ($line['duration_minutes'] ?? 0), $english);
            $base = PricingMoney::format((int) ($line['base_amount_minor'] ?? $line['line_amount_minor'] ?? 0), $currency, !$english);
            $bonus = PricingMoney::format((int) ($line['bonus_amount_minor'] ?? 0), $currency, !$english);
            $lineTotal = PricingMoney::format((int) ($line['line_amount_minor'] ?? 0), $currency, !$english);
            $status = $this->status((string) ($line['disposition'] ?? ''), $english);
            $rows .= '<tr>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . self::BORDER . ';font-size:12px;color:' . self::INK . ';direction:ltr;text-align:start;">' . $this->e($date) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . self::BORDER . ';font-size:12px;color:' . self::INK . ';">' . $this->e($course) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . self::BORDER . ';font-size:12px;color:' . self::MUTED . ';white-space:nowrap;">' . $this->e((string) $duration) . ' ' . ($english ? 'min' : 'دقیقه') . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . self::BORDER . ';font-size:12px;color:' . self::INK . ';white-space:nowrap;">' . $this->e($base) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . self::BORDER . ';font-size:12px;color:' . self::TEAL . ';white-space:nowrap;">' . $this->e($bonus) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . self::BORDER . ';font-size:12px;color:' . self::INK . ';font-weight:bold;white-space:nowrap;">' . $this->e($lineTotal) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . self::BORDER . ';font-size:11px;color:' . self::MUTED . ';">' . $this->e($status) . '</td>'
                . '</tr>';
            $textLines[] = $date . ' · ' . $course . ' · ' . $duration . ($english ? ' min' : ' دقیقه') . ' · ' . $base . ' · ' . ($english ? 'bonus ' : 'فوق‌العاده ') . $bonus . ' · ' . $lineTotal . ' · ' . $status;
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" style="padding:16px 8px;font-size:13px;color:' . self::MUTED . ';text-align:center;">' . ($english ? 'No lesson lines are recorded for this period.' : 'برای این دوره ردیفی ثبت نشده است.') . '</td></tr>';
        }

        $html = '<!doctype html><html dir="' . $direction . '" lang="' . ($english ? 'en' : 'fa') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . $this->e($subject) . '</title>'
            . '<style>@media only screen and (max-width:600px){.dzn-wrap{width:100%!important}.dzn-pad{padding:18px!important}.dzn-summary td{display:block!important;width:100%!important;padding:6px 0!important}.dzn-lessons{font-size:11px!important}.dzn-title{font-size:20px!important}}</style>'
            . '</head><body style="margin:0;padding:0;background:' . self::IVORY . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::IVORY . ';padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" class="dzn-wrap" width="640" cellpadding="0" cellspacing="0" border="0" style="width:640px;max-width:640px;background:' . self::CARD . ';border:1px solid ' . self::BORDER . ';border-radius:16px;overflow:hidden;font-family:Vazirmatn,Tahoma,\'Segoe UI\',Arial,sans-serif;color:' . self::INK . ';">'
            . '<tr><td class="dzn-pad" style="padding:24px 28px 18px;border-bottom:4px solid ' . self::POMEGRANATE . ';background:' . self::CARD . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="' . ($english ? 'left' : 'right') . '" style="vertical-align:middle;">'
            . ($logo !== '' ? '<img src="' . $this->e($logo) . '" width="140" height="44" alt="' . $this->e($brand) . '" style="display:block;border:0;outline:none;max-width:140px;height:auto;">' : '<span style="font-size:24px;font-weight:bold;color:' . self::PRIMARY . ';">' . $this->e($brand) . '</span>')
            . '<p style="margin:8px 0 0;font-size:11px;font-weight:bold;letter-spacing:.12em;text-transform:uppercase;color:' . self::PRIMARY . ';">' . $this->e($eyebrow) . '</p>'
            . '</td><td align="' . ($english ? 'right' : 'left') . '" style="vertical-align:middle;font-size:12px;line-height:1.8;color:' . self::MUTED . ';">'
            . '<strong style="color:' . self::TEAL . ';">' . $this->e($statementLabel) . ' ' . $this->e($statementId) . '</strong><br>'
            . $this->e($periodLabel) . ': ' . $this->e($period) . '<br>'
            . $this->e($issuedLabel) . ': ' . $this->e($issuedAt !== '' ? $issuedAt : '—')
            . '</td></tr></table>'
            . '<h1 class="dzn-title" style="margin:20px 0 0;font-size:26px;line-height:1.4;color:' . self::TEAL . ';font-weight:bold;">' . $this->e($title) . '</h1>'
            . '<p style="margin:6px 0 0;font-size:14px;color:' . self::MUTED . ';">' . $this->e($teacherName) . ($teacherReference !== '' ? ' · ' . $this->e($teacherReference) : '') . '</p>'
            . '</td></tr>'
            . '<tr><td class="dzn-pad" style="padding:22px 28px;">'
            . '<table role="presentation" class="dzn-summary" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;"><tr>'
            . $this->summaryCell($summaryLabels[0], $payable, true, $english)
            . $this->summaryCell($summaryLabels[1], (string) $payableLines, false, $english)
            . $this->summaryCell($summaryLabels[2], (string) $totalLines, false, $english)
            . '</tr></table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;border:1px solid ' . self::BORDER . ';border-radius:10px;background:' . self::IVORY . ';"><tr>'
            . '<td style="padding:12px 14px;font-size:12px;line-height:1.9;color:' . self::MUTED . ';">'
            . '<strong style="color:' . self::INK . ';">' . $this->e($teacherLabel) . ':</strong> ' . $this->e($teacherName) . '<br>'
            . '<strong style="color:' . self::INK . ';">' . $this->e($referenceLabel) . ':</strong> ' . $this->e($teacherReference !== '' ? $teacherReference : '—') . '<br>'
            . '<strong style="color:' . self::INK . ';">' . $this->e($periodLabel) . ':</strong> ' . $this->e($period) . ' · ' . $this->e($currency)
            . '</td></tr></table>'
            . '<table role="presentation" class="dzn-lessons" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:12px;">'
            . '<thead><tr>' . implode('', array_map(fn(string $h): string => '<th style="padding:10px 8px;background:' . self::TEAL . ';color:#FFFFFF;font-size:11px;text-align:start;white-space:nowrap;">' . $this->e($h) . '</th>', $headers)) . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;border-radius:10px;background:' . self::TEAL . ';"><tr>'
            . '<td style="padding:14px 16px;font-size:14px;font-weight:bold;color:#FFFFFF;">' . $this->e($totalLabel) . '</td>'
            . '<td align="' . ($english ? 'right' : 'left') . '" style="padding:14px 16px;font-size:20px;font-weight:bold;color:#FFFFFF;white-space:nowrap;">' . $this->e($payable) . '</td>'
            . '</tr></table>'
            . '<p style="margin:16px 0 0;font-size:12px;line-height:1.9;color:' . self::MUTED . ';">' . $this->e($note) . '</p>'
            . '</td></tr>'
            . '<tr><td class="dzn-pad" style="padding:18px 28px;border-top:1px solid ' . self::BORDER . ';background:' . self::IVORY . ';font-size:11px;line-height:1.9;color:' . self::MUTED . ';">'
            . '<p style="margin:0 0 6px;">' . $this->e($footerNote) . '</p>'
            . '<p style="margin:0 0 6px;">' . $this->e($academy) . ' · ' . $this->e($period) . ' · ' . $this->e($statementLabel) . ' ' . $this->e($statementId) . ' · v' . $this->e($version) . '</p>'
            . '<p style="margin:0;">' . ($english ? 'Support' : 'پشتیبانی') . ': <a href="mailto:' . self::SUPPORT_EMAIL . '" style="color:' . self::PRIMARY . ';text-decoration:none;">' . self::SUPPORT_EMAIL . '</a> · <a href="' . self::SITE_URL . '" style="color:' . self::PRIMARY . ';text-decoration:none;">' . $this->e($brand) . '</a></p>'
            . '</td></tr></table></td></tr></table></body></html>';

        $text = ($english ? 'Delnavazan teacher statement' : 'صورت‌حساب حقوق مدرس دلنوازان') . "\n"
            . $teacherName . ($teacherReference !== '' ? ' · ' . $teacherReference : '') . "\n"
            . $period . ' · ' . $currency . "\n\n"
            . implode("\n", $textLines) . "\n\n"
            . $totalLabel . ': ' . $payable . "\n\n"
            . $note . "\n"
            . self::SUPPORT_EMAIL;

        return array('subject' => $subject, 'html' => $html, 'text' => $text);
    }

    /** Send the rendered statement through WordPress mail with a plain-text alternative. */
    public function send(string $to, array $statement, array $lines, array $teacher, array $options = array()): bool {
        if (!function_exists('wp_mail')) return false;
        $rendered = $this->render($statement, $lines, $teacher, $options);
        $alternative = $rendered['text'];
        $hook = static function ($mailer) use ($alternative): void {
            if (is_object($mailer) && property_exists($mailer, 'AltBody')) $mailer->AltBody = $alternative;
        };
        if (function_exists('add_action')) add_action('phpmailer_init', $hook, 99);
        try {
            return (bool) wp_mail($to, $rendered['subject'], $rendered['html'], array('Content-Type: text/html; charset=UTF-8'));
        } finally {
            if (function_exists('remove_action')) remove_action('phpmailer_init', $hook, 99);
        }
    }

    private function summaryCell(string $label, string $value, bool $primary, bool $english): string {
        return '<td width="33%" style="padding:6px;vertical-align:top;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . self::BORDER . ';border-radius:10px;background:' . ($primary ? '#EAF3F1' : self::IVORY) . ';"><tr><td style="padding:12px 14px;">'
            . '<span style="display:block;font-size:11px;font-weight:bold;color:' . self::MUTED . ';">' . $this->e($label) . '</span>'
            . '<strong style="display:block;margin-top:4px;font-size:' . ($primary ? '20px' : '18px') . ';line-height:1.4;color:' . self::TEAL . ';">' . $this->e($value) . '</strong>'
            . '</td></tr></table></td>';
    }

    private function status(string $disposition, bool $english): string {
        return match ($disposition) {
            'payable' => $english ? 'Payable' : 'قابل پرداخت',
            'non_payable' => $english ? 'Not payable' : 'غیرقابل پرداخت',
            'pending' => $english ? 'Pending review' : 'در انتظار بررسی',
            default => $english ? ucfirst(str_replace('_', ' ', $disposition)) : $disposition,
        };
    }

    private function digits(string $value, bool $english): string {
        if ($english) return $value;
        return strtr($value, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }

    private function logoUrl(string $override): string {
        $override = trim($override);
        if ($override !== '' && preg_match('~^https://~i', $override) === 1) return $override;
        if (function_exists('get_theme_file_uri')) {
            $uri = (string) get_theme_file_uri('assets/images/delnavazan-logo.png');
            if ($uri !== '') return $uri;
        }
        return self::SITE_URL . '/wp-content/themes/delnavazan/assets/images/delnavazan-logo.png';
    }

    private function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
