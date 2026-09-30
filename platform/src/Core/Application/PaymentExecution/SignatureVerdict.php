<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/** Immutable signature-verification verdict: `state` is a VERIFICATION_STATES member, `reason` a WEBHOOK_REASONS member. */
final class SignatureVerdict {
    public function __construct(private string $state,private string $reason,private string $keyVersion){}
    public static function verified(string $keyVersion):self{return new self('verified','signature_verified',$keyVersion);}
    public static function refused(string $reason,string $keyVersion=''):self{return new self('refused',$reason,$keyVersion);}
    public function state():string{return $this->state;}
    public function reason():string{return $this->reason;}
    public function keyVersion():string{return $this->keyVersion;}
    public function isVerified():bool{return $this->state==='verified';}
}
