<?php
/** Disposable WordPress/MariaDB proof for transactional instrument setup. */
if(getenv('DZN_INSTRUMENT_SETUP_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Instrument setup runtime refused.\n");exit(1);}

use Delnavazan\Platform\Core\Application\{CatalogueService,CommercialIdempotency,CommercialRule};
use Delnavazan\Platform\Core\Support\Identifier;

function dzn_instrument_setup_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function dzn_instrument_setup_fails(callable $operation,string $message):void{try{$operation();}catch(Throwable){return;}throw new RuntimeException($message);}

global $wpdb;
$actor=(int)get_current_user_id();dzn_instrument_setup_assert($actor>0,'Controlled administrator required');
$p=$wpdb->prefix.'dzn_';$suffix=substr(hash('sha256',wp_generate_uuid4()),0,12);$name='DZN Instrument Setup '.$suffix;$slug=sanitize_title($name);$catalogue=new CatalogueService();

// Seed only an idempotency conflict. The setup transaction must roll back its courses/product/default.
$instrumentId=$catalogue->instrument(array('slug'=>$slug,'name_fa'=>$name,'name_en'=>$name,'status'=>'active'));
$key='instrument-setup:'.$instrumentId.':standard-product';
dzn_instrument_setup_assert($wpdb->insert($p.'commercial_commands',array('uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'create_product','command_key_digest'=>CommercialIdempotency::key($key),'command_payload_digest'=>str_repeat('0',64),'result_state'=>'active','result_id'=>null,'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>$actor))===1,'Conflict command fixture failed');
dzn_instrument_setup_fails(static fn()=>$catalogue->setupInstrument(array('name'=>$name,'standard_duration_minutes'=>45,'introductory_lesson_billing'=>'free','gap_between_lessons_minutes'=>20)),'Product conflict must fail setup');
dzn_instrument_setup_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}courses WHERE instrument_id=%d",$instrumentId))===0,'Failed setup left a Course');
dzn_instrument_setup_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}instrument_intro_course_defaults WHERE instrument_id=%d",$instrumentId))===0,'Failed setup left an introductory default');
dzn_instrument_setup_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_products p INNER JOIN {$p}courses c ON c.id=p.course_id WHERE c.instrument_id=%d",$instrumentId))===0,'Failed setup left a product');
dzn_instrument_setup_assert($wpdb->delete($p.'commercial_commands',array('command_key_digest'=>CommercialIdempotency::key($key)))===1,'Conflict command cleanup failed');

$input=array('name'=>$name,'standard_duration_minutes'=>45,'introductory_lesson_billing'=>'free','gap_between_lessons_minutes'=>20);
$first=$catalogue->setupInstrument($input);$second=$catalogue->setupInstrument($input);
dzn_instrument_setup_assert($first===$second,'Repeated setup did not converge on the same records');
$courses=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}courses WHERE instrument_id=%d AND status='active' AND archived_at IS NULL ORDER BY course_type,id",$instrumentId));
dzn_instrument_setup_assert(count($courses)===2&&(string)$courses[0]->course_type==='introductory'&&(string)$courses[1]->course_type==='standard','Setup must create exactly one active introductory and standard Course');
foreach($courses as $course)dzn_instrument_setup_assert((int)$course->default_duration_minutes===45&&(int)$course->default_buffer_minutes===20,'Introductory and standard Course timing must match setup input');
dzn_instrument_setup_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_products WHERE course_id=%d AND status='active' AND archived_at IS NULL",$first['standard_course_id']))===1,'Setup must create exactly one active standard product');
dzn_instrument_setup_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}instrument_intro_course_defaults WHERE instrument_id=%d AND course_id=%d AND status='active'",$instrumentId,$first['introductory_course_id']))===1,'Setup must create exactly one active introductory default');
dzn_instrument_setup_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_prices WHERE product_id=%d",$first['product_id']))===0,'Setup must not invent regional prices');

$catalogue->course(array('instrument_id'=>$instrumentId,'name_fa'=>$name,'name_en'=>$name,'course_type'=>'standard','status'=>'active','default_duration_minutes'=>45,'default_buffer_minutes'=>20));
dzn_instrument_setup_fails(static fn()=>$catalogue->setupInstrument($input),'Ambiguous standard Courses must fail closed');
dzn_instrument_setup_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_products WHERE course_id=%d AND status='active' AND archived_at IS NULL",$first['standard_course_id']))===1,'Conflict retry must not duplicate the product');
echo "Instrument setup runtime passed.\n";
