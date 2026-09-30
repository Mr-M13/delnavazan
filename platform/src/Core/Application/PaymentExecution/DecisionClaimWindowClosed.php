<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * [C10-2]/[C11-1] The bounded decision window this worker was working inside has closed.
 *
 * Raised when the event's claim is no longer this generation's live, unexpired claim: either its lease
 * expired — the owner stalled past the window it was granted — or exactly one successor generation took
 * the claim over. It is raised both by the work unit's entry gate, before a unit is allowed to begin, and
 * by the unit's own statement fence, *inside* the R1/R2 transaction the unit is running in. The window is
 * deliberately not renewable once it has closed, so a generation whose window is gone performs **no**
 * further decision work and **no** R1/R2 consequence work, and appends nothing.
 *
 * The statement fence raises it **before** the statement it is fencing executes, so the R1/R2 service
 * owning that transaction rolls the whole unit back: a closed window can no longer be discovered only
 * after an already-started R1/R2 mutation had committed. The stale generation then releases the live claim
 * if it still owns it and converges, so the event is completed by the next generation rather than waiting
 * out a lease nobody is working inside.
 *
 * It is a controlled stop, never a failure of the delivery: the caller converges on whatever decision the
 * current owner publishes (or reports the event as durably still owing one, §9.5).
 */
final class DecisionClaimWindowClosed extends \RuntimeException {}
