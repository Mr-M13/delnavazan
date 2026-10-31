<?php
namespace Delnavazan\Platform\Core\Application;

/** Read-only planning windows. Region-local wall-clock definitions; conversions must remain timezone/DST aware. */
final class AcademyDemandWindowRule {
    public const SLOT_MINUTES = 30;
    public const SLOTS_PER_DAY = 10;
    public const SLOTS_PER_REGION = 70;

    public static function regions():array{return array(
        'AU'=>array('label'=>'Australia','timezone'=>'Australia/Brisbane','start'=>'16:00','end'=>'21:00'),
        'EU'=>array('label'=>'Europe','timezone'=>'Europe/Berlin','start'=>'16:00','end'=>'21:00'),
        'GB'=>array('label'=>'United Kingdom','timezone'=>'Europe/London','start'=>'16:00','end'=>'21:00'),
        'NA_EAST'=>array('label'=>'North America East','timezone'=>'America/Toronto','start'=>'16:00','end'=>'21:00'),
        'NA_WEST'=>array('label'=>'North America West','timezone'=>'America/Vancouver','start'=>'16:00','end'=>'21:00'),
    );}
    public static function slots():array{ $slots=[]; foreach(self::regions() as $key=>$region){for($weekday=1;$weekday<=7;$weekday++){for($minute=16*60;$minute<21*60;$minute+=30)$slots[]=array('region'=>$key,'weekday'=>$weekday,'minute'=>$minute)+$region;}}return $slots;}

    /** Normalize any instant to the Monday 00:00 UTC that owns its civil week. */
    public static function weekStart(?\DateTimeImmutable $week=null):\DateTimeImmutable{
        $week??=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        $utc=$week->setTimezone(new \DateTimeZone('UTC'))->setTime(0,0,0);
        return $utc->modify('-'.((int)$utc->format('N')-1).' days');
    }

    /** The UTC start of one area-local golden-hour slot. */
    public static function slotUtc(\DateTimeImmutable $week,string $regionKey,int $weekday,int $minute):\DateTimeImmutable{
        $regions=self::regions();
        if(!isset($regions[$regionKey]))throw new \InvalidArgumentException('Unknown demand region');
        if($weekday<1||$weekday>7)throw new \InvalidArgumentException('Demand weekday must be 1–7');
        if($minute<16*60||$minute>=21*60||$minute%self::SLOT_MINUTES!==0)throw new \InvalidArgumentException('Demand minute must be a 30-minute slot between 16:00 and 21:00');
        try{$zone=new \DateTimeZone($regions[$regionKey]['timezone']);}catch(\Throwable){throw new \InvalidArgumentException('Demand region timezone is invalid');}
        $date=self::weekStart($week)->modify('+'.($weekday-1).' days')->format('Y-m-d');
        $local=new \DateTimeImmutable($date.' '.sprintf('%02d:%02d:00',intdiv($minute,60),$minute%60),$zone);
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function slotEndUtc(\DateTimeImmutable $week,string $regionKey,int $weekday,int $minute):\DateTimeImmutable{
        return self::slotUtc($week,$regionKey,$weekday,$minute)->modify('+'.self::SLOT_MINUTES.' minutes');
    }

    public static function weekLabel(\DateTimeImmutable $week):string{
        return self::weekStart($week)->format('j M Y');
    }

    public static function weekdayLabel(int $weekday):string{
        return array('1'=>'Monday','2'=>'Tuesday','3'=>'Wednesday','4'=>'Thursday','5'=>'Friday','6'=>'Saturday','7'=>'Sunday')[$weekday]??'Unknown day';
    }

    public static function timeLabel(int $minute):string{
        return sprintf('%02d:%02d',intdiv($minute,60),$minute%60);
    }
}
