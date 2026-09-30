<?php
/**
 * Phase 2A.2-W concurrency fixture.
 *
 * The two Phase-W owner seams of §10.0/§10.1 are the declared boundaries between Phase W and its
 * owners, so this disposable harness registers bounded doubles for them: the capability owner proves a
 * synthetic Lesson/schedule binding, and the attendance owner records how many delegations were
 * started and completed.  That counter is what lets a race prove that one confirmation delegated
 * exactly once.  Everything else - the Lesson root, the claim, the lease and the terminal outcome - is
 * the real Phase-W code path against the real Schema 031 tables.
 *
 * Synthetic Lesson ids are used deliberately: Phase W stores access artefacts only and never validates
 * a Lesson against the owning module's table, which is exactly why the root row, not the Lesson row,
 * is the declared serialisation point (§15.1).
 */
if (getenv('DZN_PHASE_2A2W_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) { fwrite(STDERR, "Phase 2A.2-W concurrency setup refused.\n"); exit(1); }
use Delnavazan\Platform\Portals\{AuthenticatedPortalReadSubject,CanonicalAttendancePortalReadPort,CanonicalLessonDeliveryPortalReadPort,CanonicalLessonSchedulePortalReadPort,PortalCapabilityOwnerPort,PortalCapabilityService,PortalOwnerPorts,PortalPublicActionService,PortalRule,PublicCapabilityReadSubject,TeacherAssignmentPortalReadPort};

final class PhaseWConcurrencyCapabilityOwner implements PortalCapabilityOwnerPort {
    public function binding(int $lessonId,int $scheduleVersionId,string $purpose,?int $studentId,bool $requirePrincipal=true):array {
        $state=get_option('dzn_phase_2a2w_race_state');
        if(is_array($state)&&!empty($state['stale_enabled'])&&in_array($scheduleVersionId,(array)($state['stale_schedules']??array()),true))throw new \InvalidArgumentException('portal_capability_stale_schedule');
        return array('lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'purpose'=>$purpose,'student_id'=>(int)$studentId,'lesson_uid'=>'race-lesson-'.$lessonId,'schedule_version_uid'=>'race-schedule-'.$scheduleVersionId);
    }
}
final class PhaseWConcurrencyAttendance implements CanonicalAttendancePortalReadPort {
    public function summaryForSubject(AuthenticatedPortalReadSubject|PublicCapabilityReadSubject $subject,int $lessonId,int $scheduleVersionId):array { throw new \RuntimeException('phase_w_race_read_unused'); }
    public function assertCapabilityClaimAdmissible(PublicCapabilityReadSubject $subject):void { if($subject->purpose!==PortalRule::ABSENCE||$subject->studentId===null)throw new \InvalidArgumentException('portal_capability_binding_mismatch'); }
    /** Records started and completed intakes so a race can prove that one confirmation delegated once. */
    public function submitCapabilityClaim(PublicCapabilityReadSubject $subject,string $redemptionReference):array {
        $state=get_option('dzn_phase_2a2w_race_state');$digest=hash('sha256',$redemptionReference);
        $started=(array)get_option('dzn_phase_2a2w_race_started',array());$started[]=$digest;update_option('dzn_phase_2a2w_race_started',$started,false);
        if(is_array($state)&&!empty($state['delegation_hold'])&&(string)getenv('DZN_PHASE_2A2W_WORKER')==='w1'){ $gate=(string)getenv('DZN_PHASE_2A2W_GATE_DIR');if(@file_put_contents($gate.'/w1.delegating','1')===false)throw new \RuntimeException('Gate write failed');for($i=0;$i<900&&!is_file($gate.'/release');$i++)usleep(100000);if(!is_file($gate.'/release'))throw new \RuntimeException('release gate timeout'); }
        $completed=(array)get_option('dzn_phase_2a2w_race_completed',array());$completed[]=$digest;update_option('dzn_phase_2a2w_race_completed',$completed,false);
        return array('case_id'=>9000+count($completed),'evidence_id'=>7000+count($completed),'lesson_id'=>$subject->lessonId,'schedule_version_id'=>$subject->scheduleVersionId,'student_id'=>$subject->studentId,'attribution'=>'public_capability_on_behalf','redemption_reference_digest'=>$digest,'state'=>'submitted');
    }
}
PortalOwnerPorts::configureCapability(new PhaseWConcurrencyCapabilityOwner());
PortalOwnerPorts::configureReadPorts(
    new class implements TeacherAssignmentPortalReadPort { public function forSubject($subject,int $enrolmentId):array { throw new \RuntimeException('phase_w_race_read_unused'); } public function pageForSubject($subject,?string $cursor,int $limit):array { throw new \RuntimeException('phase_w_race_read_unused'); } },
    new class implements CanonicalLessonSchedulePortalReadPort { public function forSubject($subject,int $lessonId):array { throw new \RuntimeException('phase_w_race_read_unused'); } public function pageForSubject($subject,?string $cursor,int $limit):array { throw new \RuntimeException('phase_w_race_read_unused'); } },
    new class implements CanonicalLessonDeliveryPortalReadPort { public function summaryForSubject($subject,int $lessonId):array { throw new \RuntimeException('phase_w_race_read_unused'); } },
    new PhaseWConcurrencyAttendance()
);

global $wpdb; $p = $wpdb->prefix . 'dzn_'; $mode = (string) getenv('DZN_PHASE_2A2W_MODE');
$modes = array('mint_vs_mint','rotate_vs_redeem','revoke_vs_redeem','two_redemptions_one_confirmation','replay_during_delegation','replay_after_crash','refusal_vs_redeem','outcome_vs_rotation','stale_schedule_vs_rotate','two_lessons_disjoint');
if (!in_array($mode, $modes, true)) throw new RuntimeException('Phase W race mode unavailable');
if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'portal_lesson_capability_roots')) !== $p . 'portal_lesson_capability_roots') throw new RuntimeException('Schema 031 portal storage is not installed');
$base = (int) $wpdb->get_var("SELECT COALESCE(MAX(lesson_id),0) FROM {$p}portal_lesson_capability_roots");
$lessonOne = $base + 1; $lessonTwo = $base + 2; $scheduleOne = $lessonOne; $scheduleTwo = $lessonTwo; $staleSchedule = $scheduleOne + 1000;
$state = array(
    'mode' => $mode, 'lesson_one' => $lessonOne, 'lesson_two' => $lessonTwo,
    'schedule_one' => $scheduleOne, 'schedule_two' => $scheduleTwo,
    'stale_schedule' => $staleSchedule, 'stale_enabled' => false, 'stale_schedules' => array($staleSchedule),
    'hold' => array('index' => 1, 'workers' => array('w1')), 'delegation_hold' => false,
    'keys' => array('one' => dzn_2a2w_race_key($mode . '-one'), 'two' => dzn_2a2w_race_key($mode . '-two')),
);
update_option('dzn_phase_2a2w_race_state', $state, false);
update_option('dzn_phase_2a2w_race_started', array(), false);
update_option('dzn_phase_2a2w_race_completed', array(), false);
$service = new PortalCapabilityService(); $actions = new PortalPublicActionService(); $expires = time() + 3600;
if ($mode === 'mint_vs_mint') {
    // Both workers mint from the same empty Lesson and purpose; the declared root decides the winner.
} else {
    $absence = $service->mint($lessonOne, $scheduleOne, PortalRule::ABSENCE, $expires, '', 101, true, dzn_2a2w_race_key($mode . '-absence'));
    $state['absence'] = array('capability_id' => (int) $absence['capability_id'], 'handle' => (string) $absence['handle'], 'token' => (string) $absence['token'], 'confirmation' => dzn_2a2w_race_confirmation($actions, $absence['handle'], $absence['token']));
}
if ($mode === 'two_lessons_disjoint') {
    $other = $service->mint($lessonTwo, $scheduleTwo, PortalRule::ABSENCE, $expires, '', 202, true, dzn_2a2w_race_key($mode . '-absence-two'));
    $state['absence_two'] = array('capability_id' => (int) $other['capability_id'], 'handle' => (string) $other['handle'], 'token' => (string) $other['token'], 'confirmation' => dzn_2a2w_race_confirmation($actions, $other['handle'], $other['token']));
    $state['hold'] = array('index' => 1, 'workers' => array('w1', 'w2'));
}
if (in_array($mode, array('rotate_vs_redeem','refusal_vs_redeem','outcome_vs_rotation','stale_schedule_vs_rotate'), true)) {
    $join = $service->mint($lessonOne, $scheduleOne, PortalRule::JOIN, $expires, 'https://meet.google.com/race-' . $lessonOne, null, true, dzn_2a2w_race_key($mode . '-join'));
    $state['join'] = array('capability_id' => (int) $join['capability_id'], 'handle' => (string) $join['handle'], 'token' => (string) $join['token']);
}
if ($mode === 'stale_schedule_vs_rotate') {
    // The capability was valid when it was minted; the owner port refuses it at redemption, which is
    // the declared stale-schedule condition rather than a forged row.
    $stale = $service->mint($lessonOne, $staleSchedule, PortalRule::ABSENCE, $expires, '', 101, true, dzn_2a2w_race_key($mode . '-stale'));
    $state['stale'] = array('capability_id' => (int) $stale['capability_id'], 'handle' => (string) $stale['handle'], 'token' => (string) $stale['token'], 'confirmation' => dzn_2a2w_race_confirmation($actions, $stale['handle'], $stale['token']));
    $state['stale_enabled'] = true;
}
if ($mode === 'outcome_vs_rotation') $state['hold'] = array('index' => 2, 'workers' => array('w1'));
if (in_array($mode, array('replay_during_delegation','replay_after_crash'), true)) { $state['hold'] = array('index' => 0, 'workers' => array()); $state['delegation_hold'] = true; }
update_option('dzn_phase_2a2w_race_state', $state, false);
echo "Phase 2A.2-W {$mode} concurrency setup passed\n";

function dzn_2a2w_race_key(string $label):string { return 'dzn-2a2w-race-' . $label . '-' . substr(hash('sha256', $label . wp_generate_uuid4()), 0, 32); }
function dzn_2a2w_race_confirmation(PortalPublicActionService $actions,string $handle,string $token):string {
    $html = $actions->renderAbsenceConfirmation($handle, $token);
    if (!preg_match('/name="confirmation" value="([a-f0-9]{128})"/', $html, $match)) throw new RuntimeException('Confirmation page did not render the one-time token');
    return (string) $match[1];
}
