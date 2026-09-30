<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * The durable provider dispatch descriptor: an adapter-sealed, adapter-opened authenticated ciphertext
 * envelope (contract §5.3, §11.6).
 *
 * It is the only carrier of a raw provider reference anywhere in Phase T. It is written once with the
 * dispatch claim inside transaction 1, it is never updated afterwards, and it is never readable by
 * Core, a read model, an admin screen, a REST response, the outbox, a log, an exception message or an
 * export. Core transports it as an opaque handle and never opens it.
 */
final class ProviderDispatchDescriptor {
    public function __construct(
        private string $cipherVersion,
        private string $keyVersion,
        private string $nonce,
        private string $ciphertext,
        private string $digest
    ){}
    public function cipherVersion():string{return $this->cipherVersion;}
    public function keyVersion():string{return $this->keyVersion;}
    public function nonce():string{return $this->nonce;}
    public function ciphertext():string{return $this->ciphertext;}
    public function digest():string{return $this->digest;}
    /** A complete sealed envelope: no member may be empty (§12.4). */
    public function complete():bool{
        return trim($this->cipherVersion)!==''&&trim($this->keyVersion)!==''
            &&trim($this->nonce)!==''&&trim($this->ciphertext)!==''&&trim($this->digest)!=='';
    }
}
