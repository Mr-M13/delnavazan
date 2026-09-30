<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * The scoped, restored worker execution context (contract §9.7 [C4-3]).
 *
 * The `dzn_platform_payment_worker_principal` option's non-human principal is not merely recorded — it
 * is adopted, because the R1/R2 boundaries the translation must satisfy authorise through
 * `current_user_can()` and record `get_current_user_id()`, and an anonymous webhook request supplies
 * neither. This is the only place in Phase T that sets a WordPress current user, and it is bounded,
 * proven and restored on every path including a thrown exception.
 */
final class PaymentExecutionWorkerContext {
    private static bool $active=false;

    public static function active():bool{return self::$active;}

    /**
     * Run one bounded translation/consequence unit under the validated service principal.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function run(callable $work):mixed{
        if(self::$active)throw new \RuntimeException('payment_worker_context_reentrant');
        $principalId=PaymentExecutionSupport::workerPrincipalId();
        if($principalId<1)throw new \RuntimeException('payment_worker_principal_required');
        $previous=(int)get_current_user_id();
        self::$active=true;
        wp_set_current_user($principalId);
        try{
            // The identity is proved effective before any work runs, so the context can never report
            // success while the boundary it is establishing would still refuse the call.
            if((int)get_current_user_id()!==$principalId)throw new \RuntimeException('payment_worker_principal_required');
            foreach(PaymentExecutionSupport::WORKER_CAPABILITIES as $capability)if(!current_user_can($capability))throw new \RuntimeException('payment_worker_principal_required');
            return $work();
        }finally{
            wp_set_current_user($previous);
            self::$active=false;
        }
    }
}
