<?php
namespace Delnavazan\Platform\Core\Infrastructure\ReadModel;

use Delnavazan\Platform\Core\Application\NotificationRecipientReadPort;
use Delnavazan\Platform\Core\Application\NotificationSupport;

/**
 * Student contact projection. dzn_students remains contact source-of-truth.
 *
 * Consent, guardian authority and route are deliberately supplied by external read-only authority filters.
 * Until those authorities are configured, eligibility fails closed and no notification can be handed off.
 */
final class StudentNotificationRecipientReadModel implements NotificationRecipientReadPort {
 public function recipient(string $audience,string $recipientKind,int $subjectAggregateId):?array{
  if($audience!=='student'||$recipientKind!=='student'||$subjectAggregateId<1)return null;
  global $wpdb;
  $table=$wpdb->prefix.'dzn_students';
  $row=$wpdb->get_row($wpdb->prepare("SELECT id,status,email,phone,whatsapp_phone,timezone,locale,wordpress_user_id,archived_at FROM {$table} WHERE id=%d LIMIT 1",$subjectAggregateId),ARRAY_A);
  if(!is_array($row)||$row['archived_at']!==null)return null;

  $email=trim((string)($row['email']??''));$phone=trim((string)($row['phone']??''));$wa=trim((string)($row['whatsapp_phone']??''));
  $emailEligible=$email!==''&&is_email($email);$smsEligible=$phone!=='';$waEligible=$wa!=='';
  $route=apply_filters('dzn_notification_student_route',null,$subjectAggregateId,$row);
  if(!in_array($route,array('email','mobile'),true))$route=null;

  $consent=apply_filters('dzn_notification_student_opted_in',null,$subjectAggregateId,$row);
  $authority=apply_filters('dzn_notification_student_guardian_authority',null,$subjectAggregateId,$row);
  $optedIn=$consent===true;
  $guardianAuthority=$authority===true;

  $contact=array(
   'route'=>$route,
   'email'=>$email,'email_eligible'=>$emailEligible,
   'sms'=>$phone,'sms_eligible'=>$smsEligible,
   'whatsapp'=>$wa,'whatsapp_eligible'=>$waEligible,
   'locale'=>(string)($row['locale']?:'fa-IR')
  );
  $routeResolvable=($route==='email'&&$emailEligible)||($route==='mobile'&&($waEligible||$smsEligible));
  $envelope=$routeResolvable?NotificationSupport::encryptEnvelope($contact):null;
  $digestInput=strtolower($email).'|'.$phone.'|'.$wa;
  return array(
   'resolvable'=>$routeResolvable&&is_array($envelope),
   'opted_in'=>$optedIn,
   'guardian_authority_present'=>$guardianAuthority,
   'recipient_digest'=>NotificationSupport::digest('student:'.$subjectAggregateId),
   'contact_digest'=>NotificationSupport::digest('student_contact:'.$digestInput),
   'contact_envelope'=>$envelope['envelope']??null,
   'contact_cipher_version'=>$envelope['cipher_version']??null,
   'contact_expires_at'=>NotificationSupport::expiresIn(900),
   'timezone'=>(string)($row['timezone']?:''),
   'academy_timezone'=>(string)(function_exists('wp_timezone_string')?wp_timezone_string():'UTC'),
   'locale'=>(string)($row['locale']?:'fa-IR')
  );
 }
}
