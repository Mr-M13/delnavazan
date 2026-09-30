<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Portals\PortalCapabilityOwnerPort;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};

/** Core-owned, read-only proof used by Portal capabilities. */
final class CanonicalLessonPortalCapabilityOwner implements PortalCapabilityOwnerPort {
    public function binding(int $lessonId,int $scheduleVersionId,string $purpose,?int $studentId,bool $requirePrincipal=true):array {
        if(!in_array($purpose,array(\Delnavazan\Platform\Portals\PortalRule::JOIN,\Delnavazan\Platform\Portals\PortalRule::ABSENCE),true)) throw new \InvalidArgumentException('portal_vocabulary_member_not_allowed');
        $lessons=new CanonicalLessonAuthorityRepository(); $schedules=new CanonicalLessonScheduleRepository();
        $lesson=$lessons->lesson($lessonId); $events=$lesson?$lessons->events($lessonId):array();
        $version=$schedules->version($scheduleVersionId);
        if(!$lesson||!CanonicalLessonAuthorityValidator::valid($lesson,$events,$lessons)||!CanonicalLessonScheduleValidator::validForLesson($lessonId,$schedules,$lessons)) throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        if(!$version||(int)$version->lesson_id!==$lessonId||!$version->uid) throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        if((int)$version->applicable_slot!==1) throw new \InvalidArgumentException('portal_capability_stale_schedule');
        if((string)$lesson->lifecycle_state!=='authorised') throw new \InvalidArgumentException('portal_object_not_portal_visible');
        if($purpose===\Delnavazan\Platform\Portals\PortalRule::ABSENCE){ if((int)$lesson->student_id<1) throw new \InvalidArgumentException('portal_parent_not_live'); if($requirePrincipal){ global $wpdb; $links=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1",(int)$lesson->student_id)); if($links!==1)throw new \InvalidArgumentException('portal_principal_required'); } }
        if($studentId!==null && (int)$studentId!==(int)$lesson->student_id) throw new \InvalidArgumentException('portal_capability_binding_mismatch');
        return array('lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'purpose'=>$purpose,'student_id'=>(int)$lesson->student_id,'lesson_uid'=>(string)$lesson->uid,'schedule_version_uid'=>(string)$version->uid);
    }
}
