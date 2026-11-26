<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Locked Phase 2A.2-P attendance assessment rules (owner-locked policy).
 *
 * These rules decide when ordinary delivery may settle automatically. Nothing here is canonical
 * delivery truth: Phase O owns that.
 *
 * v3 (current owner policy, `canonical_attendance_presence_v3`):
 *  - presence is the evidence, not identity: any two or more participants other than the
 *    organizer/liaison session, simultaneously present for at least 1200 seconds (20 minutes),
 *    settle the class as delivered;
 *  - the qualifying window is [scheduled_start − 1800s, scheduled_end + 1800s); a meeting held on
 *    any other date through the same link never qualifies;
 *  - exactly 1200 seconds pass, 1199 do not;
 *  - multiple sessions for one participant are unioned (devices are never double-counted), open or
 *    missing leave times never qualify, and a participant swap does not break a continuous run.
 *
 * v2 (`canonical_attendance_overlap_v2`) is retained only so cases admitted under it keep
 * validating and reading identically. It required verified Teacher and Student identities and one
 * hour of grace either side.
 */
final class CanonicalAttendanceRule {
    public const RULE_VERSION='canonical_attendance_presence_v3';
    public const RULE_VERSIONS=array('canonical_attendance_overlap_v2','canonical_attendance_presence_v3');
    public const THRESHOLD_SECONDS=1200;
    public const PRE_GRACE_SECONDS=1800;
    public const POST_GRACE_SECONDS=1800;
    public const MIN_PARTICIPANTS=2;
    public const EXCLUDED_IMPOSSIBLE_INTERVAL='impossible_interval';
    public const EXCLUDED_OUTSIDE_WINDOW='outside_qualifying_window';
    public const EXCLUDED_OPEN_INTERVAL='open_interval';
    public const EXCLUDED_INVALID_INTERVAL='invalid_interval';

    /** Frozen numeric policy for one rule version; null for an unknown version. */
    public static function policy(string $ruleVersion):?array{
        if($ruleVersion==='canonical_attendance_overlap_v2')return array(
            'threshold_seconds'=>1200,'pre_grace_seconds'=>3600,'post_grace_seconds'=>3600,
            'min_participants'=>0,'presence'=>false,
        );
        if($ruleVersion==='canonical_attendance_presence_v3')return array(
            'threshold_seconds'=>self::THRESHOLD_SECONDS,'pre_grace_seconds'=>self::PRE_GRACE_SECONDS,
            'post_grace_seconds'=>self::POST_GRACE_SECONDS,'min_participants'=>self::MIN_PARTICIPANTS,
            'presence'=>true,
        );
        return null;
    }

    /** Qualifying window bounds as UTC instants: [start − pre grace, end + post grace). */
    public static function window(string $occurrenceStartUtc,string $occurrenceEndUtc,?string $ruleVersion=null):array{
        $limits=self::policy($ruleVersion??self::RULE_VERSION);
        if($limits===null)throw new \InvalidArgumentException('Unknown attendance rule version');
        $start=(int)strtotime($occurrenceStartUtc.' UTC');
        $end=(int)strtotime($occurrenceEndUtc.' UTC');
        return array(
            'window_start_utc'=>gmdate('Y-m-d H:i:s',$start-(int)$limits['pre_grace_seconds']),
            'window_end_utc'=>gmdate('Y-m-d H:i:s',$end+(int)$limits['post_grace_seconds']),
            'window_start_ts'=>$start-(int)$limits['pre_grace_seconds'],
            'window_end_ts'=>$end+(int)$limits['post_grace_seconds'],
        );
    }

    /**
     * Assess simultaneous presence of distinct participants inside the qualifying window.
     *
     * @param array<string,array<int,array{join_at_utc:string,leave_at_utc:?string}>> $participants
     *        keyed by the provider account or session identity; the liaison/organizer must already
     *        be excluded by the caller.
     * @return array{seconds:int,threshold_seconds:int,min_participants:int,participants:int,eligible:bool,segments:array,excluded:array,window:array}
     */
    public static function presence(array $participants,string $occurrenceStartUtc,string $occurrenceEndUtc):array{
        $window=self::window($occurrenceStartUtc,$occurrenceEndUtc,self::RULE_VERSION);
        $excluded=array();$merged=array();
        foreach($participants as$key=>$intervals){
            $clipped=self::union(self::clip((array)$intervals,$window,$excluded,(string)$key),$window,$excluded,(string)$key);
            if($clipped)$merged[(string)$key]=$clipped;
        }
        $delta=array();
        foreach($merged as$intervals)foreach($intervals as$interval){
            $delta[$interval['start_ts']]=($delta[$interval['start_ts']]??0)+1;
            $delta[$interval['end_ts']]=($delta[$interval['end_ts']]??0)-1;
        }
        ksort($delta);
        $active=0;$seconds=0;$segments=array();$open=null;$previous=null;
        foreach($delta as$instant=>$change){
            if($previous!==null&&$instant>$previous){
                if($active>=self::MIN_PARTICIPANTS){
                    if($open===null)$open=(int)$previous;
                    $seconds+=(int)$instant-(int)$previous;
                }elseif($open!==null){
                    $segments[]=array('start_ts'=>$open,'end_ts'=>(int)$previous);$open=null;
                }
            }
            $active+=(int)$change;$previous=(int)$instant;
        }
        if($open!==null&&$previous!==null)$segments[]=array('start_ts'=>$open,'end_ts'=>(int)$previous);
        $count=count($merged);
        return array(
            'seconds'=>$seconds,
            'threshold_seconds'=>self::THRESHOLD_SECONDS,
            'min_participants'=>self::MIN_PARTICIPANTS,
            'participants'=>$count,
            'eligible'=>$count>=self::MIN_PARTICIPANTS&&$seconds>=self::THRESHOLD_SECONDS,
            'segments'=>array_map(static fn(array $s):array=>array('start_utc'=>gmdate('Y-m-d H:i:s',$s['start_ts']),'end_utc'=>gmdate('Y-m-d H:i:s',$s['end_ts'])),$segments),
            'excluded'=>$excluded,
            'window'=>$window,
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
        // Legacy v2 assessment: retained for cases admitted under the identity-based rule.
        $window=self::window($occurrenceStartUtc,$occurrenceEndUtc,'canonical_attendance_overlap_v2');
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
