<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Infrastructure\Repository\OperationalCashRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Append-only academy cashbook commands. Each posted command and its audit envelope commit atomically.
 * Stripe collections, payment execution and teacher payability remain separate authorities.
 */
final class OperationalCashLedgerService {
    public const VIEW_CAPABILITY='dzn_view_cash_ledger';
    public const MANAGE_CAPABILITY='dzn_manage_cash_ledger';
    private OperationalCashRepository $repository;
    public function __construct(?OperationalCashRepository $repository=null){$this->repository=$repository??new OperationalCashRepository();}

    public function record(array $input,string $commandKey):array {
        $accountKey=(string)($input['account_key']??'');$account=OperationalCashRule::account($accountKey);
        $kind=(string)($input['transaction_kind']??'');if(!in_array($kind,array('income','expense','opening_balance','balance_adjustment'),true))throw new \InvalidArgumentException('Choose an income, expense, opening balance or balance adjustment');
        $amount=OperationalCashRule::amount($input['amount']??null,$account['currency']);$date=OperationalCashRule::date($input['transaction_date']??null);
        $description=OperationalCashRule::text($input['description']??null,500,'Add a description');$category=OperationalCashRule::optionalText($input['category']??null,100);
        $direction=$kind==='expense'||($kind==='balance_adjustment'&&($input['adjustment_direction']??'')==='decrease')?-1:1;
        if($kind==='balance_adjustment'&&!in_array(($input['adjustment_direction']??''),array('increase','decrease'),true))throw new \InvalidArgumentException('Choose whether the balance adjustment increases or decreases the account');
        $reason=$kind==='balance_adjustment'?(string)($input['reason_code']??''):null;
        if($kind==='balance_adjustment'&&!in_array($reason,OperationalCashRule::REASON_CODES,true))throw new \InvalidArgumentException('Choose a correction reason');
        $evidence=OperationalCashRule::evidence($input,$kind==='opening_balance'||$kind==='balance_adjustment');
        if($kind==='opening_balance')$description='Opening balance · '.$description;
        if($kind==='balance_adjustment')$description='Balance adjustment · '.$description;
        $facts=array('kind'=>$kind,'account'=>$accountKey,'currency'=>$account['currency'],'date'=>$date,'amount_minor'=>$amount,'direction'=>$direction,'reason_code'=>$reason,'description'=>$description,'category'=>$category,'evidence'=>$evidence['reference_digest']);
        return $this->execute('record_transaction',$commandKey,$facts,array($accountKey),function(array $locked,int $actor,string $now,string $keyDigest,string $payloadDigest)use($accountKey,$account,$kind,$amount,$date,$description,$category,$evidence,$direction,$reason):array{
            if($kind==='opening_balance'&&$this->hasOpeningBalance((int)$locked[$accountKey]->id))throw new \RuntimeException('This account already has an opening balance. Correct it with a reversal and a new entry.');
            $id=$this->repository->insert('finance_cash_transactions',array(
                'uid'=>Identifier::uid(),'account_id'=>(int)$locked[$accountKey]->id,'account_key'=>$accountKey,'currency'=>$account['currency'],
                'transaction_date'=>$date,'transaction_kind'=>$kind,'direction'=>$direction,'amount_minor'=>$amount,
                'category'=>$category,'description'=>$description,'transfer_id'=>null,'transfer_leg'=>null,'opening_slot'=>$kind==='opening_balance'?1:null,'reverses_transaction_id'=>null,
                'reason_code'=>$kind==='opening_balance'?'opening_balance':$reason,'evidence_channel'=>$evidence['channel'],'evidence_reference'=>$evidence['reference'],
                'evidence_reference_digest'=>$evidence['reference_digest'],'evidence_at'=>$evidence['at'],
                'recorded_at'=>$now,'recorded_by'=>$actor,'rule_version'=>OperationalCashRule::RULE_VERSION,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            return array('result_id'=>$id,'result_type'=>'transaction','result_state'=>'recorded','reason_code'=>null,'details'=>array('transaction_id'=>$id,'created'=>true));
        });
    }

    public function convertToIran(array $input,string $commandKey):array {
        OperationalCashRule::account('australia');OperationalCashRule::account('iran');
        $source=OperationalCashRule::amount($input['amount_aud']??null,'AUD');$destination=OperationalCashRule::amount($input['amount_tom']??null,'TOM');
        $rate=OperationalCashRule::rate($input['rate_tom_per_aud']??null);$date=OperationalCashRule::date($input['transaction_date']??null);
        $description=OperationalCashRule::text($input['description']??null,500,'Add a transfer description');$evidence=OperationalCashRule::evidence($input,true);
        $facts=array('operation'=>'conversion','date'=>$date,'source_currency'=>'AUD','source_minor'=>$source,'destination_currency'=>'TOM','destination_minor'=>$destination,'rate_tom_per_aud'=>$rate,'description'=>$description,'evidence'=>$evidence['reference_digest']);
        return $this->execute('convert_to_iran',$commandKey,$facts,array('australia','iran'),function(array $locked,int $actor,string $now,string $keyDigest,string $payloadDigest)use($source,$destination,$rate,$date,$description,$evidence):array{
            $transferId=$this->repository->insert('finance_cash_transfers',array(
                'uid'=>Identifier::uid(),'transfer_kind'=>'conversion','transaction_date'=>$date,'source_account_key'=>'australia','destination_account_key'=>'iran',
                'source_currency'=>'AUD','source_amount_minor'=>$source,'destination_currency'=>'TOM','destination_amount_minor'=>$destination,
                'rate_tom_per_aud'=>$rate,'description'=>$description,'reverses_transfer_id'=>null,'evidence_channel'=>$evidence['channel'],'evidence_reference'=>$evidence['reference'],
                'evidence_reference_digest'=>$evidence['reference_digest'],'evidence_at'=>$evidence['at'],'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->transferLeg($transferId,$locked['australia'],'australia','AUD','transfer_out',-1,$source,$date,$description,$evidence,$actor,$now);
            $this->transferLeg($transferId,$locked['iran'],'iran','TOM','transfer_in',1,$destination,$date,$description,$evidence,$actor,$now);
            return array('result_id'=>$transferId,'result_type'=>'transfer','result_state'=>'recorded','reason_code'=>null,'details'=>array('transfer_id'=>$transferId,'created'=>true));
        });
    }

    public function reverse(int $transactionId,string $reason,string $commandKey):array {
        $original=$this->repository->byId($transactionId);if(!$original)throw new \InvalidArgumentException('Transaction not found');
        if(!in_array($reason,OperationalCashRule::REASON_CODES,true))throw new \InvalidArgumentException('Choose a correction reason');
        $account=OperationalCashRule::account((string)$original->account_key);
        $facts=array('transaction_id'=>$transactionId,'reason_code'=>$reason,'original_kind'=>(string)$original->transaction_kind,'original_direction'=>(int)$original->direction,'original_amount_minor'=>(int)$original->amount_minor,'original_currency'=>(string)$original->currency,'original_date'=>(string)$original->transaction_date,'original_category'=>$original->category,'account'=>$account['key']);
        return $this->execute('reverse_transaction',$commandKey,$facts,array($account['key']),function(array $locked,int $actor,string $now)use($original,$reason):array{
            if($original->transfer_id!==null)throw new \InvalidArgumentException('Reverse a conversion as one paired transfer');
            if($original->transaction_kind==='reversal'||$this->repository->isReversed((int)$original->id))throw new \InvalidArgumentException('This entry has already been reversed or is itself a reversal');
            $id=$this->repository->insert('finance_cash_transactions',array(
                'uid'=>Identifier::uid(),'account_id'=>(int)$original->account_id,'account_key'=>(string)$original->account_key,'currency'=>(string)$original->currency,
                'transaction_date'=>gmdate('Y-m-d'),'transaction_kind'=>'reversal','direction'=>-(int)$original->direction,'amount_minor'=>(int)$original->amount_minor,
                'category'=>$original->category,'description'=>'Reversal of transaction #'.(int)$original->id,'transfer_id'=>null,'transfer_leg'=>null,'opening_slot'=>null,'reverses_transaction_id'=>(int)$original->id,
                'reason_code'=>$reason,'evidence_channel'=>'staff_record','evidence_reference'=>null,'evidence_reference_digest'=>null,'evidence_at'=>null,
                'recorded_at'=>$now,'recorded_by'=>$actor,'rule_version'=>OperationalCashRule::RULE_VERSION,'created_at'=>$now,'created_by'=>$actor,
            ));
            return array('result_id'=>$id,'result_type'=>'transaction','result_state'=>'recorded','reason_code'=>$reason,'details'=>array('transaction_id'=>$id,'reversed_transaction_id'=>(int)$original->id,'created'=>true));
        });
    }

    public function reverseTransfer(int $transferId,string $reason,string $commandKey):array {
        $original=$this->repository->transferById($transferId);if(!$original)throw new \InvalidArgumentException('Transfer not found');
        if(!in_array($reason,OperationalCashRule::REASON_CODES,true))throw new \InvalidArgumentException('Choose a correction reason');
        $facts=array('transfer_id'=>$transferId,'reason_code'=>$reason,'transfer_date'=>(string)$original->transaction_date,'source_currency'=>(string)$original->source_currency,'source_amount_minor'=>(int)$original->source_amount_minor,'destination_currency'=>(string)$original->destination_currency,'destination_amount_minor'=>(int)$original->destination_amount_minor,'rate_tom_per_aud'=>(string)$original->rate_tom_per_aud);
        return $this->execute('reverse_transfer',$commandKey,$facts,array('australia','iran'),function(array $locked,int $actor,string $now)use($original,$reason,$transferId):array{
            if($original->transfer_kind==='reversal'||$this->repository->transferReversal($transferId))throw new \InvalidArgumentException('This transfer has already been reversed or is itself a reversal');
            $reversal=$this->repository->insert('finance_cash_transfers',array(
                'uid'=>Identifier::uid(),'transfer_kind'=>'reversal','transaction_date'=>gmdate('Y-m-d'),'source_account_key'=>'australia','destination_account_key'=>'iran',
                'source_currency'=>'AUD','source_amount_minor'=>(int)$original->source_amount_minor,'destination_currency'=>'TOM','destination_amount_minor'=>(int)$original->destination_amount_minor,
                'rate_tom_per_aud'=>(string)$original->rate_tom_per_aud,'description'=>'Reversal of transfer #'.$transferId,'reverses_transfer_id'=>$transferId,
                'evidence_channel'=>'staff_record','evidence_reference'=>null,'evidence_reference_digest'=>null,'evidence_at'=>null,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            foreach(array(array('australia','AUD',(int)$original->source_amount_minor,1),array('iran','TOM',(int)$original->destination_amount_minor,-1)) as $leg){$this->reverseTransferLeg($reversal,$locked[$leg[0]],$leg[0],$leg[1],$leg[2],$leg[3],$reason,$transferId,$actor,$now);}
            return array('result_id'=>$reversal,'result_type'=>'transfer','result_state'=>'recorded','reason_code'=>$reason,'details'=>array('transfer_id'=>$reversal,'reversed_transfer_id'=>$transferId,'created'=>true));
        });
    }

    public function reconcile(string $accountKey,array $input,string $commandKey):array {
        $account=OperationalCashRule::account($accountKey);$date=OperationalCashRule::date($input['as_of_date']??null);
        $actual=OperationalCashRule::amount($input['statement_balance']??null,$account['currency'],true);$evidence=OperationalCashRule::evidence($input,true);
        $facts=array('account'=>$accountKey,'date'=>$date,'statement_balance_minor'=>$actual,'evidence'=>$evidence['reference_digest']);
        return $this->execute('reconcile_account',$commandKey,$facts,array($accountKey),function(array $locked,int $actor,string $now)use($accountKey,$date,$actual,$evidence):array{
            $system=$this->repository->balance((int)$locked[$accountKey]->id,$date);
            $id=$this->repository->insert('finance_cash_reconciliations',array(
                'uid'=>Identifier::uid(),'account_id'=>(int)$locked[$accountKey]->id,'account_key'=>$accountKey,'currency'=>(string)$locked[$accountKey]->currency,
                'as_of_date'=>$date,'system_balance_minor'=>$system,'statement_balance_minor'=>$actual,'difference_minor'=>$system-$actual,
                'evidence_channel'=>$evidence['channel'],'evidence_reference'=>$evidence['reference'],'evidence_reference_digest'=>$evidence['reference_digest'],'evidence_at'=>$evidence['at'],
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            return array('result_id'=>$id,'result_type'=>'reconciliation','result_state'=>'completed','reason_code'=>null,'details'=>array('reconciliation_id'=>$id,'system_balance_minor'=>$system,'statement_balance_minor'=>$actual,'difference_minor'=>$system-$actual));
        });
    }

    private function transferLeg(int $transferId,object $account,string $key,string $currency,string $kind,int $direction,int $amount,string $date,string $description,array $evidence,int $actor,string $now):void {
        $this->repository->insert('finance_cash_transactions',array('uid'=>Identifier::uid(),'account_id'=>(int)$account->id,'account_key'=>$key,'currency'=>$currency,'transaction_date'=>$date,'transaction_kind'=>$kind,'direction'=>$direction,'amount_minor'=>$amount,'category'=>'Transfer','description'=>$description,'transfer_id'=>$transferId,'transfer_leg'=>$key==='australia'?'source':'destination','opening_slot'=>null,'reverses_transaction_id'=>null,'reason_code'=>null,'evidence_channel'=>$evidence['channel'],'evidence_reference'=>$evidence['reference'],'evidence_reference_digest'=>$evidence['reference_digest'],'evidence_at'=>$evidence['at'],'recorded_at'=>$now,'recorded_by'=>$actor,'rule_version'=>OperationalCashRule::RULE_VERSION,'created_at'=>$now,'created_by'=>$actor));
    }
    private function reverseTransferLeg(int $transferId,object $account,string $key,string $currency,int $amount,int $direction,string $reason,int $originalTransfer,int $actor,string $now):void {
        $this->repository->insert('finance_cash_transactions',array('uid'=>Identifier::uid(),'account_id'=>(int)$account->id,'account_key'=>$key,'currency'=>$currency,'transaction_date'=>gmdate('Y-m-d'),'transaction_kind'=>'reversal','direction'=>$direction,'amount_minor'=>$amount,'category'=>'Transfer reversal','description'=>'Reversal of transfer #'.$originalTransfer,'transfer_id'=>$transferId,'transfer_leg'=>$key==='australia'?'source':'destination','opening_slot'=>null,'reverses_transaction_id'=>null,'reason_code'=>$reason,'evidence_channel'=>'staff_record','evidence_reference'=>null,'evidence_reference_digest'=>null,'evidence_at'=>null,'recorded_at'=>$now,'recorded_by'=>$actor,'rule_version'=>OperationalCashRule::RULE_VERSION,'created_at'=>$now,'created_by'=>$actor));
    }
    private function hasOpeningBalance(int $accountId):bool{global $wpdb;$table=$wpdb->prefix.'dzn_finance_cash_transactions';return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE account_id=%d AND transaction_kind='opening_balance'",$accountId))>0;}

    private function execute(string $operation,string $rawKey,array $facts,array $accountKeys,callable $work):array {
        \Delnavazan\Platform\Core\Application\Finance\FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $actor=\Delnavazan\Platform\Core\Application\Finance\FinanceSupport::actor('Cashbook actor unavailable');
        $keyDigest=FinanceIdempotency::commandKey($rawKey);$payloadDigest=FinanceIdempotency::payload(array('domain'=>OperationalCashRule::DOMAIN,'operation'=>$operation,'facts'=>$facts));
        $expectedType=match($operation){'record_transaction','reverse_transaction'=>'transaction','convert_to_iran','reverse_transfer'=>'transfer','reconcile_account'=>'reconciliation',default=>throw new \InvalidArgumentException('Unsupported cashbook command')};
        $this->repository->begin();
        try{
            $locked=array();sort($accountKeys);foreach($accountKeys as$key){$row=$this->repository->account($key,true);if(!$row)throw new \RuntimeException('Cashbook account is not installed');$locked[$key]=$row;}
            if($existing=$this->repository->command($keyDigest)){if(!hash_equals((string)$existing->command_payload_digest,$payloadDigest)||$existing->operation!==$operation||$existing->result_type!==$expectedType)throw new \RuntimeException('command_replay_conflict');$this->repository->commit();return array('result_id'=>(int)$existing->result_id,'idempotent'=>true,'details'=>array('result_id'=>(int)$existing->result_id,'created'=>false));}
            $now=gmdate('Y-m-d H:i:s');$result=$work($locked,$actor,$now,$keyDigest,$payloadDigest);
            if($result['result_type']!==$expectedType)throw new \RuntimeException('Cashbook command result type mismatch');
            $this->repository->insert('finance_cash_commands',array('uid'=>Identifier::uid(),'command_domain'=>OperationalCashRule::DOMAIN,'operation'=>$operation,'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payloadDigest,'result_type'=>$result['result_type'],'result_id'=>(int)$result['result_id'],'result_state'=>$result['result_state'],'reason_code'=>$result['reason_code'],'created_at'=>$now,'created_by'=>$actor));
            $this->repository->commit();return array('result_id'=>(int)$result['result_id'],'idempotent'=>false,'details'=>$result['details']);
        }catch(\Throwable $error){$this->repository->rollback();$existing=$this->repository->command($keyDigest);if($existing){if(!hash_equals((string)$existing->command_payload_digest,$payloadDigest)||$existing->operation!==$operation||$existing->result_type!==$expectedType)throw new \RuntimeException('command_replay_conflict');return array('result_id'=>(int)$existing->result_id,'idempotent'=>true,'details'=>array('result_id'=>(int)$existing->result_id,'created'=>false));}throw$error;}
    }
}
