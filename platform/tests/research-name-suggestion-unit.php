<?php
/** Exercise public intake using a failure-injectable WordPress storage boundary. */
require_once __DIR__ . '/../src/Public/ResearchNameSuggestionController.php';
use Delnavazan\Platform\Public\ResearchNameSuggestionController as Inbox;
$options = $transients = $posts = [];
$storageFails = false;
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid' && $action === Inbox::ACTION; }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function wp_salt($type) { return 'test-only-secret'; }
function wp_slash($value) { return addslashes($value); }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $transients; $transients[$key] = $value; }
function add_option($key, $value, $deprecated, $autoload) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function get_option($key) { global $options; return $options[$key] ?? false; }
function delete_option($key) { global $options; unset($options[$key]); }
function wp_insert_post($post, $errors) { global $posts, $storageFails; if ($storageFails) return false; $posts[] = $post; return count($posts); }
function is_wp_error($value) { return false; }
function register_post_type($name, $config) { $GLOBALS['inbox_config'] = $config; }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$valid = ['_wpnonce'=>'valid','website'=>'','project_name'=>'هم‌آوا'];
check(Inbox::receive($valid, '192.0.2.1', 'GET') === 'invalid', 'GET must not write');
foreach ([null, [], 'wrong'] as $nonce) check(Inbox::receive(array_replace($valid, ['_wpnonce'=>$nonce]), '192.0.2.1', 'POST') === 'expired', 'Bad nonce must not write');
foreach (['', '  ', "\u{200c}", [], str_repeat('ن',121), '<b></b>'] as $name) check(Inbox::receive(array_replace($valid, ['project_name'=>$name]), '192.0.2.1', 'POST') === 'invalid', 'Malformed/empty/overlong names rejected');
check(Inbox::receive(array_replace($valid, ['website'=>'bot']), '192.0.2.1', 'POST') === 'invalid', 'Honeypot rejected');
check(count($posts) === 0, 'Rejected submissions must not be stored');
check(Inbox::receive($valid, '192.0.2.1', 'POST') === 'received', 'Guest Persian suggestion accepted');
check($posts[0]['post_status'] === 'private' && $posts[0]['post_author'] === 0, 'Suggestion private and anonymous');
check(Inbox::receive($valid, '192.0.2.1', 'POST') === 'received' && count($posts) === 1, 'Retry must not duplicate');
check(Inbox::receive(array_replace($valid, ['project_name'=>'Other']), '192.0.2.1', 'POST') === 'limited', 'Burst must be limited');
check(Inbox::receive(array_replace($valid, ['project_name'=>str_repeat('ن',120)]), '192.0.2.2', 'POST') === 'received', '120 Unicode characters allowed');
$storageFails = true;
check(Inbox::receive($valid, '192.0.2.3', 'POST') === 'error', 'Failed insert must not report success');
check(!$options, 'Locks released on storage failure');
$storageFails = false;
check(Inbox::receive($valid, '192.0.2.3', 'POST') === 'received', 'Retry possible after storage failure');
$key='dzn_research_name_'.hash_hmac('sha256','192.0.2.4',wp_salt('nonce')).'_lock';
$options[$key]=time();
check(Inbox::receive($valid,'192.0.2.4','POST')==='limited','Concurrent request blocked');
$options[$key]=time()-121;
check(Inbox::receive($valid,'192.0.2.4','POST')==='limited' && !isset($options[$key]),'Stale lock released for next retry');
Inbox::registerInbox();
$c=$GLOBALS['inbox_config'];
check(!$c['public'] && !$c['show_in_rest'] && !$c['publicly_queryable'], 'Inbox must not be public or exposed by REST');
check($c['capabilities']['read_private_posts']==='manage_options' && $c['capabilities']['edit_posts']==='manage_options','Only administrators can review');
define('ABSPATH', __DIR__);
$copy=require __DIR__.'/../../theme/template-parts/recording/project-copy.php';
check(array_column($copy['fa']['sections'],'id')===array_column($copy['en']['sections'],'id'),'Translation must have matching sections in the same order');
foreach ($copy['fa']['sections'] as $i=>$s) {
    check(count($s['paragraphs'])===count($copy['en']['sections'][$i]['paragraphs']),'Translation must contain every paragraph');
    check(count($s['steps']??[])===count($copy['en']['sections'][$i]['steps']??[]),'Translation must contain every step');
}
check(end($copy['fa']['sections'])['id']==='care','Data and participation note belongs at the end');
echo "Research name suggestion tests passed\n";
