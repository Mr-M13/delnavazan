<?php
namespace Delnavazan\Platform\Core\Application\Finance\Receipts;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Support\BrandIdentity;
use Delnavazan\Platform\Core\Support\CommunicationDesignTokens as Tokens;

/** Brand-aligned, read-only Student payment receipt. */
final class StudentReceiptRenderer {
    public function render(array $receipt, string $academyName = ''): string {
        $academyName = trim($academyName) !== '' ? trim($academyName) : BrandIdentity::PERSIAN_NAME;
        $currency = strtoupper(trim((string) ($receipt['currency'] ?? '')));
        $money = static fn(int $minor): string => PricingMoney::format($minor, $currency, true);
        $method = (string) ($receipt['method'] ?? '') === 'manual' ? 'پرداخت دستی / خارج از درگاه' : 'پرداخت آنلاین';
        $refunded = (int) ($receipt['refunded_minor'] ?? 0);
        $remaining = (int) ($receipt['remaining_minor'] ?? 0);
        $rows = array(
            'شمارهٔ رسید' => (string) ($receipt['receipt_uid'] ?? ''),
            'تاریخ پرداخت' => (string) ($receipt['paid_at'] ?? ''),
            'هنرجو' => trim((string) ($receipt['student_name'] ?? '') . ' · ' . (string) ($receipt['student_reference'] ?? ''), ' ·'),
            'دوره' => (string) ($receipt['course'] ?? ''),
            'قسط' => (string) (int) ($receipt['instalment'] ?? 0),
            'روش پرداخت' => $method,
            'شناسهٔ پیشنهاد' => (string) ($receipt['offer_reference'] ?? ''),
        );
        $border = Tokens::color('border');
        $muted = Tokens::color('text_muted');
        $ink = Tokens::color('text_primary');
        $accent = Tokens::color('accent');
        $teal = Tokens::color('text_secondary');
        $rowsHtml = '';
        foreach ($rows as $label => $value) {
            $isolate = in_array($label, array('شمارهٔ رسید', 'تاریخ پرداخت', 'شناسهٔ پیشنهاد', 'قسط'), true);
            $valueHtml = $isolate
                ? '<span dir="ltr" style="unicode-bidi:isolate;">' . $this->e($value) . '</span>'
                : $this->e($value);
            $rowsHtml .= '<tr><th style="text-align:start;padding:10px 12px;border-bottom:1px solid ' . $border . ';color:' . $muted . ';font-size:12px;font-weight:bold;width:36%;">' . $this->e($label) . '</th><td style="padding:10px 12px;border-bottom:1px solid ' . $border . ';color:' . $ink . ';font-size:14px;">' . $valueHtml . '</td></tr>';
        }
        $adjustment = '';
        if ($refunded > 0) {
            $adjustment = '<tr><th style="text-align:start;padding:10px 12px;border-bottom:1px solid ' . $border . ';color:' . $muted . ';font-size:12px;font-weight:bold;">بازپرداخت‌شده</th><td style="padding:10px 12px;border-bottom:1px solid ' . $border . ';color:' . $accent . ';font-size:14px;font-weight:bold;">' . $this->e($money($refunded)) . '</td></tr>'
                . '<tr><th style="text-align:start;padding:10px 12px;border-bottom:1px solid ' . $border . ';color:' . $muted . ';font-size:12px;font-weight:bold;">ماندهٔ پرداخت‌شده</th><td style="padding:10px 12px;border-bottom:1px solid ' . $border . ';color:' . $teal . ';font-size:14px;font-weight:bold;">' . $this->e($money($remaining)) . '</td></tr>';
        }
        $fontFace = '';
        if (function_exists('get_theme_file_uri')) {
            $fontUrl = (string) get_theme_file_uri('assets/fonts/Vazirmatn-Variable.woff2');
            if ($fontUrl !== '') {
                $fontFace = '@font-face{font-family:Vazirmatn;src:url("' . $this->e($fontUrl) . '") format("woff2-variations");font-style:normal;font-weight:400 900;font-display:swap;}';
            }
        }
        $contacts = array();
        foreach (BrandIdentity::contactLinks() as $link) {
            $contacts[] = $this->e((string) $link['label']) . ': <a href="' . $this->e((string) $link['href']) . '"><span dir="ltr" style="unicode-bidi:isolate;">' . $this->e((string) $link['display']) . '</span></a>';
        }
        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . $this->e($academyName) . ' — رسید پرداخت</title>'
            . '<style>' . $fontFace . 'body{margin:0;padding:24px 16px;background:' . Tokens::color('background') . ';font-family:' . Tokens::FONT_BODY . ';color:' . $ink . ';line-height:' . Tokens::TYPE['leading_body'] . '}main{max-width:720px;margin:0 auto;background:' . Tokens::color('surface_elevated') . ';border:1px solid ' . $border . ';border-radius:16px;overflow:hidden;box-shadow:0 16px 40px rgba(' . Tokens::rgb('ink') . ' / .08)}header{padding:24px 28px;border-bottom:4px solid ' . $accent . ';display:flex;justify-content:space-between;gap:18px;align-items:flex-start}h1{margin:0;color:' . $teal . ';font-size:' . Tokens::TYPE['section_heading'] . ';line-height:' . Tokens::TYPE['leading_tight'] . '}.eyebrow{margin:0 0 4px;color:' . Tokens::color('action_primary') . ';font-size:' . Tokens::TYPE['xs'] . ';font-weight:700;letter-spacing:.08em}.meta{color:' . $muted . ';font-size:' . Tokens::TYPE['small'] . '}.body{padding:22px 28px}.total{margin:18px 0 0;padding:14px 16px;background:' . $teal . ';color:' . Tokens::color('on_action') . ';border-radius:10px;display:flex;justify-content:space-between;font-weight:bold;font-size:' . Tokens::TYPE['financial_amount'] . '}.note{margin:16px 0 0;color:' . $muted . ';font-size:' . Tokens::TYPE['small'] . '}footer{padding:16px 28px;background:' . Tokens::color('background') . ';border-top:1px solid ' . $border . ';color:' . $muted . ';font-size:' . Tokens::TYPE['xs'] . ';line-height:' . Tokens::TYPE['leading_body'] . '}footer a{color:' . $teal . ';text-decoration:none}@media print{body{padding:0;background:#fff}main{border:0;box-shadow:none;border-radius:0}}</style></head><body><main>'
            . '<header><div><p class="eyebrow">' . $this->e($academyName) . ' · رسید پرداخت</p><h1>رسید پرداخت هنرجو</h1><p class="meta">این رسید فقط یک سابقهٔ پرداخت است و فاکتور رسمی مالیاتی نیست.</p></div><div class="meta" dir="ltr">' . $this->e($currency) . '</div></header>'
            . '<div class="body"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . $rowsHtml . $adjustment . '</table>'
            . '<div class="total"><span>مبلغ پرداخت‌شده</span><strong dir="ltr">' . $this->e($money((int) ($receipt['amount_minor'] ?? 0))) . '</strong></div>'
            . '<p class="note">پرداخت شما در سوابق مالی دلنوازان ثبت شده است. در صورت نیاز به بررسی، شمارهٔ رسید را با پشتیبانی در میان بگذارید.</p></div>'
            . '<footer><p style="margin:0 0 4px;">' . $this->e($academyName) . ' · ' . $this->e(BrandIdentity::TAGLINE) . '</p><p style="margin:0;">' . implode(' · ', $contacts) . '</p></footer></main></body></html>';
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
