<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * A typed public-intake refusal for a start inside the minimum notice window.
 * The message is already safe for a Student response; callers must not replace
 * it with a database or scheduling detail.
 */
final class BookingLeadTimeException extends \InvalidArgumentException {
    /** @param array{reason_code:string,message:string,minimum_start_utc:string,timezone:string} $refusal */
    public function __construct( private readonly array $refusal ) {
        parent::__construct( (string) ( $refusal['message'] ?? 'lead_time_too_short' ) );
    }

    public function reasonCode(): string {
        return (string) ( $this->refusal['reason_code'] ?? 'lead_time_too_short' );
    }

    public function minimumStartUtc(): string {
        return (string) ( $this->refusal['minimum_start_utc'] ?? '' );
    }

    public function timezone(): string {
        return (string) ( $this->refusal['timezone'] ?? 'UTC' );
    }
}
