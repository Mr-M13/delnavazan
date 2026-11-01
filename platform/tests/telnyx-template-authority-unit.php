<?php
$root=dirname(__DIR__);
$GLOBALS['wpdb']=(object)array('prefix'=>'wp_');
require_once $root.'/src/Core/Infrastructure/Repository/NotificationProviderRepository.php';
require_once $root.'/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root.'/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
require_once $root.'/src/Integrations/Notifications/TelnyxTemplateAuthority.php';

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateAuthority;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry;

final class DznTemplateAuthorityRepository extends NotificationProviderRepository {
    /** @param object[] $rows */
    public function __construct(private array $rows){}
    public function templatesForEvent(string $provider,string $eventKey,string $language):array{
        return array_values(array_filter($this->rows,static fn(object $row):bool=>(string)$row->provider===$provider&&(string)$row->event_key===$eventKey&&(string)$row->language===$language));
    }
    public function templateByName(string $provider,string $name,string $language):?object{
        foreach($this->rows as $row)if((string)$row->provider===$provider&&(string)$row->template_name===$name&&(string)$row->language===$language)return $row;
        return null;
    }
}

$row=static function(string $name,array $definition,string $status='APPROVED',?string $order=null):object{
    return (object)array(
        'provider'=>'telnyx','template_name'=>$name,'language'=>'fa','status'=>$status,
        'event_key'=>$definition['event_key'],'audience'=>$definition['audience'],
        'variable_order'=>$order??implode(',',$definition['variable_order']),'last_verified_at'=>'2026-11-01 00:00:00',
    );
};
$all=array();
foreach(TelnyxTemplateRegistry::definitions() as $name=>$definition)$all[]=$row($name,$definition);
$authority=new TelnyxTemplateAuthority(new DznTemplateAuthorityRepository($all));
foreach(TelnyxTemplateRegistry::definitions() as $name=>$definition){
    $resolved=$authority->resolve($definition['event_key'],'fa-IR');
    if($resolved['state']!==TelnyxTemplateAuthority::MAPPED||$resolved['name']!==$name||$resolved['variable_order']!==$definition['variable_order'])throw new RuntimeException('approved registry mapping did not resolve: '.$name);
}

$definition=TelnyxTemplateRegistry::definitions()['delnavazan_teacher_new_booking_fa'];
$pending=new TelnyxTemplateAuthority(new DznTemplateAuthorityRepository(array($row('delnavazan_teacher_new_booking_fa',$definition,'PENDING'))));
if($pending->resolve($definition['event_key'],'fa-IR')['state']!==TelnyxTemplateAuthority::NOT_APPROVED)throw new RuntimeException('pending provider mapping must fail closed');
$ambiguous=new TelnyxTemplateAuthority(new DznTemplateAuthorityRepository(array($row('delnavazan_teacher_new_booking_fa',$definition),$row('delnavazan_teacher_new_booking_fa',$definition))));
if($ambiguous->resolve($definition['event_key'],'fa-IR')['state']!==TelnyxTemplateAuthority::AMBIGUOUS)throw new RuntimeException('ambiguous approved mappings must fail closed');
$mismatch=new TelnyxTemplateAuthority(new DznTemplateAuthorityRepository(array($row('delnavazan_teacher_new_booking_fa',$definition,'APPROVED','teacher,student,course,when'))));
if($mismatch->resolve($definition['event_key'],'fa-IR')['state']!==TelnyxTemplateAuthority::CONTRACT_MISMATCH)throw new RuntimeException('variable-order mismatch must fail closed');
$cleared=$row('delnavazan_teacher_new_booking_fa',$definition);$cleared->event_key=null;$cleared->variable_order='';
$clearedAuthority=new TelnyxTemplateAuthority(new DznTemplateAuthorityRepository(array($cleared)));
if($clearedAuthority->resolve($definition['event_key'],'fa-IR')['state']!==TelnyxTemplateAuthority::CONTRACT_MISMATCH)throw new RuntimeException('discovery-cleared event mapping must not fall back to a manual option');
$missing=new TelnyxTemplateAuthority(new DznTemplateAuthorityRepository(array()));
if($missing->resolve($definition['event_key'],'fa-IR')['state']!==TelnyxTemplateAuthority::NOT_MAPPED)throw new RuntimeException('missing provider mapping must remain not mapped');
echo "Telnyx template authority unit passed\n";
