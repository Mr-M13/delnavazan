<?php
namespace Delnavazan\Platform\Portals;

/** Read-only Student-enrolment projection that does not require a Teacher Assignment or Term yet. */
interface StudentEnrolmentPortalReadPort {
    /** @return array<string,mixed> */
    public function forSubject(AuthenticatedPortalReadSubject $subject, int $enrolmentId): array;
}
