<?php
namespace Delnavazan\Platform\Core\Application\Port;

/**
 * Recording capture seam. Integrations implement; Core calls only through this port.
 */
interface RecordingProviderPort {
    /** Capture a recording; returns object keys + quality metrics + digest. */
    public function capture(array $command): array;
    /** Purge an object by opaque key. Returns success. */
    public function purge(string $objectKey): bool;
}
