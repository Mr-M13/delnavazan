<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Checkout\{CheckoutSessionDiagnosticsService,StripeCheckoutReconciliationService};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService,PaymentExecutionReadService,PaymentProviderReadService,StripeTestModeReadinessService};

/**
 * Administrator read/diagnostic surface and guarded recovery action for Phase 2A.2-T (contract §13).
 *
 * The surface is view-only: it exposes counts, states and controlled reason codes, never a provider
 * payload, a secret, a raw reference, a ciphertext, a nonce, a signature or a source address. Reads use
 * `dzn_view_payment_execution_authority`; recovery separately requires `dzn_manage_payment_execution`
 * and a REST nonce, and accepts only a local checkout attempt UID.
 */
final class PaymentExecutionController {
    public const CAPABILITY='dzn_view_payment_execution_authority';
    private const RECONCILE_CAPABILITY='dzn_manage_payment_execution';
    public const MENU_SLUG='dzn-payment-execution';
    public const PARENT_SLUG='dzn-platform';

    public static function register():void{
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('rest_api_init',array(__CLASS__,'registerRestRoutes'));
    }
    public static function registerRestRoutes():void{
        register_rest_route('delnavazan-platform/v1','/admin/payment-execution/checkout-reconcile',array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'reconciliationPermitted'),'callback'=>array(__CLASS__,'reconcileCheckout'),
        ));
    }
    public static function reconciliationPermitted(\WP_REST_Request $request):bool{
        $nonce=(string)$request->get_header('x_wp_nonce');
        return is_user_logged_in()&&current_user_can(self::RECONCILE_CAPABILITY)&&$nonce!==''&&wp_verify_nonce($nonce,'wp_rest');
    }
    public static function reconcileCheckout(\WP_REST_Request $request):\WP_REST_Response{
        try{
            $params=$request->get_json_params();
            if(!is_array($params)||$request->get_file_params()!==array()||$request->get_url_params()!==array()||$request->get_query_params()!==array())
                throw new \InvalidArgumentException('checkout_reconciliation_unavailable');
            $result=(new StripeCheckoutReconciliationService())->reconcile($params);
            return new \WP_REST_Response($result,200);
        }catch(\Throwable){
            return new \WP_REST_Response(array('state'=>'unresolved','reason_code'=>'checkout_reconciliation_unavailable'),503);
        }
    }
    public static function menu():void{
        if(!function_exists('add_submenu_page'))return;
        add_submenu_page(self::PARENT_SLUG,'Payment Execution','Payment Execution',self::CAPABILITY,self::MENU_SLUG,array(__CLASS__,'render'));
    }
    public static function render():void{
        if(!current_user_can(self::CAPABILITY))return;
        $execution=(new PaymentExecutionReadService())->commandStates();
        $dispatches=(new PaymentExecutionReadService())->outstandingDispatches();
        $outcomes=(new PaymentExecutionReadService())->attemptOutcomes();
        $accounts=(new PaymentProviderReadService())->accountStates();
        $secrets=(new PaymentProviderReadService())->secretDiagnostics();
        $intake=new PaymentEventIntakeService();
        $consequences=$intake->outstandingConsequences();
        $decisionClaims=$intake->outstandingDecisionClaims();
        $readiness=(new StripeTestModeReadinessService())->read();
        $checkoutAttempts=(new CheckoutSessionDiagnosticsService())->activeAttempts();
        echo '<div class="wrap"><h1>Payment Execution</h1>';
        echo '<p>Provider-neutral execution only. No live credential, provider traffic or deployment is authorised.</p>';
        echo '<h2>Stripe test-mode readiness</h2><p>'.esc_html($readiness['ready']?'Local preflight is ready for an explicitly authorised test-mode runtime check.':'Local preflight is not ready for Stripe test-mode runtime verification.').'</p>';
        echo '<p>Checks cover local configuration and registered routes. Public webhook reachability, Stripe session creation, webhook delivery and settlement still require a later test-mode runtime check.</p><table class="widefat"><tbody>';
        foreach($readiness['checks'] as $key=>$check)echo '<tr><th>'.esc_html((string)$key).'</th><td>'.esc_html((string)$check['state']).'</td><td>'.esc_html((string)$check['detail']).'</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Active Checkout attempts</h2><p>Only local attempt identifiers and lifecycle timing are shown; Stripe references, URLs and student details are not exposed.</p><table class="widefat"><thead><tr><th>Attempt UID</th><th>Provider</th><th>State</th><th>Age</th><th>Expiry</th><th>Attention</th></tr></thead><tbody>';
        foreach($checkoutAttempts as $attempt)echo '<tr><td><code>'.esc_html((string)$attempt['attempt_uid']).'</code></td><td>'.esc_html((string)$attempt['provider_key']).'</td><td>'.esc_html((string)$attempt['state']).'</td><td>'.esc_html((string)$attempt['age_seconds']).'s</td><td>'.esc_html((string)($attempt['expires_at']??'')).'</td><td>'.esc_html($attempt['needs_attention']?'Review':'No').'</td></tr>';
        if(!$checkoutAttempts)echo '<tr><td colspan="6">No active Checkout attempts.</td></tr>';
        echo '</tbody></table>';
        foreach(array('Commands'=>$execution,'Attempt outcomes'=>$outcomes,'Accounts'=>$accounts,'R2 consequences'=>$consequences,'Secret vault'=>$secrets) as $heading=>$values){
            echo '<h2>'.esc_html((string)$heading).'</h2><table class="widefat"><tbody>';
            foreach((array)$values as $key=>$value)echo '<tr><td>'.esc_html((string)$key).'</td><td>'.esc_html((string)$value).'</td></tr>';
            echo '</tbody></table>';
        }
        echo '<h2>Outstanding dispatch claims</h2><table class="widefat"><tbody>';
        foreach($dispatches as $row)echo '<tr><td>'.esc_html((string)$row['execution_command_id']).'</td><td>'.esc_html((string)$row['dispatch_state']).'</td><td>'.esc_html((string)$row['claim_generation']).'</td><td>'.esc_html((string)$row['age_seconds']).'s</td></tr>';
        echo '</tbody></table><h2>Live event decision claims</h2><table class="widefat"><tbody>';
        foreach($decisionClaims as $row)echo '<tr><td>'.esc_html((string)$row['provider_event_id']).'</td><td>'.esc_html((string)$row['claim_state']).'</td><td>'.esc_html((string)$row['claim_generation']).'</td><td>'.esc_html((string)$row['age_seconds']).'s</td></tr>';
        echo '</tbody></table></div>';
    }
}
