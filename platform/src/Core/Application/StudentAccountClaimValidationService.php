<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\StudentAccountClaimInvitationRepository;

/**
 * Fail-closed validation boundary. It deliberately does not create or consume any principal link:
 * Slice 4 revalidates this evidence while handing off atomically to StudentAcceptanceAuthorityService.
 */
final class StudentAccountClaimValidationService {
    public function __construct(private ?StudentAccountClaimInvitationRepository $records=null){$this->records??=new StudentAccountClaimInvitationRepository();}
    public function validateForAcceptance(string $secret,int $wordpressUserId):array{
        if($wordpressUserId<1||get_current_user_id()!==$wordpressUserId||!is_user_logged_in())throw new \InvalidArgumentException('Authenticated principal ownership required');
        if(!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new \InvalidArgumentException('Invalid account claim token');
        $digest=hash_hmac('sha256',$secret,wp_salt('auth'));$now=current_time('mysql',true);
        $this->records->begin();try{
            $context=$this->records->claimContextForTokenForUpdate($digest);
            if(!$context||$context->invitation_state!=='active'||$context->state!=='active'||(int)$context->active_slot!==1||(int)$context->current_generation!==(int)$context->generation_number)throw new \InvalidArgumentException('account_claim_unavailable');
            if($context->expires_at<=$now)throw new \InvalidArgumentException('account_claim_expired');
            $student=$this->records->studentForUpdate((int)$context->student_id);
            if(!$student||$student->archived_at!==null||$student->status!=='active'||trim((string)$student->uid)===''||trim((string)$student->reference_code)==='')throw new \InvalidArgumentException('Student is not eligible for account claim');
            if(!$this->records->wordpressUserForUpdate($wordpressUserId))throw new \InvalidArgumentException('Authenticated principal is unavailable');
            if($this->records->activePrincipalForStudentForUpdate((int)$context->student_id)||$this->records->activePrincipalForUserForUpdate($wordpressUserId))throw new \InvalidArgumentException('Student or authenticated principal already has a canonical link');
            $evidence=array('invitation_id'=>(int)$context->invitation_id,'generation_id'=>(int)$context->id,'student_id'=>(int)$context->student_id,'wordpress_user_id'=>$wordpressUserId,'generation_uid'=>(string)$context->uid,'evidence_at'=>$now);
            $this->records->commit();return $evidence;
        }catch(\Throwable $e){$this->records->rollback();throw $e;}
    }
}
