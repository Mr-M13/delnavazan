<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for provider-neutral commercial payment evidence.
 *
 * Evidence rows are immutable and deduplicated by provider reference; a payment fact and an
 * obligation settlement are appended once and never rewritten. No provider SDK, provider object,
 * raw provider payload or provider-specific column exists here: the provider is only a source of
 * evidence, and canonical acceptance stays with the commercial purchase authority.
 */
final class CommercialPaymentRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function evidence(int $evidenceId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_payment_evidence WHERE id=%d".($lock?' FOR UPDATE':''),$evidenceId);}
    public function evidenceByProviderReference(string $providerKey,string $referenceDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_payment_evidence WHERE provider_key=%s AND evidence_reference_digest=%s".($lock?' FOR UPDATE':''),$providerKey,$referenceDigest);
    }
    public function evidenceForPurchase(int $purchaseId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_payment_evidence WHERE purchase_id=%d ORDER BY id",$purchaseId))?:array();
    }
    public function insertEvidence(array $data):int{return $this->insert('commercial_payment_evidence',$data,'Payment evidence persistence failed');}

    public function factForEvidence(int $evidenceId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_payment_facts WHERE evidence_id=%d".($lock?' FOR UPDATE':''),$evidenceId);}
    public function factsForPurchase(int $purchaseId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_payment_facts WHERE purchase_id=%d ORDER BY id",$purchaseId))?:array();
    }
    public function insertFact(array $data):int{return $this->insert('commercial_payment_facts',$data,'Payment fact persistence failed');}

    public function settlementForObligation(int $obligationId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_obligation_settlements WHERE obligation_id=%d".($lock?' FOR UPDATE':''),$obligationId);
    }
    public function settlementsForOffer(int $offerId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT s.* FROM {$this->p}commercial_obligation_settlements s INNER JOIN {$this->p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d ORDER BY o.obligation_sequence{$suffix}",$offerId))?:array();
    }
    public function insertSettlement(array $data):int{return $this->insert('commercial_obligation_settlements',$data,'Obligation settlement persistence failed');}

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('uid','reference_code','provider_reference','evidence_id','obligation_id'),true)?$key:null;
    }

    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data,string $message):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return (int)$wpdb->insert_id;
    }
}
