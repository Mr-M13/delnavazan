<?php
/** Unit contract for the per-user Academy Screen Options map. */
if (!function_exists('get_current_user_id')) { function get_current_user_id(): int { return 7; } }
if (!function_exists('get_user_meta')) { function get_user_meta(int $userId, string $key, bool $single = false) { return $GLOBALS['dzn_screen_meta'][$key] ?? ''; } }
if (!function_exists('esc_attr')) { function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html')) { function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('checked')) { function checked($checked, $current = true, bool $display = true): string { $result = (string) $checked === (string) $current ? " checked='checked'" : ''; if ($display) echo $result; return $result; } }
if (!function_exists('add_action')) { function add_action(...$args): void {} }
if (!function_exists('add_filter')) { function add_filter(...$args): void {} }

require_once dirname(__DIR__) . '/src/Admin/AcademyDashboardScreen.php';

$screen = 'Delnavazan\\Platform\\Admin\\AcademyDashboardScreen';
$sections = $screen::sections();
if (count($sections) !== 4) throw new RuntimeException('Screen Options must expose four Overview sections');
foreach (array('actions', 'schedule', 'finance', 'coverage') as $key) {
    if (!isset($sections[$key])) throw new RuntimeException('Missing dashboard section: ' . $key);
}

$saved = $screen::save(false, 'dzn_academy_sections', array('actions' => '1', 'schedule' => '0', 'unknown' => '1'));
if (!isset($saved['actions'], $saved['schedule']) || (int) $saved['actions'] !== 1 || (int) $saved['schedule'] !== 0) {
    throw new RuntimeException('Screen Options must persist known section values');
}
if (isset($saved['unknown'])) throw new RuntimeException('Screen Options must discard unknown section keys');
if (count($saved) !== 4) throw new RuntimeException('Screen Options must persist every known section key');

$GLOBALS['dzn_screen_meta']['dzn_academy_sections'] = array('actions' => 0, 'schedule' => 1);
$visible = $screen::visible();
if (!empty($visible['actions']) || empty($visible['schedule']) || empty($visible['finance'])) {
    throw new RuntimeException('Stored section visibility or unset defaults were not applied');
}
if ($screen::shows('actions') || !$screen::shows('schedule')) throw new RuntimeException('shows() must reflect the stored map');

$wrong = $screen::save('sentinel', 'another_option', array('actions' => 1));
if ($wrong !== array()) throw new RuntimeException('save() must ignore a different screen option');

echo "Admin dashboard screen options unit passed\n";
