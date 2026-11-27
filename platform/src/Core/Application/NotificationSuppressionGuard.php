<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;

/**
 * The one opt-out check every mobile producer must pass before handing a message to a provider.
 *
 * A carrier STOP is stored as an active suppression for the recipient digest with purpose `*`. The
 * canonical dispatcher already consults it; lesson, renewal and refund producers historically did not,
 * so a student who replied STOP still received WhatsApp/SMS. This guard closes that gap with the same
 * recipient digest the suppression authority writes.
 *
 * It fails closed: if the register cannot be read, a mobile send is skipped rather than risking a
 * message to someone who opted out. Email and the portal inbox remain available.
 */
final class NotificationSuppressionGuard {
    public function suppressed(string $audience, int $recipientId, string $eventKey): bool {
        if ($recipientId < 1 || $eventKey === '') return true;
        if (!in_array($audience, array('student', 'teacher'), true)) return true;
        try {
            global $wpdb;
            $digest = hash_hmac('sha256', 'recipient:' . $audience . ':' . $recipientId, NotificationSupport::salt());
            $row = (new NotificationSuppressionRepository())->active($digest, $eventKey, gmdate('Y-m-d H:i:s'), true);
            // A register read failure must not be mistaken for "no opt-out".
            if (isset($wpdb) && $wpdb->last_error !== '') return true;
            return $row !== null;
        } catch (\Throwable) {
            return true;
        }
    }
}
