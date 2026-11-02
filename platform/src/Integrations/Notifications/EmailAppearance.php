<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * One shared visual wrapper for every HTML email.
 *
 * WhatsApp, SMS and portal notifications are text/provider messages; this setting deliberately
 * changes only the HTML email renderer. It stores no credential and does not change copy.
 */
final class EmailAppearance {
    public const OPTION = 'dzn_email_appearance_v1';
    public const DEFAULT_FONT = 'tahoma';

    /** @return array{logo_url:string,font:string,footer_note:string} */
    public static function defaults(): array {
        return array(
            'logo_url' => self::defaultLogo(),
            'font' => self::DEFAULT_FONT,
            'footer_note' => '',
        );
    }

    /** @return array{logo_url:string,font:string,footer_note:string} */
    public static function values(): array {
        $stored = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        return self::sanitise(is_array($stored) ? $stored : array(), self::defaults());
    }

    /** @param array<string,mixed> $input */
    public static function save(array $input): array {
        if (!function_exists('current_user_can') || !current_user_can('dzn_manage_notification_templates')) {
            throw new \RuntimeException('email_appearance_forbidden');
        }
        $values = self::sanitise($input, self::values());
        if (!function_exists('update_option') || !update_option(self::OPTION, $values, false)) {
            if (!function_exists('get_option') || get_option(self::OPTION) !== $values) {
                throw new \RuntimeException('email_appearance_save_failed');
            }
        }
        return $values;
    }

    public static function fontStack(?string $font = null): string {
        $font ??= self::values()['font'];
        return match ($font) {
            'vazirmatn' => "Vazirmatn, Tahoma, 'Segoe UI', Arial, sans-serif",
            'system' => "'Segoe UI', -apple-system, BlinkMacSystemFont, Arial, sans-serif",
            default => "Tahoma, 'Segoe UI', Arial, sans-serif",
        };
    }

    public static function logoUrl(): string {
        $value = self::values()['logo_url'];
        return $value !== '' ? $value : self::defaultLogo();
    }

    public static function defaultLogo(): string {
        if (function_exists('get_theme_file_uri')) {
            $uri = (string) get_theme_file_uri('assets/images/delnavazan-logo.png');
            if ($uri !== '') return $uri;
        }
        return 'https://delnavazan.com/wp-content/themes/delnavazan/assets/images/delnavazan-logo.png';
    }

    /** @return array{logo_url:string,font:string,footer_note:string} */
    private static function sanitise(array $input, array $fallback): array {
        $font = isset($input['font']) && is_scalar($input['font']) ? sanitize_key((string) $input['font']) : '';
        if (!in_array($font, array('tahoma', 'vazirmatn', 'system'), true)) $font = (string) ($fallback['font'] ?? self::DEFAULT_FONT);
        $logo = isset($input['logo_url']) && is_scalar($input['logo_url']) ? trim((string) $input['logo_url']) : '';
        if ($logo === '') {
            $logo = (string) ($fallback['logo_url'] ?? self::defaultLogo());
        } else {
            $logo = function_exists('esc_url_raw') ? esc_url_raw($logo) : $logo;
            if (!preg_match('#^https://#i', $logo)) $logo = (string) ($fallback['logo_url'] ?? self::defaultLogo());
        }
        $note = isset($input['footer_note']) && is_scalar($input['footer_note']) ? sanitize_text_field((string) $input['footer_note']) : '';
        if (function_exists('mb_substr')) $note = mb_substr($note, 0, 240);
        return array('logo_url' => $logo, 'font' => $font, 'footer_note' => $note);
    }
}
