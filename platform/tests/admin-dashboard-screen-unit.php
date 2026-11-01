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
if (count($sections) !== 11) throw new RuntimeException('Screen Options must expose eleven dashboard sections');
foreach (array('quick', 'kpis', 'actions', 'schedule', 'pipeline', 'activity', 'finance', 'coverage', 'attention', 'queue', 'reschedules') as $key) {
    if (!isset($sections[$key])) throw new RuntimeException('Missing dashboard section: ' . $key);
}

$saved = $screen::save(false, 'dzn_academy_sections', array('quick' => '1', 'kpis' => '0', 'unknown' => '1'));
if (!isset($saved['quick'], $saved['kpis']) || (int) $saved['quick'] !== 1 || (int) $saved['kpis'] !== 0) {
    throw new RuntimeException('Screen Options must persist known section values');
}
if (isset($saved['unknown'])) throw new RuntimeException('Screen Options must discard unknown section keys');
if (count($saved) !== 11) throw new RuntimeException('Screen Options must persist every known section key');

$GLOBALS['dzn_screen_meta']['dzn_academy_sections'] = array('quick' => 0, 'kpis' => 1);
$visible = $screen::visible();
if (!empty($visible['quick']) || empty($visible['kpis']) || empty($visible['actions'])) {
    throw new RuntimeException('Stored section visibility or unset defaults were not applied');
}
if ($screen::shows('quick') || !$screen::shows('actions')) throw new RuntimeException('shows() must reflect the stored map');

$wrong = $screen::save('sentinel', 'another_option', array('quick' => 1));
if ($wrong !== array()) throw new RuntimeException('save() must ignore a different screen option');

echo "Admin dashboard screen options unit passed\n";
