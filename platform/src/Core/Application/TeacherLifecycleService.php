<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRepository;

/** Coordinates Teacher archival with invitation and principal authority. */
final class TeacherLifecycleService {
    public function __construct(private ?PrincipalInvitationRepository $repo=null){$this->repo??=new PrincipalInvitationRepository();}

    /**
     * Soft archive preserves the Teacher and every teaching/audit record. The
     * Core link is revoked transactionally; only the teacher-specific WP role
     * is removed after commit, leaving every unrelated role untouched.
     */
    public function archive(int $teacherId):void{
        if(!current_user_can('dzn_manage_teachers'))throw new \RuntimeException('Unauthorized');
        $actor=get_current_user_id()?:null;$now=current_time('mysql',true);$userId=0;$teachers=new TeacherRepository();$this->repo->begin();
        try{
            $teacher=$this->repo->teacherForUpdate($teacherId);
            if(!$teacher||$teacher->archived_at!==null||$teacher->status==='archived')throw new \InvalidArgumentException('Record is not archivable');
            do_action('dzn_phase_2a2j_teacher_archive_lock_held',$teacherId);
            if($teachers->hasOperationalEnrolments($teacherId)||$teachers->hasApplicableTeacherAssignments($teacherId))throw new \InvalidArgumentException('Archive conflict: dependent operational records exist');
            if((new CanonicalLessonScheduleGuard())->activeFutureExists('teacher',$teacherId,$now))throw new \InvalidArgumentException('active_future_schedule_exists');
            $invitation=$this->repo->invitationForTeacher($teacherId);
            if($invitation){$this->repo->revokeUnclaimedGenerations((int)$invitation->id,$now);$this->repo->cancelPendingDeliveryOutboxes((int)$invitation->id,$now);$this->repo->update('teacher_invitations',['status'=>'revoked','version'=>(int)$invitation->version+1,'updated_at'=>$now,'updated_by'=>$actor],['id'=>(int)$invitation->id]);}
            $link=$this->repo->activeTeacherLinkForTeacher($teacherId);$userId=(int)($link->wordpress_user_id??0);
            if($link)$this->repo->update('teacher_principal_links',['status'=>'revoked','revoked_at'=>$now,'revoked_by'=>$actor,'reason_code'=>'teacher_archived'],['id'=>(int)$link->id,'status'=>'active']);
            $onboarding=$this->repo->onboardingForTeacher($teacherId);
            if($onboarding&&(string)$onboarding->state!=='offboarded'){$this->repo->update('teacher_onboarding_states',['state'=>'offboarded','readiness_state'=>'not_ready','version'=>(int)$onboarding->version+1,'updated_at'=>$now,'updated_by'=>$actor],['id'=>(int)$onboarding->id]);$this->repo->appendOnboardingEvent($teacherId,(string)$onboarding->state,'offboarded',$actor,$now);}
            $this->repo->update('teachers',['status'=>'archived','archived_at'=>$now,'archived_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor],['id'=>$teacherId,'archived_at'=>null]);
            $this->repo->audit('teacher',$teacherId,'teacher.archived',$actor,hash('sha256','teacher-archive:'.$teacherId.':'.wp_generate_uuid4()),'teacher_access_and_invitations_revoked');
            $this->repo->commit();
        }catch(\Throwable$e){$this->repo->rollback();throw$e;}
        if($userId>0){$principal=get_userdata($userId);if($principal)$principal->remove_role('dzn_teacher');}
    }

    /** Restore eligibility only; revoked access never comes back implicitly. */
    public function restore(int $teacherId):void{
        if(!current_user_can('dzn_manage_teachers'))throw new \RuntimeException('Unauthorized');
        $actor=get_current_user_id()?:null;$now=current_time('mysql',true);$this->repo->begin();
        try{
            $teacher=$this->repo->teacherForUpdate($teacherId);
            if(!$teacher||$teacher->status!=='archived'||$teacher->archived_at===null)throw new \InvalidArgumentException('Record is not archived');
            $this->repo->update('teachers',['status'=>'active','archived_at'=>null,'archived_by'=>null,'updated_at'=>$now,'updated_by'=>$actor],['id'=>$teacherId,'status'=>'archived']);
            $this->repo->audit('teacher',$teacherId,'teacher.restored',$actor,hash('sha256','teacher-restore:'.$teacherId.':'.wp_generate_uuid4()),'access_remains_revoked_reinvitation_required');
            $this->repo->commit();
        }catch(\Throwable$e){$this->repo->rollback();throw$e;}
    }
}
