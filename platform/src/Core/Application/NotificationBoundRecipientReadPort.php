<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Optional binding-aware extension for an intent whose subject is not itself the recipient record.
 * The notification authority still receives only resolved, encrypted contact facts.
 */
interface NotificationBoundRecipientReadPort extends NotificationRecipientReadPort {
    public function recipientForBinding(string $audience,string $recipientKind,int $subjectAggregateId,array $binding):?array;
}
