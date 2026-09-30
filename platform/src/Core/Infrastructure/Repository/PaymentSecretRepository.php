<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Phase 2A.2-T provider-secret vault (contract §11, §12).
 *
 * The repository stores only authenticated ciphertext plus its nonce and versions; it exposes no
 * plaintext accessor, no update path for a sealed value, and only one active row per declared scope.
 */
final class PaymentSecretRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function activeSecret(string $providerKey,string $secretClass,int $accountId,string $mode,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}payment_provider_secrets WHERE provider_key=%s AND secret_class=%s AND payment_provider_account_id=%d AND mode=%s AND active_slot=1".($lock?' FOR UPDATE':''),$providerKey,$secretClass,$accountId,$mode);
    }
    public function secret(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}payment_provider_secrets WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function secrets(string $providerKey,string $secretClass,int $accountId,string $mode):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_secrets WHERE provider_key=%s AND secret_class=%s AND payment_provider_account_id=%d AND mode=%s ORDER BY secret_version ASC",$providerKey,$secretClass,$accountId,$mode));
    }
    public function maxSecretVersion(string $providerKey,string $secretClass,int $accountId,string $mode):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(secret_version),0) FROM {$this->p}payment_provider_secrets WHERE provider_key=%s AND secret_class=%s AND payment_provider_account_id=%d AND mode=%s",$providerKey,$secretClass,$accountId,$mode));
    }
    public function insertSecret(array $data):int{return $this->insert('payment_provider_secrets',$data);}
    public function updateSecret(int $id,array $changes,array $where):int{return $this->update('payment_provider_secrets',$changes,array_merge(array('id'=>$id),$where));}

    public function insertAuditEvent(array $data):int{return $this->insert('payment_provider_secret_events',$data);}
    public function auditEvents(string $providerKey,string $secretClass):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}payment_provider_secret_events WHERE provider_key=%s AND secret_class=%s ORDER BY id ASC",$providerKey,$secretClass));
    }
    public function refusedWrites():int{
        global $wpdb;$p=$this->p;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secret_events WHERE audit_type='write_refused'");
    }
    public function decryptFailures():int{
        global $wpdb;$p=$this->p;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secret_events WHERE audit_type='decrypt_failed'");
    }

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('uid','secret_slot'),true)?$key:null;
    }

    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new PersistenceException((int)$wpdb->last_errno,(string)$error,'insert');
        return (int)$wpdb->insert_id;
    }
    private function update(string $table,array $changes,array $where):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $result=$wpdb->update($this->p.$table,$changes,$where);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($result===false)throw new \RuntimeException('Payment secret persistence failed: '.$error);
        return (int)$result;
    }
}
