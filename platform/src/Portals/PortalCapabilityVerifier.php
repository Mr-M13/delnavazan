<?php
namespace Delnavazan\Platform\Portals;

final class PortalCapabilityVerifier {
    public function verify(string $handle,string $token,string $purpose,array $context=array()):array {
        $row=(new PortalCapabilityService())->verify($handle,$token,$purpose);
        if(isset($context['lesson_id'])&&(int)$row->lesson_id!==(int)$context['lesson_id'])throw new \InvalidArgumentException('portal_capability_binding_mismatch');
        return (array)$row;
    }
}
