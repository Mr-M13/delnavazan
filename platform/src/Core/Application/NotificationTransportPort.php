<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * §8.2/§13 — the channel-neutral hand-off seam. S registers no real binding.
 *
 * The port receives an already-authorised, idempotent, channel-neutral send command and returns a
 * hand-off acknowledgement plus, when the transport reports a permanent failure, exactly one member of
 * the closed §6.6 terminal-reason vocabulary. A provider status string, a provider identifier, a
 * channel-specific error object and a raw payload are never part of this contract.
 */
interface NotificationTransportPort {
    /**
     * @param array $authorisedCommand `notification_key_digest`, `attempt_sequence`, `audience`,
     *        `template_version_id`, `variable_codes` and the decrypted parameter envelope — nothing else.
     * @return array `['acknowledged'=>bool,'permanent_failure'=>?string]` where a reported permanent
     *         failure is one `contact_unusable`/`send_refused`/`no_route` member, or null.
     */
    public function handoff(array $authorisedCommand):array;
}
