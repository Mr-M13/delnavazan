<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Infrastructure\Repository\OperationalCashRepository;

/** Read-only, single-currency account and activity projections for the admin cashbook. */
final class OperationalCashReadService {
    private OperationalCashRepository $repository;
    public function __construct(?OperationalCashRepository $repository=null){$this->repository=$repository??new OperationalCashRepository();}
    public function dashboard(string $accountKey,string $from,string $through,int $page=1):array {
        FinanceSupport::requireCapability(OperationalCashLedgerService::VIEW_CAPABILITY);
        $meta=OperationalCashRule::account($accountKey);$row=$this->repository->account($accountKey);
        if(!$row)throw new \RuntimeException('Cashbook account is not installed');
        $id=(int)$row->id;
        $count=$this->repository->transactionCount($id,$from,$through);$perPage=50;$pages=max(1,(int)ceil($count/$perPage));$page=max(1,min($pages,$page));
        return array('account'=>$row,'meta'=>$meta,'balance_minor'=>$this->repository->balance($id),'period_totals'=>$this->repository->periodTotals($id,$from,$through),'transactions'=>$this->repository->recent($id,$perPage,($page-1)*$perPage,$from,$through),'transaction_count'=>$count,'page'=>$page,'pages'=>$pages,'reconciliations'=>$this->repository->reconciliations($id),'period_from'=>$from,'period_through'=>$through);
    }
    public function accountSummaries():array {
        FinanceSupport::requireCapability(OperationalCashLedgerService::VIEW_CAPABILITY);$items=array();
        foreach(OperationalCashRule::ACCOUNTS as$key=>$meta){$row=$this->repository->account($key);if(!$row)throw new \RuntimeException('Cashbook account is not installed');$items[]=array('key'=>$key,'meta'=>$meta,'balance_minor'=>$this->repository->balance((int)$row->id));}
        return $items;
    }
    public function hasOpeningBalance(string $accountKey):bool {
        FinanceSupport::requireCapability(OperationalCashLedgerService::MANAGE_CAPABILITY);
        $account=$this->repository->account($accountKey);if(!$account)return false;
        global $wpdb;$table=$wpdb->prefix.'dzn_finance_cash_transactions';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE account_id=%d AND transaction_kind='opening_balance'",(int)$account->id))>0;
    }
}
