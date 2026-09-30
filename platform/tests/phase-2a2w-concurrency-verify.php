<?php
/**
 * Phase 2A.2-W race verifier.  Every mode asserts the declared invariant of §15 and no mode may end
 * with a second delegation, a second claim, a second terminal outcome or a persistence failure.
 */
if (getenv('DZN_PHASE_2A2W_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-W verifier refused.\n"); exit(1); }
use Delnavazan\Platform\Portals\PortalRule;
global $wpdb; $p = $wpdb->prefix . 'dzn_'; $state = get_option('dzn_phase_2a2w_race_state');
if (!is_array($state)) throw new RuntimeException('Phase W race state unavailable');
$mode = (string) $state['mode']; $lessonOne = (int) $state['lesson_one']; $lessonTwo = (int) $state['lesson_two'];
$started = array_count_values((array) get_option('dzn_phase_2a2w_race_started', array()));
$completed = array_count_values((array) get_option('dzn_phase_2a2w_race_completed', array()));
$startedCount = static function(array $started, string $confirmation): int { return (int) ($started[hash('sha256', $confirmation)] ?? 0); };
$completedCount = static function(array $completed, string $confirmation): int { return (int) ($completed[hash('sha256', $confirmation)] ?? 0); };
$actionState = static function(int $capabilityId, string $actionState): int { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_portal_public_action_events WHERE capability_id=%d AND action_state=%s", $capabilityId, $actionState)); };
$refusalReason = static function(int $capabilityId): string { global $wpdb; return (string) $wpdb->get_var($wpdb->prepare("SELECT outcome_reason_code FROM {$wpdb->prefix}dzn_portal_public_action_events WHERE capability_id=%d AND action_state='refused' ORDER BY id DESC LIMIT 1", $capabilityId)); };
$capability = static function(int $capabilityId): ?object { global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_portal_public_capabilities WHERE id=%d", $capabilityId)); return $row ?: null; };
$activeFor = static function(int $lesson, string $purpose): int { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_portal_public_capabilities WHERE lesson_id=%d AND purpose=%s AND state='active' AND active_slot=1", $lesson, $purpose)); };
$claimCount = static function(array $state) use ($actionState): int { return $actionState((int) $state['absence']['capability_id'], 'confirmed_submitting'); };
$summary = array('race' => $mode);
$denial = static function(string $surface, string $reason): int { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_portal_access_denials WHERE surface=%s AND reason_code=%s", $surface, $reason)); };

if ($mode === 'mint_vs_mint') {
    $capabilities = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}portal_public_capabilities WHERE lesson_id=%d AND purpose=%s", $lessonOne, PortalRule::ABSENCE));
    $commands = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}portal_public_capability_commands WHERE lesson_id=%d AND purpose=%s", $lessonOne, PortalRule::ABSENCE));
    $actions = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}portal_public_action_events WHERE lesson_id=%d", $lessonOne));
    if ($capabilities !== 1 || $activeFor($lessonOne, PortalRule::ABSENCE) !== 1 || $commands !== 1 || $actions !== 0) throw new RuntimeException('mint_vs_mint did not leave exactly one winner per (lesson,purpose)');
    $summary += array('capabilities' => $capabilities, 'active' => 1, 'commands' => $commands, 'action_events' => $actions, 'root_serialised' => 1);
} elseif (in_array($mode, array('rotate_vs_redeem', 'revoke_vs_redeem'), true)) {
    $predecessor = $capability((int) $state['absence']['capability_id']);
    $expectedState = $mode === 'rotate_vs_redeem' ? 'superseded' : 'revoked';
    $expectedReason = $mode === 'rotate_vs_redeem' ? 'portal_capability_superseded' : 'portal_capability_revoked';
    if (!$predecessor || (string) $predecessor->state !== $expectedState) throw new RuntimeException($mode . ' did not reach its declared predecessor state');
    if ($refusalReason((int) $predecessor->id) !== $expectedReason) throw new RuntimeException($mode . ' did not record its declared refusal evidence');
    if ($predecessor && (string) $predecessor->state === 'superseded' && (int) $predecessor->superseded_by_capability_id < 1) throw new RuntimeException('rotation did not record its successor link');
    if ($claimCount($state) !== 0 || $actionState((int) $predecessor->id, 'delegating') !== 0 || $actionState((int) $predecessor->id, 'submitted') !== 0) throw new RuntimeException($mode . ' claimed or delegated against an unusable predecessor');
    if ((int) ($completed[hash('sha256', (string) $state['absence']['confirmation'])] ?? 0) !== 0) throw new RuntimeException($mode . ' delegated after the predecessor moved');
    $summary += array('predecessor_state' => (string) $predecessor->state, 'refusal_reason' => $expectedReason, 'active_successor' => $mode === 'rotate_vs_redeem' ? $activeFor($lessonOne, PortalRule::ABSENCE) : 0, 'delegations' => 0);
} elseif ($mode === 'refusal_vs_redeem') {
    $refusals = $actionState((int) $state['join']['capability_id'], 'refused');
    $join = $capability((int) $state['join']['capability_id']);
    if ($refusals !== 1 || $refusalReason((int) $state['join']['capability_id']) !== 'portal_capability_expired') throw new RuntimeException('refusal_vs_redeem did not record the refusal evidence under the Lesson root');
    $refusalDenials = $denial('portal_public_join', 'portal_capability_expired');
    if ($refusalDenials < 1) throw new RuntimeException('refusal_vs_redeem did not record its durable denial with the refusal evidence');
    if (!$join || (string) $join->state !== 'active') throw new RuntimeException('refusal_vs_redeem changed the refused capability');
    $absenceId = (int) $state['absence']['capability_id'];
    if ($actionState($absenceId, 'confirmed_submitting') !== 1 || $actionState($absenceId, 'delegating') !== 1 || $actionState($absenceId, 'submitted') !== 1) throw new RuntimeException('refusal_vs_redeem did not complete the concurrent redemption');
    if ((int) ($completed[hash('sha256', (string) $state['absence']['confirmation'])] ?? 0) !== 1) throw new RuntimeException('refusal_vs_redeem delegated the redemption more or less than once');
    $summary += array('refusal_events' => $refusals, 'refusal_reason' => 'portal_capability_expired', 'denials' => $refusalDenials, 'redemption_claims' => 1, 'delegations' => 1);
} elseif ($mode === 'outcome_vs_rotation') {
    $predecessor = $capability((int) $state['join']['capability_id']);
    if (!$predecessor || (string) $predecessor->state !== 'superseded' || (int) $predecessor->superseded_by_capability_id < 1) throw new RuntimeException('outcome_vs_rotation did not rotate the sibling capability');
    if ($activeFor($lessonOne, PortalRule::JOIN) !== 1) throw new RuntimeException('outcome_vs_rotation left no live join generation');
    $absenceId = (int) $state['absence']['capability_id'];
    $terminal = $actionState($absenceId, 'submitted');
    if ($actionState($absenceId, 'confirmed_submitting') !== 1 || $actionState($absenceId, 'delegating') !== 1 || $terminal !== 1) throw new RuntimeException('outcome_vs_rotation lost the recorded outcome of a claim that predated the rotation');
    if ((int) ($completed[hash('sha256', (string) $state['absence']['confirmation'])] ?? 0) !== 1) throw new RuntimeException('outcome_vs_rotation delegated the redemption more or less than once');
    $summary += array('rotated_sibling' => 1, 'outcome_events' => $terminal, 'delegations' => 1);
} elseif ($mode === 'stale_schedule_vs_rotate') {
    $stale = $capability((int) $state['stale']['capability_id']);
    if (!$stale || (string) $stale->state !== 'active') throw new RuntimeException('stale_schedule_vs_rotate changed the stale capability row');
    if ($actionState((int) $stale->id, 'refused') !== 1 || $refusalReason((int) $stale->id) !== 'portal_capability_stale_schedule') throw new RuntimeException('stale_schedule_vs_rotate did not record the stale-schedule refusal under the Lesson root');
    if ($actionState((int) $stale->id, 'confirmed_submitting') !== 0) throw new RuntimeException('stale_schedule_vs_rotate claimed a stale capability');
    if ((int) ($completed[hash('sha256', (string) $state['stale']['confirmation'])] ?? 0) !== 0) throw new RuntimeException('stale_schedule_vs_rotate delegated a stale capability');
    if ($activeFor($lessonOne, PortalRule::JOIN) !== 1) throw new RuntimeException('stale_schedule_vs_rotate did not complete the concurrent rotation');
    $staleDenials = $denial('portal_public_absence', 'portal_capability_stale_schedule');
    if ($staleDenials < 1) throw new RuntimeException('stale_schedule_vs_rotate did not record the durable denial for its refusal');
    $summary += array('refusal_reason' => 'portal_capability_stale_schedule', 'delegations' => 0, 'denials' => $staleDenials);
} elseif ($mode === 'two_redemptions_one_confirmation') {
    $absenceId = (int) $state['absence']['capability_id']; $confirmation = (string) $state['absence']['confirmation'];
    $refusals = $actionState($absenceId, 'refused');
    if ($actionState($absenceId, 'confirmed_submitting') !== 1 || $actionState($absenceId, 'delegating') !== 1 || $actionState($absenceId, 'submitted') !== 1) throw new RuntimeException('two_redemptions_one_confirmation did not converge on one claim, one lease and one outcome');
    if ($refusals > 1 || ($refusals === 1 && $refusalReason($absenceId) !== 'portal_absence_submission_pending')) throw new RuntimeException('two_redemptions_one_confirmation recorded an undeclared second-request answer');
    if ($startedCount($started, $confirmation) !== 1 || $completedCount($completed, $confirmation) !== 1) throw new RuntimeException('two_redemptions_one_confirmation delegated more than once');
    $summary += array('claims' => 1, 'leases' => 1, 'outcomes' => 1, 'delegations_started' => 1, 'delegations_completed' => 1, 'pending_refusals' => $refusals);
} elseif ($mode === 'replay_during_delegation') {
    $absenceId = (int) $state['absence']['capability_id']; $confirmation = (string) $state['absence']['confirmation'];
    if ($actionState($absenceId, 'confirmed_submitting') !== 1 || $actionState($absenceId, 'delegating') !== 1 || $actionState($absenceId, 'submitted') !== 1) throw new RuntimeException('replay_during_delegation did not converge on one claim, one lease and one outcome');
    if ($actionState($absenceId, 'refused') !== 1 || $refusalReason($absenceId) !== 'portal_absence_submission_pending') throw new RuntimeException('replay_during_delegation did not refuse the replay that arrived mid-delegation');
    if ($startedCount($started, $confirmation) !== 1 || $completedCount($completed, $confirmation) !== 1) throw new RuntimeException('replay_during_delegation performed a second delegation');
    $summary += array('claims' => 1, 'leases' => 1, 'outcomes' => 1, 'pending_refusals' => 1, 'delegations_started' => 1, 'delegations_completed' => 1);
} elseif ($mode === 'replay_after_crash') {
    $absenceId = (int) $state['absence']['capability_id']; $confirmation = (string) $state['absence']['confirmation'];
    if ($actionState($absenceId, 'confirmed_submitting') !== 1 || $actionState($absenceId, 'delegating') !== 2 || $actionState($absenceId, 'submitted') !== 1) throw new RuntimeException('replay_after_crash did not take the abandoned lease over exactly once');
    if ($actionState($absenceId, 'refused') !== 0) throw new RuntimeException('replay_after_crash refused an exact replay');
    if ($startedCount($started, $confirmation) !== 2 || $completedCount($completed, $confirmation) !== 1) throw new RuntimeException('replay_after_crash did not converge the crashed delegation into exactly one recorded intake');
    $summary += array('claims' => 1, 'leases' => 2, 'outcomes' => 1, 'delegations_started' => 2, 'delegations_completed' => 1);
} elseif ($mode === 'two_lessons_disjoint') {
    foreach (array('absence' => $lessonOne, 'absence_two' => $lessonTwo) as $key => $lesson) {
        $id = (int) $state[$key]['capability_id'];
        if ($actionState($id, 'confirmed_submitting') !== 1 || $actionState($id, 'delegating') !== 1 || $actionState($id, 'submitted') !== 1) throw new RuntimeException('two_lessons_disjoint did not complete both redemptions');
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}portal_lesson_capability_roots WHERE lesson_id=%d", $lesson)) !== 1) throw new RuntimeException('two_lessons_disjoint did not create both Lesson roots');
    }
    if (array_sum($completed) !== 2) throw new RuntimeException('two_lessons_disjoint did not record exactly two delegations');
    $summary += array('lessons' => 2, 'roots' => 2, 'delegations' => 2, 'contended' => 0);
} else {
    throw new RuntimeException('Phase W race mode unavailable');
}
$summary += array('public_action_restored' => delete_option(PortalRule::PUBLIC_ACTION_OPTION) ? 1 : 0);
echo 'race=' . $mode . ' verifier=pass ' . wp_json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";
