<?php
namespace Delnavazan\Platform\Core\Application;

/** Read port for provider-confirmed introductory bookings awaiting canonical Lesson creation. */
interface ConfirmedIntroBookingReadPort {
    public function confirmedUpcomingForOperator(int $limit = 80): array;
}
