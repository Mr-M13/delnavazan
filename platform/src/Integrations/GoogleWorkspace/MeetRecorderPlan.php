<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Pure decisions for the recording companion queue.
 *
 * The queue is a best-effort map of classroom id to one pending companion join. This class holds
 * only the deterministic rules — coalescing repeated joins, expiring stale requests, reclaiming a
 * claim whose companion vanished, and choosing the oldest request — so they can be proven without
 * WordPress or the database.
 */
final class MeetRecorderPlan {
    /** A request older than this is dropped: the class has long since started without it. */
    public const TTL=900;
    /** A claimed request whose companion never reported is reclaimable after this window. */
    public const CLAIM_TTL=600;

    /**
     * Drop expired rows and return a claim held too long to the pending pool.
     *
     * @param array<int,array<string,mixed>> $jobs
     * @return array<int,array<string,mixed>>
     */
    public static function prune(array $jobs,int $now,int $ttl=self::TTL,int $claimTtl=self::CLAIM_TTL):array{
        foreach($jobs as $key=>$job){
            if(!is_array($job)){unset($jobs[$key]);continue;}
            if((int)($job['requested_at']??0)<$now-$ttl){unset($jobs[$key]);continue;}
            if((string)($job['state']??'pending')==='claimed'&&(int)($job['claimed_at']??0)<=$now-$claimTtl){
                $jobs[$key]['state']='pending';
                $jobs[$key]['claimed_at']=null;
            }
        }
        return $jobs;
    }

    /**
     * Insert or refresh the single request for a classroom.
     *
     * A repeated join keeps the original request identity and restarts it, so a teacher pressing
     * Join after a failed companion attempt does not create a burst of companion joins.
     *
     * @param array<int,array<string,mixed>> $jobs
     * @return array{jobs:array<int,array<string,mixed>>,job:array<string,mixed>}
     */
    public static function request(array $jobs,int $classroomId,string $kind,string $trigger,int $now,string $uid):array{
        $existing=isset($jobs[$classroomId])&&is_array($jobs[$classroomId])?$jobs[$classroomId]:null;
        if($existing!==null){
            $existing['state']='pending';
            $existing['claimed_at']=null;
            $existing['requested_at']=$now;
            $existing['kind']=$kind;
            $existing['trigger']=$trigger;
            $jobs[$classroomId]=$existing;
            return array('jobs'=>$jobs,'job'=>$existing);
        }
        $job=array(
            'uid'=>$uid,'classroom_id'=>$classroomId,'kind'=>$kind,'trigger'=>$trigger,
            'requested_at'=>$now,'state'=>'pending','claimed_at'=>null,
        );
        $jobs[$classroomId]=$job;
        return array('jobs'=>$jobs,'job'=>$job);
    }

    /**
     * Pick the oldest pending classroom, or null when nothing is waiting.
     *
     * @param array<int,array<string,mixed>> $jobs
     */
    public static function next(array $jobs):?int{
        $best=null;
        $bestAt=null;
        foreach($jobs as $classroomId=>$job){
            if(!is_array($job)||(string)($job['state']??'')!=='pending')continue;
            $at=(int)($job['requested_at']??0);
            if($bestAt===null||$at<$bestAt){$best=(int)$classroomId;$bestAt=$at;}
        }
        return $best;
    }

    /**
     * Mark one classroom claim and return the updated queue.
     *
     * @param array<int,array<string,mixed>> $jobs
     * @return array<int,array<string,mixed>>
     */
    public static function claim(array $jobs,int $classroomId,int $now):array{
        if(!isset($jobs[$classroomId])||!is_array($jobs[$classroomId]))return $jobs;
        $jobs[$classroomId]['state']='claimed';
        $jobs[$classroomId]['claimed_at']=$now;
        return $jobs;
    }

    /**
     * Remove a completed or failed request.
     *
     * @param array<int,array<string,mixed>> $jobs
     * @return array<int,array<string,mixed>>
     */
    public static function clear(array $jobs,int $classroomId):array{
        if(isset($jobs[$classroomId]))unset($jobs[$classroomId]);
        return $jobs;
    }
}
