<?php
namespace Delnavazan\Platform\Core\Application;
final class NotificationTransportRouter {
    public function __construct(private array $ports){}
    public function route(string $route,array $contact):array{
        if($route==='email')return $this->single('email',$contact);
        if($route!=='mobile')throw new \InvalidArgumentException('notification_route_invalid');
        $wa=$this->port('whatsapp');
        if($wa&&$wa->configured()&&!empty($contact['whatsapp_eligible']))return array($wa);
        $sms=$this->port('sms');
        if($sms&&$sms->configured()&&!empty($contact['sms_eligible']))return array($sms);
        return array();
    }
    public function fallbackAfter(string $channel,string $outcome,array $contact):array{
        if($channel!=='whatsapp'||!in_array($outcome,array('unreachable','refused'),true))return array();
        $sms=$this->port('sms');
        return $sms&&$sms->configured()&&!empty($contact['sms_eligible'])?array($sms):array();
    }
    private function single(string $channel,array $contact):array{$p=$this->port($channel);return $p&&$p->configured()&&!empty($contact[$channel.'_eligible'])?array($p):array();}
    private function port(string $channel):?NotificationTransportPort{foreach($this->ports as$p)if($p instanceof NotificationTransportPort&&$p->channel()===$channel)return $p;return null;}
}
