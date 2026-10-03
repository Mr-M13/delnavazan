<?php
namespace Delnavazan\Platform\Core\Application;

/** Read-only planning windows. Region-local wall-clock definitions; conversions must remain timezone/DST aware. */
final class AcademyDemandWindowRule {
    public static function regions():array{return array(
        'AU'=>array('label'=>'Australia','timezone'=>'Australia/Brisbane','start'=>'16:00','end'=>'21:00'),
        'EU'=>array('label'=>'Europe','timezone'=>'Europe/Berlin','start'=>'16:00','end'=>'21:00'),
        'GB'=>array('label'=>'United Kingdom','timezone'=>'Europe/London','start'=>'16:00','end'=>'21:00'),
        'NA_EAST'=>array('label'=>'North America East','timezone'=>'America/Toronto','start'=>'16:00','end'=>'21:00'),
        'NA_WEST'=>array('label'=>'North America West','timezone'=>'America/Vancouver','start'=>'16:00','end'=>'21:00'),
    );}
    public static function slots():array{ $slots=[]; foreach(self::regions() as $key=>$region){for($weekday=1;$weekday<=7;$weekday++){for($minute=16*60;$minute<21*60;$minute+=30)$slots[]=array('region'=>$key,'weekday'=>$weekday,'minute'=>$minute)+$region;}}return $slots;}
}
