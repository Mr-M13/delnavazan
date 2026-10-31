<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Pure presentation projection: one effective golden-hour policy, expressed only
 * in the Teacher's local calendar and timezone.
 *
 * This class neither calculates pay nor reads configuration. The caller supplies
 * the policy resolved by the finance authority. Intervals are split and clipped
 * into Teacher-local days so a window can cross midnight or a daylight-saving
 * transition without exposing the academy/policy clock to the Teacher.
 */
final class GoldenHourTeacherWindowProjection {
    /**
     * @param array<string,mixed> $policy Resolved policy from GoldenHourPolicyService::resolveAt().
     * @return array{
     *   state:string,enabled:bool,percentage:float,percentage_label:string,
     *   timezone:string,timezone_label:string,has_windows:bool,days:list<array<string,mixed>>
     * }
     */
    public static function forPolicy(array $policy,string $teacherTimezone,?\DateTimeImmutable $start=null,int $days=7,bool $bonusOnly=true):array{
        $teacherZone=self::zone($teacherTimezone);
        $policyTimezone=trim((string)($policy['timezone']??''));
        $policyZone=self::zone($policyTimezone);
        $percentageLabel=self::percentageLabel((int)($policy['bonus_basis_points']??0));
        if($teacherZone===null||$policyZone===null){
            return self::result('unavailable',$policy,$teacherTimezone,'',$percentageLabel,array());
        }
        $enabled=!empty($policy['enabled']);
        $basisPoints=(int)($policy['bonus_basis_points']??0);
        if($bonusOnly&&(!$enabled||$basisPoints<=0)){
            return self::result('inactive',$policy,$teacherTimezone,$policyTimezone,$percentageLabel,array());
        }
        $periods=self::periods($policy);
        if($periods===array()){
            return self::result('active',$policy,$teacherTimezone,$policyTimezone,$percentageLabel,array());
        }
        $weekdays=array_values(array_unique(array_map('intval',(array)($policy['weekdays']??array(1,2,3,4,5,6,7)))));
        if($weekdays===array())$weekdays=array(1,2,3,4,5,6,7);
        $start=$start!==null?$start->setTimezone($teacherZone):new \DateTimeImmutable('today',$teacherZone);
        $start=$start->setTime(0,0,0);
        $dayCount=max(1,min(14,$days));
        $out=array();
        for($offset=0;$offset<$dayCount;$offset++){
            $localDate=$start->modify('+'.$offset.' days');
            $dayStart=$localDate;
            $dayEnd=$localDate->modify('+1 day');
            $intervals=array();
            $policyDateBase=$localDate->setTimezone($policyZone)->setTime(0,0,0);
            // Policy dates immediately around the Teacher-local day cover midnight crossings.
            for($policyOffset=-1;$policyOffset<=1;$policyOffset++){
                $policyDate=$policyDateBase->modify('+'.$policyOffset.' days');
                if(!in_array((int)$policyDate->format('N'),$weekdays,true))continue;
                foreach($periods as$period){
                    $startWall=self::wall($policyDate->format('Y-m-d'),(string)$period['start'],$policyZone);
                    $endWall=self::wall($policyDate->format('Y-m-d'),(string)$period['end'],$policyZone);
                    if($endWall<=$startWall)$endWall=$endWall->modify('+1 day');
                    $clipStart=$startWall->setTimezone($teacherZone);
                    $clipEnd=$endWall->setTimezone($teacherZone);
                    if($clipEnd<=$dayStart||$clipStart>=$dayEnd)continue;
                    if($clipStart<$dayStart)$clipStart=$dayStart;
                    if($clipEnd>$dayEnd)$clipEnd=$dayEnd;
                    if($clipEnd<=$clipStart)continue;
                    $intervals[]=array('start'=>$clipStart,'end'=>$clipEnd);
                }
            }
            usort($intervals,static fn(array $left,array $right):int=>$left['start']<=>$right['start']);
            $merged=array();
            foreach($intervals as$interval){
                $last=count($merged)-1;
                if($last>=0&&$interval['start']<=$merged[$last]['end']){
                    if($interval['end']>$merged[$last]['end'])$merged[$last]['end']=$interval['end'];
                }else{
                    $merged[]=$interval;
                }
            }
            $localIntervals=array();
            foreach($merged as$interval){
                $minutes=max(0,(int)round(($interval['end']->getTimestamp()-$interval['start']->getTimestamp())/60));
                if($minutes<1)continue;
                $localIntervals[]=array(
                    'start'=>$interval['start']->format('H:i'),
                    'end'=>$interval['end']->format('H:i'),
                    'label'=>$interval['start']->format('H:i').'–'.$interval['end']->format('H:i'),
                    'minutes'=>$minutes,
                    'percentage'=>((int)$basisPoints)/100,
                    'percentage_label'=>$percentageLabel,
                );
            }
            $out[]=array(
                'date'=>$localDate->format('Y-m-d'),
                'weekday'=>(int)$localDate->format('N'),
                'has_window'=>$localIntervals!==array(),
                'intervals'=>$localIntervals,
                'total_minutes'=>array_sum(array_map(static fn(array $interval):int=>(int)$interval['minutes'],$localIntervals)),
            );
        }
        return self::result('active',$policy,$teacherTimezone,$policyTimezone,$percentageLabel,$out);
    }

    private static function result(string $state,array $policy,string $teacherTimezone,string $policyTimezone,string $percentageLabel,array $days):array{
        $hasWindows=false;
        foreach($days as$day)if(!empty($day['has_window'])){$hasWindows=true;break;}
        return array(
            'state'=>$state,
            'enabled'=>!empty($policy['enabled']),
            'percentage'=>((int)($policy['bonus_basis_points']??0))/100,
            'percentage_label'=>$percentageLabel,
            'timezone'=>$teacherTimezone,
            'timezone_label'=>$teacherTimezone,
            'policy_timezone'=>$policyTimezone,
            'label'=>(string)($policy['label']??''),
            'has_windows'=>$hasWindows,
            'days'=>$days,
        );
    }

    /** @return list<array{start:string,end:string}> */
    private static function periods(array $policy):array{
        $periods=array();
        foreach((array)($policy['periods']??array())as$period){
            if(!is_array($period))continue;
            $start=trim((string)($period['start']??''));
            $end=trim((string)($period['end']??''));
            if(self::time($start)&&self::time($end)&&$start!==$end)$periods[]=array('start'=>$start,'end'=>$end);
        }
        if($periods===array()){
            $start=trim((string)($policy['start']??''));
            $end=trim((string)($policy['end']??''));
            if(self::time($start)&&self::time($end)&&$start!==$end)$periods[]=array('start'=>$start,'end'=>$end);
        }
        return $periods;
    }

    private static function time(string $value):bool{
        return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value)===1||$value==='24:00';
    }

    private static function wall(string $date,string $time,\DateTimeZone $zone):\DateTimeImmutable{
        if($time==='24:00')return (new \DateTimeImmutable($date.' 00:00:00',$zone))->modify('+1 day');
        return new \DateTimeImmutable($date.' '.$time.':00',$zone);
    }

    private static function zone(string $timezone):?\DateTimeZone{
        if($timezone===''||!in_array($timezone,timezone_identifiers_list(),true))return null;
        try{return new \DateTimeZone($timezone);}catch(\Throwable){return null;}
    }

    private static function percentageLabel(int $basisPoints):string{
        return rtrim(rtrim(number_format($basisPoints/100,2,'.',''),'0'),'.');
    }
}
