<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Support\BrandIdentity;
use Delnavazan\Platform\Core\Support\CommunicationDesignTokens as Tokens;

/**
 * Reusable Delnavazan email presentation.
 *
 * One fixed, table-based, inline-styled shell serves every transactional email: warm ivory page,
 * white card, dark brand action, restrained gold detail, pomegranate rule, right-to-left Persian
 * typography and a plain-text alternative. It uses no JavaScript, no remote stylesheet and no
 * external web font, so Gmail, Apple Mail, Outlook and mobile clients render the same structure.
 *
 * The shell, palette, type stack and contact footer come from the canonical brand identity and
 * communications tokens. `render()` accepts only an escaped plain-text body. `renderTrustedHtml()`
 * exists solely for server-built financial tables whose values are already escaped by their renderer.
 */
final class DelnavazanEmailRenderer {
    /**
     * @param array{locale?:string,preheader?:string,cta?:array{label?:string,url?:string},logo_url?:string,footer_note?:string,max_width?:int,header_meta?:string,footer_details?:string[]} $options
     * @return array{subject:string,html:string,text:string}
     */
    public static function render(string $subject, string $body, array $options = array()): array {
        $text = self::text($body);
        $content = '<div style="font-size:16px;line-height:1.8;color:' . Tokens::color('text_primary') . ';">' . self::paragraphs($text) . '</div>';
        return self::renderTrustedHtml($subject, $content, $text, $options);
    }

