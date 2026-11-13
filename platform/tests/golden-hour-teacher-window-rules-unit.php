<?php
use Delnavazan\Platform\Core\Application\Finance\GoldenHourTeacherWindowProjection;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/Finance/GoldenHourTeacherWindowProjection.php';

$assert = static function (bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};

$policy = array(
	'area' => 'GLOBAL_IRAN',
	'enabled' => true,
	'timezone' => 'UTC',
	'weekdays' => array(6),
	'start' => '10:00',
	'end' => '12:00',
	'periods' => array(array('start' => '10:00', 'end' => '12:00')),
	'bonus_basis_points' => 1000,
	'rules' => array(
		array('weekdays' => array(6, 7), 'start' => '10:00', 'end' => '12:00', 'bonus_basis_points' => 1000),
		array('weekdays' => array(1), 'start' => '18:00', 'end' => '20:00', 'bonus_basis_points' => 2000),
	),
	'rules_configured' => true,
);

$projection = GoldenHourTeacherWindowProjection::forPolicy(
	$policy,
	'UTC',
	new DateTimeImmutable('2026-11-14 00:00:00', new DateTimeZone('UTC')),
	7,
	false
);

$byWeekday = array();
foreach ($projection['days'] as $day) $byWeekday[(int) $day['weekday']] = $day;

$assert(isset($byWeekday[6]) && $byWeekday[6]['intervals'][0]['start'] === '10:00' && $byWeekday[6]['intervals'][0]['end'] === '12:00', 'Saturday must use its own configured rule.');
$assert(isset($byWeekday[7]) && $byWeekday[7]['intervals'][0]['start'] === '10:00', 'Sunday must receive the weekend rule, not the first rule only.');
$assert(isset($byWeekday[1]) && $byWeekday[1]['intervals'][0]['start'] === '18:00' && $byWeekday[1]['intervals'][0]['end'] === '20:00', 'Monday must use its own configured rule.');
$assert(empty($byWeekday[2]['intervals']), 'A weekday with no configured rule must stay empty.');
$assert($byWeekday[6]['intervals'][0]['percentage_label'] === '10', 'The weekend rule must keep its own bonus percentage.');
$assert($byWeekday[1]['intervals'][0]['percentage_label'] === '20', 'The Monday rule must keep its own bonus percentage.');

$legacy = GoldenHourTeacherWindowProjection::forPolicy(
	array(
		'enabled' => false,
		'timezone' => 'UTC',
		'weekdays' => array(3),
		'start' => '09:00',
		'end' => '11:00',
		'periods' => array(array('start' => '09:00', 'end' => '11:00')),
		'bonus_basis_points' => 0,
		'rules' => array(),
	),
	'UTC',
	new DateTimeImmutable('2026-11-14 00:00:00', new DateTimeZone('UTC')),
	7,
	false
);
$legacyWednesday = null;
foreach ($legacy['days'] as $day) if ((int) $day['weekday'] === 3) $legacyWednesday = $day;
$assert($legacyWednesday !== null && $legacyWednesday['intervals'][0]['start'] === '09:00', 'A disabled bonus with configured legacy periods must still expose its designated windows.');

echo "golden-hour-teacher-window-rules-unit: OK\n";
