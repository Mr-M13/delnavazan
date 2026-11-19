<?php
namespace Delnavazan\Platform\Core\Application\Booking;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherBookingPriorityRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Effective-dated booking priority for one Teacher scope.
 *
 * An active priority makes the Teacher the first offer for the covered course
 * when allocation starts or an operator recovers a request. It never bypasses
 * eligibility, availability, occupancy or continuation holds, and it never
 * changes the round-robin cursor. Disabling closes the live row; history is
 * preserved and every transition is append-audited with an idempotency digest.
 */
final class TeacherBookingPriorityService {
    public const CAPABILITY='dzn_manage_teacher_rates';
    public const SCOPE_TEACHER='teacher';
    public const SCOPE_COURSE='teacher_course';
    private const REASON_CODES=array('operator_priority','operator_removed','bulk_priority');

    public function __construct(private ?TeacherBookingPriorityRepository $repository=null){
        $this->repository??=new TeacherBookingPriorityRepository();
    }

    /** @return list<object> The live priorities of one Teacher, with course labels. */
    public function forTeacher(int $teacherId):array{
        return $teacherId>0?$this->repository->activeForTeacher($teacherId):array();
    }

    /**
     * The candidate Teachers that hold an active priority for this exact course.
     *
     * @param list<int> $teacherIds
     * @return list<int>
     */
    public function prioritizedTeachers(array $teacherIds,int $courseId,string $now):array{
        return $this->repository->prioritizedTeacherIds($teacherIds,$courseId,$now);
    }

    /**
     * Enable or disable one scope. Idempotent by command key and by state:
     * enabling an already-live scope returns the same row, disabling a missing
     * scope is a successful no-op.
     *
     * @return array{priority_id:int,teacher_id:int,scope_kind:string,course_scope_id:int,state:string,replayed:bool}
     */
    public function set(int $teacherId,string $scopeKind,int $courseScopeId,bool $enabled,string $reasonCode,string $commandKey):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        $scopeKind=strtolower(trim($scopeKind));
        if(!in_array($scopeKind,array(self::SCOPE_TEACHER,self::SCOPE_COURSE),true))throw new \InvalidArgumentException('Choose a valid booking-priority scope');
        if($scopeKind===self::SCOPE_TEACHER)$courseScopeId=0;
        if($scopeKind===self::SCOPE_COURSE&&$courseScopeId<1)throw new \InvalidArgumentException('Choose the instrument-course scope');
        $reasonCode=strtolower(trim($reasonCode));
        if(!in_array($reasonCode,self::REASON_CODES,true))$reasonCode=$enabled?'operator_priority':'operator_removed';
        $commandKey=trim($commandKey);
        if($commandKey===''||strlen($commandKey)>191)throw new \InvalidArgumentException('Booking-priority command required');
        if($teacherId<1)throw new \InvalidArgumentException('Teacher required');
        if($scopeKind===self::SCOPE_COURSE&&!$this->repository->eligibleForCourse($teacherId,$courseScopeId))throw new \InvalidArgumentException('The Teacher is not eligible for that course');

        $now=gmdate('Y-m-d H:i:s');
        $actor=max(0,(int)get_current_user_id());
        $digest=hash('sha256','teacher-booking-priority:'.$commandKey);
        $this->repository->begin();
        try{
            $this->repository->lockTeacherRoot($teacherId,$now,$actor);
            if($this->repository->auditByDigest($digest)){
                $live=$this->repository->activeForScope($teacherId,$scopeKind,$courseScopeId,true);
                $this->repository->commit();
                return array('priority_id'=>$live?(int)$live->id:0,'teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'state'=>$live?'active':'inactive','replayed'=>true);
            }
            $live=$this->repository->activeForScope($teacherId,$scopeKind,$courseScopeId,true);
            if($enabled){
                if($live){
                    $this->repository->insertAudit($this->auditPayload('teacher_booking_priority_unchanged',$teacherId,(int)$live->id,$scopeKind,$courseScopeId,$reasonCode,$digest,$now,$actor));
                    $this->repository->commit();
                    return array('priority_id'=>(int)$live->id,'teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'state'=>'active','replayed'=>true);
                }
                $id=$this->repository->insertPriority(array(
                    'uid'=>Identifier::uid(),'teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,
                    'state'=>'active','active_slot'=>1,'effective_from'=>$now,'effective_until'=>null,
                    'reason_code'=>$reasonCode,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
                ));
                $this->repository->insertAudit($this->auditPayload('teacher_booking_priority_set',$teacherId,$id,$scopeKind,$courseScopeId,$reasonCode,$digest,$now,$actor));
                $this->repository->commit();
                return array('priority_id'=>$id,'teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'state'=>'active','replayed'=>false);
            }
            if(!$live){
                $this->repository->insertAudit($this->auditPayload('teacher_booking_priority_absent',$teacherId,0,$scopeKind,$courseScopeId,$reasonCode,$digest,$now,$actor));
                $this->repository->commit();
                return array('priority_id'=>0,'teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'state'=>'inactive','replayed'=>true);
            }
            $id=(int)$live->id;
            $this->repository->closePriority($id,$now,$actor);
            $this->repository->insertAudit($this->auditPayload('teacher_booking_priority_removed',$teacherId,$id,$scopeKind,$courseScopeId,$reasonCode,$digest,$now,$actor));
            $this->repository->commit();
            return array('priority_id'=>$id,'teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'state'=>'inactive','replayed'=>false);
        }catch(\Throwable $error){
            $this->repository->rollback();
            throw $error;
        }
    }

    private function auditPayload(string $eventType,int $teacherId,int $priorityId,string $scopeKind,int $courseScopeId,string $reasonCode,string $digest,string $now,int $actor):array{
        return array(
            'aggregate_type'=>'teacher','aggregate_id'=>$teacherId,'event_type'=>$eventType,
            'actor_type'=>'user','actor_id'=>$actor,'reason_code'=>$reasonCode,
            'safe_detail'=>(string)json_encode(array('priority_id'=>$priorityId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId)),
            'idempotency_key'=>$digest,'occurred_at'=>$now,
        );
    }
}
