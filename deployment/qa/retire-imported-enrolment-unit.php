<?php
/**
 * Offline unit harness for the imported-enrolment retirement decision.
 *
 * Loads the real payload in library mode (no database, no WP-CLI run) and
 * exercises every pre-flight refusal branch plus the clean case. It never
 * talks to WordPress, MySQL, a provider or a network.
 *
 * Usage: php deployment/qa/retire-imported-enrolment-unit.php
 */
declare(strict_types=1);

define('WP_CLI', true);
define('DZN_RETIRE_PAYLOAD_LIBRARY', true);

require __DIR__ . '/retire-imported-enrolment.php';

$failures = 0;

/** @param array<int,string> $expected */
function dzn_retire_unit_expect(string $name, array $state, array $expected): void {
    global $failures;
    $actual = dzn_retire_decision($state);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, sprintf(
            "FAIL %s: expected [%s], got [%s]\n",
            $name,
            implode(',', $expected),
            implode(',', $actual)
        ));
        return;
    }
    echo "ok   {$name}\n";
}

/** @param array<string,mixed> $overrides */
function dzn_retire_unit_state(array $overrides = array()): array {
    $state = array(
        'expected_student_reference' => 'DZN-STU-000017',
        'expected_term_reference'    => 'DZN-TRM-000005',
        'now'                       => '2026-11-22 10:00:00',
        'enrolment'                 => array(
            'id'                  => 91,
            'uid'                 => '01ABC',
            'reference_code'      => 'ENR-000091',
            'record_model'        => 'legacy_phase1',
            'status'              => 'active',
            'student_id'          => 21,
            'teacher_id'          => 7,
            'course_id'           => 4,
            'archived_at_present' => 0,
            'updated_at'          => '2026-11-22 09:30:00',
        ),
        'student'                   => array(
            'id'             => 21,
            'reference_code' => 'DZN-STU-000017',
            'status'         => 'active',
            'available'      => true,
        ),
        'terms'                     => array(
            array(
                'id'                  => 55,
                'reference_code'      => 'DZN-TRM-000005',
                'record_model'        => 'legacy_phase1',
                'status'              => 'active',
                'payment_state'       => 'unknown',
                'lesson_allocation'   => 8,
                'archived_at_present' => 0,
            ),
        ),
        'lessons'                   => array(
            array(
                'id'                  => 501,
                'uid'                 => '01L1',
                'reference_code'      => 'DZN-LSN-000501',
                'record_model'        => 'legacy_phase1',
                'status'              => 'scheduled',
                'lesson_type'         => 'standard',
                'booking_request_id'  => 0,
                'starts_at_utc'       => '2026-11-24 06:30:00',
                'ends_at_utc'         => '2026-11-24 07:15:00',
                'archived_at_present' => 0,
            ),
            array(
                'id'                  => 502,
                'uid'                 => '01L2',
                'reference_code'      => 'DZN-LSN-000502',
                'record_model'        => 'legacy_phase1',
                'status'              => 'scheduled',
                'lesson_type'         => 'standard',
                'booking_request_id'  => 0,
                'starts_at_utc'       => '2026-12-01 06:30:00',
                'ends_at_utc'         => '2026-12-01 07:15:00',
                'archived_at_present' => 0,
            ),
        ),
        'import_evidence'           => 1,
        'canonical_enrolment_id'    => 0,
        'enrolment_reference_count' => 1,
        'downstream_reference_count' => 0,
        'lesson_reference_count'    => 0,
    );
    foreach ($overrides as $key => $value) {
        if ($key === 'enrolment' || $key === 'student') {
            $state[$key] = $value === null ? null : array_replace($state[$key], $value);
            continue;
        }
        $state[$key] = $value;
    }
    return $state;
}

dzn_retire_unit_expect('clean import is retirable', dzn_retire_unit_state(), array());

dzn_retire_unit_expect('missing enrolment', dzn_retire_unit_state(array('enrolment' => null)), array('enrolment_not_found'));

dzn_retire_unit_expect('canonical record model', dzn_retire_unit_state(array('enrolment' => array('record_model' => 'canonical_student_course_v1'))), array('enrolment_not_an_import'));

dzn_retire_unit_expect('already archived', dzn_retire_unit_state(array('enrolment' => array('archived_at_present' => 1))), array('enrolment_already_archived'));

