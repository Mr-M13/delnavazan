<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

/**
 * Safe presentation-only statement template rules.
 *
 * Templates can change how a statement looks; they can never change a recorded amount,
 * period, disposition or currency. Values substituted into placeholders are escaped as
 * text, and the administrator-authored HTML/CSS is sanitised before every render.
 */
final class StatementTemplateRule {
    public const TEMPLATE_VERSION = 'statement_template_v1';
    public const MAX_HTML_BYTES = 120000;
    public const MAX_CSS_BYTES = 60000;

    public const PLACEHOLDERS = array(
        'academy_name' => 'Academy display name',
        'logo_url' => 'Logo image URL (https only)',
        'teacher_name' => 'Teacher display name',
        'teacher_reference' => 'Teacher reference code',
        'statement_id' => 'Statement identifier',
        'statement_version' => 'Statement version',
        'statement_state' => 'Draft, issued, superseded or withdrawn',
        'period_start' => 'Statement period start',
        'period_end' => 'Statement period end',
        'period_label' => 'Human period label',
        'currency' => 'ISO currency code',
        'currency_symbol' => 'Currency symbol',
        'total_lines' => 'Number of lesson lines',
        'payable_lines' => 'Number of payable lines',
        'payable_amount' => 'Formatted payable amount',
        'payable_amount_minor' => 'Exact payable amount in minor units',
        'issued_at' => 'Issued instant, when present',
        'generated_at' => 'Render instant',
        'direction' => 'rtl or ltr',
        'locale' => 'fa or en',
        'timezone' => 'IANA timezone label',
    );

    public const LESSON_PLACEHOLDERS = array(
        'lesson_id' => 'Lesson identifier',
        'lesson_date' => 'Lesson occurrence date',
        'course' => 'Course name',
        'duration_minutes' => 'Recorded lesson duration in minutes',
        'rate_amount' => 'Snapshotted base rate for the lesson',
        'base_amount' => 'Base pay before any golden-hour bonus',
        'bonus_amount' => 'Golden-hour bonus for the lesson',
        'disposition' => 'Payability disposition',
        'basis' => 'Payability basis code',
        'amount' => 'Formatted total line amount',
        'amount_minor' => 'Exact total line amount in minor units',
        'status' => 'Line presentation status',
    );

