<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\CheckoutSessionRepository;

/** Secret-free operator view of active local checkout attempts and their recovery age. */
final class CheckoutSessionDiagnosticsService {
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';

    public function __construct(private ?CheckoutSessionRepository $sessions=null){
        $this->sessions??=new CheckoutSessionRepository();
    }

    /** @return array<int,array{attempt_uid:string,provider_key:string,state:string,age_seconds:int,expires_at:?string,needs_attention:bool}> */
    public function activeAttempts(int $limit=50):array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $now=time();$nowSql=gmdate('Y-m-d H:i:s');$rows=array();
        foreach($this->sessions->activeForDiagnostics($limit) as $attempt){
            $created=strtotime((string)($attempt->created_at??'').' UTC');
            $age=$created===false?0:max(0,$now-$created);
            $state=(string)($attempt->state??'unavailable');
            $expired=$state==='open'&&$attempt->expires_at!==null&&(string)$attempt->expires_at<=$nowSql;
            $rows[]=array(
                'attempt_uid'=>(string)$attempt->uid,'provider_key'=>(string)$attempt->provider_key,
                'state'=>$expired?'expired_open':($state==='creating'&&$age>=300?'stale_creating':$state),
                'age_seconds'=>$age,'expires_at'=>$attempt->expires_at===null?null:(string)$attempt->expires_at,
                'needs_attention'=>$expired||($state==='creating'&&$age>=300),
            );
        }
        return $rows;
    }
}
