<?php
/**
 * Lightweight WordPress bootstrap for high-concurrency workers.
 *
 * Full wp-settings.php bootstraps every plugin and consumes ~50 MB per process,
 * which prevents a 100-process test inside a 4 GB disposable Docker VM. This
 * bootstrap uses WordPress SHORTINIT (database, options and core functions) and
 * loads only the Delnavazan classes the booking services need. It is test-only
 * and never used by the application.
 */
$wpRoot = getenv('DZN_STRESS_WP_ROOT') ?: '/var/www/html';
if (!is_file($wpRoot . '/wp-load.php')) throw new RuntimeException('WordPress root missing: ' . $wpRoot);
define('SHORTINIT', true);
require_once $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-includes/class-wp-user.php';
require_once ABSPATH . 'wp-includes/pluggable.php';
require_once ABSPATH . 'wp-includes/user.php';
require_once ABSPATH . 'wp-includes/http.php';
require_once ABSPATH . 'wp-includes/rest-api.php';
require_once ABSPATH . 'wp-includes/formatting.php';
require_once ABSPATH . 'wp-includes/link-template.php';
require_once ABSPATH . 'wp-includes/l10n.php';
require_once ABSPATH . 'wp-includes/default-constants.php';
require_once ABSPATH . 'wp-includes/class-wp-roles.php';
require_once ABSPATH . 'wp-includes/class-wp-role.php';
require_once ABSPATH . 'wp-includes/capabilities.php';
require_once ABSPATH . 'wp-includes/kses.php';
require_once ABSPATH . 'wp-includes/blocks.php';
if (!defined('WP_PLUGIN_URL')) define('WP_PLUGIN_URL', home_url('/wp-content/plugins'));
if (!defined('WPMU_PLUGIN_URL')) define('WPMU_PLUGIN_URL', home_url('/wp-content/mu-plugins'));
if (function_exists('wp_cookie_constants')) wp_cookie_constants();

if (!defined('DZN_PLATFORM_DIR')) define('DZN_PLATFORM_DIR', dirname(__DIR__) . '/');
if (!defined('DZN_PLATFORM_VERSION')) define('DZN_PLATFORM_VERSION', '0.1.0');
if (!defined('DZN_PLATFORM_SCHEMA_VERSION')) define('DZN_PLATFORM_SCHEMA_VERSION', '61');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Delnavazan\\Platform\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = DZN_PLATFORM_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) require $file;
});