    public const DEFAULT_CSS = <<<'CSS'
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; padding: 28px 18px; font-family: Vazirmatn, Tahoma, Arial, sans-serif; color: #252726; background: #FBF8F2; line-height: 1.8; }
.statement { max-width: 920px; margin: 0 auto; background: #FFFFFF; border: 1px solid #DDD6CA; border-radius: 18px; overflow: hidden; box-shadow: 0 18px 45px rgba(37,39,38,.08); }
.statement-header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; padding: 28px 32px 22px; border-bottom: 4px solid #A33E48; background: linear-gradient(135deg, #FFFFFF 0%, #FBF8F2 100%); }
.brand { display: flex; gap: 16px; align-items: flex-start; }
.brand img { max-height: 58px; max-width: 160px; }
.eyebrow { margin: 0 0 4px; color: #1E7B70; font-size: 12px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
h1 { margin: 0; font-size: 26px; line-height: 1.35; color: #104A4D; }
.period { margin: 6px 0 0; color: #5F625F; font-size: 14px; }
.meta { min-width: 190px; text-align: end; font-size: 12px; color: #5F625F; }
.meta strong { color: #252726; }
.summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 24px 32px 0; }
.summary-card { border: 1px solid #DDD6CA; border-radius: 12px; background: #FBF8F2; padding: 14px 16px; }
.summary-card span { display: block; color: #5F625F; font-size: 12px; font-weight: 700; }
.summary-card strong { display: block; margin-top: 4px; color: #104A4D; font-size: 22px; line-height: 1.3; }
.teacher { margin: 18px 32px 0; padding: 12px 16px; border-inline-start: 4px solid #D4AB4F; background: #FBF3E4; border-radius: 8px; font-size: 14px; }
h2 { margin: 28px 32px 0; font-size: 18px; color: #104A4D; }
table { width: calc(100% - 64px); margin: 12px 32px 0; border-collapse: collapse; font-size: 13px; }
th { background: #104A4D; color: #FFFFFF; text-align: start; padding: 10px 8px; font-weight: 700; }
td { border-bottom: 1px solid #DDD6CA; padding: 10px 8px; vertical-align: top; }
tbody tr:nth-child(even) td { background: #F6F1E8; }
.totals { margin: 18px 32px 0; padding: 16px 18px; border-radius: 12px; background: #104A4D; color: #FFFFFF; display: flex; flex-wrap: wrap; gap: 8px 16px; justify-content: space-between; align-items: center; font-weight: 800; font-size: 18px; }
.totals strong { font-size: 24px; }
.note { margin: 16px 32px 0; color: #5F625F; font-size: 12px; line-height: 1.9; }
footer { margin-top: 28px; padding: 18px 32px 24px; border-top: 1px solid #DDD6CA; background: #FBF8F2; color: #5F625F; font-size: 12px; line-height: 1.9; }
footer p { margin: 0 0 4px; }
@media print { body { padding: 0; background: #FFFFFF; } .statement { border: 0; box-shadow: none; border-radius: 0; } thead { display: table-header-group; } tr { break-inside: avoid; } }
@media (max-width: 720px) { .summary { grid-template-columns: 1fr; margin: 18px 18px 0; } .statement-header { flex-direction: column; padding: 20px 18px; } .meta { text-align: start; } h2, table, .totals, .note { margin-inline: 18px; } table { width: calc(100% - 36px); } footer { padding-inline: 18px; } }
CSS;

    public const DEFAULT_HEADER = <<<'HTML'
<header class="statement-header">
  <div class="brand">
    {{#logo_url}}<img src="{{logo_url}}" alt="{{academy_name}}">{{/logo_url}}
    <div>
      <p class="eyebrow">{{academy_name}} · صورت‌حساب حقوق مدرس</p>
      <h1>صورت‌حساب حقوق مدرس</h1>
      <p class="period">{{period_label}} · {{period_start}} → {{period_end}}</p>
    </div>
  </div>
  <div class="meta">
    <p>شماره صورت‌حساب: <strong>{{statement_id}}</strong></p>
    <p>نسخه: <strong>{{statement_version}}</strong> · وضعیت: <strong>{{statement_state}}</strong></p>
    <p>تاریخ صدور: <strong>{{issued_at}}</strong></p>
    {{#timezone}}<p>منطقهٔ زمانی: <strong dir="ltr">{{timezone}}</strong></p>{{/timezone}}
  </div>
</header>
<div class="summary">
  <div class="summary-card"><span>مبلغ قابل پرداخت</span><strong>{{payable_amount}}</strong></div>
  <div class="summary-card"><span>جلسات قابل پرداخت</span><strong>{{payable_lines}}</strong></div>
  <div class="summary-card"><span>کل جلسات دوره</span><strong>{{total_lines}}</strong></div>
</div>
<div class="teacher"><strong>{{teacher_name}}</strong> · {{teacher_reference}} · {{currency}}</div>
HTML;

    public const DEFAULT_BODY = <<<'HTML'
<h2>ریز حقوق جلسات</h2>
<table>
  <thead><tr><th>تاریخ</th><th>دوره</th><th>مدت</th><th>نرخ پایه</th><th>فوق‌العاده</th><th>مبلغ نهایی</th><th>وضعیت</th></tr></thead>
  <tbody>
  {{#lessons}}
    <tr>
      <td>{{lesson_date}}</td>
      <td>{{course}}</td>
      <td>{{duration_minutes}} دقیقه</td>
      <td>{{base_amount}}</td>
      <td>{{bonus_amount}}</td>
      <td>{{amount}}</td>
      <td>{{status}}</td>
    </tr>
  {{/lessons}}
  </tbody>
</table>
<div class="totals"><span>جمع قابل پرداخت</span><strong>{{payable_amount}}</strong></div>
<p class="note">این صورت‌حساب فقط حقوق جلسات را ثبت می‌کند و رسید واریز، فاکتور رسمی یا دستور بانکی نیست. مبلغ و ردیف‌ها از سوابق مالی تأییدشدهٔ کلاس‌ها محاسبه شده‌اند.</p>
HTML;

    public const DEFAULT_FOOTER = <<<'HTML'
<footer>
  <p><strong>{{academy_name}}</strong> · صورت‌حساب حقوق مدرس · دوره {{period_label}}</p>
  <p>شناسه: {{statement_id}} · نسخه {{statement_version}} · تولید: {{generated_at}}</p>
  <p>پشتیبانی: <span dir="ltr">admin@delnavazan.com</span> · واتساپ و تلفن: <span dir="ltr">+61 413 413 004</span> · <span dir="ltr">https://delnavazan.com</span></p>
</footer>
HTML;

    public static function defaultTemplate(): array {
        return array(
            'header_html' => self::DEFAULT_HEADER,
            'body_html' => self::DEFAULT_BODY,
            'footer_html' => self::DEFAULT_FOOTER,
            'css' => self::DEFAULT_CSS,
            'locale' => 'fa',
            'logo_url' => '',
        );
    }

    /** Sanitise the administrator-authored HTML. No scripts, frames, forms or event handlers survive. */
    public static function sanitizeHtml(string $html): string {
        if (strlen($html) > self::MAX_HTML_BYTES) throw new \InvalidArgumentException('template_html_too_large');
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link|base|svg|math)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<\s*/?\s*(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link|base|svg|math)[^>]*>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s(?:style|formaction|xlink:href|http-equiv)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $allowed = '<div><span><p><h1><h2><h3><h4><h5><h6><table><thead><tbody><tfoot><tr><th><td><ul><ol><li><strong><b><em><i><br><hr><img><a><section><header><footer><main><aside><small><dl><dt><dd><blockquote><pre><code>';
        if (function_exists('wp_kses')) return self::scrubUrls(wp_kses($html, self::ksesAllowlist()));
        $html = strip_tags($html, $allowed);
        $html = preg_replace_callback('/<([a-z][a-z0-9]*)([^>]*)>/i', static function (array $match): string {
            $tag = strtolower((string) $match[1]);
            $allowedAttributes = array('class', 'id', 'dir', 'lang', 'title', 'alt', 'href', 'src', 'colspan', 'rowspan', 'scope', 'rel');
            $attributes = '';
            if (preg_match_all('/([a-z0-9:-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', (string) $match[2], $found, PREG_SET_ORDER) > 0) {
                foreach ($found as $attribute) {
                    $name = strtolower((string) $attribute[1]);
                    if (!in_array($name, $allowedAttributes, true)) continue;
                    $value = trim((string) $attribute[2], "\"' ");
                    if (($name === 'href' || $name === 'src') && preg_match('~^(https://|#|/(?!/))~i', $value) !== 1) continue;
                    $attributes .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                }
            }
            return '<' . $tag . $attributes . '>';
        }, $html) ?? '';
        return self::scrubUrls($html);
    }

    /** Sanitise CSS: layout/typography only, no remote loads, scripts or fixed overlay tricks. */
    public static function sanitizeCss(string $css): string {
        if (strlen($css) > self::MAX_CSS_BYTES) throw new \InvalidArgumentException('template_css_too_large');
        $css = preg_replace('#</?\s*style[^>]*>#i', '', $css) ?? '';
        $blocked = array(
            '/@import\b[^;]*;?/i', '/expression\s*\(/i', '/javascript\s*:/i', '/vbscript\s*:/i',
            '/behavior\s*:/i', '/-moz-binding\s*:/i', '/url\s*\(/i', '/@font-face\b[^{]*\{[^}]*\}/i',
            '/position\s*:\s*(fixed|sticky)/i', '/content\s*:/i', '/<[^>]*>/',
        );
        foreach ($blocked as $pattern) $css = preg_replace($pattern, '', $css) ?? '';
        return $css;
    }

    /** Validate an https logo URL; other schemes are refused rather than silently rewritten. */
    public static function safeUrl(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^https://[^\s"\'<>]+$#i', $url) !== 1) throw new \InvalidArgumentException('unsafe_template_url');
        return $url;
    }

    public static function sanitizeTemplate(array $input): array {
        $template = self::defaultTemplate();
        foreach (array('header_html', 'body_html', 'footer_html') as $field) {
            if (array_key_exists($field, $input)) $template[$field] = self::sanitizeHtml((string) $input[$field]);
        }
        if (array_key_exists('css', $input)) $template['css'] = self::sanitizeCss((string) $input['css']);
        if (array_key_exists('logo_url', $input)) $template['logo_url'] = self::safeUrl((string) $input['logo_url']);
        $locale = strtolower((string) ($input['locale'] ?? $template['locale']));
        $template['locale'] = in_array($locale, array('fa', 'en'), true) ? $locale : 'fa';
        return $template;
    }

    public static function sampleTemplate(): array {
        $template = self::defaultTemplate();
        $template['logo_url'] = '';
        return $template;
    }

    private static function ksesAllowlist(): array {
        $attrs = array('class' => true, 'id' => true, 'dir' => true, 'lang' => true, 'title' => true, 'alt' => true, 'href' => true, 'src' => true, 'colspan' => true, 'rowspan' => true, 'scope' => true);
        $tags = array();
        foreach (array('div', 'span', 'p', 'section', 'header', 'footer', 'main', 'aside', 'blockquote', 'pre', 'code', 'small', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr') as $tag) $tags[$tag] = $attrs;
        $tags['a'] = $attrs + array('rel' => true);
        $tags['img'] = $attrs;
        return $tags;
    }

    private static function scrubUrls(string $html): string {
        return preg_replace_callback('/\s(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', static function (array $match): string {
            $url = trim((string) ($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''))));
            if ($url === '' || preg_match('~^(https://|#|/(?!/))~i', $url) === 1) return $match[0];
            return '';
        }, $html) ?? '';
    }
}
