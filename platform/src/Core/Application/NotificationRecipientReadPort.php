<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * §8.2 — the read-only projection of the identity/consent owner's recipient state.
 *
 * S consumes a *resolved*, channel-neutral eligibility signal: it never stores, derives or becomes the
 * source of truth for consent, and it never reads a contact value outside the §11 envelope seam. The port
 * returns digests and resolved facts only.
 */
interface NotificationRecipientReadPort {
    /**
     * @return array|null `resolvable`, `opted_in`, `guardian_authority_present`, `recipient_digest`,
     *         `contact_digest`, `contact_envelope`, `contact_cipher_version`, `contact_expires_at`,
     *         `timezone`, `academy_timezone`, `locale` — or null when nothing resolves.
     */
    public function recipient(string $audience,string $recipientKind,int $subjectAggregateId):?array;
}
