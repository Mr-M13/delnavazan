<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Support\BrandIdentity;
use Delnavazan\Platform\Core\Support\CommunicationDesignTokens as Tokens;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;

/**
 * Teacher statement email built on the one shared Delnavazan email shell.
 *
 * The exact same palette, typography and contact footer as every other transactional message.
 * Only recorded statement and line values are substituted; the admin-editable statement template is
 * not used here, so an email can never contain arbitrary administrator HTML.
 */
final class TeacherStatementEmailRenderer {
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
        $academy = trim((string) ($options['academy_name'] ?? BrandIdentity::name($english))) ?: BrandIdentity::name($english);
        $currency = (string) ($statement['currency'] ?? '');
        $period = (string) ($statement['period_label'] ?? trim((string) ($statement['period_start_utc'] ?? '') . ' – ' . (string) ($statement['period_end_utc'] ?? '')));
        $teacherName = trim((string) ($teacher['name'] ?? $statement['teacher_name'] ?? ''));
        $teacherReference = trim((string) ($teacher['reference'] ?? $statement['teacher_reference'] ?? ''));
        $payable = PricingMoney::format((int) ($statement['payable_amount_minor'] ?? 0), $currency, !$english);
        $payableLines = (int) ($statement['payable_line_count'] ?? 0);
        $totalLines = (int) ($statement['total_line_count'] ?? count($lines));
        $statementId = (string) ($statement['statement_id'] ?? '');
        $version = (string) ($statement['statement_version'] ?? '');
        $timezone = trim((string) ($options['timezone'] ?? $statement['period_timezone'] ?? $statement['timezone'] ?? ''));
        $calendar = strtolower(trim((string) ($options['calendar'] ?? $statement['calendar_preference'] ?? 'auto')));
        if (!in_array($calendar, array('persian', 'gregorian'), true)) $calendar = 'gregorian';
        $issuedAt = $this->displayInstant((string) ($statement['issued_at'] ?? ''), $english, $timezone, $calendar, true);
        $subject = $english
            ? 'Delnavazan teacher statement — ' . $period
            : 'صورت‌حساب حقوق مدرس — ' . $period;

        $title = $english ? 'Teacher compensation statement' : 'صورت‌حساب حقوق مدرس';
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

        $ink = Tokens::color('text_primary');
        $muted = Tokens::color('text_muted');
        $teal = Tokens::color('text_secondary');
        $border = Tokens::color('border');
        $onDark = Tokens::color('on_action');

        $rows = '';
        $textLines = array();
        foreach ($lines as $line) {
            $date = $this->displayInstant((string) ($line['occurrence_starts_at_utc'] ?? ''), $english, $timezone, $calendar, false);
            $course = (string) ($line['course'] ?? '');
            $duration = $this->digits((string) (int) ($line['duration_minutes'] ?? 0), $english);
            $base = PricingMoney::format((int) ($line['base_amount_minor'] ?? $line['line_amount_minor'] ?? 0), $currency, !$english);
            $bonus = PricingMoney::format((int) ($line['bonus_amount_minor'] ?? 0), $currency, !$english);
            $lineTotal = PricingMoney::format((int) ($line['line_amount_minor'] ?? 0), $currency, !$english);
            $status = $this->status((string) ($line['disposition'] ?? ''), $english);
            $rows .= '<tr>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . $border . ';font-size:12px;color:' . $ink . ';direction:ltr;text-align:start;">' . $this->e($date) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . $border . ';font-size:12px;color:' . $ink . ';">' . $this->e($course) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . $border . ';font-size:12px;color:' . $muted . ';white-space:nowrap;">' . $this->e((string) $duration) . ' ' . ($english ? 'min' : 'دقیقه') . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . $border . ';font-size:12px;color:' . $ink . ';white-space:nowrap;" dir="ltr">' . $this->e($base) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . $border . ';font-size:12px;color:' . $teal . ';white-space:nowrap;" dir="ltr">' . $this->e($bonus) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . $border . ';font-size:12px;color:' . $ink . ';font-weight:bold;white-space:nowrap;" dir="ltr">' . $this->e($lineTotal) . '</td>'
                . '<td style="padding:9px 8px;border-bottom:1px solid ' . $border . ';font-size:12px;color:' . $muted . ';">' . $this->e($status) . '</td>'
                . '</tr>';
            $textLines[] = $date . ' · ' . $course . ' · ' . $duration . ($english ? ' min' : ' دقیقه') . ' · ' . $base . ' · ' . ($english ? 'bonus ' : 'فوق‌العاده ') . $bonus . ' · ' . $lineTotal . ' · ' . $status;
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" style="padding:16px 8px;font-size:13px;color:' . $muted . ';text-align:center;">' . ($english ? 'No lesson lines are recorded for this period.' : 'برای این دوره ردیفی ثبت نشده است.') . '</td></tr>';
        }

        $headerMeta = '<strong style="color:' . $teal . ';">' . $this->e($statementLabel) . ' ' . $this->e($statementId) . '</strong><br>'
            . $this->e($periodLabel) . ': ' . $this->e($period) . '<br>'
            . $this->e($issuedLabel) . ': ' . $this->e($issuedAt !== '' ? $issuedAt : '—')
            . ($timezone !== '' ? ' <span dir="ltr" style="unicode-bidi:isolate;">(' . $this->e($timezone) . ')</span>' : '');

