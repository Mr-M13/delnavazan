<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;

final class PrincipalInvitationReadService {
    public function rows(): array {
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) throw new \RuntimeException( 'Unauthorized' );
        return ( new PrincipalInvitationRepository() )->adminRows();
    }
}
