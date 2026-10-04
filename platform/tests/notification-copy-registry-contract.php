<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationCopyPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationSupport.php';
require_once dirname(__DIR__).'/src/Integrations/Notifications/ConfiguredNotificationCopy.php';
use Delnavazan\Platform\Integrations\Notifications\ConfiguredNotificationCopy;
function wp_salt($scheme='auth'){return 'test-salt';}
$versionResolver=static fn(int $id)=>(object)array('subject_template_digest'=>hash_hmac('sha256','template_copy:','test-salt'),'body_template_digest'=>hash_hmac('sha256','template_copy:سلام {{name}}','test-salt'));
$r=new ConfiguredNotificationCopy(array('7:whatsapp:fa-IR'=>array('body'=>'سلام {{name}}')),$versionResolver);
$x=$r->render(7,'whatsapp','fa-IR',array('name'=>'ندا'));
if(($x['body']??'')!=='سلام ندا')throw new RuntimeException('Configured copy must render frozen parameters');
if($r->render(7,'sms','fa-IR',array('name'=>'ندا'))!==null)throw new RuntimeException('Missing channel copy must fail closed');
$failed=false;try{$r->render(7,'whatsapp','fa-IR',array());}catch(RuntimeException $e){$failed=$e->getMessage()==='template_variable_mismatch';}
if(!$failed)throw new RuntimeException('Missing frozen variable must fail closed');
echo "Notification copy registry contract passed\n";
