<?php
/** Focused source contract for instrument-scoped availability invariants. */
$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Core/Application/TeacherAvailabilityService.php');
$repo = file_get_contents($root . '/src/Core/Infrastructure/Repository/TeacherAvailabilityRepository.php');
$controller = file_get_contents($root . '/src/Portals/TeacherAvailabilitySelfServiceController.php');
$migration = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$bridge = file_get_contents(dirname($root) . '/theme/inc/platform-bridge.php');
$booking = file_get_contents($root . '/src/Core/Application/BookingAvailabilityAssessmentService.php');
foreach (array('service'=>$service,'repo'=>$repo,'controller'=>$controller,'migration'=>$migration,'bridge'=>$bridge,'booking'=>$booking) as $name=>$source) {
    if (false === $source) throw new RuntimeException('Unable to read '.$name.' source');
}
$mustContain = static function (string $source, string $needle, string $message): void {
    if (false === strpos($source, $needle)) throw new RuntimeException($message);
};
$mustContain($repo, 'instrument_id IS NULL AND NOT EXISTS', 'Legacy rules must inherit only until an instrument is configured');
$mustContain($repo, 'teacher_availability_instrument_states', 'Configured-empty instrument state must be persisted');
$mustContain($service, 'editableOwnRule', 'Self-service edits must resolve inherited rules into instrument-owned copies');
$mustContain($service, 'assertInstrumentForTeacher', 'Instrument mutations must validate teaching eligibility');
$mustContain($service, 'teacher_self_service_split', 'Partial removal must persist split ranges');
$mustContain($controller, "admin_post_dzn_teacher_availability_range_remove", 'Range removal action must be registered');
$mustContain($controller, "configureOwnInstrument", 'Empty instrument schedules must be an explicit action');
$mustContain($repo, 'retireOwnInstrumentRules', 'Configured-empty schedules must retire existing instrument rules');
$mustContain($migration, 'DROP INDEX rule_identity', 'Instrument migration must remove the legacy rule identity');
$mustContain($migration, 'rule_identity_instrument', 'Instrument migration must install the instrument-aware identity');
$mustContain($bridge, "'instrument_id' => " . '$instrument_id', 'Coverage facts must retain their instrument scope');

// Small executable interval probes model the two behavioural contracts that do not need WP.
$subtract = static function (int $start, int $end, int $removeStart, int $removeEnd): array {
    $parts = array();
    if ($start < $removeStart) $parts[] = array($start, $removeStart);
    if ($removeEnd < $end) $parts[] = array($removeEnd, $end);
    return $parts;
};
if ($subtract(9 * 60, 17 * 60, 12 * 60, 13 * 60) !== array(array(9 * 60, 12 * 60), array(13 * 60, 17 * 60))) throw new RuntimeException('Split-range removal contract failed');
if ($subtract(9 * 60, 17 * 60, 9 * 60, 17 * 60) !== array()) throw new RuntimeException('Full-range removal contract failed');
$mustContain($booking, 'occupancy->overlapping', 'Booking validation must retain teacher-wide shared occupancy across instruments');
echo "Teacher instrument availability contract passed\n";
