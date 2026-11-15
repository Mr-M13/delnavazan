<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * A retryable transient scheduling-contention refusal.
 *
 * The safe message is a stable code so a controller or portal can invite a
 * retry without exposing database text or implying that authorization failed.
 */
final class BookingContentionException extends \RuntimeException {
    public const ALLOCATION_BUSY = 'allocation_busy';
    public const TEACHER_BUSY = 'teacher_busy';
    public const RETRY_AFTER_SECONDS = 2;

    private readonly string $errorCode;

    public function __construct( string $errorCode = self::ALLOCATION_BUSY, ?\Throwable $previous = null ) {
        $this->errorCode = in_array( $errorCode, array( self::ALLOCATION_BUSY, self::TEACHER_BUSY ), true ) ? $errorCode : self::ALLOCATION_BUSY;
        parent::__construct( $this->errorCode, self::RETRY_AFTER_SECONDS, $previous );
    }

    public function errorCode(): string {
        return $this->errorCode;
    }

    public function retryAfterSeconds(): int {
        return self::RETRY_AFTER_SECONDS;
    }
}