        $content = '<table role="presentation" class="dzn-summary" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;"><tr>'
            . $this->summaryCell($summaryLabels[0], $payable, true)
            . $this->summaryCell($summaryLabels[1], (string) $payableLines, false)
            . $this->summaryCell($summaryLabels[2], (string) $totalLines, false)
            . '</tr></table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;border:1px solid ' . $border . ';border-radius:10px;background:' . Tokens::color('background') . ';"><tr>'
            . '<td style="padding:12px 14px;font-size:13px;line-height:1.9;color:' . $muted . ';">'
            . '<strong style="color:' . $ink . ';">' . $this->e($teacherLabel) . ':</strong> ' . $this->e($teacherName) . '<br>'
            . '<strong style="color:' . $ink . ';">' . $this->e($referenceLabel) . ':</strong> <span dir="ltr" style="unicode-bidi:isolate;">' . $this->e($teacherReference !== '' ? $teacherReference : '—') . '</span><br>'
            . '<strong style="color:' . $ink . ';">' . $this->e($periodLabel) . ':</strong> ' . $this->e($period) . ' · <span dir="ltr">' . $this->e($currency) . '</span>'
            . '</td></tr></table>'
            . '<table role="presentation" class="dzn-lessons" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:12px;">'
            . '<thead><tr>' . implode('', array_map(fn(string $h): string => '<th style="padding:10px 8px;background:' . $teal . ';color:' . $onDark . ';font-size:12px;text-align:start;white-space:nowrap;">' . $this->e($h) . '</th>', $headers)) . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;border-radius:10px;background:' . $teal . ';"><tr>'
            . '<td style="padding:14px 16px;font-size:14px;font-weight:bold;color:' . $onDark . ';">' . $this->e($totalLabel) . '</td>'
            . '<td align="' . ($english ? 'right' : 'left') . '" style="padding:14px 16px;font-size:' . Tokens::TYPE['financial_total'] . ';font-weight:bold;color:' . $onDark . ';white-space:nowrap;" dir="ltr">' . $this->e($payable) . '</td>'
            . '</tr></table>'
            . '<p style="margin:16px 0 0;font-size:12px;line-height:1.9;color:' . $muted . ';">' . $this->e($note) . '</p>';

        $rendered = DelnavazanEmailRenderer::renderTrustedHtml($subject, $content, '', array(
            'locale' => $english ? 'en' : 'fa-IR',
            'logo_url' => (string) ($options['logo_url'] ?? ''),
            'max_width' => 640,
            'header_meta' => $headerMeta,
            'footer_note' => $footerNote,
            'footer_details' => array(
                $academy . ' · ' . $period . ' · ' . $statementLabel . ' ' . $statementId . ' · v' . $version,
            ),
        ));

        $text = ($english ? 'Delnavazan teacher statement' : 'صورت‌حساب حقوق مدرس دلنوازان') . "\n"
            . $teacherName . ($teacherReference !== '' ? ' · ' . $teacherReference : '') . "\n"
            . $period . ' · ' . $currency . "\n\n"
            . implode("\n", $textLines) . "\n\n"
            . $totalLabel . ': ' . $payable . "\n\n"
            . $note . "\n"
            . BrandIdentity::contactLine($english);

        $rendered['text'] = $text;
        return $rendered;
    }

    /** Send the rendered statement through the shared branded mail path. */
    public function send(string $to, array $statement, array $lines, array $teacher, array $options = array()): bool {
        return DelnavazanEmailRenderer::sendRendered($to, $this->render($statement, $lines, $teacher, $options));
    }

    private function summaryCell(string $label, string $value, bool $primary): string {
        $teal = Tokens::color('text_secondary');
        $muted = Tokens::color('text_muted');
        return '<td class="dzn-summary-cell" width="33%" style="padding:6px;vertical-align:top;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . Tokens::color('border') . ';border-radius:10px;background:' . ($primary ? Tokens::color('success_surface') : Tokens::color('background')) . ';"><tr><td style="padding:12px 14px;">'
            . '<span style="display:block;font-size:11px;font-weight:bold;color:' . $muted . ';">' . $this->e($label) . '</span>'
            . '<strong style="display:block;margin-top:4px;font-size:' . ($primary ? Tokens::TYPE['financial_amount'] : '18px') . ';line-height:1.4;color:' . $teal . ';">' . $this->e($value) . '</strong>'
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

    /** Render a canonical UTC instant in the recipient's timezone and calendar, never in server time. */
    private function displayInstant(string $utc, bool $english, string $timezone, string $calendar, bool $withTime): string {
        $utc = trim($utc);
        if ($utc === '') return '';
        try {
            $zone = $timezone !== '' ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }
        $timestamp = strtotime($utc . ' UTC');
        if ($timestamp === false) return $utc;
        if ($calendar === 'persian' && class_exists('IntlDateFormatter')) {
            try {
                $formatter = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $zone->getName(), \IntlDateFormatter::TRADITIONAL);
                $formatter->setPattern($withTime ? 'yyyy/MM/dd HH:mm' : 'yyyy/MM/dd');
                $formatted = $formatter->format(new \DateTimeImmutable('@' . $timestamp));
                if (is_string($formatted) && $formatted !== '') return $this->digits($formatted, $english);
            } catch (\Throwable) {
                // Fall through to the Gregorian formatter.
            }
        }
        $local = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
        return $this->digits($local->format($withTime ? 'Y-m-d H:i' : 'Y-m-d'), $english);
    }

    private function digits(string $value, bool $english): string {
        if ($english) return $value;
        return strtr($value, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }

    private function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
