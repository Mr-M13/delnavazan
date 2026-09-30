<?php
namespace Delnavazan\Platform\Core\Application;

/** Raised only for a genuinely new public intake operation. */
final class BookingRequestRateLimitException extends \RuntimeException {}
