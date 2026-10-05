<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\StudentAccountClaimValidationService;

/** POST-only claim boundary; final authority revalidates and consumes atomically. */
final class StudentAccountClaimController {
    public static function register():void{add_action('admin_post_dzn_student_account_claim',array(__CLASS__,'claim'));}
    public static function claim():never{try{$e=self::validatedEvidence();(new \Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService())->establishPrincipalFromAccountClaim(trim((string)(wp_unslash($_POST)['claim_code']??'')),(int)$e['wordpress_user_id']);wp_safe_redirect(home_url('/student-portal/'));exit;}catch(\Throwable){wp_die('Account claim could not be completed.','Delnavazan',array('response'=>400));}}
    public static function validatedEvidence():array{check_admin_referer('dzn_student_account_claim');if(!is_user_logged_in())throw new \InvalidArgumentException('Authentication required');$post=wp_unslash($_POST);$secret=trim((string)($post['claim_code']??''));return (new StudentAccountClaimValidationService())->validateForAcceptance($secret,(int)get_current_user_id());}
}
