<?php
namespace Delnavazan\Platform\Core\Application;

/** Proposal-scoped digest helpers. Raw client keys never reach persistence. */
final class ProposalIdempotency {
    public static function keyDigest( string $raw ): string {
        $raw = trim( $raw );
        if ( strlen( $raw ) < 24 || strlen( $raw ) > 255 || ! preg_match( '/^[A-Za-z0-9._~-]+$/D', $raw ) ) {
            throw new \InvalidArgumentException( 'Valid Proposal idempotency key required' );
        }
        return hash_hmac( 'sha256', $raw, wp_salt( 'dzn_proposal_issuance' ) );
    }

    public static function payloadDigest( array $command ): string {
        return hash( 'sha256', wp_json_encode( self::canonicalize( $command ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    public static function versionFingerprint( array $facts ): string {
        return hash( 'sha256', wp_json_encode( self::canonicalize( $facts ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    public static function auditDigest( string $value ): string {
        return hash_hmac( 'sha256', $value, wp_salt( 'dzn_proposal_audit' ) );
    }

    private static function canonicalize( mixed $value ): mixed {
        if ( ! is_array( $value ) ) return $value;
        if ( array_is_list( $value ) ) return array_map( array( self::class, 'canonicalize' ), $value );
        ksort( $value, SORT_STRING );
        foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
        return $value;
    }
}
