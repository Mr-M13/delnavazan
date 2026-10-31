<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\GoldenHourPolicyService;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;

/**
 * Read-only capacity-planning projection over canonical eligibility and declared
 * weekly availability. Existing bookings and student-specific constraints are
 * deliberately outside this planning view; the scheduling authority remains the
 * booking-time decision maker.
 */
final class AcademyCoverageReadService {
    public function matrix(?\DateTimeImmutable $week=null,string $sort='gap'):array{
        global $wpdb;
        $weekStart=AcademyDemandWindowRule::weekStart($week);
        try{
            $p=$wpdb->prefix.'dzn_';
            $instruments=$wpdb->get_results("SELECT id,name_fa,name_en FROM {$p}instruments WHERE status='active' AND archived_at IS NULL ORDER BY COALESCE(NULLIF(name_en,''),name_fa),id");
            if($wpdb->last_error!=='')throw new \RuntimeException('coverage_instruments_unavailable');
            $instruments=is_array($instruments)?$instruments:array();
            if(!$instruments)return $this->emptyResult('empty',$weekStart,'No active instruments are configured, so coverage cannot be calculated.');

            $rows=$wpdb->get_results("SELECT DISTINCT t.id teacher_id,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,c.instrument_id FROM {$p}teachers t INNER JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id AND o.state='active' AND o.readiness_state='ready' INNER JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id AND a.state IN ('accepting','limited') INNER JOIN {$p}teacher_course_eligibilities e ON e.teacher_id=t.id AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP()) AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP()) INNER JOIN {$p}courses c ON c.id=e.course_id AND c.status='active' AND c.archived_at IS NULL INNER JOIN {$p}teacher_availability_profiles ap ON ap.teacher_id=t.id AND ap.status='active' INNER JOIN {$p}teacher_availability_rules r ON r.profile_id=ap.id AND r.teacher_id=t.id AND r.status='active' AND r.state IN ('preferred','requestable') AND r.timezone=ap.timezone WHERE t.status='active' AND t.archived_at IS NULL");
            if($wpdb->last_error!=='')throw new \RuntimeException('coverage_teachers_unavailable');
            $rows=is_array($rows)?$rows:array();

            $byTeacher=array();
            foreach($rows as$row){
                $teacherId=(int)$row->teacher_id;$instrumentId=(int)$row->instrument_id;
                if($teacherId<1||$instrumentId<1)continue;
                if(!isset($byTeacher[$teacherId]))$byTeacher[$teacherId]=array('id'=>$teacherId,'name'=>trim((string)($row->teacher_name??'')),'instrument_ids'=>array());
                $byTeacher[$teacherId]['instrument_ids'][$instrumentId]=$instrumentId;
            }

            $windows=$this->windowsForWeek($weekStart);
            if(!$windows)return $this->emptyResult('empty',$weekStart,'No golden-hour windows are configured for the selected week, so coverage cannot be calculated.');

            $service=new TeacherAvailabilityService();
            $from=$weekStart->modify('-1 day')->format('Y-m-d 00:00:00');
            $until=$weekStart->modify('+8 days')->format('Y-m-d 00:00:00');
            $teachers=array();$unknownInstruments=array();$failed=0;
            foreach($byTeacher as$teacherId=>$teacher){
                try{$segments=$service->effective($teacherId,$from,$until);}
                catch(\Throwable){$failed++;foreach($teacher['instrument_ids']as$instrumentId)$unknownInstruments[$instrumentId]=$instrumentId;continue;}
                $availability=array();
                foreach($segments as$segment){
                    if(!isset($segment['state'],$segment['starts_at_utc'],$segment['ends_at_utc']))continue;
                    $availability[]=array('state'=>(string)$segment['state'],'starts_at_utc'=>(string)$segment['starts_at_utc'],'ends_at_utc'=>(string)$segment['ends_at_utc']);
                }
                $teachers[]=array('id'=>$teacherId,'name'=>$teacher['name'],'instrument_ids'=>array_values($teacher['instrument_ids']),'availability'=>$availability);
            }

            $result=AcademyCoverageAnalyzer::analyze(array_map(static fn(object $row):array=>array('id'=>(int)$row->id,'name'=>(string)($row->name_fa?:$row->name_en)),$instruments),$teachers,$weekStart,array_values($unknownInstruments),$sort,$windows);
            if($failed>0){
                $result['status']='partial';
                $result['errors']=array($failed===1?'One teacher availability record could not be read. Affected cells are marked Unknown.':$failed.' teacher availability records could not be read. Affected cells are marked Unknown.');
            }
            return $result;
        }catch(\Throwable){
            return $this->emptyResult('unavailable',$weekStart,'Coverage could not be calculated because the availability, catalogue, or golden-hour window data could not be read. No coverage percentage is shown.');
        }
    }

    /**
     * Resolve the shared structural coverage windows for the selected week.
     *
     * Finance owns the window contract. Coverage consumes `coverageWindows()`
     * directly: the operational areas are independent of the global Iran pay
     * policy, so disabling a bonus cannot remove a coverage window. If finance
     * later adds versioned per-area coverage windows, the replacement belongs at
     * this boundary; the presentation and availability calculation stay unchanged.
     *
     * @return list<array<string,mixed>>
     */
    private function windowsForWeek(\DateTimeImmutable $weekStart):array{
        $policy=new GoldenHourPolicyService();
        $structural=$policy->coverageWindows();
        $windows=array();
        for($offset=0;$offset<7;$offset++){
            $date=$weekStart->modify('+'.$offset.' days')->format('Y-m-d');
            $weekday=$offset+1;
            foreach($structural as$area=>$window){
                $weekdays=array_values(array_unique(array_map('intval',(array)($window['weekdays']??array(1,2,3,4,5,6,7)))));
                if(!in_array($weekday,$weekdays,true))continue;
                $start=GoldenHourRule::minutes((string)($window['start']??'16:00'));
                $end=GoldenHourRule::minutes((string)($window['end']??'21:00'));
                if($end<=$start)continue;
                $windows[]=array(
                    'region'=>(string)$area,
                    'label'=>(string)($window['label']??$area),
                    'timezone'=>(string)($window['timezone']??'UTC'),
                    'date'=>$date,
                    'weekday'=>$weekday,
                    'start_minute'=>$start,
                    'end_minute'=>$end,
                    'version'=>(int)($window['setting_version']??0),
                );
            }
        }
        return $windows;
    }

    private function emptyResult(string $status,\DateTimeImmutable $weekStart,string $message):array{
        return array(
            'status'=>$status,
            'week_start'=>$weekStart->format('Y-m-d'),
            'week_label'=>AcademyDemandWindowRule::weekLabel($weekStart),
            'regions'=>AcademyDemandWindowRule::regions(),
            'instruments'=>array(),
            'teacher_count'=>0,
            'total_slots_per_area'=>AcademyDemandWindowRule::SLOTS_PER_REGION,
            'message'=>$message,
            'errors'=>array(),
        );
    }
}
