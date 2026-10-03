<?php
namespace Delnavazan\Platform\Admin\Controller;
final class AdminWorkspaceController {
 public static function register():void{add_action('admin_menu',[self::class,'menu'],99);}
 public static function menu():void{add_submenu_page('dzn-platform','Workspace','Workspace','dzn_view_diagnostics','dzn-workspace',[self::class,'screen']);}
 public static function screen():void{
  if(!current_user_can('dzn_view_diagnostics'))wp_die('Forbidden',403);
  $groups=[
   'Students'=>[['dzn-student','Students','dzn_manage_students'],['dzn-student-identity-authority','Account & acceptance','dzn_view_student_acceptance_eligibility'],['dzn-enrolment','Enrolments','dzn_manage_enrolments']],
   'Teachers'=>[['dzn-teacher','Teachers','dzn_manage_teachers'],['dzn-onboarding','Onboarding & invitations','dzn_manage_onboarding'],['dzn-teaching-eligibility','Teaching eligibility','dzn_manage_teaching_eligibility'],['dzn-teacher-availability','Availability','dzn_manage_teacher_availability'],['dzn-academy-coverage','Golden-hour coverage','dzn_view_booking_requests']],
   'Bookings & teaching'=>[['dzn-academy-operations','Academy pipeline','dzn_view_booking_requests'],['dzn-booking-requests','Booking requests','dzn_view_booking_requests'],['dzn-booking-request-coordination','Request coordination','dzn_manage_booking_request_coordination'],['dzn-term','Terms','dzn_manage_terms'],['dzn-lesson','Classes','dzn_manage_lessons'],['dzn-attendance-review','Attendance review','dzn_manage_canonical_attendance_review']],
   'Courses & pricing'=>[['dzn-instrument','Instruments','dzn_manage_courses'],['dzn-course','Courses','dzn_manage_courses'],['dzn-commercial-catalogue','Products & regional pricing','dzn_manage_commercial_catalogue']],
   'Communications'=>[['dzn-communications','Communications health','dzn_view_notification_authority']],
   'Finance'=>[['dzn-finance-statements','Teacher statements','dzn_view_finance_authority'],['dzn-finance-rates','Teacher rates','dzn_view_finance_authority'],['dzn-finance-payability','Class payability','dzn_view_finance_authority'],['dzn-finance-reconciliation','Reconciliation','dzn_view_finance_authority'],['dzn-payment-execution','Payment execution','dzn_view_payment_execution_authority']],
   'System'=>[['dzn-platform','System health','dzn_view_diagnostics'],['dzn-exception','Operational exceptions','dzn_manage_exceptions'],['dzn-staff-access','Staff access','manage_options']],
  ];
  echo '<div class="wrap"><h1>Delnavazan workspace</h1><p>Choose the area you are working on. Technical authority remains in the underlying screen; this page is the simple navigation layer.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">';
  foreach($groups as$title=>$items){$visible=array_filter($items,fn($x)=>current_user_can($x[2]));if(!$visible)continue;echo '<div class="card" style="max-width:none"><h2>'.esc_html($title).'</h2><ul>';foreach($visible as$x)echo '<li style="margin:10px 0"><a href="'.esc_url(admin_url('admin.php?page='.$x[0])).'"><strong>'.esc_html($x[1]).'</strong></a></li>';echo '</ul></div>';}echo '</div></div>';
 }
}
