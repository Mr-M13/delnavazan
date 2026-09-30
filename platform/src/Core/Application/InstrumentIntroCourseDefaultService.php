<?php
namespace Delnavazan\Platform\Core\Application;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeachingEligibilityRepository;
final class InstrumentIntroCourseDefaultService {
    public function __construct(private ?TeachingEligibilityRepository $repo=null){$this->repo??=new TeachingEligibilityRepository();}
    public function set(array $input):int{if(!current_user_can('dzn_manage_teaching_eligibility'))throw new \RuntimeException('Unauthorized');$instrument=Normalizer::id($input['instrument_id']??null);$course=Normalizer::id($input['course_id']??null);$status=Normalizer::one($input['status']??'active',['active','inactive'],'default status');$reason=Normalizer::text($input['reason_code']??null,64);$now=current_time('mysql',true);$this->repo->begin();try{$i=$this->repo->instrumentForUpdate($instrument);$c=$this->repo->courseForUpdate($course);if(!$i||$i->status!=='active'||$i->archived_at!==null||!$c||$c->status!=='active'||$c->archived_at!==null||(int)$c->instrument_id!==$instrument||$c->course_type!=='introductory')throw new \InvalidArgumentException('Valid active Intro Course for Instrument required');$id=$this->repo->saveDefault($instrument,$course,$status,$reason,$now,get_current_user_id()?:null);$this->repo->commit();return$id;}catch(\Throwable$e){$this->repo->rollback();throw$e;}}
    public function resolve(int $instrumentId):?object{return $this->repo->resolveDefault(Normalizer::id($instrumentId));}
}
