<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;

/**
 * Retire pending offers that a committed confirmation or reschedule has made
 * impossible for the same Teacher and interval.
 *
 * The caller must already hold the Teacher scheduling root and own the
 * enclosing transaction. A retired request keeps its history and enters the
 * administrator's open-capacity alert queue; no pending Teacher action or
 * queued offer notification survives the winner.
 */
final class BookingOfferRetirementService {
    public function __construct( private ?BookingAllocationRepository $repository = null ) {
        $this->repository ??= new BookingAllocationRepository();
    }

    /** @return list<array{offer_id:int,booking_request_id:int,delivery_id:int}> */
    public function retireCompeting( int $teacherId, string $startsAtUtc, string $occupiedEndUtc, int $excludeBookingRequestId, string $now ): array {
        $retired = $this->repository->retireCompetingOffers( $teacherId, $startsAtUtc, $occupiedEndUtc, $excludeBookingRequestId, $now );
        foreach ( $retired as $row ) {
            $this->repository->alert( (int) $row['booking_request_id'], 'capacity_lost', 'capacity_lost', 'normal', $now );
        }
        return $retired;
    }
}
