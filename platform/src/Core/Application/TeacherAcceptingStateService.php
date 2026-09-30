<?php
namespace Delnavazan\Platform\Core\Application;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeachingEligibilityRepository;
final class TeacherAcceptingStateService {
    public function __construct(private ?TeachingEligibilityRepository $repo=null){$this->repo??=new TeachingEligibilityRepository();}
    public function set(array $input):int{if(!current_user_can('dzn_manage_teaching_eligibility'))throw new \RuntimeException('Unauthorized');$teacher=Normalizer::id($input['teacher_id']??null);$state=Normalizer::one($input['state']??null,['accepting','limited','paused'],'accepting state');$reason=Normalizer::text($input['reason_code']??null,64);$now=current_time('mysql',true);$this->repo->begin();try{$row=$this->repo->teacherForUpdate($teacher);if(!$row)throw new \InvalidArgumentException('Teacher not found');$id=$this->repo->saveAccepting($teacher,$state,$reason,$now,get_current_user_id()?:null);$this->repo->commit();return$id;}catch(\Throwable$e){$this->repo->rollback();throw$e;}}
}
