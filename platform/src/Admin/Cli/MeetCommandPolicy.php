<?php
namespace Delnavazan\Platform\Admin\Cli;

/**
 * Pure approval policy for manual operator commands.
 *
 * Scheduled workers are governed by their cron/feature flags. Manual mutation must either use the
 * reviewed repair plan or an explicit, targeted administrator command.
 */
final class MeetCommandPolicy {
    /**
     * @return array{mode:string,plan:?string,reason:?string,message:?string}
     */
    public static function repair(bool $dryRun,?string $plan):array{
        return self::decision($dryRun,$plan);
    }

    /**
     * Manual worker apply is limited to existing-row recovery. Missing-row discovery must go through
     * `repair`, which carries the reviewed plan digest.
     *
     * @return array{mode:string,plan:?string,reason:?string,message:?string}
     */
    public static function worker(bool $dryRun,bool $discover,?string $plan):array{
        if(!$dryRun&&$discover){
            return self::rejected('discovery_requires_repair','Manual discovery must use wp dzn-meet repair --dry-run followed by repair --plan=<digest>.');
        }
        return self::decision($dryRun,$plan);
    }

    public static function operatorAllowed(int $userId,bool $manageOptions,bool $manageLessons):bool{
        return $userId>0&&($manageOptions||$manageLessons);
    }

    /** @return array{mode:string,plan:?string,reason:?string,message:?string} */
    private static function decision(bool $dryRun,?string $plan):array{
        if($dryRun)return array('mode'=>'dry_run','plan'=>null,'reason'=>null,'message'=>null);
        $plan=trim((string)$plan);
        if($plan==='')return self::rejected('plan_required','--plan=<plan_digest> is required. Run the dry run, review the exact operations, then apply that digest.');
        if(!preg_match('/^[a-f0-9]{64}$/D',$plan))return self::rejected('plan_invalid','The approved plan digest is not a valid 64-character SHA-256 digest.');
        return array('mode'=>'apply','plan'=>$plan,'reason'=>null,'message'=>null);
    }

    /** @return array{mode:string,plan:?string,reason:?string,message:?string} */
    private static function rejected(string $reason,string $message):array{
        return array('mode'=>'rejected','plan'=>null,'reason'=>$reason,'message'=>$message);
    }
}