dzn_retire_unit_expect('student mismatch', dzn_retire_unit_state(array('expected_student_reference' => 'DZN-STU-000099')), array('student_reference_mismatch'));

dzn_retire_unit_expect('term mismatch', dzn_retire_unit_state(array('expected_term_reference' => 'DZN-TRM-000099')), array('term_reference_mismatch'));

dzn_retire_unit_expect('student unavailable', dzn_retire_unit_state(array('student' => array('available' => false))), array('student_unavailable'));

dzn_retire_unit_expect('no import evidence', dzn_retire_unit_state(array('import_evidence' => 0)), array('import_evidence_missing'));

dzn_retire_unit_expect('canonical enrolment exists', dzn_retire_unit_state(array('canonical_enrolment_id' => 12)), array('canonical_enrolment_exists'));

dzn_retire_unit_expect('ambiguous reference', dzn_retire_unit_state(array('enrolment_reference_count' => 2)), array('enrolment_reference_ambiguous'));

dzn_retire_unit_expect('downstream references', dzn_retire_unit_state(array('downstream_reference_count' => 3)), array('downstream_reference_exists'));

dzn_retire_unit_expect('lesson references', dzn_retire_unit_state(array('lesson_reference_count' => 1)), array('lesson_reference_exists'));

dzn_retire_unit_expect('no term', dzn_retire_unit_state(array('terms' => array())), array('term_count_invalid'));

$two_terms = dzn_retire_unit_state();
$two_terms['terms'][] = $two_terms['terms'][0];
$two_terms['terms'][1]['id'] = 56;
dzn_retire_unit_expect('two terms', $two_terms, array('term_count_invalid'));

$paid = dzn_retire_unit_state();
$paid['terms'][0]['payment_state'] = 'paid';
dzn_retire_unit_expect('term recorded money', $paid, array('term_payment_recorded'));

$canonical_term = dzn_retire_unit_state();
$canonical_term['terms'][0]['record_model'] = 'canonical_enrolment_term_v1';
dzn_retire_unit_expect('term not an import', $canonical_term, array('term_not_an_import'));

$archived_term = dzn_retire_unit_state();
$archived_term['terms'][0]['archived_at_present'] = 1;
dzn_retire_unit_expect('term archived', $archived_term, array('term_already_archived'));

dzn_retire_unit_expect('no lessons', dzn_retire_unit_state(array('lessons' => array())), array('lesson_count_invalid'));

$completed = dzn_retire_unit_state();
$completed['lessons'][0]['status'] = 'completed';
dzn_retire_unit_expect('completed lesson', $completed, array('lesson_not_retirable'));

$archived_lesson = dzn_retire_unit_state();
$archived_lesson['lessons'][1]['archived_at_present'] = 1;
dzn_retire_unit_expect('archived lesson', $archived_lesson, array('lesson_already_archived'));

$replacement = dzn_retire_unit_state();
$replacement['lessons'][0]['lesson_type'] = 'replacement';
dzn_retire_unit_expect('non-standard lesson', $replacement, array('lesson_not_standard'));

$booked = dzn_retire_unit_state();
$booked['lessons'][0]['booking_request_id'] = 300;
dzn_retire_unit_expect('lesson linked to booking', $booked, array('lesson_linked_to_booking'));

$started = dzn_retire_unit_state();
$started['lessons'][0]['starts_at_utc'] = '2026-11-22 09:00:00';
dzn_retire_unit_expect('lesson already started', $started, array('lesson_already_started'));

$unscheduled = dzn_retire_unit_state();
$unscheduled['lessons'][0]['starts_at_utc'] = '';
dzn_retire_unit_expect('lesson without schedule', $unscheduled, array('lesson_already_started'));

$canonical_lesson = dzn_retire_unit_state();
$canonical_lesson['lessons'][0]['record_model'] = 'canonical_term_lesson_v1';
dzn_retire_unit_expect('canonical lesson', $canonical_lesson, array('lesson_not_retirable'));

if ($failures > 0) {
    fwrite(STDERR, sprintf("%d retirement-decision unit check(s) failed.\n", $failures));
    exit(1);
}

echo "Imported-enrolment retirement decision unit checks passed\n";
