<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Bounded classification of transient InnoDB contention.
 *
 * A database deadlock or lock-wait timeout is not an authorization or booking
 * refusal. Callers must roll back and invite a retry instead of telling a
 * Teacher or Student that the request is no longer available. Detection reads
 * the database error before rollback clears `$wpdb->last_error`.
 */
final class BookingContention {
    private const PATTERNS = array(
        'deadlock found when trying to get lock',
        'lock wait timeout exceeded',
        'try restarting transaction',
        'wsrep detected deadlock',
    );

    public static function isLockFailure( ?string $error ): bool {
        $text = strtolower( trim( (string) $error ) );
        if ( $text === '' ) return false;
        foreach ( self::PATTERNS as $pattern ) {
            if ( str_contains( $text, $pattern ) ) return true;
        }
        return preg_match( '/\b(?:1213|1205)\b/', $text ) === 1;
    }

    /** Capture the transient failure before the caller rolls back the transaction. */
    public static function capture( \Throwable $error, string $code = BookingContentionException::ALLOCATION_BUSY ): ?BookingContentionException {
        global $wpdb;
        $databaseError = is_object( $wpdb ) ? (string) ( $wpdb->last_error ?? '' ) : '';
        $combined = trim( $error->getMessage() . ' ' . $databaseError );
        if ( ! self::isLockFailure( $combined ) ) return null;
        return new BookingContentionException( $code, $error );
    }
}
