<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Caller-supplied raw provider references for exactly one command (contract §5.3).
 *
 * Memory-only: never stored, never logged, never returned. Each claim is proven against the mapping
 * registry row it must already own, handed to `sealDispatchDescriptor()`, and discarded with the
 * request that carried it.
 */
final class ProviderReferenceClaims {
    /** @param array<string,string> $objectReferences canonical kind => raw provider object reference */
    public function __construct(private string $providerAccountReference,private array $objectReferences=array()){}
    public function providerAccountReference():string{return $this->providerAccountReference;}
    public function objectReference(string $canonicalKind):?string{
        $value=$this->objectReferences[$canonicalKind]??null;
        return is_string($value)&&trim($value)!==''?$value:null;
    }
    /** @return array<string,string> */
    public function objectReferences():array{return $this->objectReferences;}
    /** Redacted summary for diagnostics and contract scans: no raw reference ever leaves this class. */
    public function summaries():array{
        $summary=array('account'=>trim($this->providerAccountReference)!=='');
        foreach($this->objectReferences as $kind=>$value)$summary[(string)$kind]=trim((string)$value)!=='';
        return $summary;
    }
}
