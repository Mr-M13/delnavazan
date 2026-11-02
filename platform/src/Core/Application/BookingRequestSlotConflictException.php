<?php
namespace Delnavazan\Platform\Core\Application;

/** The same Student already owns an overlapping Lesson or active introductory booking. */
final class BookingRequestSlotConflictException extends \RuntimeException {}
