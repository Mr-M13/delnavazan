<?php
namespace Delnavazan\Platform\Core\Support;

/**
 * Canonical communications design tokens.
 *
 * The values are the Delnavazan theme tokens from `theme/assets/css/theme.css`. Website CSS is the
 * visual authority; this class is the renderer-side projection used by transactional email, receipts
 * and statements. `platform/tests/communications-brand-contract.php` fails if the two drift, so the
 * web, email and document surfaces can never maintain three independent palettes.
 */
final class CommunicationDesignTokens {
    public const COLORS = array(
        'background' => '#FBF8F2',
        'surface' => '#F2ECE1',
        'surface_elevated' => '#FFFFFF',
        'surface_muted' => '#E9E2D5',
        'surface_sunken' => '#F6F1E8',
        'text_primary' => '#252726',
        'text_secondary' => '#104A4D',
        'text_muted' => '#5F625F',
        'action_primary' => '#1E7B70',
        'action_primary_hover' => '#104A4D',
        'action_secondary' => '#315C82',
        'border' => '#DDD6CA',
        'border_strong' => '#C9C0B0',
        'accent' => '#A33E48',
        'highlight' => '#D4AB4F',
        'highlight_ink' => '#6F4F10',
        'success' => '#2F6B4F',
        'warning' => '#945B16',
        'error' => '#A33A3A',
        'information' => '#315C82',
        'success_surface' => '#EAF3ED',
        'warning_surface' => '#FBF3E4',
        'error_surface' => '#F9ECEC',
        'information_surface' => '#EDF2F7',
        'success_border' => '#A9C9B5',
        'warning_border' => '#DFC08C',
        'error_border' => '#D9A5A5',
        'information_border' => '#A9BDD2',
        'focus' => '#315C82',
        'focus_on_dark' => '#FFF8ED',
        'on_action' => '#FFFFFF',
        'on_dark' => '#FFFDF8',
    );

    /** Raw channels for translucent elevation. Gold must match `highlight`, not a second gold. */
    public const RGB = array(
        'brand' => '16 74 77',
        'ink' => '37 39 38',
        'paper' => '251 248 242',
        'gold' => '212 171 79',
        'on_dark' => '255 253 248',
    );

    /** Screen and document stack: the bundled Vazirmatn variable font, then system Persian fallbacks. */
    public const FONT_BODY = 'Vazirmatn, Tahoma, Arial, sans-serif';

    /**
     * Email stack: Vazirmatn when the client has it, then broadly available system fonts. Email
     * clients cannot be forced to load a web font, so this is a deliberate fallback stack.
     */
    public const FONT_EMAIL = "Vazirmatn, Tahoma, 'Segoe UI', Arial, sans-serif";

    public const FONT_MONO = "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";

    public const TYPE = array(
        'body' => '16px',
        'small' => '14px',
        'xs' => '12px',
        'button' => '15px',
        'section_heading' => '24px',
        'page_heading' => '32px',
        'financial_amount' => '20px',
        'financial_total' => '24px',
        'leading_body' => '1.8',
        'leading_tight' => '1.45',
    );

    public static function color(string $role): string {
        return self::COLORS[$role] ?? self::COLORS['text_primary'];
    }

    public static function rgb(string $role): string {
        return self::RGB[$role] ?? self::RGB['ink'];
    }

    /** Base CSS shared by printable receipts and statements. */
    public static function documentCss(): string {
        $c = self::COLORS;
        return 'body{margin:0;padding:24px 16px;background:' . $c['background'] . ';color:' . $c['text_primary'] . ';font-family:' . self::FONT_BODY . ';font-size:' . self::TYPE['body'] . ';line-height:' . self::TYPE['leading_body'] . ';direction:rtl;}'
            . '.document{max-width:920px;margin:0 auto;background:' . $c['surface_elevated'] . ';border:1px solid ' . $c['border'] . ';border-radius:16px;overflow:hidden;box-shadow:0 16px 40px rgba(' . self::RGB['ink'] . ' / .08);}'
            . '.document-header{padding:24px 30px 18px;border-bottom:4px solid ' . $c['accent'] . ';background:' . $c['surface_elevated'] . ';}'
            . '.document-header h1{margin:14px 0 0;font-size:' . self::TYPE['page_heading'] . ';line-height:' . self::TYPE['leading_tight'] . ';color:' . $c['text_secondary'] . ';}'
            . '.eyebrow{margin:0;color:' . $c['action_primary'] . ';font-size:' . self::TYPE['xs'] . ';font-weight:700;letter-spacing:.08em;}'
            . '.document-body{padding:24px 30px;}'
            . '.document-footer{padding:16px 30px 22px;border-top:1px solid ' . $c['border'] . ';background:' . $c['background'] . ';color:' . $c['text_muted'] . ';font-size:' . self::TYPE['xs'] . ';line-height:' . self::TYPE['leading_body'] . ';}'
            . '.document-footer a{color:' . $c['text_secondary'] . ';text-decoration:none;}'
            . 'table{width:100%;border-collapse:collapse;font-size:' . self::TYPE['small'] . ';}'
            . 'th{background:' . $c['text_secondary'] . ';color:' . $c['on_action'] . ';text-align:start;padding:10px 8px;}'
            . 'td{border-bottom:1px solid ' . $c['border'] . ';padding:10px 8px;vertical-align:top;}'
            . '.total{margin:18px 0 0;padding:14px 16px;border-radius:10px;background:' . $c['text_secondary'] . ';color:' . $c['on_action'] . ';display:flex;justify-content:space-between;align-items:center;font-weight:700;}'
            . '.total strong{font-size:' . self::TYPE['financial_total'] . ';}'
            . '@media print{body{padding:0;background:' . $c['surface_elevated'] . ';}.document{border:0;box-shadow:none;border-radius:0;}thead{display:table-header-group;}tr{break-inside:avoid;}}'
            . '@media (max-width:720px){.document-header,.document-body,.document-footer{padding-inline:18px;}}';
    }
}
