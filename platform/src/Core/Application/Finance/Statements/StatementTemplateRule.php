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
    );

    public const LESSON_PLACEHOLDERS = array(
        'lesson_id' => 'Lesson identifier',
        'lesson_date' => 'Lesson occurrence date',
        'course' => 'Course name',
        'disposition' => 'Payability disposition',
        'basis' => 'Payability basis code',
        'amount' => 'Formatted line amount',
        'amount_minor' => 'Exact line amount in minor units',
        'status' => 'Line presentation status',
    );

    public const DEFAULT_CSS = <<<'CSS'
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; padding: 32px; font-family: Tahoma, "Vazirmatn", Arial, sans-serif; color: #1d2327; background: #fff; line-height: 1.7; }
.statement { max-width: 920px; margin: 0 auto; }
header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; border-bottom: 2px solid #1d2327; padding-bottom: 16px; }
header img { max-height: 64px; max-width: 180px; }
h1 { margin: 0; font-size: 26px; }
h2 { margin-top: 28px; font-size: 18px; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; }
th, td { border-bottom: 1px solid #dcdcde; padding: 7px 6px; text-align: start; }
th { background: #f6f7f7; }
.meta { color: #50575e; font-size: 13px; }
.totals { margin-top: 18px; font-weight: 700; font-size: 16px; }
footer { margin-top: 32px; border-top: 1px solid #dcdcde; padding-top: 14px; color: #50575e; font-size: 12px; }
@media print { body { padding: 0; } header { break-inside: avoid; } }
CSS;

    public const DEFAULT_HEADER = <<<'HTML'
<header>
  <div>
    <h1>{{academy_name}}</h1>
    <p class="meta">{{period_label}} · {{period_start}} → {{period_end}}</p>
    <p class="meta">{{teacher_name}} · {{teacher_reference}}</p>
  </div>
  {{#logo_url}}<img src="{{logo_url}}" alt="{{academy_name}}">{{/logo_url}}
</header>
HTML;

    public const DEFAULT_BODY = <<<'HTML'
<h2>Lesson earnings</h2>
<table>
  <thead><tr><th>Lesson</th><th>Date</th><th>Course</th><th>Status</th><th>Amount</th></tr></thead>
  <tbody>
  {{#lessons}}
    <tr>
      <td>{{lesson_id}}</td>
      <td>{{lesson_date}}</td>
      <td>{{course}}</td>
      <td>{{disposition}} · {{basis}}</td>
      <td>{{amount}}</td>
    </tr>
  {{/lessons}}
  </tbody>
</table>
<p class="totals">Payable: {{payable_amount}}</p>
<p class="meta">Statement {{statement_id}} · version {{statement_version}} · {{statement_state}}</p>
HTML;

    public const DEFAULT_FOOTER = <<<'HTML'
<footer>
  <p>{{academy_name}} · Teacher compensation statement · Generated {{generated_at}}</p>
  <p>This statement records lesson compensation only. It is not a payout receipt.</p>
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
