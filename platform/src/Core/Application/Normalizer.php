<?php
namespace Delnavazan\Platform\Core\Application;
final class Normalizer {
 public static function text(mixed $v,int $max=191,bool $required=false):?string{$v=is_string($v)?sanitize_text_field($v):'';$v=trim($v);if($required&&!$v)throw new \InvalidArgumentException('Required text missing');if(strlen($v)>$max)throw new \InvalidArgumentException('Text too long');return $v?:null;}
 public static function email(mixed $v):?string{$v=trim(sanitize_email((string)$v));if($v&&!is_email($v))throw new \InvalidArgumentException('Invalid email');return $v?:null;}
 public static function phone(mixed $v):?string{$v=preg_replace('/[^0-9+() .-]/','',(string)$v);return $v?substr($v,0,32):null;}
 public static function id(mixed $v,bool $required=true):?int{$v=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(false===$v&&$required)throw new \InvalidArgumentException('Positive ID required');return false===$v?null:(int)$v;}
 public static function count(mixed $v,int $min=0,?int $max=null):int{$v=filter_var($v,FILTER_VALIDATE_INT);if(false===$v||$v<$min||($max!==null&&$v>$max))throw new \InvalidArgumentException('Invalid count');return (int)$v;}
 public static function timezone(mixed $v):?string{$v=self::text($v,64);if($v&&!in_array($v,timezone_identifiers_list(),true))throw new \InvalidArgumentException('Invalid IANA timezone');return $v;}
 public static function country(mixed $v):?string{$v=strtoupper((string)$v);if($v&&!preg_match('/^[A-Z]{2}$/',$v))throw new \InvalidArgumentException('Invalid country');return $v?:null;}
 public static function one(mixed $v,array $allowed,string $label):string{$v=(string)$v;if(!in_array($v,$allowed,true))throw new \InvalidArgumentException('Invalid '.$label);return $v;}
 public static function timezoneSource(mixed $v):?string{if($v===null||$v==='')return null;return self::one($v,['student_selected','admin_selected','imported','system_suggested'],'timezone source');}
 public static function locale(mixed $v):?string{$v=self::text($v,16);if($v&&!preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/',$v))throw new \InvalidArgumentException('Invalid locale');return $v;}
 public static function time(mixed $v):?string{$v=(string)$v;if($v&&!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/',$v))throw new \InvalidArgumentException('Invalid local time');return $v?:null;}
}
