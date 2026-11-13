<?php
/** URL contract for the embedded editor and filter-preserving mutations. */
function admin_url(string $path=''):string{return 'https://academy.example/wp-admin/'.$path;}
function sanitize_text_field(string $value):string{return trim(strip_tags($value));}
function sanitize_key(string $value):string{return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function wp_unslash(string $value):string{return stripslashes($value);}
require_once dirname(__DIR__).'/src/Admin/Controller/NotificationManagementController.php';

$class='Delnavazan\\Platform\\Admin\\Controller\\NotificationManagementController';
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$_GET=array('search'=>'lesson','category'=>'booking','recipient'=>'student','channel'=>'email','status'=>'partial');
$editor=new ReflectionMethod($class,'editorUrl');$editor->setAccessible(true);
$url=(string)$editor->invoke(null,'booking.confirmed.student','sms','fa-IR');
$assert(str_contains($url,'template_channel=sms'),'editor channel must use its own query parameter');
$assert(str_contains($url,'filter_channel=email'),'table channel filter must survive editor navigation');
$assert(str_contains($url,'filter_search=lesson')&&str_contains($url,'filter_category=booking')&&str_contains($url,'filter_recipient=student')&&str_contains($url,'filter_status=partial'),'all table filters must survive editor navigation');
$assert(!str_contains($url,'&channel=email'),'editor channel must not overwrite the table channel filter');

$_POST=array('filter_search'=>'lesson','filter_category'=>'booking','filter_recipient'=>'student','filter_channel'=>'email','filter_status'=>'partial');
$context=new ReflectionMethod($class,'postTableContext');$context->setAccessible(true);
$saved=$context->invoke(null);
$assert($saved===array('search'=>'lesson','category'=>'booking','recipient'=>'student','channel'=>'email','status'=>'partial'),'post mutation context must preserve every filter');
$source=(string)file_get_contents(dirname(__DIR__).'/src/Admin/Controller/NotificationManagementController.php');
$assert(str_contains($source,"'template_channel'=>$input['channel']"),'save redirect must keep template channel separate');
$assert(str_contains($source,"self::redirectBack('events',self::postTableContext())"),'channel toggle must redirect with table context');
echo "Notification management navigation contract passed\n";
