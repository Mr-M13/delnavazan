<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationActionLinkPolicy.php';
use Delnavazan\Platform\Core\Application\NotificationActionLinkPolicy;
$staging=NotificationActionLinkPolicy::teacherInvitation('staging','https://staging.example.test/');
if($staging!=='https://staging.example.test/teacher-invitation/')throw new RuntimeException('Staging link must use the staging site URL');
$production=NotificationActionLinkPolicy::teacherInvitation('production','https://academy.example.com/');
if($production!=='https://academy.example.com/teacher-invitation/')throw new RuntimeException('Production link must use the canonical WordPress site URL by default');
foreach(array(array('production','https://staging.example.test/'),array('production','http://academy.example.test/'),array('production','https:///missing-host/'),array('development','https://academy.example.test/')) as $case){$failed=false;try{NotificationActionLinkPolicy::teacherInvitation(...$case);}catch(RuntimeException){$failed=true;}if(!$failed)throw new RuntimeException('Unsafe or unsupported environment URL must fail closed');}
echo "Notification action-link environment isolation contract passed\n";
