<?php
/** Synthetic, local WP-CLI-only student account claim acceptance. Never deployed. */
defined('ABSPATH') || exit(1);
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Core\Application\StudentAccountClaimInvitationService;
use Delnavazan\Platform\Core\Application\StudentAccountClaimValidationService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
$marker=(string)getenv('DZN_STUDENT_CLAIM_RUNTIME_RUN_ID');
if (!defined('WP_CLI') || !WP_CLI || getenv('DZN_STUDENT_CLAIM_RUNTIME_TEST')!=='isolated' || !in_array(wp_get_environment_type(),array('local','development'),true) || !preg_match('/^dznstudent[0-9]{10,}$/',$marker) || get_option('dzn_student_claim_runtime_marker')!==$marker || !current_user_can('dzn_issue_student_account_claims')) throw new RuntimeException('Student claim fixture refused');
function dzn_student_runtime_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_student_runtime_reject(callable $callback,string $label):void{try{$callback();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($label);}
global $wpdb;$p=$wpdb->prefix.'dzn_';$admin=get_current_user_id();$at=gmdate('Y-m-d H:i:s');$suffix=substr(hash('sha256',wp_generate_uuid4()),0,16);
$user=wp_insert_user(array('user_login'=>'dznstudent'.$suffix,'user_pass'=>wp_generate_password(36,true,true),'user_email'=>'student-'.$suffix.'@example.invalid','role'=>'subscriber'));
dzn_student_runtime_assert(!is_wp_error($user)&&$user>0,'Synthetic user creation failed');
$student=0;
try{
 dzn_student_runtime_assert($wpdb->insert($p.'students',array('uid'=>Identifier::uid(),'status'=>'active','display_name'=>'Synthetic Student '.$suffix,'email'=>'student-'.$suffix.'@example.invalid','timezone'=>'Australia/Brisbane','created_at'=>$at,'updated_at'=>$at,'created_by'=>$admin,'updated_by'=>$admin))===1,'Synthetic student creation failed');
 $student=(int)$wpdb->insert_id;
 $service=new StudentAccountClaimInvitationService();
 // Claim notification authorities are deliberately absent: issuance must fail closed and roll back.
 dzn_student_runtime_reject(static fn()=> (new StudentAccountClaimValidationService())->validateForAcceptance('not-a-token',(int)$user),'Malformed token accepted');
 wp_set_current_user((int)$user);
 dzn_student_runtime_reject(static fn()=> (new StudentAccountClaimValidationService())->validateForAcceptance(str_repeat('a',64),(int)$user),'Unknown token accepted');
 dzn_student_runtime_reject(static fn()=> (new StudentAcceptanceAuthorityService())->establishPrincipalFromAccountClaim(str_repeat('a',64),(int)$user),'Unknown token established principal');
 wp_set_current_user($admin);
 dzn_student_runtime_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE student_id=%d",$student))===0,'Invalid claims must not establish principal');
 echo "Student claim malformed/unknown token fail-closed runtime: PASS\n";
}finally{wp_set_current_user($admin);if($student>0)$wpdb->delete($p.'students',array('id'=>$student));require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user((int)$user);delete_option('dzn_student_claim_runtime_marker');}
