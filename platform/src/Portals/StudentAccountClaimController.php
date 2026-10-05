<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\StudentAccountClaimValidationService;

/**
 * Staged POST controller. It is intentionally not registered until the atomic authority handoff
 * exists; accepting a token without consuming it and establishing the canonical link is unsafe.
 */
final class StudentAccountClaimController {
    public static function validatedEvidence():array{
        check_admin_referer('dzn_student_account_claim');
        if(!is_user_logged_in())throw new \InvalidArgumentException('Authentication required');
        $post=wp_unslash($_POST);$secret=trim((string)($post['claim_code']??''));
        return (new StudentAccountClaimValidationService())->validateForAcceptance($secret,(int)get_current_user_id());
    }
}
