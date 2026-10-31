<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure golden-hour coverage projection.
 *
 * The input availability intervals must already be authoritative, resolved facts
 * (for example TeacherAvailabilityService::effective()). Blocked intervals still
 * win here as a defensive guard against unresolved or overlapping data. The
 * analyzer never reads the database, so slot, week-effective window and
 * daylight-saving behaviour can be exercised without WordPress.
 */
final class AcademyCoverageAnalyzer {
    public const STATE_AVAILABLE = 'available';
    public const STATE_NO_ELIGIBLE_TEACHERS = 'no_eligible_teachers';
    public const STATE_UNKNOWN = 'unknown';

    /**
     * @param array<int,array{id:int,name:string}> $instruments
     * @param array<int,array{id:int,name:string,instrument_ids:array<int,int>,availability:array<int,array{state:string,starts_at_utc:string,ends_at_utc:string}>}> $teachers
     * @param array<int,int> $unknownInstrumentIds
     * @param null|list<array<string,mixed>> $windows Effective per-date windows; null uses the retained static demand-window interface.
     */
    public static function analyze(array $instruments,array $teachers,?\DateTimeImmutable $week=null,array $unknownInstrumentIds=array(),string $sort='gap',?array $windows=null):array{
        $weekStart=AcademyDemandWindowRule::weekStart($week);
        $normalized=self::normalizeWindows($weekStart,$windows);
        $regions=$normalized['regions'];
        $unknown=array_fill_keys(array_map('intval',$unknownInstrumentIds),true);
        $teachers=self::normalizeTeachers($teachers);
        $rows=array();

        foreach($instruments as $instrument){
            if(!is_array($instrument))continue;
            $id=(int)($instrument['id']??0);
            if($id<1)continue;
            $name=trim((string)($instrument['name']??''));
            if($name==='')$name='Instrument '.$id;
            $eligible=array();
            foreach($teachers as$teacherId=>$teacher)if(in_array($id,$teacher['instrument_ids'],true))$eligible[$teacherId]=$teacher;
            $areas=array();
            foreach($regions as$regionKey=>$region)$areas[$regionKey]=self::cell($regionKey,$region,$weekStart,$eligible,isset($unknown[$id])||($region['data_state']??'ok')==='invalid');
            $gapSlots=0;$coveredSlots=0;$singleSlots=0;
            foreach($areas as$area){
                if($area['metric_state']===self::STATE_AVAILABLE){$gapSlots+=(int)$area['total_slots']-(int)$area['covered_slots'];$coveredSlots+=(int)$area['covered_slots'];$singleSlots+=(int)$area['single_teacher_slots'];}
                else{$gapSlots+=(int)$area['total_slots'];}
            }
            $rows[]=array('id'=>$id,'name'=>$name,'areas'=>$areas,'eligible_teacher_count'=>count($eligible),'total_gap_slots'=>$gapSlots,'total_covered_slots'=>$coveredSlots,'total_single_teacher_slots'=>$singleSlots);
        }

        self::sortRows($rows,$sort);
        $totals=array_values(array_unique(array_map(static fn(array $region):int=>(int)($region['total_slots']??0),$regions)));

        return array(
            'status'=>$rows?'ok':'empty',
            'week_start'=>$weekStart->format('Y-m-d'),
            'week_label'=>AcademyDemandWindowRule::weekLabel($weekStart),
            'regions'=>$regions,
            'instruments'=>$rows,
            'teacher_count'=>count($teachers),
            'total_slots_per_area'=>count($totals)===1?$totals[0]:null,
        );
    }

