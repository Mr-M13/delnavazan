<?php
namespace Delnavazan\Platform\Admin\Controller;
final class AdminWorkspaceController {
 public static function register():void{add_action('admin_menu',[self::class,'menu'],99);add_action('admin_menu',[self::class,'simplifySidebar'],999);}
 public static function menu():void{}
 public static function simplifySidebar():void{
  // Keep authority screens registered and directly reachable, but remove implementation-level
  // entries from the everyday sidebar. Workspace remains the human navigation layer.
  global $submenu;if(empty($submenu['dzn-platform']))return;
  $keep=['dzn-academy-operations','dzn-staff-access','dzn-platform','dzn-system-health'];
  $submenu['dzn-platform']=array_values(array_filter($submenu['dzn-platform'],static fn($item)=>isset($item[2])&&in_array((string)$item[2],$keep,true)));
 }
 private static function hasAccess():bool{
  if(current_user_can('manage_options'))return true;
  foreach(['dzn_view_diagnostics','dzn_manage_students','dzn_view_student_acceptance_eligibility','dzn_manage_enrolments','dzn_manage_teachers','dzn_manage_onboarding','dzn_manage_teaching_eligibility','dzn_manage_teacher_availability','dzn_view_booking_requests','dzn_manage_booking_request_coordination','dzn_manage_terms','dzn_manage_lessons','dzn_manage_canonical_attendance_review','dzn_manage_courses','dzn_manage_commercial_catalogue','dzn_view_notification_authority','dzn_view_finance_authority','dzn_view_payment_execution_authority','dzn_manage_exceptions'] as $cap)if(current_user_can($cap))return true;
  return false;
 }
 public static function screen():void{
  if(!self::hasAccess())wp_die('Forbidden',403);
  $groups=[
   'Students'=>[['dzn-student','Students','dzn_manage_students'],['dzn-student-identity-authority','Account & acceptance','dzn_view_student_acceptance_eligibility'],['dzn-enrolment','Enrolments','dzn_manage_enrolments']],
   'Teachers'=>[['dzn-teacher','Teachers','dzn_manage_teachers'],['dzn-onboarding','Onboarding & invitations','dzn_manage_onboarding'],['dzn-teaching-eligibility','Teaching eligibility','dzn_manage_teaching_eligibility'],['dzn-teacher-availability','Availability','dzn_manage_teacher_availability'],['dzn-academy-coverage','Golden-hour coverage','dzn_view_booking_requests']],
   'Bookings & teaching'=>[['dzn-academy-operations','Academy pipeline','dzn_view_booking_requests'],['dzn-booking-requests','Booking requests','dzn_view_booking_requests'],['dzn-booking-request-coordination','Request coordination','dzn_manage_booking_request_coordination'],['dzn-term','Terms','dzn_manage_terms'],['dzn-lesson','Classes','dzn_manage_lessons'],['dzn-attendance-review','Attendance review','dzn_manage_canonical_attendance_review']],
   'Courses & pricing'=>[['dzn-instrument','Instruments','dzn_manage_courses'],['dzn-course','Courses','dzn_manage_courses'],['dzn-commercial-catalogue','Products & regional pricing','dzn_manage_commercial_catalogue']],
   'Communications'=>[['dzn-communications','Communications health','dzn_view_notification_authority']],
   'Finance'=>[['dzn-finance-statements','Teacher statements','dzn_view_finance_authority'],['dzn-finance-rates','Teacher rates','dzn_view_finance_authority'],['dzn-finance-payability','Class payability','dzn_view_finance_authority'],['dzn-finance-reconciliation','Reconciliation','dzn_view_finance_authority'],['dzn-payment-execution','Payment execution','dzn_view_payment_execution_authority']],
   'System'=>[['dzn-system-health','System health','dzn_view_diagnostics'],['dzn-exception','Operational exceptions','dzn_manage_exceptions'],['dzn-staff-access','Staff access','manage_options']],
  ];
  echo '<div class="wrap"><h1>Delnavazan workspace</h1><p>Choose the area you are working on. Technical authority remains in the underlying screen; this page is the simple navigation layer.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">';
  foreach($groups as$title=>$items){$visible=array_filter($items,fn($x)=>current_user_can($x[2]));if(!$visible)continue;echo '<div class="card" style="max-width:none"><h2>'.esc_html($title).'</h2><ul>';foreach($visible as$x)echo '<li style="margin:10px 0"><a href="'.esc_url(admin_url('admin.php?page='.$x[0])).'"><strong>'.esc_html($x[1]).'</strong></a></li>';echo '</ul></div>';}echo '</div></div>';
 }
}
