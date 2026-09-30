<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Phase 2A.2-T provider-account, mapping and provider-event storage
 * (contract §7, §9, §12).
 *
 * Every account row, mapping row and provider event is stored digest-only: no raw provider reference,
 * no raw payload, no raw signature and no raw source address is ever written. Duplicate arbitration is
 * always by an explicit named index, never by a read-then-write race.
 */
final class PaymentProviderRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}

    // --- provider accounts -------------------------------------------------------------------------
    public function account(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_provider_accounts WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function accountByReferenceCode(string $referenceCode,bool $lock=false):?object{
        $referenceCode=trim($referenceCode);
        if($referenceCode==='')return null;
        return $this->one("SELECT * FROM {$this->p}payment_provider_accounts WHERE reference_code=%s".($lock?' FOR UPDATE':''),$referenceCode);
    }
    public function accountsForProvider(string $providerKey):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_accounts WHERE provider_key=%s ORDER BY id ASC",$providerKey))?:array();
    }
    public function allAccounts():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_provider_accounts ORDER BY id ASC")?:array();
    }
    public function accountsByReferenceCode(string $referenceCode,bool $lock=false):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_accounts WHERE reference_code=%s".($lock?' FOR UPDATE':''),trim($referenceCode)))?:array();
    }
    public function insertAccount(array $data):int{return $this->insert('payment_provider_accounts',$data);}
    public function updateAccount(int $id,int $expectedVersion,array $fields,string $now,int $actor):int{
        $changes=array_merge($fields,array('account_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor));
        return $this->update('payment_provider_accounts',$changes,array('id'=>$id,'account_version'=>$expectedVersion));
    }
    public function maxAccountEventSequence(int $accountId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->p}payment_provider_account_events WHERE payment_provider_account_id=%d",$accountId));
    }
    public function insertAccountEvent(array $data):int{return $this->insert('payment_provider_account_events',$data);}
    public function accountEvents(int $accountId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_account_events WHERE payment_provider_account_id=%d ORDER BY event_sequence ASC",$accountId))?:array();
    }
    public function insertAccountCommand(array $data):int{return $this->insert('payment_provider_account_commands',$data);}

    // --- provider object mappings -------------------------------------------------------------------
    public function object(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_provider_objects WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function activeObject(int $accountId,string $canonicalKind,int $canonicalId,string $objectKind,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}payment_provider_objects WHERE payment_provider_account_id=%d AND canonical_kind=%s AND canonical_id=%d AND object_kind=%s AND active_slot=1".($lock?' FOR UPDATE':''),$accountId,$canonicalKind,$canonicalId,$objectKind);
    }
    public function objectByReferenceDigest(int $accountId,string $objectKind,string $referenceDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}payment_provider_objects WHERE payment_provider_account_id=%d AND object_kind=%s AND object_reference_digest=%s".($lock?' FOR UPDATE':''),$accountId,$objectKind,$referenceDigest);
    }
    /**
     * [C8-2] Every *active* mapping of one provider object reference, whatever its kind.
     *
     * The provider-side unique index is kind-scoped, so one raw reference could in principle be linked
     * under two different kinds. The caller requires exactly one candidate and refuses an ambiguous
     * object rather than choosing one, and a historical row (`active_slot IS NULL` — superseded or
     * detached) is never returned, because it is no longer authority for attribution.
     */
    public function activeObjectsByReferenceDigest(int $accountId,string $referenceDigest,bool $lock=false):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_objects WHERE payment_provider_account_id=%d AND object_reference_digest=%s AND active_slot=1".($lock?' FOR UPDATE':''),$accountId,$referenceDigest))?:array();
    }
    /** [C8-2] The R1 obligation one canonical collection intent already owns (NULL when it owns none). */
    public function obligationForCollectionIntent(int $collectionIntentId):?int{
        $row=$this->one("SELECT obligation_id FROM {$this->p}collection_intents WHERE id=%d",$collectionIntentId);
        return $row?(int)$row->obligation_id:null;
    }
    public function objectsForCanonical(string $canonicalKind,int $canonicalId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_objects WHERE canonical_kind=%s AND canonical_id=%d ORDER BY id ASC",$canonicalKind,$canonicalId))?:array();
    }
    public function allObjects():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_provider_objects ORDER BY id ASC")?:array();
    }
    public function insertObject(array $data):int{return $this->insert('payment_provider_objects',$data);}
    public function updateObject(int $id,int $expectedVersion,array $fields,string $now,int $actor):int{
        $changes=array_merge($fields,array('link_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor));
        return $this->update('payment_provider_objects',$changes,array('id'=>$id,'link_version'=>$expectedVersion));
    }
    public function maxObjectEventSequence(int $objectId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->p}payment_provider_object_events WHERE payment_provider_object_id=%d",$objectId));
    }
    public function insertObjectEvent(array $data):int{return $this->insert('payment_provider_object_events',$data);}
    public function objectEvents(int $objectId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_object_events WHERE payment_provider_object_id=%d ORDER BY event_sequence ASC",$objectId))?:array();
    }
    public function insertObjectCommand(array $data):int{return $this->insert('payment_provider_object_commands',$data);}
    public function accountCommand(string $digest):?object{return $this->one("SELECT * FROM {$this->p}payment_provider_account_commands WHERE command_key_digest=%s",$digest);}
    public function objectCommand(string $digest):?object{return $this->one("SELECT * FROM {$this->p}payment_provider_object_commands WHERE command_key_digest=%s",$digest);}

    // --- provider event receipts, events and decisions ----------------------------------------------
    public function insertReceipt(array $data):int{return $this->insert('payment_provider_event_receipts',$data);}
    public function receipt(int $id):?object{return $this->one("SELECT * FROM {$this->p}payment_provider_event_receipts WHERE id=%d",$id);}
    public function receipts():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_provider_event_receipts ORDER BY id ASC")?:array();
    }
    public function insertEvent(array $data):int{return $this->insert('payment_provider_events',$data);}
    public function event(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_provider_events WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function eventByReferenceDigest(string $providerKey,string $eventReferenceDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}payment_provider_events WHERE provider_key=%s AND event_reference_digest=%s".($lock?' FOR UPDATE':''),$providerKey,$eventReferenceDigest);
    }
    public function events():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_provider_events ORDER BY id ASC")?:array();
    }
    public function maxDecisionSequence(int $eventId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(decision_sequence),0)+1 FROM {$this->p}payment_provider_event_decisions WHERE provider_event_id=%d",$eventId));
    }
    public function insertDecision(array $data):int{return $this->insert('payment_provider_event_decisions',$data);}
    public function decisions(int $eventId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY decision_sequence ASC",$eventId))?:array();
    }
    public function latestDecision(int $eventId):?object{
        return $this->one("SELECT * FROM {$this->p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY decision_sequence DESC LIMIT 1",$eventId);
    }
    public function allDecisions():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_provider_event_decisions ORDER BY id ASC")?:array();
    }

    // --- the per-event decision claim (the phase's own mutable intake row, [C9-1]/[C9-2]) ------------
    public function insertDecisionClaim(array $data):int{return $this->insert('payment_provider_event_decision_claims',$data);}
    public function decisionClaim(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_provider_event_decision_claims WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    /**
     * [C9-1] The live claim of one event, or nothing when the event owes no claimed decision.
     *
     * `UNIQUE event_claim (provider_event_id, active_claim_slot)` — never this read — is what arbitrates
     * two workers, exactly as the dispatch claim's `subject_claim` index arbitrates two commands.
     */
    public function liveDecisionClaim(int $providerEventId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1".($lock?' FOR UPDATE':''),$providerEventId);
    }
    public function decisionClaims():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_provider_event_decision_claims ORDER BY id ASC")?:array();
    }
    /**
     * [C9-2]/[C12-1]/[C14-1] Fenced `claimed → settled`: one affected row is the proof this generation still
     * owns the event *inside an open window*.
     *
     * It is the first call of the transaction that appends the decision — [C14-1] and the locking read it
     * starts with is that transaction's first statement — so the claim row's lock serialises the next
     * `decision_sequence` allocation a losing or replaced owner might otherwise derive at the same time.
     *
     * [C12-1] The append is bounded by the same window that bounded the work: the transition requires the
     * worker's own live generation, its live slot and token **and** an unexpired `lease_expires_at`, and it
     * takes that verdict from one database-time expression — `UTC_TIMESTAMP()` — which also stamps the
     * settlement, so a hook callback, or any other delay between the seam and this transition, can never
     * settle a claim whose window closed in the meantime: a decision operation that outlives its 120-second
     * lease settles nothing and appends nothing here, exactly like a replaced generation, even when the
     * caller captured its own clock before the delay. The caller releases the claim it appended nothing to
     * (fenced by its own generation and token) and converges, so an expired-but-not-yet-taken-over claim
     * never strands the event.
     *
     * [C14-1] A database-time expression is evaluated **once, when its statement starts** — not when the
     * statement reaches the row it is judging. A single conditional update that had to wait for another
     * transaction's lock on the claim row would therefore still judge the window with the instant it began
     * waiting, and could settle a claim whose lease lapsed during that wait. The transition is therefore two
     * statements: a fenced locking read that first acquires the claim row's lock — waiting for any other
     * holder there, where waiting is harmless because it decides nothing — and only then the conditional
     * update, which can no longer wait for that row and so takes its own database-time verdict after the
     * wait rather than before it. The locking read can only ever *refuse* a claim the update would also
     * refuse (a row it cannot see is not this generation's live claim); it is never the proof of ownership.
     * The update's affected-row count remains the only proof, so a window that lapsed while the append was
     * queued behind the lock settles nothing, stamps nothing and publishes nothing.
     */
    public function settleDecisionClaim(int $claimId,int $expectedGeneration,string $tokenDigest):int{
        global $wpdb;
        $live=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}payment_provider_event_decision_claims WHERE id=%d AND claim_state='claimed' AND claim_generation=%d AND claim_token_digest=%s AND active_claim_slot=1 AND lease_expires_at IS NOT NULL AND lease_expires_at>=UTC_TIMESTAMP() FOR UPDATE",
            $claimId,$expectedGeneration,$tokenDigest
        ));
        if($live===null||$live==='')return 0;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_provider_event_decision_claims SET claim_state='settled',active_claim_slot=NULL,lease_expires_at=NULL,settled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=%d AND claim_state='claimed' AND claim_generation=%d AND claim_token_digest=%s AND active_claim_slot=1 AND lease_expires_at IS NOT NULL AND lease_expires_at>=UTC_TIMESTAMP()",
            $claimId,$expectedGeneration,$tokenDigest
        ));
    }
    /** [C9-2] Fenced `claimed → released`: the owner appended no decision and frees the event's live slot. */
    public function releaseDecisionClaim(int $claimId,int $expectedGeneration,string $tokenDigest,string $now):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_provider_event_decision_claims SET claim_state='released',active_claim_slot=NULL,lease_expires_at=NULL,settled_at=%s,updated_at=%s WHERE id=%d AND claim_state='claimed' AND claim_generation=%d AND claim_token_digest=%s",
            $now,$now,$claimId,$expectedGeneration,$tokenDigest
        ));
    }
    /** [C9-2] Conditional expired-lease takeover: one statement bumps the generation and issues a fresh lease. */
    public function takeoverDecisionClaim(int $claimId,int $observedGeneration,string $tokenDigest,string $leaseUntil,string $now):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_provider_event_decision_claims SET claim_state='claimed',claim_generation=claim_generation+1,claim_token_digest=%s,lease_expires_at=%s,claimed_at=%s,updated_at=%s WHERE id=%d AND claim_state='claimed' AND claim_generation=%d AND active_claim_slot=1 AND lease_expires_at IS NOT NULL AND lease_expires_at<%s",
            $tokenDigest,$leaseUntil,$now,$now,$claimId,$observedGeneration,$now
        ));
    }
    /**
     * [C10-2]/[C11-1] Fenced, lease-bounded ownership proof of one decision work unit — taken at the
     * unit's entry *and* inside the transaction the unit itself runs in, immediately before every statement
     * that transaction executes (see `PaymentExecutionRule::DECISION_UNIT_FENCE_FILTER`).
     *
     * The locking read proves, and the extension that follows only ever continues, the bounded window the
     * owner is working inside: the claim must still be `claimed` at this worker's own `claim_generation` and
     * `claim_token_digest`, still carry its live slot, **and** still hold an unexpired
     * `lease_expires_at`. A read that returns no row is the proof that the window has closed — the lease
     * lapsed before this point, or exactly one successor generation took the claim over — and the caller
     * must perform no further decision or R1/R2 consequence work at all. The returned verdict is a read,
     * never an affected-row count, so a renewal that happens to write the same second is never mistaken for
     * a closed window.
     *
     * `FOR UPDATE` is deliberate and load-bearing: taken inside a work unit's own transaction, the read
     * locks the claim row for the rest of that transaction, so a decision-claim takeover — which needs this
     * same row and an *expired* lease — can never interleave with a unit, and a unit can never commit a
     * statement outside the window it was granted. `$leaseUntil` is `null` for a statement that runs inside
     * the unit's own transaction, whose window was already granted at the unit's entry and must simply
     * cover every statement the transaction runs: the unit then stays bounded by the window it was given.
     * It names the renewed lease for a statement that runs outside any transaction — R1 records its routing
     * write there — so the statement itself runs inside a freshly renewed window and nothing can slip in
     * front of it. The extension is a continuation, never a resurrection: when the read proved no live
     * window, no lease is written at all.
     */
    public function fenceDecisionClaimWindow(int $claimId,int $expectedGeneration,string $tokenDigest,?string $leaseUntil,string $now):bool{
        global $wpdb;
        $live=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}payment_provider_event_decision_claims WHERE id=%d AND claim_state='claimed' AND claim_generation=%d AND claim_token_digest=%s AND active_claim_slot=1 AND lease_expires_at IS NOT NULL AND lease_expires_at>=%s FOR UPDATE",
            $claimId,$expectedGeneration,$tokenDigest,$now
        ));
        if($live===null||$live==='')return false;
        if($leaseUntil===null)return true;
        if($wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_provider_event_decision_claims SET lease_expires_at=%s,updated_at=%s WHERE id=%d AND claim_state='claimed' AND claim_generation=%d AND claim_token_digest=%s AND active_claim_slot=1",
            $leaseUntil,$now,$claimId,$expectedGeneration,$tokenDigest
        ))===false)throw new \RuntimeException('Decision claim window persistence failed');
        return true;
    }

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array(
            'uid','reference_code','provider_account','provider_object','canonical_object','secret_slot',
            'account_sequence','object_sequence','provider_event','decision_sequence','request_digest',
            'command_key_digest','command_result','command_dispatch','subject_claim','event_claim',
        ),true)?$key:null;
    }

    private function insert(string $table,array $data):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new PersistenceException((int)$wpdb->last_errno,(string)$error,'insert '.$table);
        return (int)$wpdb->insert_id;
    }
    private function update(string $table,array $changes,array $where):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $result=$wpdb->update($this->p.$table,$changes,$where);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($result===false)throw new \RuntimeException('Payment provider persistence failed: '.$error);
        return (int)$result;
    }
}
