<?php
/**
 * Standalone Meta Pixel render contract.
 *
 * Proves the pixel is inert unless an operator enables it with a valid id, and that the public
 * snippet is limited to the public host, anonymous visitors and consented requests. It never
 * contacts Meta and never proves delivery or attribution.
 */

$GLOBALS['dzn_meta_pixel_options'] = array();
$GLOBALS['dzn_meta_pixel_filters'] = array();
$GLOBALS['dzn_meta_pixel_admin'] = false;
$GLOBALS['dzn_meta_pixel_logged_in'] = false;
$GLOBALS['dzn_meta_pixel_home'] = 'https://delnavazan.com/';

function get_option($key, $default = false) { return $GLOBALS['dzn_meta_pixel_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['dzn_meta_pixel_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['dzn_meta_pixel_options'][$key]); return true; }
function add_action($name, $callback) {}
function apply_filters($tag, $value) { return $GLOBALS['dzn_meta_pixel_filters'][$tag] ?? $value; }
function home_url($path = '/') { return rtrim($GLOBALS['dzn_meta_pixel_home'], '/') . '/' . ltrim((string) $path, '/'); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function is_admin() { return (bool) $GLOBALS['dzn_meta_pixel_admin']; }
function is_user_logged_in() { return (bool) $GLOBALS['dzn_meta_pixel_logged_in']; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_is_json_request() { return false; }
function is_feed() { return false; }
function is_preview() { return false; }
function is_customize_preview() { return false; }
function is_robots() { return false; }
function esc_js($value) { return addslashes((string) $value); }
function esc_url($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }

require_once __DIR__ . '/../src/Core/Application/IntegrationSettingsService.php';
require_once __DIR__ . '/../src/Integrations/MetaPixel.php';

use Delnavazan\Platform\Integrations\MetaPixel;

function dzn_meta_pixel_configure(string $pixel, string $enabled): void {
    $GLOBALS['dzn_meta_pixel_options']['dzn_integration_settings_v1'] = array(
        'meta_pixel' => array('pixel_id' => $pixel, 'enabled' => $enabled),
    );
}

function dzn_meta_pixel_reset_request(): void {
    $GLOBALS['dzn_meta_pixel_admin'] = false;
    $GLOBALS['dzn_meta_pixel_logged_in'] = false;
    $GLOBALS['dzn_meta_pixel_home'] = 'https://delnavazan.com/';
    $GLOBALS['dzn_meta_pixel_filters'] = array();
    unset($_COOKIE['dzn_marketing_consent']);
}

function dzn_meta_pixel_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function dzn_meta_pixel_render(): string {
    ob_start();
    MetaPixel::renderBaseCode();
    return (string) ob_get_clean();
}

// Disabled is inert even with a valid id.
dzn_meta_pixel_reset_request();
dzn_meta_pixel_configure('123456789012345', '0');
dzn_meta_pixel_assert(!MetaPixel::configured(), 'disabled pixel must not be configured');
dzn_meta_pixel_assert(dzn_meta_pixel_render() === '', 'disabled pixel must print nothing');

// A malformed id is refused, never echoed.
dzn_meta_pixel_configure('not-a-pixel', '1');
dzn_meta_pixel_assert(MetaPixel::pixelId() === '', 'malformed pixel id must be rejected');
dzn_meta_pixel_assert(!MetaPixel::configured(), 'malformed pixel id must not be configured');
dzn_meta_pixel_assert(dzn_meta_pixel_render() === '', 'malformed pixel id must print nothing');

// Enabled on the public host prints the base snippet and the conversion helper only.
dzn_meta_pixel_configure('123456789012345', '1');
dzn_meta_pixel_reset_request();
dzn_meta_pixel_assert(MetaPixel::shouldRender(), 'public anonymous request should render');
$html = dzn_meta_pixel_render();
dzn_meta_pixel_assert(str_contains($html, "fbq('init', '123456789012345')"), 'pixel init');
dzn_meta_pixel_assert(str_contains($html, "fbq('track', 'PageView')"), 'PageView');
dzn_meta_pixel_assert(str_contains($html, 'window.dznMetaPixel'), 'conversion helper');
dzn_meta_pixel_assert(str_contains($html, 'facebook.com/tr?id=123456789012345&ev=PageView&noscript=1'), 'canonical noscript fallback with a literal ampersand');
dzn_meta_pixel_assert(!str_contains($html, '&#038;'), 'no HTML-escaped ampersand in the fallback URL');
dzn_meta_pixel_assert(!str_contains($html, 'em:'), 'no advanced-matching email field');
dzn_meta_pixel_assert(!str_contains($html, 'access_token'), 'no credential in page source');

// Off the public host, in admin, and for signed-in users the pixel is silent.
$GLOBALS['dzn_meta_pixel_home'] = 'https://staging.example.com/';
dzn_meta_pixel_assert(!MetaPixel::shouldRender(), 'non-public host must not render');
$GLOBALS['dzn_meta_pixel_home'] = 'https://delnavazan.com/';
$GLOBALS['dzn_meta_pixel_admin'] = true;
dzn_meta_pixel_assert(!MetaPixel::shouldRender(), 'admin request must not render');
$GLOBALS['dzn_meta_pixel_admin'] = false;
$GLOBALS['dzn_meta_pixel_logged_in'] = true;
dzn_meta_pixel_assert(!MetaPixel::shouldRender(), 'signed-in user must not render');

// Consent is an explicit gate for a future consent manager.
dzn_meta_pixel_reset_request();
$_COOKIE['dzn_marketing_consent'] = 'denied';
dzn_meta_pixel_assert(!MetaPixel::shouldRender(), 'denied marketing consent must not render');
unset($_COOKIE['dzn_marketing_consent']);
$GLOBALS['dzn_meta_pixel_filters']['dzn_meta_pixel_consent_granted'] = false;
dzn_meta_pixel_assert(!MetaPixel::shouldRender(), 'consent filter denial must not render');
dzn_meta_pixel_reset_request();
$GLOBALS['dzn_meta_pixel_filters']['dzn_meta_pixel_should_render'] = false;
dzn_meta_pixel_assert(!MetaPixel::shouldRender(), 'render filter denial must not render');

// Local validation reports configuration only.
dzn_meta_pixel_reset_request();
dzn_meta_pixel_configure('123456789012345', '1');
$validation = MetaPixel::validateConfiguration();
dzn_meta_pixel_assert($validation['state'] === 'success' && $validation['code'] === 'configuration_valid', 'valid configuration');
dzn_meta_pixel_configure('', '1');
dzn_meta_pixel_assert(MetaPixel::validateConfiguration()['code'] === 'pixel_id_missing', 'missing pixel id');
dzn_meta_pixel_configure('123456789012345', '0');
dzn_meta_pixel_assert(MetaPixel::validateConfiguration()['state'] === 'blocked', 'disabled pixel is blocked');

echo "Meta Pixel unit: PASS\n";
