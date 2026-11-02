<?php
/** Pure state/permission contract for the coordination blocker and next-action engine. */
require_once dirname(__DIR__) . '/src/Core/Application/CoordinationBlockerAssessment.php';

use Delnavazan\Platform\Core\Application\CoordinationBlockerAssessment;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assessment = new CoordinationBlockerAssessment();
$permissions = array(
    'coordination' => true, 'identity_resolve' => true, 'authority_read' => true,
    'authority_manage' => true, 'assent' => true, 'proposal' => true,
    'provisional' => true, 'final_acceptance' => true, 'conversion' => true,
    'assignment' => true, 'claim' => true, 'notification_retry' => true,
    'payment' => true, 'exceptions' => true, 'diagnostics' => true, 'eligibility' => true,
);
$base = array(
    'case_state' => 'open', 'case_closed' => false, 'privacy_erased' => false,
    'identity' => 'resolved', 'student_status' => 'active', 'capacity' => 'adult', 'authority' => 'adult_principal',
    'candidate_count' => 1, 'viable_candidate_count' => 1, 'current_assent' => false,
    'current_proposal' => false, 'provisional_current' => false, 'arrangement' => false,
    'conversion_state' => 'not_applicable', 'assignment' => false, 'claim' => 'none',
    'payment' => 'paid', 'notification_failures' => 0, 'calendar_projection' => 'confirmed',
    'operational_exceptions' => 0, 'has_requested_times' => true, 'requested_time_expired' => false,
    'teacher_conflicts' => 0, 'student_conflicts' => 0,
);
$codes = static fn(array $result): array => array_map(static fn(array $blocker): string => (string) $blocker['code'], $result['blockers']);

$result = $assessment->assess(array_merge($base, array('candidate_count' => 0, 'viable_candidate_count' => 0)), $permissions);
$assert(in_array('no_candidates', $codes($result), true), 'No candidate must be an explicit blocker');
$assert($result['recommended']['label'] === 'Add a Teacher candidate', 'No candidate must recommend adding a candidate');
$assert($result['alternatives'] !== array(), 'Alternatives must remain visible beside the recommendation');

$result = $assessment->assess(array_merge($base, array('identity' => 'unresolved')), $permissions);
$assert(in_array('identity_unresolved', $codes($result), true), 'Unresolved identity must be an explicit blocker');
$assert($result['recommended']['label'] === 'Resolve Student identity', 'Identity must be recommended before downstream steps');

$result = $assessment->assess(array_merge($base, array('capacity' => 'unknown')), $permissions);
$assert(in_array('capacity_unresolved', $codes($result), true), 'Unclassified capacity must be an explicit blocker');
$assert($result['recommended']['label'] === 'Resolve acceptance authority', 'Unknown capacity must recommend the authority review');
$blocker = null;
foreach ($result['blockers'] as $candidate) if ($candidate['code'] === 'capacity_unresolved') { $blocker = $candidate; break; }
$assert($blocker !== null && str_contains((string) $blocker['evidence'], 'Human-reviewed'), 'Capacity blocker must name the evidence required');
$assert($blocker !== null && str_contains((string) $blocker['unlocks'], 'acceptance route'), 'Capacity blocker must explain what becomes possible');

$result = $assessment->assess(array_merge($base, array('authority' => 'missing')), $permissions);
$assert(in_array('authority_missing', $codes($result), true), 'Missing accepting authority must be a blocker');
$assert($result['recommended']['label'] === 'Resolve acceptance authority', 'Missing authority must recommend the authority review');

$result = $assessment->assess(array_merge($base, array('current_assent' => true)), $permissions);
$assert(in_array('proposal_missing', $codes($result), true), 'Current agreement without a Proposal must be a blocker');
$assert($result['recommended']['label'] === 'Issue the Proposal', 'Current agreement must recommend Proposal issuance');

$result = $assessment->assess(array_merge($base, array('current_assent' => true, 'current_proposal' => true)), $permissions);
$assert(in_array('provisional_missing', $codes($result), true), 'Current Proposal without response must be a blocker');
$assert($result['recommended']['label'] === 'Record the student or guardian response', 'Current Proposal must recommend recording the response');

$result = $assessment->assess(array_merge($base, array('current_assent' => true, 'current_proposal' => true, 'provisional_current' => true)), $permissions);
$assert(in_array('arrangement_missing', $codes($result), true), 'Ready acceptance without final arrangement must be a blocker');
$assert($result['recommended']['label'] === 'Finalise the arrangement', 'Provisional evidence must recommend final acceptance');

$result = $assessment->assess(array_merge($base, array('current_assent' => true, 'current_proposal' => true, 'provisional_current' => true, 'arrangement' => true, 'conversion_state' => 'ready')), $permissions);
$assert($result['recommended']['label'] === 'Convert the accepted arrangement', 'A ready arrangement must recommend conversion');

$result = $assessment->assess(array_merge($base, array('arrangement' => true, 'conversion_state' => 'converted', 'assignment' => true, 'claim' => 'active')), $permissions);
$assert(str_contains((string) $result['recommended']['label'], 'Coordination complete'), 'Converted and assigned case must report completion');

$result = $assessment->assess(array_merge($base, array('teacher_conflicts' => 1, 'current_assent' => false)), $permissions);
$assert(in_array('teacher_conflict', $codes($result), true), 'A Teacher collision must be a critical blocker');

$result = $assessment->assess(array_merge($base, array('case_state' => 'withdrawn', 'case_closed' => true)), $permissions);
$assert(in_array('case_closed', $codes($result), true), 'Closed case must be explicit');
$assert(str_contains((string) $result['recommended']['label'], 'Review the closed case'), 'Closed case must not recommend a mutation');

$restricted = array_fill_keys(array_keys($permissions), false);
$result = $assessment->assess(array_merge($base, array('identity' => 'unresolved')), $restricted);
$assert($result['recommended']['available'] === false, 'Recommendation must be unavailable without its narrow capability');
$assert($result['recommended']['capability'] === 'dzn_resolve_booking_request_student_identity', 'Recommendation must name the exact prerequisite capability');

$result = $assessment->assess(array_merge($base, array('notification_failures' => 2)), $permissions);
$assert(in_array('notification_failed', $codes($result), true), 'Failed notification evidence must be visible as a blocker');

echo "Coordination blocker and recommendation unit passed\n";
