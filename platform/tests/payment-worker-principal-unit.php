<?php
require dirname(__DIR__).'/src/Core/Application/PaymentExecution/PaymentExecutionSupport.php';
use Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionSupport as Support;
function get_option($key){return $GLOBALS['principal'];}
function get_user_by($field,$id){return $GLOBALS['worker'];}
$principal=11;
$valid=(object)['user_status'=>0,'roles'=>[Support::WORKER_ROLE],'caps'=>[Support::WORKER_ROLE=>true],'allcaps'=>array_fill_keys(Support::WORKER_ALLOWED_CAPABILITIES,true)];
$worker=clone $valid;
if(Support::workerPrincipalId()!==11)throw new RuntimeException('Valid bounded worker refused');
$cases=1;
foreach(['unset','missing','inactive','combined_role','wrong_role','direct_grant','missing_cap','admin','extra_cap'] as $case){
    $principal=11;$worker=clone $valid;
    switch($case){
        case 'unset':$principal=0;break;
        case 'missing':$worker=null;break;
        case 'inactive':$worker->user_status=1;break;
        case 'combined_role':$worker->roles[]='subscriber';break;
        case 'wrong_role':$worker->roles=['administrator'];break;
        case 'direct_grant':$worker->caps['read']=true;break;
        case 'missing_cap':unset($worker->allcaps[Support::WORKER_CAPABILITIES[0]]);break;
        case 'admin':$worker->allcaps['manage_options']=true;break;
        case 'extra_cap':$worker->allcaps['edit_posts']=true;break;
    }
    if(Support::workerPrincipalId()!==0)throw new RuntimeException('Unsafe worker accepted: '.$case);
    $cases++;
}
echo "Payment worker principal: $cases scenarios passed\n";
