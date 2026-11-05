<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/** Pure deterministic operation ordering, total limit and approval digest. */
final class MeetReconciliationPlan {
    public const VERSION=1;

    public static function normalise(array $operations,int $limit):array{
        $limit=max(1,min(500,$limit));
        $best=array();
        foreach($operations as $operation){
            if(!is_array($operation)||!isset($operation['key']))continue;
            $key=(string)$operation['key'];
            if(!isset($best[$key])||self::compare($operation,$best[$key])<0)$best[$key]=$operation;
        }
        $operations=array_values($best);
        usort($operations,array(__CLASS__,'compare'));
        return array_slice($operations,0,$limit);
    }

    public static function compare(array $a,array $b):int{
        $byStart=strcmp((string)($a['starts_at_utc']??''),(string)($b['starts_at_utc']??''));
        if($byStart!==0)return $byStart;
        $byPriority=(int)($a['priority']??0)<=>(int)($b['priority']??0);
        if($byPriority!==0)return $byPriority;
        $byKind=strcmp((string)($a['kind']??''),(string)($b['kind']??''));
        if($byKind!==0)return $byKind;
        return (int)($a['record_id']??0)<=>(int)($b['record_id']??0);
    }

    public static function digest(array $operations,int $limit,bool $discover):string{
        $payload=array('version'=>self::VERSION,'limit'=>$limit,'discover'=>$discover,'operations'=>array_map(
            static fn(array $op):array=>array(
                'key'=>(string)($op['key']??''),'operation'=>(string)($op['operation']??''),
                'mode'=>(string)($op['mode']??'automatic'),'starts_at_utc'=>(string)($op['starts_at_utc']??''),
                'fingerprint'=>(string)($op['fingerprint']??''),
            ),$operations
        ));
        return hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES) ?: serialize($payload));
    }

    public static function assertApproved(string $planned,string $approved):void{
        if(!hash_equals($planned,trim($approved)))throw new \RuntimeException('meet_reconciliation_plan_stale');
    }
}
