<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\Finance\FinanceRule;
use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;

/**
 * Shared persistence boundary for the Phase 2A.2-U aggregates.
 *
 * Append-only tables expose insert and named-index reads only; a mutable table exposes exactly the
 * conditional statements its contract declares and no method that can rewrite an identity, an amount, a
 * currency or an effective instant. Every write is a declared Finance table (or one of the two declared
 * infrastructure seams written through {@see FinanceSupport}) and every read is a named-index lookup.
 */
abstract class FinanceRepository {
    protected string $p;
    protected function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function begin():void{FinanceSupport::begin();}
    public function commit():void{FinanceSupport::commit();}
    public function rollback():void{FinanceSupport::rollback();}

    protected function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($args===array()?$sql:$wpdb->prepare($sql,...$args));}
    protected function many(string $sql,...$args):array{global $wpdb;$rows=$wpdb->get_results($args===array()?$sql:$wpdb->prepare($sql,...$args));return is_array($rows)?$rows:array();}
    protected function value(string $sql,...$args):mixed{global $wpdb;return $wpdb->get_var($args===array()?$sql:$wpdb->prepare($sql,...$args));}
    protected function insert(string $table,array $data,string $message):int{return FinanceSupport::insertRow($table,$data,$message);}
    protected function cas(string $table,array $values,array $where,string $message):int{return FinanceSupport::compareAndSwap($table,$values,$where,$message);}
    /** Assign the stable public handle of a row once, immediately after its first insert. */
    protected function assignReference(string $table,int $id,string $reference):void{
        $changed=$this->cas($table,array('reference_code'=>$reference),array('id'=>$id,'reference_code'=>null),'Finance reference assignment failed');
        if($changed!==1)throw new \RuntimeException('Finance reference assignment failed');
    }
    /** The declared table guard: a Finance repository may never touch an undeclared table. */
    protected function declared(string $table):string{
        if(!in_array($table,FinanceRule::TABLES,true))throw new \InvalidArgumentException('Declared Finance table required');
        return $this->p.$table;
    }
}
