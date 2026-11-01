<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;

/** Presentation-only teacher statement renderer. */
final class StatementTemplateService {
    public function render(array $statement, array $lines, array $template, string $academyName = 'Delnavazan', ?int $generatedAt = null): string {
        $template = StatementTemplateRule::sanitizeTemplate($template);
        $locale = (string) $template['locale'];
        $persian = $locale === 'fa';
        $direction = $persian ? 'rtl' : 'ltr';
        $currency = (string) ($statement['currency'] ?? 'TOM');
        $generatedAt = $generatedAt ?? time();
        $periodStart = (string) ($statement['period_start_utc'] ?? '');
        $periodEnd = (string) ($statement['period_end_utc'] ?? '');
        $values = array(
            'academy_name' => $academyName,
            'logo_url' => (string) ($template['logo_url'] ?? ''),
            'teacher_name' => (string) ($statement['teacher_name'] ?? ''),
            'teacher_reference' => (string) ($statement['teacher_reference'] ?? ''),
            'statement_id' => (string) ($statement['statement_id'] ?? ''),
            'statement_version' => (string) ($statement['statement_version'] ?? ''),
            'statement_state' => (string) ($statement['state'] ?? 'draft'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_label' => (string) ($statement['period_label'] ?? ($periodStart . ' → ' . $periodEnd)),
            'currency' => $currency,
            'currency_symbol' => $this->symbol($currency),
            'total_lines' => (string) ($statement['total_line_count'] ?? count($lines)),
            'payable_lines' => (string) ($statement['payable_line_count'] ?? 0),
            'payable_amount' => PricingMoney::format((int) ($statement['payable_amount_minor'] ?? 0), $currency, $persian),
            'payable_amount_minor' => (string) (int) ($statement['payable_amount_minor'] ?? 0),
            'issued_at' => (string) ($statement['issued_at'] ?? ''),
            'generated_at' => gmdate('Y-m-d H:i', $generatedAt) . ' UTC',
            'direction' => $direction,
            'locale' => $locale,
        );

        $body = (string) $template['body_html'];
        $lessonHtml = '';
        foreach ($lines as $line) {
            $lessonHtml .= $this->replace($this->lessonBlock($body), array(
                'lesson_id' => (string) ($line['lesson_id'] ?? ''),
                'lesson_date' => (string) ($line['occurrence_starts_at_utc'] ?? $line['lesson_date'] ?? ''),
                'course' => (string) ($line['course'] ?? ''),
                'duration_minutes' => $this->digits((string) (int) ($line['duration_minutes'] ?? 0), $persian),
                'rate_amount' => PricingMoney::format((int) ($line['rate_amount_minor'] ?? 0), $currency, $persian),
                'base_amount' => PricingMoney::format((int) ($line['base_amount_minor'] ?? $line['line_amount_minor'] ?? $line['amount_minor'] ?? 0), $currency, $persian),
                'bonus_amount' => PricingMoney::format((int) ($line['bonus_amount_minor'] ?? 0), $currency, $persian),
                'disposition' => (string) ($line['disposition'] ?? ''),
                'basis' => (string) ($line['basis_code'] ?? $line['basis'] ?? ''),
                'amount' => PricingMoney::format((int) ($line['line_amount_minor'] ?? $line['amount_minor'] ?? 0), $currency, $persian),
                'amount_minor' => (string) (int) ($line['line_amount_minor'] ?? $line['amount_minor'] ?? 0),
                'status' => (string) ($line['status'] ?? ($line['disposition'] ?? '')),
            ));
        }
        $body = preg_replace_callback('/\{\{#lessons\}\}(.*?)\{\{\/lessons\}\}/s', static fn() => '{{dzn_lessons}}', $body, 1) ?? $body;
        $body = str_replace('{{dzn_lessons}}', $lessonHtml, $body);

        $header = $this->conditional($this->replace((string) $template['header_html'], $values), $values);
        $body = $this->conditional($this->replace($body, $values), $values);
        $footer = $this->conditional($this->replace((string) $template['footer_html'], $values), $values);
        $css = StatementTemplateRule::sanitizeCss((string) $template['css']);

        return '<!doctype html><html lang="' . ($persian ? 'fa' : 'en') . '" dir="' . $direction . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->e($academyName) . ' — ' . $this->e($values['period_label']) . '</title>'
            . '<style>' . $this->fontFaceCss() . $css . '</style></head><body><main class="statement" dir="' . $direction . '">'
            . $header . $body . $footer . '</main></body></html>';
    }

    /** Build a safe sample statement for the Settings live preview. */
    public function renderSample(array $template, string $academyName = 'Delnavazan'): string {
        $statement = array(
            'statement_id' => 'SAMPLE-1', 'statement_version' => 1, 'state' => 'draft',
            'period_start_utc' => '2026-10-01', 'period_end_utc' => '2026-10-31',
            'period_label' => 'October 2026', 'currency' => 'TOM',
            'total_line_count' => 2, 'payable_line_count' => 1, 'payable_amount_minor' => 4500000,
            'teacher_name' => 'نمونه استاد', 'teacher_reference' => 'DZN-TCH-0001',
        );
        $lines = array(
            array('lesson_id' => 'DZN-LSN-001', 'occurrence_starts_at_utc' => '2026-10-05 15:00:00', 'course' => 'کلاس تار', 'duration_minutes' => 60, 'rate_amount_minor' => 4000000, 'base_amount_minor' => 4000000, 'bonus_amount_minor' => 500000, 'disposition' => 'payable', 'basis_code' => 'delivered_occurrence', 'line_amount_minor' => 4500000),
            array('lesson_id' => 'DZN-LSN-002', 'occurrence_starts_at_utc' => '2026-10-12 15:00:00', 'course' => 'کلاس تار', 'duration_minutes' => 60, 'rate_amount_minor' => 4000000, 'base_amount_minor' => 0, 'bonus_amount_minor' => 0, 'disposition' => 'non_payable', 'basis_code' => 'academy_obligation', 'line_amount_minor' => 0),
        );
        return $this->render($statement, $lines, $template, $academyName);
    }

    private function lessonBlock(string $body): string {
        if (preg_match('/\{\{#lessons\}\}(.*?)\{\{\/lessons\}\}/s', $body, $match) === 1) return (string) $match[1];
        return '';
    }

    private function replace(string $template, array $values): string {
        return preg_replace_callback('/\{\{([a-z0-9_]+)\}\}/i', static function (array $match) use ($values): string {
            $key = strtolower((string) $match[1]);
            if (!array_key_exists($key, $values)) return '';
            return htmlspecialchars((string) $values[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template) ?? '';
    }

    private function conditional(string $template, array $values): string {
        foreach ($values as $key => $value) {
            $quoted = preg_quote((string) $key, '/');
            $pattern = '/\{\{#' . $quoted . '\}\}(.*?)\{\{\/' . $quoted . '\}\}/is';
            $template = preg_replace_callback($pattern, static fn(array $match): string => (string) $value !== '' ? (string) $match[1] : '', $template) ?? $template;
        }
        return $template;
    }

    private function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function digits(string $value, bool $persian): string {
        if (!$persian) return $value;
        return strtr($value, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }

    /** Trusted, theme-local brand font. This is never part of the editable template CSS. */
    private function fontFaceCss(): string {
        if (!function_exists('get_theme_file_uri')) return '';
        $url = (string) get_theme_file_uri('assets/fonts/Vazirmatn-Variable.woff2');
        if ($url === '') return '';
        return '@font-face{font-family:Vazirmatn;src:url("' . $this->e($url) . '") format("woff2-variations");font-style:normal;font-weight:400 900;font-display:swap;}';
    }

    private function symbol(string $currency): string {
        return array('AUD' => 'A$', 'NZD' => 'NZ$', 'USD' => 'US$', 'CAD' => 'C$', 'EUR' => '€', 'GBP' => '£', 'TOM' => 'تومان')[strtoupper($currency)] ?? strtoupper($currency);
    }
}