    /**
     * Render an email around server-built, already-escaped HTML content.
     *
     * Callers must escape every dynamic value. This method never accepts template-authored markup.
     *
     * @param array{locale?:string,preheader?:string,cta?:array{label?:string,url?:string},logo_url?:string,footer_note?:string,max_width?:int,header_meta?:string,footer_details?:string[]} $options
     * @return array{subject:string,html:string,text:string}
     */
    public static function renderTrustedHtml(string $subject, string $contentHtml, string $text, array $options = array()): array {
        $locale = NotificationCatalogue::normaliseLocale((string) ($options['locale'] ?? NotificationCatalogue::PRIMARY_LOCALE));
        $english = $locale === 'en';
        $subject = self::oneLine($subject);
        $cta = self::cta($options['cta'] ?? array(), $english);
        $preheader = self::oneLine((string) ($options['preheader'] ?? ''));
        $logo = self::logoUrl((string) ($options['logo_url'] ?? ''));
        $note = self::oneLine((string) ($options['footer_note'] ?? ''));
        $details = array();
        foreach ((array) ($options['footer_details'] ?? array()) as $detail) {
            $detail = self::oneLine((string) $detail);
            if ($detail !== '') $details[] = $detail;
        }
        $width = max(320, min(720, (int) ($options['max_width'] ?? 600)));
        $brand = BrandIdentity::name($english);
        $tagline = BrandIdentity::tagline($english);
        $supportLabel = BrandIdentity::supportLabel($english);
        $rights = $english
            ? 'This message was sent because of an account or class activity at Delnavazan.'
            : 'این پیام به دلیل فعالیت حساب کاربری یا کلاس شما در دلنوازان فرستاده شده است.';
        $headerMeta = (string) ($options['header_meta'] ?? '');
        $logoSide = $english ? 'left' : 'right';
        $metaSide = $english ? 'right' : 'left';

        $html = '<!doctype html><html dir="' . ($english ? 'ltr' : 'rtl') . '" lang="' . ($english ? 'en' : 'fa') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light"><title>' . self::e($subject) . '</title>'
            . '<style>@media only screen and (max-width:600px){.dzn-wrap{width:100%!important}.dzn-card{padding:20px!important}.dzn-title{font-size:20px!important}.dzn-cta a{display:block!important;text-align:center!important}}body{margin:0!important;padding:0!important;background:' . Tokens::color('background') . ';}</style></head>'
            . '<body style="margin:0;padding:0;background:' . Tokens::color('background') . ';">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . Tokens::color('background') . ';padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" class="dzn-wrap" width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:' . $width . 'px;max-width:' . $width . 'px;background:' . Tokens::color('surface_elevated') . ';border:1px solid ' . Tokens::color('border') . ';border-radius:16px;overflow:hidden;font-family:' . str_replace('"', '&quot;', Tokens::FONT_EMAIL) . ';color:' . Tokens::color('text_primary') . ';">'
            . '<tr><td style="padding:20px 28px;border-bottom:3px solid ' . Tokens::color('accent') . ';background:' . Tokens::color('surface_elevated') . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="' . $logoSide . '" style="vertical-align:middle;">'
            . '<a href="' . self::e(BrandIdentity::SITE_URL) . '" style="text-decoration:none;color:' . Tokens::color('text_primary') . ';">'
            . ($logo !== '' ? '<img src="' . self::e($logo) . '" width="132" height="40" alt="' . self::e($brand) . '" style="display:block;border:0;outline:none;width:auto;max-width:132px;max-height:44px;height:auto;">' : '<span style="font-size:22px;font-weight:bold;color:' . Tokens::color('action_primary') . ';">' . self::e($brand) . '</span>')
            . '</a></td><td align="' . $metaSide . '" style="vertical-align:middle;font-size:12px;color:' . Tokens::color('text_muted') . ';line-height:1.7;">'
            . ($headerMeta !== '' ? self::e($tagline) . '<br>' . $headerMeta : self::e($tagline))
            . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td class="dzn-card" style="padding:28px;">'
            . '<h1 class="dzn-title" style="margin:0 0 16px;font-size:24px;line-height:1.6;color:' . Tokens::color('text_secondary') . ';font-weight:bold;">' . self::e($subject) . '</h1>'
            . $contentHtml;
        if ($cta !== null) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 8px;"><tr><td class="dzn-cta" align="center" bgcolor="' . Tokens::color('action_primary') . '" style="border-radius:8px;">'
                . '<a href="' . self::e($cta['url']) . '" style="display:inline-block;padding:13px 26px;font-family:' . str_replace('"', '&quot;', Tokens::FONT_EMAIL) . ';font-size:15px;font-weight:bold;color:' . Tokens::color('on_action') . ';text-decoration:none;border-radius:8px;">' . self::e($cta['label']) . '</a></td></tr></table>'
                . '<p style="margin:8px 0 0;font-size:12px;line-height:1.8;color:' . Tokens::color('text_muted') . ';">' . ($english ? 'If the button does not work, copy this address into your browser:' : 'اگر دکمه کار نکرد، این نشانی را در مرورگر خود باز کنید:') . '<br><span dir="ltr" style="display:inline-block;unicode-bidi:isolate;word-break:break-all;">' . self::e($cta['url']) . '</span></p>';
        }
        $html .= '</td></tr>'
            . '<tr><td style="padding:18px 28px;border-top:1px solid ' . Tokens::color('border') . ';background:' . Tokens::color('background') . ';font-size:12px;line-height:1.9;color:' . Tokens::color('text_muted') . ';">'
            . '<p style="margin:0 0 6px;">' . self::e($rights) . '</p>'
            . ($note !== '' ? '<p style="margin:0 0 6px;">' . self::e($note) . '</p>' : '');
        foreach ($details as $detail) $html .= '<p style="margin:0 0 6px;">' . self::e($detail) . '</p>';
        $html .= '<p style="margin:0;">' . self::e($supportLabel) . ': ' . self::contactLinksHtml($english) . '</p>'
            . '</td></tr></table></td></tr></table></body></html>';

