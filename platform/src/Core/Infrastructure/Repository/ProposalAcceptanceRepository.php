<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Append-only provisional acceptance evidence persistence. */
final class ProposalAcceptanceRepository {
    private string $prefix;
    public function __construct(){global $wpdb;$this->prefix=$wpdb->prefix.'dzn_';}
    public function eventForCommandForUpdate(string $digest):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}proposal_acceptance_events WHERE command_key_digest=%s FOR UPDATE",$digest));}
    public function eventForCommand(string $digest):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}proposal_acceptance_events WHERE command_key_digest=%s",$digest));}
    public function insert(array $data):int{global $wpdb;if($wpdb->insert($this->prefix.'proposal_acceptance_events',$data)===false)throw new \RuntimeException('Provisional acceptance persistence failed: '.$wpdb->last_error);return(int)$wpdb->insert_id;}
    public function assignReference(int $id,string $reference):void{global $wpdb;if($wpdb->update($this->prefix.'proposal_acceptance_events',array('reference_code'=>$reference),array('id'=>$id,'reference_code'=>null))!==1)throw new \RuntimeException('Provisional acceptance reference assignment failed');}
    public function isDuplicate(\Throwable $e):bool{global $wpdb;return str_contains(strtolower($e->getMessage().' '.$wpdb->last_error),'duplicate');}
}
