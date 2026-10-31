<?php
namespace Delnavazan\Platform\Core\Application;

/** Pure projection shared by the Teacher availability UI and its tests. */
final class TeacherAvailabilitySummary {
    /**
     * @param array<int,array<string,mixed>|object> $rules
     * @param array<string,mixed> $golden
     * @return array<string,mixed>
     */
    public static function build(array $rules,array $golden):array{
        $selected=[];$weeklyMinutes=0;
        foreach($rules as$rule){
            $row=is_object($rule)?get_object_vars($rule):$rule;
            $state=(string)($row['state']??'');
            if(!in_array($state,array('preferred','requestable'),true))continue;
            $weekday=(int)($row['weekday']??0);$start=self::minute((string)($row['local_start_time']??$row['start']??''));$end=self::minute((string)($row['local_end_time']??$row['end']??''));
            if($weekday<1||$weekday>7||$start===null||$end===null||$end<=$start)continue;
            $selected[$weekday][]=array('start'=>$start,'end'=>$end);
            $weeklyMinutes+=$end-$start;
        }
        foreach($selected as$weekday=>$intervals)$selected[$weekday]=self::merge($intervals);

        $goldenMinutes=0;$days=[];
        foreach((array)($golden['days']??array())as$day){
            if(!is_array($day))continue;
            $weekday=(int)($day['weekday']??0);$dayIntervals=[];$daySelected=0;
            foreach((array)($day['intervals']??array())as$interval){
                if(!is_array($interval))continue;
                $start=self::minute((string)($interval['start']??''));$end=self::minute((string)($interval['end']??''));
                if($start!==null&&$start>0&&$end===0)$end=1440;
                if($start===null||$end===null||$end<=$start)continue;
                $points=array($start,$end);
                foreach($selected[$weekday]??array()as$chosen){
                    if($chosen['end']<=$start||$chosen['start']>=$end)continue;
                    $points[]=max($start,$chosen['start']);$points[]=min($end,$chosen['end']);
                }
                $points=array_values(array_unique($points));sort($points);$segments=[];$intervalSelected=0;
                for($i=0,$last=count($points)-1;$i<$last;$i++){
                    $segmentStart=$points[$i];$segmentEnd=$points[$i+1];if($segmentEnd<=$segmentStart)continue;
                    $isSelected=false;foreach($selected[$weekday]??array()as$chosen)if($chosen['start']<=$segmentStart&&$chosen['end']>=$segmentEnd){$isSelected=true;break;}
                    if($isSelected){$minutes=$segmentEnd-$segmentStart;$intervalSelected+=$minutes;$daySelected+=$minutes;$goldenMinutes+=$minutes;}
                    $segments[]=array('start'=>self::clock($segmentStart),'end'=>self::clock($segmentEnd),'label'=>self::clock($segmentStart).'–'.self::clock($segmentEnd),'selected'=>$isSelected);
                }
                $dayIntervals[]=$interval+array('selected_minutes'=>$intervalSelected,'fully_selected'=>$intervalSelected===($end-$start),'partially_selected'=>$intervalSelected>0&&$intervalSelected<($end-$start),'segments'=>$segments);
            }
            $day['intervals']=$dayIntervals;$day['selected_minutes']=$daySelected;$days[]=$day;
        }
        $golden['days']=$days;$golden['selected_minutes']=$goldenMinutes;
        return array('weekly_minutes'=>$weeklyMinutes,'golden_minutes'=>$goldenMinutes,'selected'=>$selected,'golden'=>$golden);
    }

    private static function minute(string$value):?int{
        $value=trim($value);if($value==='24:00'||$value==='24:00:00')return 1440;
        if(preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D',$value)!==1)return null;
        return((int)substr($value,0,2))*60+(int)substr($value,3,2);
    }
    private static function clock(int$minute):string{return$minute===1440?'24:00':sprintf('%02d:%02d',intdiv($minute,60),$minute%60);}
    private static function merge(array$intervals):array{
        usort($intervals,static fn(array$a,array$b):int=>[$a['start'],$a['end']]<=>[$b['start'],$b['end']]);$merged=[];
        foreach($intervals as$interval){$last=count($merged)-1;if($last>=0&&$interval['start']<=$merged[$last]['end'])$merged[$last]['end']=max($merged[$last]['end'],$interval['end']);else$merged[]=$interval;}
        return$merged;
    }
}
