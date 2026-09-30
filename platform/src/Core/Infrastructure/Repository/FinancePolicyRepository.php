<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-U policy registry and its command result store. */
final class FinancePolicyRepository extends FinanceRepository {
    public function __construct(){parent::__construct();}

    public function latest(string $policyKey,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_policies WHERE policy_key=%s ORDER BY effective_from DESC,policy_version DESC LIMIT 1".($lock?' FOR UPDATE':''),$policyKey);
    }
    /** The version that covers an instant: the greatest `effective_from <= $atUtc`, or `null` when none. */
    public function covering(string $policyKey,string $atUtc,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_policies WHERE policy_key=%s AND effective_from<=%s ORDER BY effective_from DESC,policy_version DESC LIMIT 1".($lock?' FOR UPDATE':''),$policyKey,$atUtc);
    }
    public function byKeyVersion(string $policyKey,int $policyVersion,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_policies WHERE policy_key=%s AND policy_version=%d".($lock?' FOR UPDATE':''),$policyKey,$policyVersion);
    }
    public function byId(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_policies WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function versions(string $policyKey):array{
        return $this->many("SELECT * FROM {$this->p}finance_policies WHERE policy_key=%s ORDER BY policy_version",$policyKey);
    }
    public function allPolicies():array{return $this->many("SELECT * FROM {$this->p}finance_policies ORDER BY policy_key,policy_version");}
    public function insertPolicy(array $data):int{return $this->insert('finance_policies',$data,'Finance policy persistence failed');}
    /** §6.1: the single conditional `active → superseded` statement. */
    public function supersede(int $id,string $now,int $actor):int{
        return $this->cas('finance_policies',array('status'=>'superseded','updated_at'=>$now),array('id'=>$id,'status'=>'active'),'Finance policy supersession failed');
    }
    /** §6.1: the single conditional `active|superseded → withdrawn` statement; `withdrawn` is terminal. */
    public function withdraw(int $id,string $now,int $actor,string $reasonCode):int{
        global $wpdb;
        $table=$this->declared('finance_policies');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET status='withdrawn',reason_code=%s,updated_at=%s WHERE id=%d AND status IN ('active','superseded')",$reasonCode,$now,$id));
        if($changed===false)throw new \RuntimeException('Finance policy withdrawal failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    /** §6.3: the recorded consumption maximum of one policy key, read from its declared indexes. */
    public function consumptionMaximum(string $policyKey):?string{
        $instants=array();
        if($policyKey==='INTRO_PAYABILITY_POLICY'){
            $instants=array_merge($instants,array_column($this->many("SELECT snapshot_instant_utc FROM {$this->p}finance_lesson_snapshots WHERE intro_policy_key=%s",$policyKey),'snapshot_instant_utc'));
            $instants=array_merge($instants,array_column($this->many("SELECT corrected.snapshot_instant_utc FROM {$this->p}finance_snapshot_corrections correction INNER JOIN {$this->p}finance_lesson_snapshots corrected ON corrected.id=correction.snapshot_id WHERE correction.intro_policy_key=%s",$policyKey),'snapshot_instant_utc'));
        }
        foreach($this->many("SELECT bound.snapshot_instant_utc FROM {$this->p}finance_payability_evaluations evaluation INNER JOIN {$this->p}finance_lesson_snapshots bound ON bound.id=evaluation.snapshot_id WHERE evaluation.policy_key=%s",$policyKey) as $row)$instants[]=(string)$row->snapshot_instant_utc;
        if($policyKey==='FINANCE_STATEMENT_TIMEZONE'){
            foreach($this->many("SELECT created_at FROM {$this->p}finance_statements WHERE timezone_policy_version IS NOT NULL") as $row)$instants[]=(string)$row->created_at;
        }
        $instants=array_values(array_filter(array_map('strval',$instants),static fn($value)=>$value!==''&&$value!=='0000-00-00 00:00:00'));
        if($instants===array())return null;
        sort($instants,SORT_STRING);
        return $instants[count($instants)-1];
    }
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}finance_policy_commands WHERE command_key_digest=%s",$digest);}
    public function commandById(int $id):?object{return $this->one("SELECT * FROM {$this->p}finance_policy_commands WHERE id=%d",$id);}
    public function insertCommand(array $data):int{return $this->insert('finance_policy_commands',$data,'Finance policy command persistence failed');}
}
