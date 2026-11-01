<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\TeacherWorkspaceView;
use Delnavazan\Platform\Core\Application\TeacherWorkspaceReadService;

/** One consolidated Teachers workspace: directory, full profile and onboarding. */
final class TeacherWorkspaceController {
    public static function screen(): void {
        if (!current_user_can(Menu::TEACHER_OPERATIONS_CAP) && !current_user_can(Menu::TEACHER_ONBOARDING_CAP)) {
            wp_die('Forbidden', 403);
        }
        $read = new TeacherWorkspaceReadService();
        echo '<div class="wrap dzn-teachers-admin">';
        TeacherWorkspaceView::render($read, 'dzn-teachers-menu');
        echo '</div>';
        self::script();
    }

    private static function script(): void {
        echo '<script>(function(){if(window.dznTeacherWorkspace)return;window.dznTeacherWorkspace=true;document.addEventListener("input",function(event){var input=event.target.closest(".dzn-tw-account-search");if(!input)return;var form=input.closest("form");if(!form)return;var select=form.querySelector(".dzn-tw-account-select");if(!select)return;var query=input.value.trim().toLocaleLowerCase();Array.prototype.forEach.call(select.options,function(option,index){option.hidden=index>0&&query!==""&&option.textContent.toLocaleLowerCase().indexOf(query)===-1;});});})();</script>';
    }
}
