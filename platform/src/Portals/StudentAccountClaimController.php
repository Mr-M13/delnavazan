<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\StudentAccountClaimValidationService;

/** POST-only claim boundary; final authority revalidates and consumes atomically. */
final class StudentAccountClaimController {
    public static function register():void{add_action('admin_post_dzn_student_account_claim',array(__CLASS__,'claim'));add_action('admin_post_nopriv_dzn_student_account_claim',array(__CLASS__,'claim'));}
    public static function claim():never{try{if(!is_user_logged_in())throw new \InvalidArgumentException('account_claim_login_required');$e=self::validatedEvidence();(new \Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService())->establishPrincipalFromAccountClaim(trim((string)(wp_unslash($_POST)['claim_code']??'')),(int)$e['wordpress_user_id']);wp_safe_redirect(home_url('/student-portal/?account_claim=success'));exit;}catch(\Throwable $error){$state=$error instanceof \InvalidArgumentException&&$error->getMessage()==='account_claim_expired'?'expired':($error instanceof \InvalidArgumentException&&$error->getMessage()==='account_claim_login_required'?'login':'unavailable');wp_safe_redirect(home_url('/student-account-claim/?claim='.$state));exit;}}
    public static function validatedEvidence():array{check_admin_referer('dzn_student_account_claim');if(!is_user_logged_in())throw new \InvalidArgumentException('Authentication required');$post=wp_unslash($_POST);$secret=trim((string)($post['claim_code']??''));return (new StudentAccountClaimValidationService())->validateForAcceptance($secret,(int)get_current_user_id());}
}
