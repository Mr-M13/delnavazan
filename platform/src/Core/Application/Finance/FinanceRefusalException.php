<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * A declared business refusal of a Finance command (§15.8).
 *
 * Thrown from inside the command's own transaction, caught at the command boundary, and committed as
 * exactly its refusal evidence — the refused command row, the matching `finance_exceptions` row and
 * their digest-only audit rows — while the attempted mutation rolls back. The message is always the
 * exact §5.2.1 reason code; no other exception class is ever treated as a refusal.
 */
final class FinanceRefusalException extends \RuntimeException {
    public function __construct(
        private string $reasonCode,
        private string $refusalSummary,
        private array $refusalScope=array(),
        ?\Throwable $previous=null
    ){
        parent::__construct($reasonCode,0,$previous);
    }
    public function reasonCode():string{return $this->reasonCode;}
    public function summary():string{return $this->refusalSummary;}
    public function scope():array{return $this->refusalScope;}
}