        return array('subject' => $subject, 'html' => $html, 'text' => trim($text));
    }

    /**
     * Send one branded message through WordPress mail with a plain-text alternative.
     *
     * @param array{locale?:string,cta?:array{label?:string,url?:string},logo_url?:string,footer_note?:string,max_width?:int,header_meta?:string,footer_details?:string[]} $options
     * @param string[] $headers
     */
    public static function send(string $to, string $subject, string $body, array $options = array(), array $headers = array()): bool {
        return self::sendRendered($to, self::render($subject, $body, $options), $headers);
    }

    /**
     * Send an already-rendered shell. Used by financial renderers that compose trusted HTML blocks.
     *
     * @param array{subject:string,html:string,text:string} $rendered
     * @param string[] $headers
     */
    public static function sendRendered(string $to, array $rendered, array $headers = array()): bool {
        if (!function_exists('wp_mail')) return false;
        $headers = array_values(array_filter($headers, static fn(string $header): bool => stripos($header, 'Content-Type:') !== 0));
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $alternative = (string) ($rendered['text'] ?? '');
        $hook = static function ($mailer) use ($alternative): void {
            if (is_object($mailer) && property_exists($mailer, 'AltBody')) $mailer->AltBody = $alternative;
        };
        if (function_exists('add_action')) add_action('phpmailer_init', $hook, 99);
        try {
            return (bool) wp_mail($to, (string) $rendered['subject'], (string) $rendered['html'], $headers);
        } finally {
            if (function_exists('remove_action')) remove_action('phpmailer_init', $hook, 99);
        }
    }

    /** The plain-text alternative: the authored body, normalised and without markup. */
    public static function text(string $body): string {
        $body = strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $body));
        $body = str_replace(array("\r\n", "\r"), "\n", $body);
        $body = preg_replace("/\n{3,}/", "\n\n", $body);
        return trim((string) $body);
    }

    public static function oneLine(string $value): string {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    }

    /** Escaped paragraphs, with single newlines preserved as line breaks inside one block. */
    private static function paragraphs(string $text): string {
        $html = '';
        foreach (preg_split("/\n{2,}/", $text) ?: array() as $block) {
            $block = trim($block);
            if ($block === '') continue;
            $html .= '<p style="margin:0 0 14px;">' . nl2br(self::e($block), false) . '</p>';
        }
        return $html;
    }

    /**
     * A CTA is derived from an already-authorised action URL, never from an editable template. The
     * renderer refuses a non-http(s) scheme so a template cannot smuggle a script or data URL.
     *
     * @return array{label:string,url:string}|null
     */
    private static function cta(array $cta, bool $english): ?array {
        $url = trim((string) ($cta['url'] ?? ''));
        if ($url === '') return null;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) return null;
        $label = self::oneLine((string) ($cta['label'] ?? ''));
        if ($label === '') $label = $english ? 'Open Delnavazan' : 'رفتن به دلنوازان';
        return array('label' => $label, 'url' => $url);
    }

    /** Public contact links with LTR isolation for the machine-readable values. */
    private static function contactLinksHtml(bool $english): string {
        $out = array();
        foreach (BrandIdentity::contactLinks() as $link) {
            $label = $english ? $link['label_en'] : $link['label'];
            $display = (string) $link['display'];
            if ($link['href'] === BrandIdentity::SITE_URL) $display = 'delnavazan.com';
            $out[] = self::e($label) . ': <a href="' . self::e((string) $link['href']) . '" style="color:' . Tokens::color('text_secondary') . ';text-decoration:none;"><span dir="ltr" style="unicode-bidi:isolate;">' . self::e($display) . '</span></a>';
        }
        return implode(' · ', $out);
    }

    private static function logoUrl(string $override): string {
        if ($override !== '') return $override;
        if (function_exists('get_theme_file_uri')) {
            $uri = (string) get_theme_file_uri('assets/images/delnavazan-logo.png');
            if ($uri !== '') return $uri;
        }
        if (function_exists('apply_filters')) return (string) apply_filters('dzn_email_logo_url', BrandIdentity::SITE_URL . '/wp-content/themes/delnavazan/assets/images/delnavazan-logo.png');
        return BrandIdentity::SITE_URL . '/wp-content/themes/delnavazan/assets/images/delnavazan-logo.png';
    }

    private static function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
