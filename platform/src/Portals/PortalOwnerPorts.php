<?php
namespace Delnavazan\Platform\Portals;

final class AuthenticatedPortalReadSubject {
    public function __construct(public readonly string $surface,public readonly int $wordpressUserId,public readonly string $kind,public readonly int $principalId,public readonly ?int $guardianGrantId=null,public readonly ?string $guardianGrantScope=null) {
        if($wordpressUserId<1||$principalId<1)throw new \InvalidArgumentException('portal_principal_unresolved');
        if($kind==='guardian'&&($guardianGrantId===null||$guardianGrantId<1||$guardianGrantScope!==PortalRule::GUARDIAN_PORTAL_READ_SCOPE))throw new \InvalidArgumentException('portal_principal_unresolved');
        if($kind!=='guardian'&&($guardianGrantId!==null||$guardianGrantScope!==null))throw new \InvalidArgumentException('portal_principal_unresolved');
    }
}
final class PublicCapabilityReadSubject {
    public function __construct(public readonly int $capabilityId,public readonly string $purpose,public readonly int $lessonId,public readonly int $scheduleVersionId,public readonly int $generation,public readonly ?int $studentId,public readonly string $expiresAt) { if($capabilityId<1||$lessonId<1||$scheduleVersionId<1||$generation<1) throw new \InvalidArgumentException('portal_capability_binding_mismatch'); }
}
interface TeacherAssignmentPortalReadPort { public function forSubject(AuthenticatedPortalReadSubject|PublicCapabilityReadSubject $subject,int $enrolmentId):array; public function pageForSubject(AuthenticatedPortalReadSubject|PublicCapabilityReadSubject $subject,?string $cursor,int $limit):array; }
interface CanonicalLessonSchedulePortalReadPort { public function forSubject(AuthenticatedPortalReadSubject|PublicCapabilityReadSubject $subject,int $lessonId):array; public function pageForSubject(AuthenticatedPortalReadSubject|PublicCapabilityReadSubject $subject,?string $cursor,int $limit):array; }
interface CanonicalLessonDeliveryPortalReadPort { public function summaryForSubject(AuthenticatedPortalReadSubject|PublicCapabilityReadSubject $subject,int $lessonId):array; }
interface CanonicalAttendancePortalReadPort { public function summaryForSubject(AuthenticatedPortalReadSubject|PublicCapabilityReadSubject $subject,int $lessonId,int $scheduleVersionId):array; public function assertCapabilityClaimAdmissible(PublicCapabilityReadSubject $subject):void; public function submitCapabilityClaim(PublicCapabilityReadSubject $subject,string $redemptionReference):array; }
interface PortalCapabilityOwnerPort { public function binding(int $lessonId,int $scheduleVersionId,string $purpose,?int $studentId,bool $requirePrincipal=true):array; }

final class PortalOwnerPorts {
    private static ?PortalCapabilityOwnerPort $capability=null;
    private static array $reads=[];
    public static function configureCapability(PortalCapabilityOwnerPort $port):void { self::$capability=$port; }
    public static function capability():PortalCapabilityOwnerPort { if(!self::$capability) throw new \InvalidArgumentException('portal_parent_not_live'); return self::$capability; }
    public static function configureReadPorts(TeacherAssignmentPortalReadPort $assignment,CanonicalLessonSchedulePortalReadPort $schedule,CanonicalLessonDeliveryPortalReadPort $delivery,CanonicalAttendancePortalReadPort $attendance):void { self::$reads=compact('assignment','schedule','delivery','attendance'); }
    public static function assignment():TeacherAssignmentPortalReadPort { return self::port('assignment'); }
    public static function schedule():CanonicalLessonSchedulePortalReadPort { return self::port('schedule'); }
    public static function delivery():CanonicalLessonDeliveryPortalReadPort { return self::port('delivery'); }
    public static function attendance():CanonicalAttendancePortalReadPort { return self::port('attendance'); }
    private static function port(string $name):object { if(!isset(self::$reads[$name])) throw new \InvalidArgumentException('portal_parent_not_live'); return self::$reads[$name]; }
}

final class PortalAccessPolicy {
    /**
     * The policy deliberately selects the registered owner ports itself.  Accepting a caller-supplied
     * projection here would make it possible for an internal caller to bypass an owning validator.
     * `$legacyPort` remains an ignored compatibility argument for the previously materialised seam.
     */
    public static function assertObject(string $surface,string $kind,int $targetId,array $principal,?object $legacyPort=null):array {
        try {
            $allowed=(in_array($principal['kind'],array('student','guardian'),true)&&in_array($kind,array('lesson','enrolment'),true))||($principal['kind']==='teacher'&&in_array($kind,array('lesson','assignment'),true));
            if(!$allowed)throw new \InvalidArgumentException('portal_object_not_portal_visible');
            if($principal['kind']==='teacher'&&!current_user_can('dzn_view_own_portal_schedule'))throw new \InvalidArgumentException('portal_object_not_owned');
            $subject=new AuthenticatedPortalReadSubject($surface,(int)get_current_user_id(),(string)$principal['kind'],(int)$principal['id'],$principal['kind']==='guardian'?(int)($principal['grant_id']??0):null,$principal['kind']==='guardian'?(string)($principal['grant_scope']??''):null);
            $surfaceReader=new PortalInternalReadSurface();
            if($kind==='lesson')return in_array($principal['kind'],array('student','guardian'),true)?$surfaceReader->studentLesson($subject,$targetId):$surfaceReader->teacherLesson($subject,$targetId);
            if($kind==='enrolment')return $surfaceReader->studentEnrolment($subject,$targetId);
            return $surfaceReader->teacherAssignment($subject,$targetId);
        } catch(\Throwable $e) { self::deny($surface,$kind,$targetId,$principal,(string)$e->getMessage()); throw $e; }
    }
    private static function deny(string $surface,string $kind,int $target,array $principal,string $reason):void {
        global $wpdb; $p=$wpdb->prefix.'dzn_portal_access_denials'; if(!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p)))return;
        $reason=in_array($reason,PortalRule::EXCEPTION_REASON_CODES,true)?$reason:'portal_upstream_aggregate_invalid';
        $wpdb->insert($p,array('uid'=>substr(hash('sha256',wp_generate_uuid4()),0,26),'surface'=>$surface,'principal_kind'=>$principal['kind']??null,'principal_id'=>$principal['id']??null,'target_kind'=>$kind,'target_id'=>$target,'reason_code'=>$reason,'request_fingerprint_digest'=>hash('sha256',wp_json_encode($_REQUEST)),'occurred_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s')));
    }
}
