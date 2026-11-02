<?php
/**
 * Pure consent purpose, recording-gate and ML-eligibility policy tests.
 *
 * No WordPress, database or network is used. These tests prove the fail-closed decision tables
 * independently of any frontend control or stored projection.
 */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/ConsentPurposeRegistry.php';
require_once $root . '/src/Core/Application/RecordingConsentPolicy.php';
require_once $root . '/src/Core/Application/MlDatasetEligibilityPolicy.php';

use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry as P;
use Delnavazan\Platform\Core\Application\RecordingConsentPolicy;
use Delnavazan\Platform\Core\Application\MlDatasetEligibilityPolicy;

$fail = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

/* Purpose separation and registry shape. */
$fail(P::has(P::TEACHER_RECORDING) && P::has(P::TEACHER_RESEARCH), 'Teacher recording and research must be independent purposes');
$fail(P::has(P::STUDENT_RECORDING) && P::has(P::STUDENT_RESEARCH), 'Student recording and research must be independent purposes');
$fail(P::audience(P::TEACHER_RECORDING) !== P::audience(P::TEACHER_RESEARCH), 'Teacher purposes must cite different notices');
$fail(P::audience(P::STUDENT_RECORDING) !== P::audience(P::STUDENT_RESEARCH), 'Student purposes must cite different notices');
$fail(P::isPositive(P::STUDENT_RECORDING, 'allow') && !P::isPositive(P::STUDENT_RECORDING, 'decline'), 'Recording allow is the only positive value');
$fail(P::isPositive(P::STUDENT_RESEARCH, 'opt_in') && !P::isPositive(P::STUDENT_RESEARCH, 'withdraw'), 'Research opt-in is the only positive value');
$fail(!P::isPositive(P::STUDENT_RESEARCH, 'allow'), 'Recording allow must never satisfy research');
$fail(P::isWithdrawal(P::STUDENT_RECORDING, 'decline') && P::isWithdrawal(P::TEACHER_RESEARCH, 'withdraw'), 'Withdrawal values must be explicit');
$fail(P::isOptional(P::TEACHER_RESEARCH) && P::isOptional(P::STUDENT_RESEARCH), 'Research must remain optional');
$fail(P::guardianAllowed(P::STUDENT_RESEARCH) && !P::guardianAllowed(P::TEACHER_RESEARCH), 'Guardian path is Student-only');
try { P::get('unknown_future_purpose'); $fail(false, 'Unknown purpose must be refused'); } catch (InvalidArgumentException $e) { $fail($e->getMessage() === 'consent_purpose_invalid', 'Unknown purpose reason'); }

$gate = new RecordingConsentPolicy();
$base = array(
    'lesson_found' => true, 'lesson_canonical' => true, 'lesson_authorised' => true,
    'teacher_terms_current' => true, 'teacher_recording_current' => true,
    'teacher_policy_current' => true, 'student_found' => true, 'student_capacity' => 'adult',
    'student_recording_current' => true, 'student_policy_current' => true,
    'guardian_required' => false, 'guardian_authority_valid' => false,
    'guardian_recording_current' => false, 'guardian_policy_current' => true,
    'blocking_withdrawal' => false,
);
$fail($gate->evaluate($base) === array('authorised' => true, 'reason' => 'authorised'), 'Complete adult facts authorise');
$deny = static function (array $override, string $reason) use ($gate, $base, $fail): void {
    $result = $gate->evaluate(array_merge($base, $override));
    $fail($result['authorised'] === false && $result['reason'] === $reason, 'Expected denial ' . $reason . ' got ' . $result['reason']);
};
foreach (array(
    array(array('lesson_found' => false), 'lesson_not_found'),
    array(array('lesson_canonical' => false), 'lesson_not_canonical'),
    array(array('lesson_authorised' => false), 'lesson_not_authorised'),
    array(array('blocking_withdrawal' => true), 'withdrawal_blocking'),
    array(array('teacher_terms_current' => false), 'teacher_terms_missing'),
    array(array('teacher_recording_current' => false, 'teacher_recording_decision' => 'decline'), 'teacher_recording_declined'),
    array(array('teacher_recording_current' => false), 'teacher_recording_missing'),
    array(array('teacher_policy_current' => false), 'teacher_policy_superseded'),
    array(array('student_found' => false), 'student_missing'),
    array(array('student_capacity' => 'unknown'), 'student_capacity_unresolved'),
    array(array('student_recording_current' => false, 'student_recording_declined' => true), 'student_recording_declined'),
    array(array('student_recording_current' => false), 'student_recording_missing'),
    array(array('student_policy_current' => false), 'student_policy_superseded'),
) as [$override, $reason]) $deny($override, $reason);

