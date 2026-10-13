<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;

final class PrincipalInvitationReadService {
    public function rows(int $page=1,int $perPage=100): array {
        if ( ! $this->canReadInvitationStatus() ) throw new \RuntimeException( 'Unauthorized' );
        return ( new PrincipalInvitationRepository() )->adminRows($page,$perPage);
    }
    public function count():int { if(!$this->canReadInvitationStatus())throw new \RuntimeException('Unauthorized'); return (new PrincipalInvitationRepository())->adminRowCount(); }
    private function canReadInvitationStatus(): bool { return current_user_can('dzn_manage_onboarding') || current_user_can('dzn_issue_teacher_invitations') || current_user_can('dzn_revoke_teacher_invitations'); }
}
