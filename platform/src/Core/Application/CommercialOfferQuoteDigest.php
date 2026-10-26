<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Deterministic digest of the commercial facts a Student was shown before choosing a plan.
 *
 * This is a stale-quote guard, never an authorisation credential. The server recomputes the
 * same facts under its canonical locks before issuing an offer and refuses a mismatch. A client
 * cannot prove authority by presenting a digest; it can only prove that the quote it saw is the
 * quote the server is about to honour.
 */
final class CommercialOfferQuoteDigest {
    public const VERSION = 'commercial_offer_quote_v1';

    /** @param array<string,mixed> $facts */
    public static function make(array $facts): string {
        $facts['version'] = self::VERSION;
        self::sortRecursive($facts);
        $encoded = json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) throw new \RuntimeException('commercial_quote_digest_failed');
        return hash('sha256', $encoded);
    }

    public static function valid(string $digest): bool {
        return preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }

    /** @param array<string,mixed> $facts */
    private static function sortRecursive(array &$facts): void {
        foreach ($facts as &$value) {
            if (is_array($value)) self::sortRecursive($value);
        }
        unset($value);
        ksort($facts);
    }
}
