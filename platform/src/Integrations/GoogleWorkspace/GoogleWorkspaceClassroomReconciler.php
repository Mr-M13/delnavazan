<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

/**
 * Bounded dry-run and recovery for missing or incomplete organizer classrooms.
 *
 * The report is deliberately descriptive and secret-free: it returns database identities, UTC
 * instants and controlled state/reason codes, never e-mail addresses, provider object ids, Meet URLs
 * or credentials. Discovery is opt-in so a deployment cannot trigger a production-wide backfill
 * before its dry run has been reviewed.
 */
final class GoogleWorkspaceClassroomReconciler {
    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?GoogleWorkspaceClassroomService $classrooms=null,
        private ?BookingCalendarProjectionService $bookings=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
    }

    /** Whether the scheduled worker may discover and repair rows that do not yet exist. */
    public static function automaticEnabled():bool{
        if(defined('DZN_MEET_AUTO_RECONCILE')){
            $value=strtolower(trim((string)constant('DZN_MEET_AUTO_RECONCILE')));
            return !in_array($value,array('0','false','off','no',''),true);
        }
        $env=getenv('DZN_MEET_AUTO_RECONCILE');
        if(is_string($env)&&trim($env)!=='')return !in_array(strtolower(trim($env)),array('0','false','off','no'),true);
        if(function_exists('get_option'))return (string)get_option('dzn_meet_auto_reconcile','0')==='1';
        return false;
    }

    /**
     * Exact affected records and intended changes. No provider call or database mutation occurs.
     *
     * @return array<string,mixed>
     */
    public function report(int $limit=100):array{
        $limit=max(1,min(500,$limit));
        $missingLessons=$this->repository->missingLessonClassrooms($limit);
        $missingBookings=$this->repository->missingBookingClassrooms($limit);
        $staleLessons=$this->repository->staleLessonClassrooms($limit);
        $staleBookings=$this->repository->staleBookingClassrooms($limit);
        $teacherMismatches=$this->repository->teacherMismatchClassrooms($limit);
        $retryable=$this->retryableClassrooms($limit);
        $unconfirmed=$this->repository->unconfirmedBookingProjections($limit);
        $failedBookings=$this->repository->failedBookingProjections($limit);
        $blocked=$this->repository->blockedClassrooms($limit);

        return array(
            'generated_at'=>gmdate('Y-m-d H:i:s'),
            'automatic_reconciliation'=>self::automaticEnabled()?'enabled':'disabled',
            'counts'=>array(
                'missing_lesson_classrooms'=>count($missingLessons),
                'missing_booking_classrooms'=>count($missingBookings),
                'stale_lesson_classrooms'=>count($staleLessons),
                'stale_booking_classrooms'=>count($staleBookings),
                'teacher_mismatch_classrooms'=>count($teacherMismatches),
                'retryable_classrooms'=>count($retryable),
                'unconfirmed_booking_projections'=>count($unconfirmed),
                'failed_booking_projections'=>count($failedBookings),
                'blocked_or_failed_classrooms'=>count($blocked),
            ),
            'missing_lesson_classrooms'=>$this->lessonRows($missingLessons,'create_organizer_event_space_and_cohost'),
            'missing_booking_classrooms'=>$this->bookingRows($missingBookings,'create_organizer_event_space_and_cohost'),
            'stale_lesson_classrooms'=>$this->staleRows($staleLessons,'patch_existing_event_to_current_schedule'),
            'stale_booking_classrooms'=>$this->staleBookingRows($staleBookings,'patch_existing_booking_event_to_current_instant'),
            'teacher_mismatch_classrooms'=>$this->classroomRows($teacherMismatches,'assign_current_teacher_as_cohost_and_update_event'),
            'retryable_classrooms'=>$this->classroomRows($retryable,'bounded_retry_existing_provider_projection'),
            'unconfirmed_booking_projections'=>$this->bookingRows($unconfirmed,'resume_booking_projection'),
            'failed_booking_projections'=>$this->bookingRows($failedBookings,'manual_administrator_retry_required'),
            'blocked_or_failed_classrooms'=>$this->classroomRows($blocked,'administrator_action_required'),
        );
    }

    /**
     * Provision missing eligible rows, retry bounded transient failures, and optionally discover new
     * missing classrooms. A dry run never calls Google and never mutates local state.
     *
     * @return array{discover:bool,dry_run:bool,attempted:int,succeeded:int,failed:int,skipped:int,actions:array<int,array>}
     */
    public function run(int $limit=25,bool $discover=false,bool $dryRun=false):array{
        $limit=max(1,min(100,$limit));
        $actions=array();$attempted=0;$succeeded=0;$failed=0;$skipped=0;
        $seenBookings=array();

        if($discover){
            foreach($this->repository->missingLessonClassrooms($limit) as $row){
                $actions[]=$this->act($dryRun,'lesson',(int)$row->lesson_id,'create',
                    fn()=>($this->classrooms??=new GoogleWorkspaceClassroomService())->ensureForLesson((int)$row->lesson_id,(int)$row->schedule_version_id),
                    $attempted,$succeeded,$failed,$skipped);
            }
            foreach($this->repository->staleLessonClassrooms($limit) as $row){
                $actions[]=$this->act($dryRun,'lesson',(int)$row->lesson_id,'reschedule',
                    fn()=>($this->classrooms??=new GoogleWorkspaceClassroomService())->rescheduleLesson((int)$row->lesson_id,(int)$row->current_schedule_version_id),
                    $attempted,$succeeded,$failed,$skipped);
            }
            foreach($this->repository->staleBookingClassrooms($limit) as $row){
                $id=(int)$row->booking_confirmation_id;
                $actions[]=$this->act($dryRun,'booking',$id,'reschedule',
                    fn()=>($this->classrooms??=new GoogleWorkspaceClassroomService())->rescheduleForBooking(
                        $id,(string)$row->current_starts_at_utc,(string)$row->current_ends_at_utc,(string)$row->current_schedule_timezone
                    ),
                    $attempted,$succeeded,$failed,$skipped);
            }
            foreach($this->repository->teacherMismatchClassrooms($limit) as $row){
                $actions[]=$this->act($dryRun,'lesson',(int)$row->lesson_id,'replace_teacher',
                    fn()=>($this->classrooms??=new GoogleWorkspaceClassroomService())->replaceTeacher((int)$row->lesson_id,(int)$row->current_teacher_id),
                    $attempted,$succeeded,$failed,$skipped);
            }
            foreach($this->repository->missingBookingClassrooms($limit) as $row){
                $id=(int)$row->confirmation_id;
                $actions[]=$this->act($dryRun,'booking',$id,'create',
                    fn()=>($this->classrooms??=new GoogleWorkspaceClassroomService())->ensureForBooking($id),
                    $attempted,$succeeded,$failed,$skipped);
            }
        }

        foreach($this->retryableClassrooms($limit) as $row){
            if((int)$row->lesson_id>0){
                $version=$this->repository->currentScheduleVersion((int)$row->lesson_id);
                if(!$version||(int)$version->applicable_slot!==1||$version->superseded_at!==null){$skipped++;continue;}
                $lessonId=(int)$row->lesson_id;$versionId=(int)$version->id;
                $actions[]=$this->act($dryRun,'lesson',$lessonId,'retry',
                    fn()=>($this->classrooms??=new GoogleWorkspaceClassroomService())->ensureForLesson($lessonId,$versionId),
                    $attempted,$succeeded,$failed,$skipped);
            }elseif((int)$row->booking_confirmation_id>0){
                $id=(int)$row->booking_confirmation_id;
                if(isset($seenBookings[$id])){continue;}
                $seenBookings[$id]=true;
                $actions[]=$this->act($dryRun,'booking',$id,'retry',
                    fn()=>($this->bookings??=new BookingCalendarProjectionService())->dispatch($id),
                    $attempted,$succeeded,$failed,$skipped);
            }else{
                $skipped++;
            }
        }

        foreach($this->repository->unconfirmedBookingProjections($limit) as $row){
            $id=(int)$row->confirmation_id;
            if(isset($seenBookings[$id]))continue;
            $seenBookings[$id]=true;
            $actions[]=$this->act($dryRun,'booking',$id,'resume',
                fn()=>($this->bookings??=new BookingCalendarProjectionService())->dispatch($id),
                $attempted,$succeeded,$failed,$skipped);
        }

        return array(
            'discover'=>$discover,'dry_run'=>$dryRun,
            'attempted'=>$attempted,'succeeded'=>$succeeded,'failed'=>$failed,'skipped'=>$skipped,
            'actions'=>$actions,
        );
    }

    /** Existing rows only; safe for the recurring worker while broad discovery remains opt-in. */
    private function retryableClassrooms(int $limit):array{
        $now=time();
        return array_values(array_filter(
            $this->repository->retryableClassrooms($limit),
            static fn(object $row):bool=>MeetProvisioningRule::mayAutoRetryClassroom(
                (string)$row->classroom_state,
                (string)($row->cohost_state??MeetCoHostRule::PENDING),
                trim((string)($row->failure_reason_code??''))?:null,
                trim((string)($row->cohost_next_attempt_at??''))?:null,
                (int)($row->cohost_attempt_count??0),
                $now
            )
        ));
    }

    private function act(bool $dryRun,string $kind,int $id,string $operation,callable $call,int &$attempted,int &$succeeded,int &$failed,int &$skipped):array{
        $action=array('kind'=>$kind,'id'=>$id,'operation'=>$operation,'state'=>$dryRun?'would_run':'pending');
        if($dryRun)return $action;
        $attempted++;
        try{
            $result=$call();
            $action['state']='completed';
            $action['result_state']=is_array($result)?(string)($result['state']??($result['cancelled']??false?'cancelled':'completed')):'completed';
            $succeeded++;
        }catch(\Throwable $error){
            $message=trim($error->getMessage());
            $action['state']='failed';
            $action['reason_code']=preg_match('/^[a-z0-9_]{3,64}$/D',$message)?$message:'provisioning_failed';
            $failed++;
        }
        return $action;
    }

    private function lessonRows(array $rows,string $intent):array{
        return array_map(static fn(object $row):array=>array(
            'lesson_id'=>(int)$row->lesson_id,
            'lesson_uid'=>(string)$row->lesson_uid,
            'schedule_version_id'=>(int)$row->schedule_version_id,
            'teacher_id'=>(int)$row->teacher_id,
            'student_id'=>(int)$row->student_id,
            'starts_at_utc'=>(string)$row->starts_at_utc,
            'ends_at_utc'=>(string)$row->ends_at_utc,
            'schedule_timezone'=>(string)$row->schedule_timezone,
            'intent'=>$intent,
        ),$rows);
    }

    private function bookingRows(array $rows,string $intent):array{
        return array_map(static fn(object $row):array=>array(
            'confirmation_id'=>(int)$row->confirmation_id,
            'confirmation_uid'=>(string)$row->confirmation_uid,
            'teacher_id'=>(int)$row->teacher_id,
            'booking_request_id'=>(int)($row->booking_request_id??0),
            'starts_at_utc'=>(string)$row->starts_at_utc,
            'ends_at_utc'=>(string)$row->ends_at_utc,
            'schedule_timezone'=>(string)$row->schedule_timezone,
            'state'=>(string)($row->state??$row->confirmation_state??''),
            'failure_reason_code'=>($row->failure_reason_code??null)!==null?(string)$row->failure_reason_code:null,
            'attempt_count'=>(int)($row->attempt_count??0),
            'intent'=>$intent,
        ),$rows);
    }

    private function staleRows(array $rows,string $intent):array{
        return array_map(static fn(object $row):array=>array(
            'classroom_id'=>(int)$row->id,
            'lesson_id'=>(int)$row->lesson_id,
            'lesson_uid'=>(string)$row->lesson_uid,
            'provider_schedule_version_id'=>(int)$row->schedule_version_id,
            'current_schedule_version_id'=>(int)$row->current_schedule_version_id,
            'current_starts_at_utc'=>(string)$row->current_starts_at_utc,
            'current_ends_at_utc'=>(string)$row->current_ends_at_utc,
            'current_schedule_timezone'=>(string)$row->current_schedule_timezone,
            'intent'=>$intent,
        ),$rows);
    }

    private function staleBookingRows(array $rows,string $intent):array{
        return array_map(static fn(object $row):array=>array(
            'classroom_id'=>(int)$row->id,
            'confirmation_id'=>(int)$row->booking_confirmation_id,
            'confirmation_uid'=>(string)$row->confirmation_uid,
            'provider_starts_at_utc'=>(string)$row->starts_at_utc,
            'current_starts_at_utc'=>(string)$row->current_starts_at_utc,
            'current_ends_at_utc'=>(string)$row->current_ends_at_utc,
            'current_schedule_timezone'=>(string)$row->current_schedule_timezone,
            'intent'=>$intent,
        ),$rows);
    }

    private function classroomRows(array $rows,string $intent):array{
        return array_map(static fn(object $row):array=>array(
            'classroom_id'=>(int)$row->id,
            'lesson_id'=>(int)($row->lesson_id??0),
            'booking_confirmation_id'=>(int)($row->booking_confirmation_id??0),
            'teacher_id'=>(int)$row->teacher_id,
            'classroom_state'=>(string)$row->classroom_state,
            'cohost_state'=>(string)($row->cohost_state??MeetCoHostRule::PENDING),
            'failure_reason_code'=>($row->failure_reason_code??null)!==null?(string)$row->failure_reason_code:null,
            'cohost_error_code'=>($row->cohost_error_code??null)!==null?(string)$row->cohost_error_code:null,
            'cohost_attempt_count'=>(int)($row->cohost_attempt_count??0),
            'cohost_next_attempt_at'=>($row->cohost_next_attempt_at??null)!==null?(string)$row->cohost_next_attempt_at:null,
            'starts_at_utc'=>(string)$row->starts_at_utc,
            'intent'=>$intent,
        ),$rows);
    }
}
