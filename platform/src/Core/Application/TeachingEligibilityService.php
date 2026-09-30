<?php
namespace Delnavazan\Platform\Core\Application;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeachingEligibilityRepository;

final class TeachingEligibilityService {
    public function __construct(private ?TeachingEligibilityRepository $repo=null){$this->repo??=new TeachingEligibilityRepository();}
    public function setEligibility(array $input):int{$this->admin();$teacher=Normalizer::id($input['teacher_id']??null);$course=Normalizer::id($input['course_id']??null);$status=Normalizer::one($input['status']??'active',['active','inactive'],'eligibility status');[$from,$until]=$this->dates($input);$reason=$this->reason($input['reason_code']??null);$now=current_time('mysql',true);$this->repo->begin();try{$teacherRow=$this->repo->teacherForUpdate($teacher);$courseRow=$this->repo->courseForUpdate($course);if(!$courseRow||!$teacherRow)throw new \InvalidArgumentException('Teacher and Course are required');$id=$this->repo->saveEligibility($teacher,$course,$status,$from,$until,$reason,$now,get_current_user_id()?:null);$this->repo->commit();return$id;}catch(\Throwable$e){$this->repo->rollback();throw$e;}}
    public function effectivelyEligible(int $teacherId,int $courseId):bool{return $this->repo->effectiveEligibility(Normalizer::id($teacherId),Normalizer::id($courseId),current_time('mysql',true));}
    private function admin():void{if(!current_user_can('dzn_manage_teaching_eligibility'))throw new \RuntimeException('Unauthorized');}
    private function dates(array $input):array{$from=$this->utcDate($input['effective_from']??null);$until=$this->utcDate($input['effective_until']??null);if($from&&$until&&$until<=$from)throw new \InvalidArgumentException('Eligibility end must follow start');return[$from,$until];}
    private function utcDate(mixed $value):?string{if($value===null||$value==='')return null;$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$value,new \DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d H:i:s')!==(string)$value)throw new \InvalidArgumentException('Invalid UTC effective date');return(string)$value;}
    private function reason(mixed $value):?string{return Normalizer::text($value,64);}
}
