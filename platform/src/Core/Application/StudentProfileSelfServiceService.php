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
        if(strlen($phone)>32||strlen($whatsapp)>32)throw new \InvalidArgumentException('student_phone_invalid');
        global $wpdb;
        $table=$wpdb->prefix.'dzn_students';
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
        return array('student_id'=>$studentId,'timezone'=>$timezone,'calendar_preference'=>$calendar);
    }
}
