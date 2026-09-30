<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Locked Phase 2A.2-P attendance assessment rule (owner-locked policy).
 *
 * The rule is deliberately explicit and versioned because it decides when ordinary delivery may
 * settle automatically. Nothing here is canonical delivery truth: Phase O owns that.
 *
 * Owner locks encoded here:
 *  - pre-class grace is ZERO seconds: participation before the scheduled start contributes nothing;
 *  - post-class grace is exactly 900 seconds (15 minutes), measured from the scheduled end;
 *  - the qualifying window is therefore [scheduled_start, scheduled_end + 900s);
 *  - automatic success requires at least 1200 seconds (20 minutes) of simultaneous Teacher/Student
 *    qualifying overlap; exactly 1200 passes, 1199 does not;
 *  - multiple trusted intervals for one participant are unioned (devices/accounts are never
 *    double-counted), and open/missing leave times never qualify.
 */
final class CanonicalAttendanceRule {
    public const RULE_VERSION='canonical_attendance_overlap_v1';
    public const THRESHOLD_SECONDS=1200;
    public const PRE_GRACE_SECONDS=0;
    public const POST_GRACE_SECONDS=900;
    public const EXCLUDED_IMPOSSIBLE_INTERVAL='impossible_interval';
    public const EXCLUDED_OUTSIDE_WINDOW='outside_qualifying_window';
    public const EXCLUDED_OPEN_INTERVAL='open_interval';

    /** Qualifying window bounds as UTC instants: [start, end + post grace). */
    public static function window(string $occurrenceStartUtc,string $occurrenceEndUtc):array{
        $start=(int)strtotime($occurrenceStartUtc.' UTC');
        $end=(int)strtotime($occurrenceEndUtc.' UTC');
        return array(
            'window_start_utc'=>gmdate('Y-m-d H:i:s',$start+self::PRE_GRACE_SECONDS),
            'window_end_utc'=>gmdate('Y-m-d H:i:s',$end+self::POST_GRACE_SECONDS),
            'window_start_ts'=>$start+self::PRE_GRACE_SECONDS,
            'window_end_ts'=>$end+self::POST_GRACE_SECONDS,
        );
    }

    /**
     * Assess the qualifying simultaneous overlap of two already-identity-resolved interval sets.
     *
     * @param array $teacherIntervals array of array{join_at_utc:string,leave_at_utc:?string}
     * @param array $studentIntervals same shape
     * @return array{seconds:int,threshold_seconds:int,eligible:bool,segments:array,excluded:array,excluded_teacher:int,excluded_student:int}
     */
    public static function assess(array $teacherIntervals,array $studentIntervals,string $occurrenceStartUtc,string $occurrenceEndUtc):array{
        $window=self::window($occurrenceStartUtc,$occurrenceEndUtc);
        $excluded=array();
        $teacher=self::union(self::clip($teacherIntervals,$window,$excluded,'teacher'),$window,$excluded,'teacher');
        $student=self::union(self::clip($studentIntervals,$window,$excluded,'student'),$window,$excluded,'student');
        $segments=self::intersect($teacher,$student);
        $seconds=0;
        foreach($segments as$segment)$seconds+=$segment['end_ts']-$segment['start_ts'];
        return array(
            'seconds'=>$seconds,
            'threshold_seconds'=>self::THRESHOLD_SECONDS,
            'eligible'=>$seconds>=self::THRESHOLD_SECONDS,
            'segments'=>array_map(static fn($s)=>array('start_utc'=>gmdate('Y-m-d H:i:s',$s['start_ts']),'end_utc'=>gmdate('Y-m-d H:i:s',$s['end_ts'])),$segments),
            'excluded'=>$excluded,
            'teacher_intervals'=>self::describe($teacher),
            'student_intervals'=>self::describe($student),
            'window'=>$window,
        );
    }

    /** Validate one raw participant interval and clip it to the qualifying window. */
    private static function clip(array $intervals,array $window,array &$excluded,string $role):array{
        $clipped=array();
        foreach($intervals as$interval){
            $join=(string)($interval['join_at_utc']??'');
            $leave=$interval['leave_at_utc']??null;
            if(!CanonicalAttendanceValidator::utc($join)){$excluded[]=array('role'=>$role,'reason'=>'invalid_interval');continue;}
            if($leave===null||$leave===''){$excluded[]=array('role'=>$role,'reason'=>self::EXCLUDED_OPEN_INTERVAL,'join_at_utc'=>$join);continue;}
            $leave=(string)$leave;
            if(!CanonicalAttendanceValidator::utc($leave)){$excluded[]=array('role'=>$role,'reason'=>'invalid_interval','join_at_utc'=>$join);continue;}
            $joinTs=(int)strtotime($join.' UTC');
            $leaveTs=(int)strtotime($leave.' UTC');
            if($leaveTs<=$joinTs){$excluded[]=array('role'=>$role,'reason'=>self::EXCLUDED_IMPOSSIBLE_INTERVAL,'join_at_utc'=>$join,'leave_at_utc'=>$leave);continue;}
            $start=max($joinTs,(int)$window['window_start_ts']);
            $end=min($leaveTs,(int)$window['window_end_ts']);
            if($end<=$start){$excluded[]=array('role'=>$role,'reason'=>self::EXCLUDED_OUTSIDE_WINDOW,'join_at_utc'=>$join,'leave_at_utc'=>$leave);continue;}
            $clipped[]=array('start_ts'=>$start,'end_ts'=>$end);
        }
        return $clipped;
    }

    /** Union overlapping or adjacent intervals so multiple devices never double-count. */
    private static function union(array $intervals,array $window,array &$excluded,string $role):array{
        if(!$intervals)return array();
        usort($intervals,static fn($a,$b)=>$a['start_ts']<=>$b['start_ts']);
        $merged=array();
        foreach($intervals as$interval){
            $last=count($merged)-1;
            if($last>=0&&$interval['start_ts']<=$merged[$last]['end_ts']){
                if($interval['end_ts']>$merged[$last]['end_ts'])$merged[$last]['end_ts']=$interval['end_ts'];
                continue;
            }
            $merged[]=$interval;
        }
        return $merged;
    }

    /** Intersect two unioned interval sets and merge the resulting segments. */
    private static function intersect(array $left,array $right):array{
        $segments=array();
        foreach($left as$a){
            foreach($right as$b){
                $start=max($a['start_ts'],$b['start_ts']);
                $end=min($a['end_ts'],$b['end_ts']);
                if($end>$start)$segments[]=array('start_ts'=>$start,'end_ts'=>$end);
            }
        }
        if(!$segments)return array();
        usort($segments,static fn($a,$b)=>$a['start_ts']<=>$b['start_ts']);
        $merged=array();
        foreach($segments as$segment){
            $last=count($merged)-1;
            if($last>=0&&$segment['start_ts']<=$merged[$last]['end_ts']){
                if($segment['end_ts']>$merged[$last]['end_ts'])$merged[$last]['end_ts']=$segment['end_ts'];
                continue;
            }
            $merged[]=$segment;
        }
        return $merged;
    }

    private static function describe(array $intervals):array{
        return array_map(static fn($i)=>array('start_utc'=>gmdate('Y-m-d H:i:s',$i['start_ts']),'end_utc'=>gmdate('Y-m-d H:i:s',$i['end_ts'])),$intervals);
    }
}
