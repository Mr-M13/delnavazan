<?php
namespace Delnavazan\Platform\Core\Infrastructure\ReadModel;

use Delnavazan\Platform\Core\Application\NotificationRecipientReadPort;
use Delnavazan\Platform\Core\Application\NotificationSupport;

/**
 * Student recipient projection. dzn_students remains contact source-of-truth; this adapter emits only
 * resolved facts/digests plus the short-lived authenticated envelope required by notification transport.
 */
final class StudentNotificationRecipientReadModel implements NotificationRecipientReadPort {
 public function recipient(string $audience,string $recipientKind,int $subjectAggregateId):?array{
  if($audience!=='student'||$recipientKind!=='student'||$subjectAggregateId<1)return null;
  global $wpdb;
  $table=$wpdb->prefix.'dzn_students';
  $row=$wpdb->get_row($wpdb->prepare("SELECT id,status,email,phone,whatsapp_phone,timezone,locale,wordpress_user_id,archived_at FROM {$table} WHERE id=%d LIMIT 1",$subjectAggregateId),ARRAY_A);
  if(!is_array($row)||$row['archived_at']!==null)return null;
  $email=trim((string)($row['email']??''));$phone=trim((string)($row['phone']??''));$wa=trim((string)($row['whatsapp_phone']??''));
  $contact=array(
   'route'=>$wa!==''||$phone!==''?'mobile':'email',
   'email'=>$email,'email_eligible'=>$email!==''&&is_email($email),
   'sms'=>$phone,'sms_eligible'=>$phone!=='',
   'whatsapp'=>$wa,'whatsapp_eligible'=>$wa!=='',
   'locale'=>(string)($row['locale']?:'fa-IR')
  );
  $resolvable=$contact['email_eligible']||$contact['sms_eligible']||$contact['whatsapp_eligible'];
  $envelope=$resolvable?NotificationSupport::encryptEnvelope($contact):null;
  $digestInput=strtolower($email).'|'.$phone.'|'.$wa;
  return array(
   'resolvable'=>$resolvable&&is_array($envelope),
   'opted_in'=>true,
   'guardian_authority_present'=>true,
   'recipient_digest'=>hash('sha256','student:'.$subjectAggregateId),
   'contact_digest'=>hash('sha256',$digestInput),
   'contact_envelope'=>$envelope['envelope']??null,
   'contact_cipher_version'=>$envelope['cipher_version']??null,
   'contact_expires_at'=>gmdate('Y-m-d H:i:s',time()+900),
   'timezone'=>(string)($row['timezone']?:''),
   'academy_timezone'=>(string)(function_exists('wp_timezone_string')?wp_timezone_string():'UTC'),
   'locale'=>(string)($row['locale']?:'fa-IR')
  );
 }
}
