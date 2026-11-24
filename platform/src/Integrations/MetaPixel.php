<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/**
 * Meta (Facebook & Instagram) Pixel for the public marketing site.
 *
 * Browser-side only: it prints Meta's standard base snippet on the public marketing site,
 * tracks PageView, and exposes `window.dznMetaPixel.track()` for the conversion moments the
 * site owns (currently the successful introductory booking-request submission). It never performs
 * advanced matching and never sends a name, email, phone number or any other personal data.
 *
 * The integration is inert unless an operator explicitly enables it and stores a pixel id in
 * Settings → Integrations → Meta Pixel. Signed-in users, administration requests, feeds,
 * previews and non-public hosts never receive the snippet. A cookie consent manager can deny
 * marketing cookies with `dzn_marketing_consent=denied`, or a site plugin can return false
 * from the `dzn_meta_pixel_consent_granted` filter.
 *
 * Server-side Conversions API delivery is deliberately not part of this integration: it
 * requires a business access token and customer-information parameters, and is a separate
 * reviewed phase.
 */
final class MetaPixel {
    public const PROVIDER = 'meta_pixel';
    public const RESULT_OPTION = 'dzn_meta_pixel_connection_result';
    private const PIXEL_PATTERN = '/^[0-9]{5,20}$/D';

    /** Hosts that serve the public marketing site. Never track a staging or local host. */
    private const PUBLIC_HOSTS = array('delnavazan.com', 'www.delnavazan.com');

    public static function register(): void {
        add_action('wp_head', array(__CLASS__, 'renderBaseCode'), 5);
        add_action('wp_body_open', array(__CLASS__, 'renderNoScript'), 5);
    }

    /** Validated, stored pixel id. Empty when unset or malformed. */
    public static function pixelId(): string {
        $value = self::setting('pixel_id');
        return preg_match(self::PIXEL_PATTERN, $value) === 1 ? $value : '';
    }

    public static function enabled(): bool {
        return self::setting('enabled') === '1';
    }

    public static function configured(): bool {
        return self::enabled() && self::pixelId() !== '';
    }

    /** Whether the base snippet may print for this request. */
    public static function shouldRender(): bool {
        if (!self::configured()) return false;
        if (is_admin()) return false;
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return false;
        if (function_exists('wp_doing_cron') && wp_doing_cron()) return false;
        if (function_exists('wp_is_json_request') && wp_is_json_request()) return false;
        if (function_exists('is_feed') && is_feed()) return false;
        if (function_exists('is_preview') && is_preview()) return false;
        if (function_exists('is_customize_preview') && is_customize_preview()) return false;
        if (function_exists('is_robots') && is_robots()) return false;
        // Portal and account pages belong to signed-in people; never advertise to them.
        if (is_user_logged_in()) return false;
        if (!self::isPublicHost()) return false;
        if (!self::consentGranted()) return false;
        return (bool) apply_filters('dzn_meta_pixel_should_render', true);
    }

    /** The pixel id is public configuration; the page source already carries it. */
    public static function renderBaseCode(): void {
        if (!self::shouldRender()) return;
        $pixel = self::pixelId();
        if ($pixel === '') return;
        echo "\n<!-- Delnavazan Meta Pixel: browser-side PageView and explicit conversion events only. No personal data is sent. -->\n";
        echo "<script>\n";
        echo "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');\n";
        echo "fbq('init','" . esc_js($pixel) . "');\n";
        echo "fbq('track','PageView');\n";
        echo "window.dznMetaPixel={track:function(name,params,eventId){if(typeof window.fbq!=='function'){return;}var options=eventId?{eventID:String(eventId)}:undefined;window.fbq('track',String(name),params||{},options);}};\n";
        echo "</script>\n";
    }

    /** The standard no-JavaScript fallback image, under the same gates as the base snippet. */
    public static function renderNoScript(): void {
        if (!self::shouldRender()) return;
        $pixel = self::pixelId();
        if ($pixel === '') return;
        $src = esc_url('https://www.facebook.com/tr?id=' . $pixel . '&ev=PageView&noscript=1');
        echo '<noscript><img height="1" width="1" style="display:none" alt="" src="' . $src . '"></noscript>' . "\n";
    }

    /** Consent is opt-out by default; a consent manager or the filter can deny marketing cookies. */
    public static function consentGranted(): bool {
        $cookie = isset($_COOKIE['dzn_marketing_consent']) ? strtolower(trim((string) $_COOKIE['dzn_marketing_consent'])) : '';
        if (in_array($cookie, array('denied', 'false', '0', 'no'), true)) return false;
        return (bool) apply_filters('dzn_meta_pixel_consent_granted', true);
    }

    /** Local-only validation. It never contacts Meta, so it proves configuration, not delivery. */
    public static function validateConfiguration(): array {
        if (self::pixelId() === '') {
            return array('state' => 'failed', 'code' => self::setting('pixel_id') === '' ? 'pixel_id_missing' : 'pixel_id_invalid');
        }
        if (!self::enabled()) return array('state' => 'blocked', 'code' => 'disabled');
        return array('state' => 'success', 'code' => 'configuration_valid');
    }

    /** Secret-free status for the integrations control plane. */
    public static function status(): array {
        $last = (array) get_option(self::RESULT_OPTION, array());
        return array(
            'enabled' => self::enabled(),
            'pixel_id_present' => self::pixelId() !== '',
            'browser_pixel' => self::configured() ? 'configured' : 'not_configured',
            'public_hosts' => self::publicHosts(),
            'last_validation' => array(
                'state' => (string) ($last['state'] ?? 'not_run'),
                'code' => (string) ($last['code'] ?? ''),
                'at' => (string) ($last['at'] ?? ''),
            ),
        );
    }

    private static function isPublicHost(): bool {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host === '') return false;
        return in_array($host, self::publicHosts(), true);
    }

    /** @return string[] */
    private static function publicHosts(): array {
        $hosts = (array) apply_filters('dzn_meta_pixel_public_hosts', self::PUBLIC_HOSTS);
        return array_values(array_filter(array_map(static fn($host): string => strtolower(trim((string) $host)), $hosts)));
    }

    private static function setting(string $field): string {
        try {
            return trim((new IntegrationSettingsService())->value(self::PROVIDER, $field));
        } catch (\Throwable) {
            return '';
        }
    }
}
