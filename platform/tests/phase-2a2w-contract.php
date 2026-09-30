<?php
/** Contract guard for Phase-W's security and migration seams. The static seams below are followed by the
 *  behavioural coverage of the public rate-limit admission (`phase-2a2w-public-rate-limit-unit.php`),
 *  embedded so the two run as one guard: fixed per-surface admission and audit attribution, a genuine
 *  limit refusal, and fail-open on a fingerprinting or cache failure.  The §15.6 refusal-versus-failure
 *  split (`phase-2a2w-persistence-failure-unit.php`) is embedded the same way: only a declared business
 *  refusal writes refusal evidence, and a persistence/corruption failure propagates with no refusal row. */
$root=dirname(__DIR__);
$cap=file_get_contents($root.'/src/Portals/PortalCapabilityService.php');
$public=file_get_contents($root.'/src/Portals/PortalPublicActionController.php');
$action=file_get_contents($root.'/src/Portals/PortalPublicActionService.php');
$attendance=file_get_contents($root.'/src/Core/Application/CanonicalAttendanceIntakeService.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$replay=file_get_contents($root.'/tests/phase-2a2w-replay-runtime.php');
foreach(array('hash_equals','aes-256-gcm','FOR UPDATE','superseded_by_capability_id','portal_public_capability_commands','portal_public_capability_events') as $needle)if(strpos($cap,$needle)===false)throw new RuntimeException('Phase-W capability contract missing '.$needle);
if(strpos($public,"(string)get_option(PortalRule::PUBLIC_ACTION_OPTION,'')!==PortalRule::PUBLIC_ACTION_ENABLED_VALUE")===false)throw new RuntimeException('Public action option is not strict');
foreach(array('new PortalRateLimiter()','allow($surface,$fingerprint)','portal_rate_limited') as $needle)if(strpos($public,$needle)===false)throw new RuntimeException('Public rate-limit admission seam missing '.$needle);
foreach(array("private const SURFACE_JOIN='portal_public_join';","private const SURFACE_ABSENCE='portal_public_absence';") as $needle)if(strpos($public,$needle)===false)throw new RuntimeException('Public route surface is not a fixed registered constant: '.$needle);
if(strpos($public,'REQUEST_URI')!==false)throw new RuntimeException('Public surface must not be derived from attacker-controlled request text');
if(strpos($public,'self::surface()')!==false)throw new RuntimeException('Public surface must not be derived by a request-text helper');
$admitStart=strpos($public,'private static function admit(');
$admitEnd=strpos($public,'public static function join(');
if($admitStart===false||$admitEnd===false||$admitEnd<$admitStart)throw new RuntimeException('Public rate-limit admission seam missing');
$admit=substr($public,$admitStart,$admitEnd-$admitStart);
foreach(array('catch(\Throwable','if(false===$allowed)',"throw new \InvalidArgumentException('portal_rate_limited')") as $needle)if(strpos($admit,$needle)===false)throw new RuntimeException('Public rate-limit fail-open seam missing '.$needle);
if(strpos($admit,'catch(\InvalidArgumentException')!==false)throw new RuntimeException('A typed fingerprinting or cache failure must not be rethrown as a refusal');
if(strpos($admit,'catch(\Throwable')>strpos($admit,"throw new \InvalidArgumentException('portal_rate_limited')"))throw new RuntimeException('portal_rate_limited must be thrown only after the fail-open catch completes');
if(strpos($public,'portal_access_denials')!==false)throw new RuntimeException('Public-action controller must not write denial evidence outside the refusal transaction');
if(strpos($public,'recordRefusal')===false)throw new RuntimeException('Public-action controller must delegate its refusal evidence to the root-serialised refusal transaction');
$rule=file_get_contents($root.'/src/Portals/PortalRule.php');
$admin=file_get_contents($root.'/src/Admin/Controller/PortalCapabilityController.php');
foreach(array('PERSISTENCE_FAILURE_CODES','public static function refusalReason') as $needle)if(strpos($rule,$needle)===false)throw new RuntimeException('Phase-W declared refusal/failure split missing '.$needle);
foreach(array('administrative command controller'=>$admin,'public-action controller'=>$public) as $label=>$source){
    if(strpos($source,'PortalRule::refusalReason($e)')===false||strpos($source,'if($reason===null)throw $e;')===false)throw new RuntimeException('The '.$label.' must classify a throwable before it writes refusal evidence');
    if(strpos($source,'in_array($e->getMessage(),PortalRule::EXCEPTION_REASON_CODES,true)')!==false)throw new RuntimeException('The '.$label.' must not classify a throwable by its own message vocabulary');
    if(strpos($source,"?'portal_upstream_aggregate_invalid'")!==false)throw new RuntimeException('The '.$label.' must not convert an unrecognised throwable into portal_upstream_aggregate_invalid');
}
if(strpos($action,'$refusal=PortalRule::refusalReason($e);if($refusal===null)throw $e;')===false)throw new RuntimeException('The post-delegation owner handoff must record only a declared owner refusal and re-raise anything else');
$joinStart=strpos($public,'public static function join(');
$absenceStart=strpos($public,'public static function absence(');
if($joinStart===false||$absenceStart===false||$absenceStart<$joinStart)throw new RuntimeException('Public route callbacks are missing');
$join=substr($public,$joinStart,$absenceStart-$joinStart);
$absence=substr($public,$absenceStart);
foreach(array('self::admit(self::SURFACE_JOIN,$handle)','self::refused($e,PortalRule::JOIN,$handle,') as $needle)if(strpos($join,$needle)===false)throw new RuntimeException('Join callback must pass its own fixed surface and refusal purpose: '.$needle);
foreach(array('self::admit(self::SURFACE_ABSENCE,$handle)','self::refused($e,PortalRule::ABSENCE,$handle,') as $needle)if(strpos($absence,$needle)===false)throw new RuntimeException('Absence callback must pass its own fixed surface and refusal purpose: '.$needle);
$admissions=array();$offset=0;while(false!==($offset=strpos($public,'self::admit(',$offset))){$admissions[]=$offset;$offset+=1;}
$gates=array();$offset=0;while(false!==($offset=strpos($public,"get_option(PortalRule::PUBLIC_ACTION_OPTION,'')",$offset))){$gates[]=$offset;$offset+=1;}
if(count($admissions)!==2||count($gates)!==2)throw new RuntimeException('Public rate-limit admission or option-gate coverage changed');
foreach($gates as $index=>$position)if($admissions[$index]>$position)throw new RuntimeException('Public rate-limit admission must precede the option gate');
foreach(array('verify_portal_facing_services_schema','portal_lesson_capability_roots','portal_public_action_events','digest shape','orphan portal action') as $needle)if(strpos($migration,$needle)===false)throw new RuntimeException('Phase-W migration verifier missing '.$needle);
foreach(array('verifyConsumed','confirmed_submitting','consumed_action_event_id') as $needle)if(strpos($action,$needle)===false&&strpos($cap,$needle)===false)throw new RuntimeException('Phase-W replay convergence seam missing '.$needle);
foreach(array('underLessonRoot','claimDelegation','recordOutcome',"'action_state'=>'delegating'",'DELEGATION_LEASE_SECONDS','portal_absence_submission_pending') as $needle)if(strpos($action,$needle)===false)throw new RuntimeException('Phase-W root-serialised single-delegator seam missing '.$needle);
foreach(array('bool $required=true','$row->subject_student_id===null?null:(int)$row->subject_student_id,false)') as $needle)if(strpos($cap,$needle)===false)throw new RuntimeException('Phase-W consumed replay principal bypass seam missing '.$needle);
foreach(array('revoked_at','requirePrincipal') as $needle)if(strpos($replay,$needle)===false)throw new RuntimeException('Phase-W revoked-principal replay coverage missing '.$needle);
foreach(array('renderAbsenceConfirmation(','confirmAbsence(','verifyConsumed(','portal_principal_required',"'replayed'",'consumed_action_event_id') as $needle)if(strpos($replay,$needle)===false)throw new RuntimeException('Phase-W revoked-principal replay coverage missing '.$needle);
foreach(array('PUBLIC_CAPABILITY_AUDIT_ACTOR','public_capability_on_behalf','command($digest)') as $needle)if(strpos($attendance,$needle)===false)throw new RuntimeException('Anonymous capability handoff seam missing '.$needle);
if(!defined('DZN_2A2W_RATE_UNIT_EMBEDDED'))define('DZN_2A2W_RATE_UNIT_EMBEDDED',true);
require __DIR__.'/phase-2a2w-public-rate-limit-unit.php';
if(!defined('DZN_2A2W_PERSISTENCE_UNIT_EMBEDDED'))define('DZN_2A2W_PERSISTENCE_UNIT_EMBEDDED',true);
require __DIR__.'/phase-2a2w-persistence-failure-unit.php';
echo "Phase-W contract passed\n";
