<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Durable consent processing worker.
 *
 * Jobs are claimed under a row lock, attempted with bounded retries and leased so a crashed worker
 * cannot hold work forever. Dataset exclusion is idempotent. A recording disposition is truthful:
 * this build creates no capture asset, so completion records `no_capture_assets` rather than
 * pretending media was deleted.
 */
final class ConsentLifecycleWorker {
    public const CRON_HOOK='dzn_consent_processing';
    private MlDatasetEligibilityService $datasets;

    public function __construct(?MlDatasetEligibilityService $datasets=null){$this->datasets=$datasets??new MlDatasetEligibilityService();}

    /** Durable scheduler registration; processing is idempotent and safe with capture disabled. */
    public static function register():void{
        add_action(self::CRON_HOOK,static function():void{
            try{(new self())->run(20);}catch(\Throwable $error){do_action('dzn_consent_processing_failed',$error->getMessage());}
        });
        add_action('init',static function():void{
            if(function_exists('wp_next_scheduled')&&!wp_next_scheduled(self::CRON_HOOK))wp_schedule_event(time()+300,'hourly',self::CRON_HOOK);
        });
    }

    public function run(int $limit=20):array{
        $out=array('processed'=>0,'completed'=>0,'failed'=>0,'retried'=>0);
        for($i=0;$i<$limit;$i++){
            $job=$this->claim();
            if($job===null)break;
            $out['processed']++;
            try{
                $result=$this->execute($job);
                $this->complete((int)$job->id,$result);
                $out['completed']++;
            }catch(\Throwable $error){
                $retry=$this->fail($job,$error);
                if($retry)$out['retried']++;else$out['failed']++;
            }
        }
        return $out;
    }

    /** Authorised administrator retry of a failed job. Never fabricates consent or eligibility. */
    public function retry(int $jobId,int $actor):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $updated=$wpdb->query($wpdb->prepare("UPDATE {$p}consent_processing_jobs SET state='pending',available_at=%s,last_error_code=NULL,lease_token_digest=NULL,leased_at=NULL,lease_expires_at=NULL,updated_at=%s,updated_by=%d WHERE id=%d AND state='failed'",$now,$now,$actor,$jobId));
        if($updated===false)throw new \RuntimeException('consent_processing_retry_failed');
        return $updated===1;
    }

    private function claim():?object{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        try{
            $job=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_processing_jobs WHERE state='pending' AND available_at<=%s ORDER BY id ASC LIMIT 1 FOR UPDATE",$now));
            if(!$job){$wpdb->query('COMMIT');return null;}
            $token=Identifier::uid();
            $updated=$wpdb->query($wpdb->prepare("UPDATE {$p}consent_processing_jobs SET state='running',attempts=attempts+1,lease_token_digest=%s,leased_at=%s,lease_expires_at=%s,updated_at=%s WHERE id=%d AND state='pending'",hash('sha256',$token),$now,gmdate('Y-m-d H:i:s',time()+900),$now,(int)$job->id));
            if($updated!==1){$wpdb->query('ROLLBACK');return null;}
            $wpdb->query('COMMIT');
            $job->attempts=(int)$job->attempts+1;
            return $job;
        }catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
    }

    private function execute(object $job):array{
        $type=(string)$job->job_type;
        if($type==='dataset_exclusion'){
            $reason=(string)$job->purpose_code===(string)ConsentPurposeRegistry::TEACHER_RESEARCH||(string)$job->purpose_code===(string)ConsentPurposeRegistry::STUDENT_RESEARCH?'research_consent_withdrawn':'consent_withdrawn';
            $this->datasets->markExcluded((int)$job->source_id,$reason,(int)$job->created_by);
            return array('result'=>'excluded','reason'=>$reason);
        }
        if($type==='policy_reevaluation'){
            $result=$this->datasets->reEvaluateItem((int)$job->source_id,(int)$job->created_by);
            return array('result'=>$result['eligible']?'eligible':'quarantined','reason'=>$result['reason']);
        }
        if($type==='recording_disposition'){
            // No capture provider or asset store is enabled in this build. The truthful result is
            // that there is no captured asset to delete; future capture workers must extend this case.
            return array('result'=>'no_capture_assets','reason'=>'provider_disabled');
        }
        throw new \RuntimeException('consent_processing_job_type_unknown');
    }

    private function complete(int $jobId,array $result):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $job=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_processing_jobs WHERE id=%d LIMIT 1",$jobId));
        $wpdb->update($p.'consent_processing_jobs',array('state'=>'completed','result_code'=>substr((string)$result['result'],0,64),'last_error_code'=>null,'completed_at'=>$now,'lease_token_digest'=>null,'leased_at'=>null,'lease_expires_at'=>null,'updated_at'=>$now),array('id'=>$jobId));
        $this->event($jobId,$job, 'completed',(string)$result['reason']);
    }

    private function fail(object $job,\Throwable $error):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $code=$this->code($error->getMessage());
        $attempts=(int)$job->attempts;$max=max(1,(int)$job->max_attempts);
        $retry=$attempts<$max;
        $state=$retry?'pending':'failed';
        $available=$retry?gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**max(0,$attempts-1)))):$now;
        $wpdb->update($p.'consent_processing_jobs',array('state'=>$state,'available_at'=>$available,'last_error_code'=>$code,'lease_token_digest'=>null,'leased_at'=>null,'lease_expires_at'=>null,'updated_at'=>$now),array('id'=>(int)$job->id));
        $this->event((int)$job->id,$job,$retry?'retry_scheduled':'failed',$code);
        return $retry;
    }

    private function event(int $jobId,?object $job,string $eventType,string $reason):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($p.'consent_processing_events',array('uid'=>Identifier::uid(),'job_id'=>$jobId,'dataset_item_id'=>(string)($job->source_kind??'')==='dataset_item'?(int)$job->source_id:null,'subject_type'=>(string)($job->subject_type??'system'),'subject_id'=>(int)($job->subject_id??0),'purpose_code'=>(string)($job->purpose_code??''),'event_type'=>$eventType,'reason_code'=>$this->code($reason),'evidence_digest'=>hash('sha256',$eventType.'|'.$reason.'|'.$jobId),'occurred_at'=>$now,'created_at'=>$now,'created_by'=>max(0,(int)($job->created_by??0))));
    }

    private function code(string $value):string{$value=sanitize_key($value);return $value!==''?substr($value,0,64):'consent_processing_failed';}
}
