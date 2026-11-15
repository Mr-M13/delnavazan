<?php
// Standalone transport and deduplication contract. Never makes a real network request.
namespace Delnavazan\Platform\Integrations {
    $GLOBALS['dzn_ads_options'] = array();
    $GLOBALS['dzn_ads_posts'] = array();
    function get_option($key, $default = false) { return $GLOBALS['dzn_ads_options'][$key] ?? $default; }
    function add_option($key, $value, $deprecated = '', $autoload = null) {
        if (array_key_exists($key, $GLOBALS['dzn_ads_options'])) return false;
        $GLOBALS['dzn_ads_options'][$key] = $value; return true;
    }
    function update_option($key, $value, $autoload = null) { $GLOBALS['dzn_ads_options'][$key] = $value; return true; }
    function delete_option($key) { unset($GLOBALS['dzn_ads_options'][$key]); return true; }
    function home_url($path) { return 'https://delnavazan.com' . $path; }
    function wp_json_encode($data) { return json_encode($data); }
    function wp_remote_post($url, $args) {
        $GLOBALS['dzn_ads_posts'][] = array($url, $args);
        return array('response' => array('code' => $GLOBALS['dzn_ads_http_status'] ?? 200));
    }
    function is_wp_error($result) { return false; }
    function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
    function add_action($name, $callback) {}
    function wp_next_scheduled($name) { return true; }
    function wp_schedule_event($time, $schedule, $name) {}
    class FakeDb {
        public string $prefix = 'wp_';
        public function prepare($sql, ...$args) { return $sql; }
        public function get_var($sql) { return 42; }
    }
    $GLOBALS['wpdb'] = new FakeDb();
    define('DZN_CHATGPT_ADS_API_KEY', 'unit-test-secret');
    require_once __DIR__ . '/../src/Integrations/ChatGptAdsConversionWorker.php';
    function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
    $method = new \ReflectionMethod(ChatGptAdsConversionWorker::class, 'send');
    $row = (object) array('id' => 42, 'uid' => '01KABCDEFGHJKLMNPQRSTVWXYZ', 'confirmed_at' => '2026-11-15 00:00:00');
    check(strlen($row->uid) === 26, 'fixture UID');
    $method->invoke(null, $row);
    check(count($GLOBALS['dzn_ads_posts']) === 1, 'one conversion request');
    [$url, $args] = $GLOBALS['dzn_ads_posts'][0];
    check(str_contains($url, 'pid=6wyWvkz5rmQXEPAC4T2Zm9'), 'pixel id');
    $body = json_decode($args['body'], true);
    check($body['events'][0]['type'] === 'appointment_scheduled', 'event type');
    check($body['events'][0]['id'] === 'dzn-intro-' . $row->uid, 'stable event id');
    check($body['events'][0]['timestamp_ms'] === strtotime($row->confirmed_at . ' UTC') * 1000, 'timestamp');
    check($body['events'][0]['data']['type'] === 'customer_action', 'action');
    check(($GLOBALS['dzn_ads_options']['dzn_ads_intro_42']['status'] ?? '') === 'delivered', 'success persistence');
    $method->invoke(null, $row);
    check(count($GLOBALS['dzn_ads_posts']) === 1, 'no repeat after delivered');
    $retry = (object) array('id' => 43, 'uid' => '01KABCDEFGHJKLMNPQRSTVWXY0', 'confirmed_at' => '2026-11-15 00:00:00');
    $GLOBALS['dzn_ads_http_status'] = 503;
    $method->invoke(null, $retry);
    check(($GLOBALS['dzn_ads_options']['dzn_ads_intro_43']['status'] ?? '') === 'retry', 'retry state');
    $method->invoke(null, $retry);
    check(count($GLOBALS['dzn_ads_posts']) === 2, 'backoff prevents immediate repeat');
    echo "ChatGPT Ads conversion unit: PASS\n";
}