    /**
     * Normalize either the retained static window interface or finance-resolved
     * per-date definitions. The latter may change weekdays, hours, timezone or
     * label during the selected week.
     */
    private static function normalizeWindows(\DateTimeImmutable $weekStart,?array $windows):array{
        if($windows===null)$windows=self::defaultWindows($weekStart);
        $static=AcademyDemandWindowRule::regions();
        $regions=array();
        foreach($windows as$window){
            if(!is_array($window))continue;
            $regionKey=trim((string)($window['region']??''));
            if($regionKey==='')continue;
            $weekday=(int)($window['weekday']??0);
            if($weekday<1||$weekday>7)continue;
            $fallbackLabel=(string)($static[$regionKey]['label']??$regionKey);
            $fallbackTimezone=(string)($static[$regionKey]['timezone']??'UTC');
            $label=trim((string)($window['label']??$fallbackLabel));
            if($label==='')$label=$fallbackLabel;
            $timezone=trim((string)($window['timezone']??$fallbackTimezone));
            $start=self::minuteValue($window['start_minute']??($window['start']??null));
            $end=self::minuteValue($window['end_minute']??($window['end']??null));
            $date=self::dateValue($window['date']??null,$weekStart,$weekday);
            if(!isset($regions[$regionKey]))$regions[$regionKey]=array('label'=>$label,'timezone'=>$timezone,'timezones'=>array(),'timezone_label'=>$timezone,'days'=>array(),'data_state'=>'ok','total_slots'=>0);
            if($start===null||$end===null||$end<=$start||!self::timezoneValid($timezone)){
                $regions[$regionKey]['data_state']='invalid';
                continue;
            }
            $regions[$regionKey]['label']=$label;
            $regions[$regionKey]['timezone']=$timezone;
            $regions[$regionKey]['timezones'][$timezone]=$timezone;
            $regions[$regionKey]['days'][$weekday]=array(
                'weekday'=>$weekday,
                'date'=>$date,
                'start_minute'=>$start,
                'end_minute'=>$end,
                'timezone'=>$timezone,
                'label'=>$label,
                'version'=>(int)($window['version']??0),
            );
        }
        foreach($regions as&$region){
            ksort($region['days']);
            $timezones=array_values($region['timezones']);
            if(!$timezones&&$region['timezone']!=='')$timezones[]=$region['timezone'];
            $region['timezone']=$timezones[0]??'UTC';
            $region['timezone_label']=count($timezones)===1?$timezones[0]:(count($timezones)>1?implode(' / ',$timezones):$region['timezone']);
            $region['total_slots']=0;
            foreach($region['days']as$day)$region['total_slots']+=self::slotCount((int)$day['start_minute'],(int)$day['end_minute']);
        }
        unset($region);
        return array('regions'=>$regions);
    }

    private static function defaultWindows(\DateTimeImmutable $weekStart):array{
        $out=array();
        foreach(AcademyDemandWindowRule::regions()as$regionKey=>$region){
            $start=self::minuteValue($region['start']??null);
            $end=self::minuteValue($region['end']??null);
            if($start===null||$end===null||$end<=$start)continue;
            for($weekday=1;$weekday<=7;$weekday++){
                $out[]=array('region'=>$regionKey,'label'=>(string)$region['label'],'timezone'=>(string)$region['timezone'],'date'=>$weekStart->modify('+'.($weekday-1).' days')->format('Y-m-d'),'weekday'=>$weekday,'start_minute'=>$start,'end_minute'=>$end);
            }
        }
        return $out;
    }

    private static function normalizeTeachers(array $teachers):array{
        $out=array();
        foreach($teachers as$teacher){
            if(!is_array($teacher))continue;
            $id=(int)($teacher['id']??0);
            if($id<1)continue;
            if(!isset($out[$id]))$out[$id]=array('id'=>$id,'name'=>'','instrument_ids'=>array(),'availability'=>array(),'invalid'=>false);
            $name=trim((string)($teacher['name']??''));
            if($name!==''&&$out[$id]['name']==='')$out[$id]['name']=$name;
            foreach((array)($teacher['instrument_ids']??array())as$instrumentId){$instrumentId=(int)$instrumentId;if($instrumentId>0)$out[$id]['instrument_ids'][$instrumentId]=$instrumentId;}
            foreach((array)($teacher['availability']??array())as$interval){
                if(!is_array($interval)){$out[$id]['invalid']=true;continue;}
                $state=(string)($interval['state']??'');
                $start=self::utc($interval['starts_at_utc']??null);
                $end=self::utc($interval['ends_at_utc']??null);
                if($start===null||$end===null||$end<=$start||!in_array($state,array('preferred','requestable','blocked'),true)){$out[$id]['invalid']=true;continue;}
                $key=$state.'|'.$start.'|'.$end;
                $out[$id]['availability'][$key]=array('state'=>$state,'starts_at_utc'=>$start,'ends_at_utc'=>$end);
            }
        }
        foreach($out as&$teacher){
            if($teacher['name']==='')$teacher['name']='Teacher '.$teacher['id'];
            $teacher['instrument_ids']=array_values($teacher['instrument_ids']);
            $teacher['availability']=array_values($teacher['availability']);
        }
        unset($teacher);
        return $out;
    }

