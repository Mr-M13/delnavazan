<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * The Phase 1 generic creator is intentionally closed. Canonical Enrolments
 * require a later, explicit Accepted Service Arrangement conversion authority.
 */
final class EnrolmentService {
    public function create(array $data): int {
        if (!current_user_can('dzn_manage_enrolments')) throw new \RuntimeException('Unauthorized');
        throw new \RuntimeException('Generic Enrolment creation is disabled');
    }
}
