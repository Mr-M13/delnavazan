<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService,PaymentExecutionReadService,PaymentProviderReadService};

/**
 * Administrator read/diagnostic surface for Phase 2A.2-T (contract §13).
 *
 * The surface is view-only: it exposes counts, states and controlled reason codes, never a provider
 * payload, a secret, a raw reference, a ciphertext, a nonce, a signature or a source address. Every read
 * re-checks `dzn_view_payment_execution_authority` server-side.
 */
final class PaymentExecutionController {
    public const CAPABILITY='dzn_view_payment_execution_authority';
    public const MENU_SLUG='dzn-payment-execution';
    public const PARENT_SLUG='dzn-platform';

    public static function register():void{
        add_action('admin_menu',array(__CLASS__,'menu'));
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
        echo '<div class="wrap"><h1>Payment Execution</h1>';
        echo '<p>Provider-neutral execution only. No live credential, provider traffic or deployment is authorised.</p>';
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
