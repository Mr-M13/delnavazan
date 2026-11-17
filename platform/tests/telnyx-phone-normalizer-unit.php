<?php
require_once dirname(__DIR__).'/src/Integrations/Notifications/PhoneNumberNormalizer.php';
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
$cases=array(
    '+989121234567'=>'+989121234567',
    '00989121234567'=>'+989121234567',
    '09121234567'=>'+989121234567',
    '9121234567'=>'+989121234567',
    '989121234567'=>'+989121234567',
    '+1 (555) 000-1111'=>'+15550001111',
    '15550001111'=>'+15550001111',
);
foreach($cases as $input=>$expected)if(PhoneNumberNormalizer::toE164((string)$input)!==$expected)throw new RuntimeException('phone normalisation failed: '.$input.' -> '.PhoneNumberNormalizer::toE164((string)$input));
foreach(array('','abc','123','+0123456789','000') as $invalid)if(PhoneNumberNormalizer::toE164($invalid)!=='')throw new RuntimeException('invalid phone was accepted: '.$invalid);
$pairs=array(
    array(array('09121234567',''),array('phone'=>'09121234567','whatsapp'=>'09121234567'),'phone fills WhatsApp'),
    array(array('','09121234567'),array('phone'=>'09121234567','whatsapp'=>'09121234567'),'WhatsApp fills phone'),
    array(array('09121234567','+15550001111'),array('phone'=>'09121234567','whatsapp'=>'+15550001111'),'different numbers stay as found'),
    array(array('',''),array('phone'=>'','whatsapp'=>''),'both empty stay empty'),
);
foreach($pairs as [$input,$expected,$label]){
    if(PhoneNumberNormalizer::pair($input[0],$input[1])!==$expected)throw new RuntimeException('phone pair rule failed: '.$label);
}
echo "Telnyx phone normalizer unit passed\n";
