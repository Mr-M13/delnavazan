<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * A booking cannot be attached to exactly one canonical Student identity.
 *
 * Raised when the same verified email already belongs to another portal account, when more than one
 * Student record shares the email, or when the booking contact contradicts the existing identity.
 * It is a conflict for staff review, never a credential failure: the caller must not retry with a
 * different password and the booking must not create a second student.
 */
final class BookingAccountIdentityConflict extends \RuntimeException {
}