    private static function cell(string $regionKey,array $region,\DateTimeImmutable $week,array $eligible,bool $unknown):array{
        $slots=array();$gaps=array();$covered=0;$single=0;$multi=0;$invalid=$unknown;$contributing=array();$times=array();$days=array();
        for($weekday=1;$weekday<=7;$weekday++){
            $day=$region['days'][$weekday]??null;
            $daySlots=array();
            if(is_array($day)){
                $dayGap=null;
                for($minute=(int)$day['start_minute'];$minute+AcademyDemandWindowRule::SLOT_MINUTES<=(int)$day['end_minute'];$minute+=AcademyDemandWindowRule::SLOT_MINUTES){
                    $times[$minute]=true;
                    $start=self::slotUtc((string)$day['date'],$minute,(string)$day['timezone']);
                    $end=$start->modify('+'.AcademyDemandWindowRule::SLOT_MINUTES.' minutes');
                    $startUtc=$start->format('Y-m-d H:i:s');$endUtc=$end->format('Y-m-d H:i:s');
                    $available=array();
                    foreach($eligible as$teacherId=>$teacher){
                        if($teacher['invalid']){$invalid=true;continue;}
                        $hasAvailability=false;$blocked=false;
                        foreach($teacher['availability']as$interval){
                            if($interval['starts_at_utc']<=$startUtc&&$interval['ends_at_utc']>=$endUtc){
                                if($interval['state']==='blocked')$blocked=true;
                                elseif($interval['state']==='preferred'||$interval['state']==='requestable')$hasAvailability=true;
                            }
                        }
                        if($hasAvailability&&!$blocked){$available[$teacherId]=$teacher['name'];$contributing[$teacherId]=$teacher['name'];}
                    }
                    $count=count($available);
                    if($invalid)$state='unknown';
                    elseif($count===0)$state='gap';
                    elseif($count===1)$state='fragile';
                    else$state='covered';
                    if($state==='covered')$covered++;
                    elseif($state==='fragile'){$covered++;$single++;}
                    if($state==='covered'&&$count>=2)$multi++;
                    $slot=array(
                        'weekday'=>$weekday,
                        'day'=>AcademyDemandWindowRule::weekdayLabel($weekday),
                        'date'=>(string)$day['date'],
                        'timezone'=>(string)$day['timezone'],
                        'minute'=>$minute,
                        'end_minute'=>$minute+AcademyDemandWindowRule::SLOT_MINUTES,
                        'time'=>AcademyDemandWindowRule::timeLabel($minute),
                        'end_time'=>AcademyDemandWindowRule::timeLabel($minute+AcademyDemandWindowRule::SLOT_MINUTES),
                        'state'=>$state,
                        'teacher_count'=>$count,
                        'teacher_names'=>array_values($available),
                        'starts_at_utc'=>$startUtc,
                        'ends_at_utc'=>$endUtc,
                    );
                    $slots[]=$slot;$daySlots[]=$slot;
                    if($state==='gap'){
                        if($dayGap!==null&&$dayGap['end_minute']===$minute){$dayGap['end_minute']=$minute+AcademyDemandWindowRule::SLOT_MINUTES;$dayGap['slots']++;}
                        else{if($dayGap!==null)$gaps[]=$dayGap;$dayGap=array('weekday'=>$weekday,'day'=>AcademyDemandWindowRule::weekdayLabel($weekday),'start_minute'=>$minute,'end_minute'=>$minute+AcademyDemandWindowRule::SLOT_MINUTES,'slots'=>1);}
                    }elseif($dayGap!==null){$gaps[]=$dayGap;$dayGap=null;}
                }
                if($dayGap!==null)$gaps[]=$dayGap;
            }
            $days[$weekday]=array(
                'weekday'=>$weekday,
                'day'=>AcademyDemandWindowRule::weekdayLabel($weekday),
                'has_window'=>is_array($day),
                'date'=>(string)($day['date']??''),
                'timezone'=>(string)($day['timezone']??''),
                'start_minute'=>isset($day['start_minute'])?(int)$day['start_minute']:null,
                'end_minute'=>isset($day['end_minute'])?(int)$day['end_minute']:null,
                'slots'=>$daySlots,
            );
        }
        $timeValues=array_keys($times);sort($timeValues,SORT_NUMERIC);
        foreach($gaps as&$gap){$gap['start']=AcademyDemandWindowRule::timeLabel($gap['start_minute']);$gap['end']=AcademyDemandWindowRule::timeLabel($gap['end_minute']);$gap['label']=$gap['day'].' '.$gap['start'].'–'.$gap['end'];}
        unset($gap);
        usort($gaps,static fn(array $a,array $b):int=>$b['slots']<=>$a['slots']?:$a['weekday']<=>$b['weekday']?:$a['start_minute']<=>$b['start_minute']);

        $total=count($slots);
        $metric=$unknown||$invalid||$total<1?self::STATE_UNKNOWN:($eligible?self::STATE_AVAILABLE:self::STATE_NO_ELIGIBLE_TEACHERS);
        $resilience=$metric===self::STATE_UNKNOWN?'unknown':($covered===0?'gap':($single>0?'fragile':'covered'));
        $names=array_values($contributing);sort($names,SORT_NATURAL|SORT_FLAG_CASE);
        return array(
            'region'=>$regionKey,
            'region_label'=>(string)$region['label'],
            'timezone'=>(string)$region['timezone'],
            'timezone_label'=>(string)($region['timezone_label']??$region['timezone']),
            'metric_state'=>$metric,
            'coverage_percent'=>$metric===self::STATE_AVAILABLE?(int)round(100*$covered/$total):null,
            'covered_slots'=>$metric===self::STATE_AVAILABLE?$covered:($metric===self::STATE_NO_ELIGIBLE_TEACHERS?0:null),
            'single_teacher_slots'=>$metric===self::STATE_AVAILABLE?$single:($metric===self::STATE_NO_ELIGIBLE_TEACHERS?0:null),
            'multi_teacher_slots'=>$metric===self::STATE_AVAILABLE?$multi:($metric===self::STATE_NO_ELIGIBLE_TEACHERS?0:null),
            'total_slots'=>$total,
            'gap_slots'=>$metric===self::STATE_AVAILABLE?$total-$covered:$total,
            'resilience'=>$resilience,
            'eligible_teacher_count'=>count($eligible),
            'teacher_count'=>count($names),
            'teacher_names'=>$names,
            'gaps'=>$gaps,
            'longest_gaps'=>array_slice($gaps,0,5),
            'times'=>$timeValues,
            'days'=>$days,
            'slots'=>$slots,
        );
    }

