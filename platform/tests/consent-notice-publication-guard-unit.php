<?php
/** Pure guard for the proposed consent-notice publication set. */
$root=dirname(__DIR__);
require_once $root.'/src/Core/Application/ConsentPurposeRegistry.php';
require_once $root.'/src/Core/Application/RecordingDecisionAuthorityService.php';
require_once $root.'/src/Core/Application/RecordingNoticePublicationService.php';
require_once $root.'/src/Core/Application/ConsentNoticeDraftValidator.php';
use Delnavazan\Platform\Core\Application\ConsentNoticeDraftValidator as V;
$fail=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$good=array('audience'=>'student_lesson_recording','language'=>'en','body'=>str_repeat('Approved consent text. ',5));
$fail(V::validate($good)===array(),'approved notice must validate');
foreach(array(
    array_merge($good,array('audience'=>'unapproved')),array_merge($good,array('language'=>'fr')),
    array_merge($good,array('body'=>'Short')),array_merge($good,array('body'=>$good['body'].' [[RETENTION_PERIOD]]')),
    array_merge($good,array('body'=>$good['body'].' DRAFT')),array_merge($good,array('body'=>$good['body'].' <script>x</script>')),
)as$bad)$fail(V::validate($bad)!==array(),'invalid notice must be refused');
$draft=json_decode((string)file_get_contents($root.'/content/consent-notices/2026-11-04-draft.json'),true);
$fail(is_array($draft)&&count($draft['notices'])===12,'draft file must contain twelve bilingual notices');
$set=V::validateSet($draft['notices']);
$fail($set['valid']===false,'the unapproved draft set must be refused until placeholders are resolved');
$badIndexes=array_keys($set['errors']);$fail(count($badIndexes)===12,'every draft notice must be reported as unpublished');
$fail(!in_array('audience_invalid',$set['errors'][0],true),'draft audiences must be supported');
echo "Consent notice publication guard: PASS\n";