$minor = array_merge($base, array('student_capacity' => 'minor', 'guardian_required' => true, 'guardian_authority_valid' => true, 'guardian_recording_current' => true));
$fail($gate->evaluate($minor)['authorised'] === true, 'Verified minor guardian facts authorise');
foreach (array(
    array(array('guardian_authority_valid' => false), 'guardian_authority_missing'),
    array(array('guardian_recording_current' => false, 'guardian_recording_declined' => true), 'guardian_recording_declined'),
    array(array('guardian_recording_current' => false), 'guardian_recording_missing'),
    array(array('guardian_policy_current' => false), 'guardian_policy_superseded'),
) as [$override, $reason]) {
    $result = $gate->evaluate(array_merge($minor, $override));
    $fail($result['authorised'] === false && $result['reason'] === $reason, 'Minor expected ' . $reason . ' got ' . $result['reason']);
}
$noResearch = array_merge($base, array('student_recording_current' => true));
$fail($gate->evaluate($noResearch)['authorised'] === true, 'Recording gate must ignore research status');
$fail($gate->evaluate(array())['reason'] === 'policy_unavailable', 'Missing facts must fail closed');

$ml = new MlDatasetEligibilityPolicy();
$mlBase = array(
    'provenance_valid' => true, 'recording_authorised' => true, 'student_capacity' => 'adult',
    'teacher_research_current' => true, 'teacher_policy_current' => true,
    'student_research_current' => true, 'student_policy_current' => true,
    'guardian_authority_valid' => false, 'guardian_research_current' => false,
    'guardian_policy_current' => true, 'excluded' => false, 'quarantined' => false,
    'retention_restriction' => false,
);
$fail($ml->evaluate($mlBase) === array('eligible' => true, 'reason' => 'eligible'), 'Complete adult research facts are eligible');
$mlDeny = static function (array $override, string $reason) use ($ml, $mlBase, $fail): void {
    $result = $ml->evaluate(array_merge($mlBase, $override));
    $fail($result['eligible'] === false && $result['reason'] === $reason, 'Expected ML denial ' . $reason . ' got ' . $result['reason']);
};
foreach (array(
    array(array('provenance_valid' => false), 'provenance_unavailable'),
    array(array('recording_authorised' => false), 'recording_not_authorised'),
    array(array('teacher_research_current' => false), 'teacher_research_missing'),
    array(array('teacher_research_current' => false, 'teacher_research_withdrawn' => true), 'teacher_research_withdrawn'),
    array(array('teacher_policy_current' => false), 'teacher_policy_superseded'),
    array(array('student_research_current' => false), 'student_research_missing'),
    array(array('student_research_current' => false, 'student_research_withdrawn' => true), 'student_research_withdrawn'),
    array(array('student_policy_current' => false, 'student_research_withdrawn' => true), 'student_research_withdrawn'),
    array(array('student_policy_current' => false), 'student_policy_superseded'),
    array(array('excluded' => true), 'excluded'),
    array(array('quarantined' => true), 'quarantined'),
    array(array('retention_restriction' => true), 'retention_restriction'),
) as [$override, $reason]) $mlDeny($override, $reason);
$mlMinor = array_merge($mlBase, array('student_capacity' => 'minor', 'guardian_authority_valid' => true, 'guardian_research_current' => true));
$fail($ml->evaluate($mlMinor)['eligible'] === true, 'Verified minor guardian research is eligible');
$mlDenyMinor = static function (array $override, string $reason) use ($ml, $mlMinor, $fail): void {
    $result = $ml->evaluate(array_merge($mlMinor, $override));
    $fail($result['eligible'] === false && $result['reason'] === $reason, 'Expected minor ML denial ' . $reason . ' got ' . $result['reason']);
};
$mlDenyMinor(array('guardian_authority_valid' => false), 'guardian_authority_invalid');
$mlDenyMinor(array('guardian_research_current' => false), 'guardian_research_missing');
$mlDenyMinor(array('guardian_research_current' => false, 'guardian_research_withdrawn' => true), 'guardian_research_withdrawn');
$mlDenyMinor(array('guardian_policy_current' => false), 'guardian_policy_superseded');
$fail($ml->evaluate(array_merge($mlBase, array('student_research_current' => false)))['reason'] === 'student_research_missing', 'Recording-only consent must not make a dataset item eligible');

echo "Consent policy unit: PASS\n";
