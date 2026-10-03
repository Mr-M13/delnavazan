<?php
namespace Delnavazan\Platform\Core\Application;

/** Read-only capacity planning projection over canonical eligibility and weekly availability. */
final class AcademyCoverageReadService {
    public function matrix(?\DateTimeImmutable $week=null):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$week=$week?:new \DateTimeImmutable('monday this week',new \DateTimeZone('UTC'));
        $teachers=$wpdb->get_results("SELECT DISTINCT t.id teacher_id,c.instrument_id,r.weekday,r.local_start_time,r.local_end_time,r.timezone FROM {$p}teachers t INNER JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id AND o.state='active' AND o.readiness_state='ready' INNER JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id AND a.state IN ('accepting','limited') INNER JOIN {$p}teacher_course_eligibilities e ON e.teacher_id=t.id AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP()) AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP()) INNER JOIN {$p}courses c ON c.id=e.course_id AND c.status='active' AND c.archived_at IS NULL INNER JOIN {$p}teacher_availability_profiles ap ON ap.teacher_id=t.id AND ap.status='active' INNER JOIN {$p}teacher_availability_rules r ON r.profile_id=ap.id AND r.teacher_id=t.id AND r.status='active' AND r.state IN ('preferred','requestable') AND r.timezone=ap.timezone WHERE t.status='active' AND t.archived_at IS NULL");
        $instruments=$wpdb->get_results("SELECT id,name_fa,name_en FROM {$p}instruments WHERE status='active' AND archived_at IS NULL ORDER BY id");
        $regions=AcademyDemandWindowRule::regions();$out=[];
        foreach($instruments as$i)foreach($regions as$key=>$region){$covered=0;$total=70;$gaps=[];for($d=1;$d<=7;$d++)for($m=960;$m<1260;$m+=30){$target=$this->regionalSlotUtc($week,$d,$m,$region['timezone']);$count=0;foreach($teachers as$t)if((int)$t->instrument_id===(int)$i->id&&$this->covers($t,$target))$count++;if($count)$covered++;else $gaps[]=array('weekday'=>$d,'minute'=>$m);}$out[]=array('instrument_id'=>(int)$i->id,'instrument'=>(string)($i->name_fa?:$i->name_en),'region'=>$key,'region_label'=>$region['label'],'covered_slots'=>$covered,'total_slots'=>$total,'coverage_percent'=>(int)round(100*$covered/$total),'gaps'=>$gaps);}
        return $out;
    }
    private function regionalSlotUtc(\DateTimeImmutable $week,int $weekday,int $minute,string $timezone):\DateTimeImmutable{$date=$week->modify('+'.($weekday-1).' days');$local=new \DateTimeImmutable($date->format('Y-m-d').' '.sprintf('%02d:%02d',intdiv($minute,60),$minute%60),new \DateTimeZone($timezone));return $local->setTimezone(new \DateTimeZone('UTC'));}
    private function covers(object $rule,\DateTimeImmutable $utc):bool{$local=$utc->setTimezone(new \DateTimeZone((string)$rule->timezone));if((int)$local->format('N')!==(int)$rule->weekday)return false;$m=(int)$local->format('G')*60+(int)$local->format('i');$s=$this->minutes((string)$rule->local_start_time);$e=$this->minutes((string)$rule->local_end_time);return $m>=$s&&$m+30<=$e;}
    private function minutes(string $time):int{$p=array_map('intval',explode(':',$time));return($p[0]??0)*60+($p[1]??0);}
}
