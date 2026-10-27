<?php
namespace Delnavazan\Platform\Core\Application\Port;

interface RecordingConsentPort {
    public function currentConsent(string $subjectType, int $subjectId, string $purposeCode): ?array;
}
