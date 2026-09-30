<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * The complete operation-specific shape of a Phase 2A.2-R1 commercial command row.
 *
 * Replaying a recorded command may only report idempotent success when the persisted command row still
 * describes exactly the operation that was performed. Validating the populated fields alone is not
 * enough: `commercial_commands` carries nullable selectors, so a foreign-but-valid value in a selector
 * that the operation never owns (a borrowed `term_id` on a capacity command, an `obligation_id` on a
 * claim operation, a `teacher_id` on a Term binding) would otherwise be silently ignored.
 *
 * This is the one canonical shape check:
 *
 *  - the command domain and operation must be exactly this operation;
 *  - the recorded result (`result_state`, `result_id`) must be exactly the revalidated result;
 *  - every selector the operation owns must equal the revalidated authoritative aggregate;
 *  - every selector the operation does not own must remain exactly NULL;
 *  - the recorded audit fields must be a valid persisted fact.
 */
final class CommercialCommandShape {
    public const ESTABLISH='establish_protected_capacity';
    public const RELEASE='release_protected_capacity';
    public const BIND='bind_entitlement_to_term';

    /** Every nullable selector the commercial command schema carries, in schema order. */
    public const SELECTORS=array('student_id','teacher_id','offer_id','obligation_id','purchase_id','entitlement_id','claim_id','term_id');

    /**
     * The selectors each Phase-R1 operation owns and persists.
     *
     * A capacity handoff is committed before any Term or obligation truth exists, and a release is
     * anchored on the claim alone (its offer is proved through the commitment chain), so neither owns
     * `term_id`, `obligation_id` or — for a release — `offer_id`. A Term binding is anchored on the
     * Term, Enrolment and claim; the Teacher authority for a bound Term travels with the claim and the
     * Enrolment, so the command owns no `teacher_id` and no `obligation_id` of its own.
     */
    public static function selectorsFor(string $operation):?array{
        return match($operation){
            self::ESTABLISH=>array('student_id','teacher_id','offer_id','purchase_id','entitlement_id','claim_id'),
            self::RELEASE=>array('student_id','teacher_id','purchase_id','entitlement_id','claim_id'),
            self::BIND=>array('student_id','offer_id','purchase_id','entitlement_id','claim_id','term_id'),
            default=>null,
        };
    }

    /**
     * Prove the complete persisted shape of one command against the revalidated aggregate.
     *
     * @param array<string,int|null> $selectors the authoritative value of every selector the operation owns
     * @param string $reason the caller's controlled failure reason (existing command vocabulary)
     */
    public static function assertShape(object $command,string $operation,string $resultState,int $resultId,array $selectors,string $reason):void{
        $owned=self::selectorsFor($operation);
        if($owned===null||$resultId<1)throw new \InvalidArgumentException($reason);
        if((string)$command->command_domain!==CommercialRule::DOMAIN||(string)$command->operation!==$operation)throw new \InvalidArgumentException($reason);
        if((string)$command->result_state!==$resultState)throw new \InvalidArgumentException($reason);
        if($command->result_id===null||(int)$command->result_id!==$resultId)throw new \InvalidArgumentException($reason);
        foreach($selectors as $selector=>$value)if(!in_array($selector,$owned,true))throw new \InvalidArgumentException($reason);
        foreach(self::SELECTORS as $selector){
            $recorded=$command->{$selector};
            if(in_array($selector,$owned,true)){
                // An owned selector must be declared with its authoritative value, so no owned selector
                // can be left unvalidated by a caller.
                if(!array_key_exists($selector,$selectors))throw new \InvalidArgumentException($reason);
                $expected=$selectors[$selector];
                if($expected===null){if($recorded!==null)throw new \InvalidArgumentException($reason);continue;}
                if($recorded===null||(int)$recorded!==(int)$expected)throw new \InvalidArgumentException($reason);
                continue;
            }
            // A selector this operation does not own must remain exactly NULL: a foreign-but-valid
            // identifier in an inapplicable selector is contamination, not evidence.
            if($recorded!==null)throw new \InvalidArgumentException($reason);
        }
        if(!CommercialValidator::utc((string)$command->created_at)||(int)$command->created_by<1)throw new \InvalidArgumentException($reason);
    }
}