    private static function minuteValue(mixed $value):?int{
        if(is_int($value)||is_float($value)){ $minute=(int)$value; return $minute>=0&&$minute<=1440?$minute:null; }
        if(!is_string($value))return null;
        $value=trim($value);
        if(preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/D',$value,$matches)){
            $hour=(int)$matches[1];$minute=(int)$matches[2];
            if($hour===24&&$minute===0)return 1440;
            if($hour<0||$hour>23||$minute<0||$minute>59)return null;
            return $hour*60+$minute;
        }
        if(preg_match('/^[0-9]+$/D',$value)){ $minute=(int)$value; return $minute>=0&&$minute<=1440?$minute:null; }
        return null;
    }

    private static function dateValue(mixed $value,\DateTimeImmutable $weekStart,int $weekday):string{
        if(is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)){
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
            if($date&&$date->format('Y-m-d')===$value)return $value;
        }
        return $weekStart->modify('+'.($weekday-1).' days')->format('Y-m-d');
    }

    private static function timezoneValid(string $timezone):bool{
        if($timezone==='')return false;
        try{new \DateTimeZone($timezone);return true;}catch(\Throwable){return false;}
    }

    private static function slotCount(int $start,int $end):int{
        return $end>$start?intdiv($end-$start,AcademyDemandWindowRule::SLOT_MINUTES):0;
    }

    private static function slotUtc(string $date,int $minute,string $timezone):\DateTimeImmutable{
        $local=new \DateTimeImmutable($date.' '.AcademyDemandWindowRule::timeLabel($minute).':00',new \DateTimeZone($timezone));
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function utc(mixed $value):?string{
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return $date&&$date->format('Y-m-d H:i:s')===$value?$value:null;
    }

    private static function sortRows(array &$rows,string $sort):void{
        if($sort==='alpha'){usort($rows,static fn(array $a,array $b):int=>strnatcasecmp($a['name'],$b['name'])?:$a['id']<=>$b['id']);return;}
        usort($rows,static fn(array $a,array $b):int=>$b['total_gap_slots']<=>$a['total_gap_slots']?:$b['total_single_teacher_slots']<=>$a['total_single_teacher_slots']?:strnatcasecmp($a['name'],$b['name'])?:$a['id']<=>$b['id']);
    }
}
