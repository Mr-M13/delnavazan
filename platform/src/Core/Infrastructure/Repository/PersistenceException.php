<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;
final class PersistenceException extends \RuntimeException { public function __construct(public readonly int $errno,public readonly string $dbError,public readonly string $operation){parent::__construct($operation.': '.$dbError,$errno);} public function uidCollision():bool{return $this->errno===1062&&str_contains($this->dbError,'uid');} }
