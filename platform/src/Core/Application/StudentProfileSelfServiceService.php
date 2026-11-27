<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Student-owned self-service profile update.
 *
 * Only contact number, WhatsApp number, timezone and calendar presentation are mutable. Email,
 * legal name, enrolment authority and financial state remain owned by the Platform.
 */
final class StudentProfileSelfServiceService {
    public function update(array $input):array{
        $principal=(new PortalPrincipalResolver())->resolve('student');
        $studentId=(int)$principal['id'];
        $timezone=trim((string)($input['timezone']??''));
        if(!in_array($timezone,timezone_identifiers_list(),true))throw new \InvalidArgumentException('student_timezone_invalid');
        $calendar=strtolower(trim((string)($input['calendar_preference']??'auto')));
        if(!in_array($calendar,array('auto','gregorian','persian'),true))throw new \InvalidArgumentException('student_calendar_invalid');
        $phone=trim((string)($input['phone']??''));
        $whatsapp=trim((string)($input['whatsapp_phone']??''));
        // Accept a real dialable shape, not arbitrary text that later becomes a provider recipient.
        foreach(array($phone,$whatsapp) as $number){
            if($number!==''&&!preg_match('/^\+?[0-9][0-9 ()\-]{5,31}$/',$number))throw new \InvalidArgumentException('student_phone_invalid');
        }
        global $wpdb;
        $table=$wpdb->prefix.'dzn_students';
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('student_profile_update_failed');
        try{
        $changed=$wpdb->update($table,array(
            'phone'=>$phone,
            'whatsapp_phone'=>$whatsapp,
            'timezone'=>$timezone,
            'timezone_source'=>'student_selected',
            'calendar_preference'=>$calendar,
            'updated_at'=>gmdate('Y-m-d H:i:s'),
            'updated_by'=>get_current_user_id(),
        ),array('id'=>$studentId,'status'=>'active','archived_at'=>null));
        if($changed===false||$wpdb->last_error!=='')throw new \RuntimeException('student_profile_update_failed');
        $audit=$wpdb->insert($wpdb->prefix.'platform_audit_events',array(
            'aggregate_type'=>'student','aggregate_id'=>$studentId,'event_type'=>'student.profile_self_updated',
            'actor_type'=>'user','actor_id'=>get_current_user_id(),'reason_code'=>'self_service',
            'safe_detail'=>wp_json_encode(array('fields'=>array('phone','whatsapp_phone','timezone','calendar_preference'),'timezone_source'=>'student_selected')),
            'idempotency_key'=>hash('sha256','student-profile-self:'.$studentId.':'.get_current_user_id().':'.microtime(true)),
            'occurred_at'=>gmdate('Y-m-d H:i:s'),
        ));
        if($audit===false||$wpdb->query('COMMIT')===false)throw new \RuntimeException('student_profile_update_failed');
        return array('student_id'=>$studentId,'timezone'=>$timezone,'calendar_preference'=>$calendar);
        }catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
    }
}
