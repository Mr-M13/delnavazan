<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Phase 2A.2-T execution authority (contract §8, §12).
 *
 * `payment_execution_commands`, `payment_execution_attempts` and `payment_execution_results` are
 * insert-only: this repository exposes no update and no delete method for any of them. The single
 * mutable execution row is the dispatch claim, and every transition on it is one conditional
 * compare-and-swap whose affected-row count is the proof of ownership.
 */
final class PaymentExecutionRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}

    // --- commands (immutable authorisation evidence) -------------------------------------------------
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}payment_execution_commands WHERE command_key_digest=%s",$digest);}
    public function commandById(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_execution_commands WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function insertCommand(array $data):int{return $this->insert('payment_execution_commands',$data);}
    public function commands():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_execution_commands ORDER BY id ASC")?:array();
    }

    // --- attempts and results (append-only, at most one of each per command) --------------------------
    public function attemptForCommand(int $commandId):?object{return $this->one("SELECT * FROM {$this->p}payment_execution_attempts WHERE execution_command_id=%d",$commandId);}
    public function insertAttempt(array $data):int{return $this->insert('payment_execution_attempts',$data);}
    public function resultForCommand(int $commandId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_execution_results WHERE execution_command_id=%d".($lock?' FOR UPDATE':''),$commandId);}
    public function insertResult(array $data):int{return $this->insert('payment_execution_results',$data);}
    public function results():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_execution_results ORDER BY id ASC")?:array();
    }

    // --- the dispatch claim (the only mutable execution row) -----------------------------------------
    public function dispatchForCommand(int $commandId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_execution_dispatches WHERE execution_command_id=%d".($lock?' FOR UPDATE':''),$commandId);}
    public function dispatchById(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}payment_execution_dispatches WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function liveClaimForSubject(string $subjectKind,int $subjectId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}payment_execution_dispatches WHERE arbitration_subject_kind=%s AND arbitration_subject_id=%d AND active_claim_slot=1".($lock?' FOR UPDATE':''),$subjectKind,$subjectId);
    }
    public function insertDispatch(array $data):int{return $this->insert('payment_execution_dispatches',$data);}
    public function dispatches():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}payment_execution_dispatches ORDER BY id ASC")?:array();
    }

    /** Conditional `claimed → in_flight` acquisition: exactly one affected row confers ownership. */
    public function acquireLease(int $dispatchId,int $expectedGeneration,string $tokenDigest,string $leaseUntil):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_execution_dispatches SET dispatch_state='in_flight',claim_token_digest=%s,lease_expires_at=%s,updated_at=%s WHERE id=%d AND dispatch_state='claimed' AND claim_generation=%d",
            $tokenDigest,$leaseUntil,$leaseUntil,$dispatchId,$expectedGeneration
        ));
    }
    /** Conditional expired-lease takeover: one statement bumps the generation and issues a fresh token. */
    public function takeoverLease(int $dispatchId,int $observedGeneration,string $tokenDigest,string $leaseUntil,string $now):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_execution_dispatches SET dispatch_state='in_flight',claim_generation=claim_generation+1,claim_token_digest=%s,lease_expires_at=%s,updated_at=%s WHERE id=%d AND dispatch_state='in_flight' AND claim_generation=%d AND lease_expires_at IS NOT NULL AND lease_expires_at<%s",
            $tokenDigest,$leaseUntil,$now,$dispatchId,$observedGeneration,$now
        ));
    }
    /** Conditional pre-call ownership check and lease renewal for a takeover generation (§8.3 [C5-2]). */
    public function renewLease(int $dispatchId,int $generation,string $tokenDigest,string $leaseUntil,string $now):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_execution_dispatches SET lease_expires_at=%s,updated_at=%s WHERE id=%d AND dispatch_state='in_flight' AND claim_generation=%d AND claim_token_digest=%s AND lease_expires_at IS NOT NULL AND lease_expires_at>%s",
            $leaseUntil,$now,$dispatchId,$generation,$tokenDigest,$now
        ));
    }
    /** Fenced `in_flight → settled` settlement. */
    public function settleClaim(int $dispatchId,int $generation,string $tokenDigest,string $now):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_execution_dispatches SET dispatch_state='settled',active_claim_slot=NULL,settled_at=%s,lease_expires_at=NULL,updated_at=%s WHERE id=%d AND dispatch_state='in_flight' AND claim_generation=%d AND claim_token_digest=%s",
            $now,$now,$dispatchId,$generation,$tokenDigest
        ));
    }
    /** Fenced `claimed → released` descriptor refusal. A `claimed` claim never issued a call. */
    public function releaseClaim(int $dispatchId,int $observedGeneration,string $observedToken,string $now):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_execution_dispatches SET dispatch_state='released',active_claim_slot=NULL,settled_at=%s,updated_at=%s WHERE id=%d AND dispatch_state='claimed' AND claim_generation=%d AND claim_token_digest=%s",
            $now,$now,$dispatchId,$observedGeneration,$observedToken
        ));
    }
    /** Fenced generation-1 `in_flight → released` no-call abort: provably call-free (§8.3 [C7-2]). */
    public function noCallAbort(int $dispatchId,string $tokenDigest,string $now):int{
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}payment_execution_dispatches SET dispatch_state='released',lease_expires_at=NULL,active_claim_slot=NULL,settled_at=%s,updated_at=%s WHERE id=%d AND dispatch_state='in_flight' AND claim_generation=1 AND claim_token_digest=%s AND lease_expires_at IS NOT NULL AND lease_expires_at>%s",
            $now,$now,$dispatchId,$tokenDigest,$now
        ));
    }

    // --- authoritative aggregate reads (never a write to an external parent) --------------------------
    /** The R1 obligation with its offer, purchase and settlement state, re-read inside the transaction. */
    public function obligationContext(int $obligationId,bool $lock=false):?object{
        return $this->one(
            "SELECT obligation.id AS obligation_id,obligation.offer_id AS offer_id,obligation.obligation_sequence AS obligation_sequence,obligation.amount_minor AS amount_minor,obligation.currency AS currency,obligation.sessions_from AS sessions_from,obligation.sessions_to AS sessions_to,offer.beneficiary_student_id AS student_id,offer.student_id AS learner_student_id,offer.teacher_id AS teacher_id,offer.course_id AS course_id,offer.product_id AS product_id,offer.state AS offer_state,offer.currency AS offer_currency,offer.amount_due_minor AS offer_amount_due,offer.expires_at AS offer_expires_at,purchase.id AS purchase_id,purchase.state AS purchase_state,purchase.currency AS purchase_currency FROM {$this->p}commercial_offer_obligations obligation JOIN {$this->p}commercial_offers offer ON offer.id=obligation.offer_id LEFT JOIN {$this->p}commercial_purchases purchase ON purchase.offer_id=offer.id WHERE obligation.id=%d".($lock?' FOR UPDATE':''),
            $obligationId
        );
    }
    public function settlementForObligation(int $obligationId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_obligation_settlements WHERE obligation_id=%d".($lock?' FOR UPDATE':''),$obligationId);
    }
    /** The R2 collection intent with its frozen cycle mode and the cycle's live state. */
    public function collectionIntentContext(int $intentId,bool $lock=false):?object{
        return $this->one(
            "SELECT intent.id AS collection_intent_id,intent.renewal_cycle_id AS renewal_cycle_id,intent.obligation_id AS obligation_id,intent.kind AS kind,intent.state AS intent_state,intent.charge_at AS charge_at,cycle.id AS cycle_id,cycle.state AS cycle_state,cycle.collection_mode AS collection_mode,cycle.recurring_enrolment_id AS recurring_enrolment_id,cycle.currency AS cycle_currency,enrolment.student_id AS student_id,enrolment.course_id AS course_id FROM {$this->p}collection_intents intent JOIN {$this->p}renewal_cycles cycle ON cycle.id=intent.renewal_cycle_id JOIN {$this->p}recurring_enrolments enrolment ON enrolment.id=cycle.recurring_enrolment_id WHERE intent.id=%d".($lock?' FOR UPDATE':''),
            $intentId
        );
    }
    public function intentForObligation(int $obligationId):?object{
        return $this->one("SELECT * FROM {$this->p}collection_intents WHERE obligation_id=%d ORDER BY id ASC LIMIT 1",$obligationId);
    }
    public function cycleForIntent(int $intentId):?object{
        return $this->one("SELECT cycle.* FROM {$this->p}renewal_cycles cycle JOIN {$this->p}collection_intents intent ON intent.renewal_cycle_id=cycle.id WHERE intent.id=%d",$intentId);
    }
    public function recurringEnrolment(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}recurring_enrolments WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function student(int $id):?object{return $this->one("SELECT * FROM {$this->p}students WHERE id=%d",$id);}

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('uid','command_key_digest','command_result','attempt_sequence','command_dispatch','subject_claim'),true)?$key:null;
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
}
