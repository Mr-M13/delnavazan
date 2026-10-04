<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class CheckoutSessionRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function activeForObligation(int $obligationId,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}checkout_sessions WHERE obligation_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$obligationId));}
    /** PII- and provider-reference-free rows for guarded operator diagnostics. */
    public function activeForDiagnostics(int $limit=50):array{
        global $wpdb;$limit=max(1,min(100,$limit));
        return $wpdb->get_results($wpdb->prepare("SELECT uid,state,provider_key,created_at,expires_at FROM {$this->p}checkout_sessions WHERE active_slot=1 ORDER BY id DESC LIMIT %d",$limit))?:array();
    }
    public function byUid(string $uid):?object{return $this->row("SELECT * FROM {$this->p}checkout_sessions WHERE uid=%s",$uid);}
    /** Exact previously recorded Stripe identity; only its keyed digest is queried or returned. */
    public function byProviderReferenceDigest(string $providerKey,string $digest):array{
        if(preg_match('/^[a-f0-9]{64}$/D',$digest)!==1)throw new \InvalidArgumentException('Invalid provider reference digest');
        global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}checkout_sessions WHERE provider_key=%s AND provider_reference_digest=%s ORDER BY id",$providerKey,$digest))?:array();
    }
    public function byRequestDigest(string $digest,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}checkout_sessions WHERE request_key_digest=%s".($lock?' FOR UPDATE':''),$digest));}
    /** Last immutable session gives a deterministic next server-side request generation. */
    public function latestForObligation(int $obligationId,bool $lock=false):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}checkout_sessions WHERE obligation_id=%d ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''),$obligationId));}
    public function insert(array $data):int{global $wpdb;if($wpdb->insert($this->p.'checkout_sessions',$data)===false)throw new \RuntimeException('Checkout session persistence failed');return(int)$wpdb->insert_id;}
    /** Persist only the keyed digest of provider identity; reversible references live encrypted in the vault. */
    public function recordOpen(int $id,string $providerReferenceDigest,string $expiresAt):void{
        global $wpdb;
        if(preg_match('/^[a-f0-9]{64}$/D',$providerReferenceDigest)!==1)throw new \InvalidArgumentException('Invalid provider reference digest');
        $session=$wpdb->get_row($wpdb->prepare("SELECT state,provider_reference_digest FROM {$this->p}checkout_sessions WHERE id=%d AND active_slot=1 FOR UPDATE",$id));
        if(!$session||!in_array((string)$session->state,array('creating','open'),true))throw new \RuntimeException('Stale checkout session');
        if((string)$session->state==='open'){
            if(!hash_equals((string)$session->provider_reference_digest,$providerReferenceDigest))throw new \RuntimeException('Checkout provider identity conflict');
            return;
        }
        $changed=$wpdb->update($this->p.'checkout_sessions',array('state'=>'open','provider_reference_digest'=>$providerReferenceDigest,'expires_at'=>$expiresAt),array('id'=>$id,'state'=>'creating','active_slot'=>1));
        if($changed!==1)throw new \RuntimeException('Stale checkout session');
    }
    public function close(int $id,string $state,string $reason,string $at):void{
        global $wpdb;
        if(!in_array($state,array('completed','expired','failed'),true))throw new \InvalidArgumentException('Invalid checkout terminal state');
        $changed=$wpdb->update($this->p.'checkout_sessions',array('state'=>$state,'active_slot'=>null,'closed_at'=>$at,'close_reason'=>$reason),array('id'=>$id,'active_slot'=>1));
        if($changed!==1)throw new \RuntimeException('Stale checkout session');
    }
    private function row(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
}
