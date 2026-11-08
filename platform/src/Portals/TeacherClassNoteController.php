<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\TeacherClassNoteService;

/**
 * Authenticated Teacher-only save of a private class note.
 *
 * The target is rebuilt from validated occurrence identifiers, never from a submitted redirect
 * URL. Ownership, the exact applicable schedule and Teacher authority are re-proved in the
 * application service before persistence.
 */
final class TeacherClassNoteController {
    public const ACTION='dzn_teacher_save_class_note';

    public static function register():void{
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'save'));
    }

    public static function save():never{
        if(!is_user_logged_in())auth_redirect();
        $nonce=sanitize_text_field((string)($_POST['_wpnonce']??''));
        if(!wp_verify_nonce($nonce,self::ACTION))wp_die('Forbidden',403);
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')self::back('','','note_unavailable');

        $lessonUid=trim((string)($_POST['lesson_uid']??''));
        $scheduleUid=trim((string)($_POST['schedule_version_uid']??''));
        $note=isset($_POST['note_text'])&&is_string($_POST['note_text'])
            ? sanitize_textarea_field(wp_unslash($_POST['note_text']))
            : '';

        try{
            (new TeacherClassNoteService())->saveForLesson($lessonUid,$scheduleUid,$note);
            self::back($lessonUid,$scheduleUid,'saved');
        }catch(\InvalidArgumentException $error){
            $status='teacher_note_too_long'===$error->getMessage()?'note_too_long':'note_unavailable';
            self::back($lessonUid,$scheduleUid,$status);
        }catch(\Throwable){
            self::back($lessonUid,$scheduleUid,'note_unavailable');
        }
    }

    private static function back(string $lessonUid,string $scheduleUid,string $status):never{
        if(preg_match('/^[0-9A-Z]{26}$/D',$lessonUid)&&preg_match('/^[0-9A-Z]{26}$/D',$scheduleUid)){
            $target=add_query_arg(array(
                'lesson_uid'=>$lessonUid,
                'schedule_version_uid'=>$scheduleUid,
                'view'=>'finish',
                'note_status'=>$status,
            ),home_url('/music-classroom/'));
            wp_safe_redirect($target);
            exit;
        }
        wp_safe_redirect(add_query_arg(array('teacher-view'=>'classes','class_status'=>$status),home_url('/teacher-portal/')));
        exit;
    }
}
