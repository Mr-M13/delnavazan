<?php
namespace Delnavazan\Platform\Admin\Controller;

/** A small navigation layer. Every action remains on its capability-gated owner screen. */
final class AdminWorkspaceController {
    public static function register():void{
        add_action('admin_menu',[self::class,'hideWorkspaceMenuForUnauthorizedUsers'],999);
        add_action('admin_head',[self::class,'compactSidebar']);
    }

    public static function hideWorkspaceMenuForUnauthorizedUsers():void{
        global $submenu;
        if(!self::hasAccess()){remove_menu_page('dzn-platform');unset($submenu['dzn-platform']);}
    }

    /** Keep registered submenus intact: WordPress uses them to authorize direct owner-screen links. */
    public static function compactSidebar():void{
        if(!self::hasAccess())return;
        echo '<style id="dzn-workspace-menu">#toplevel_page_dzn-platform .wp-submenu li:not(:first-child){display:none}</style>';
    }

    private static function hasAccess():bool{
        if(current_user_can('manage_options'))return true;
        foreach(['dzn_view_diagnostics','dzn_view_portal_capabilities','dzn_manage_students','dzn_view_student_acceptance_eligibility','dzn_manage_enrolments','dzn_manage_teachers','dzn_manage_onboarding','dzn_manage_teaching_eligibility','dzn_manage_teacher_availability','dzn_view_booking_requests','dzn_manage_booking_request_coordination','dzn_manage_terms','dzn_manage_lessons','dzn_manage_canonical_attendance_review','dzn_manage_courses','dzn_manage_commercial_catalogue','dzn_view_notification_authority','dzn_view_finance_authority','dzn_view_payment_execution_authority','dzn_manage_exceptions','dzn_manage_canonical_terms','dzn_manage_canonical_lessons','dzn_convert_service_arrangements_to_enrolments','dzn_manage_teacher_assignments','dzn_manage_canonical_lesson_delivery','dzn_manage_canonical_lesson_schedules'] as $cap)if(current_user_can($cap))return true;
        return false;
    }

    private static function areas():array{
        return [
            'Teachers'=>[
                'description'=>'Invite a teacher, let them complete their own account and profile, then review readiness.',
                'primary'=>[['dzn-onboarding','Invite and review teachers','dzn_manage_onboarding']],
                'more'=>[['dzn-teacher','Teacher records','dzn_manage_teachers'],['dzn-teaching-eligibility','Teaching eligibility','dzn_manage_teaching_eligibility'],['dzn-teacher-availability','Availability','dzn_manage_teacher_availability'],['dzn-academy-coverage','Coverage gaps','dzn_view_booking_requests']],
            ],
            'Students'=>[
                'description'=>'Review requests, resolve identity with a human decision, then connect the student account.',
                'primary'=>[['dzn-student-identity-authority','Account and acceptance','dzn_view_student_acceptance_eligibility']],
                'more'=>[['dzn-student','Student records','dzn_manage_students'],['dzn-enrolment','Enrolments','dzn_manage_enrolments']],
            ],
            'Classes'=>[
                'description'=>'Follow each request through coordination, acceptance, introductory teaching and the term.',
                'primary'=>[['dzn-academy-operations','Open class pipeline','dzn_view_booking_requests']],
                'more'=>[['dzn-booking-requests','Booking requests','dzn_view_booking_requests'],['dzn-booking-request-coordination','Coordinate request','dzn_manage_booking_request_coordination'],['dzn-core-dataset-readiness','Canonical class actions','dzn_manage_canonical_terms'],['dzn-term','Term records','dzn_manage_terms'],['dzn-lesson','Lesson records','dzn_manage_lessons'],['dzn-attendance-review','Attendance review','dzn_manage_canonical_attendance_review'],['dzn-instrument','Instruments','dzn_manage_courses'],['dzn-course','Courses','dzn_manage_courses']],
            ],
            'Admin'=>[
                'description'=>'Pricing, communications, finance, staff access and system health.',
                'primary'=>[['dzn-system-health','System health','dzn_view_diagnostics']],
                'more'=>[['dzn-commercial-catalogue','Products and pricing','dzn_manage_commercial_catalogue'],['dzn-communications','Communications','dzn_view_notification_authority'],['dzn-finance-statements','Teacher statements','dzn_view_finance_authority'],['dzn-finance-rates','Teacher rates','dzn_view_finance_authority'],['dzn-finance-payability','Class payability','dzn_view_finance_authority'],['dzn-finance-policies','Finance policies','dzn_view_finance_authority'],['dzn-finance-reconciliation','Reconciliation','dzn_view_finance_authority'],['dzn-payment-execution','Payment execution','dzn_view_payment_execution_authority'],['dzn-portal-capabilities','Portal links','dzn_view_portal_capabilities'],['dzn-exception','Exceptions','dzn_manage_exceptions'],['dzn-staff-access','Staff access','manage_options']],
            ],
        ];
    }

    public static function screen():void{
        if(!self::hasAccess())wp_die('Forbidden',403);
        echo '<div class="wrap"><h1>Delnavazan workspace</h1><p>Choose what you need to do. Each step opens the screen that owns the decision and checks your permissions.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">';
        foreach(self::areas() as $title=>$area){
            $primary=array_values(array_filter($area['primary'],fn($x)=>self::canOpen($x)));
            $more=array_values(array_filter($area['more'],fn($x)=>self::canOpen($x)));
            if(!$primary&&!$more)continue;
            echo '<section class="card" style="max-width:none"><h2>'.esc_html($title).'</h2><p>'.esc_html($area['description']).'</p>';
            foreach($primary as $item)self::link($item,true);
            if($more){echo '<details><summary>More tools</summary><ul>';foreach($more as $item){echo '<li style="margin:8px 0">';self::link($item,false);echo '</li>';}echo '</ul></details>';}
            echo '</section>';
        }
        echo '</div></div>';
    }

    private static function link(array $item,bool $primary):void{
        echo '<a '.($primary?'class="button button-primary" style="margin:0 0 16px" ':'').'href="'.esc_url(admin_url('admin.php?page='.$item[0])).'">'.esc_html($item[1]).'</a>';
    }

    private static function canOpen(array $x):bool{
        if($x[0]==='dzn-core-dataset-readiness'){
            foreach(['dzn_convert_service_arrangements_to_enrolments','dzn_manage_teacher_assignments','dzn_manage_canonical_terms','dzn_manage_canonical_lessons','dzn_manage_canonical_lesson_delivery','dzn_manage_canonical_lesson_schedules'] as $cap)if(current_user_can($cap))return true;
            return false;
        }
        return current_user_can($x[2]);
    }
}
