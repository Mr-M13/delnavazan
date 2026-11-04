<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Authenticated, policy-controlled recording playback.
 *
 * The browser never receives a Drive URL or access token. Every decision is appended to
 * meet_recording_access_events, including denials. Ordinary administrators get metadata only.
 */
final class MeetRecordingPlaybackController {
    public static function register():void{
        add_action('admin_post_dzn_meet_recording_play',array(__CLASS__,'play'));
    }

    public static function play():never{
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('dzn_meet_recording_play');
        $lessonUid=trim((string)($_POST['lesson_uid']??''));
        $purpose=sanitize_key((string)($_POST['access_purpose']??''));
        $reasonCode=sanitize_key((string)($_POST['reason_code']??''));
        $reasonText=sanitize_text_field((string)($_POST['reason_text']??''));
        $portal=home_url('/student-portal/');
        try{
            global $wpdb;$p=$wpdb->prefix.'dzn_';
            $lesson=$wpdb->get_row($wpdb->prepare("SELECT id,teacher_id,student_id FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid));
            if(!$lesson)throw new \RuntimeException('recording_unavailable');
            $userId=(int)get_current_user_id();
            $repository=new GoogleWorkspaceRepository();
            $teacherId=$repository->teacherPrincipalUser((int)$lesson->teacher_id);
            $studentId=$repository->studentPrincipalUser((int)$lesson->student_id);
            $isAdmin=current_user_can('manage_options')||current_user_can('dzn_view_recording_operations')||current_user_can('dzn_manage_recording_operations')||current_user_can('dzn_manage_recording_legal_hold');
            if($purpose==='')$purpose=$teacherId===$userId&&$teacherId>0?MeetRecordingAccessRule::TEACHER_REVIEW:($studentId===$userId&&$studentId>0?MeetRecordingAccessRule::STUDENT_REVIEW:MeetRecordingAccessRule::ADMIN_OPERATIONAL);
            if($purpose===MeetRecordingAccessRule::COMPLIANCE_REVIEW)$portal=home_url('/wp-admin/admin.php?page=dzn-recordings');
            $decision=(new MeetRecordingAccessService($repository))->decide((int)$lesson->id,$purpose,$reasonCode,$reasonText);
            if(empty($decision['media_allowed'])||empty($decision['drive_file_id']))throw new \RuntimeException((string)($decision['reason']??'recording_not_authorised'));
            $handle=(new MeetRecordingService($repository))->streamHandleForFile((string)$decision['drive_file_id']);
            self::stream($handle);
        }catch(\Throwable $error){
            $code=preg_match('/^[a-z0-9_]{3,64}$/D',$error->getMessage())?$error->getMessage():'recording_unavailable';
            wp_safe_redirect(add_query_arg(array('recording_status'=>$code),$portal));
            exit;
        }
    }

    private static function stream(array $handle):never{
        $fileId=GoogleWorkspaceClassroomRule::driveFileId((string)$handle['file_id']);
        $url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?alt=media&supportsAllDrives=true';
        $token=(string)$handle['access_token'];
        $range=trim((string)($_SERVER['HTTP_RANGE']??''));
        nocache_headers();
        header('Content-Type: video/mp4');
        header('Content-Disposition: inline; filename="delnavazan-lesson.mp4"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if(function_exists('curl_init')){
            $curl=curl_init($url);
            $headers=array('Authorization: Bearer '.$token,'Accept: video/mp4');
            if($range!=='')$headers[]='Range: '.$range;
            curl_setopt_array($curl,array(
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HEADER=>false,
                CURLOPT_TIMEOUT=>0,
                CURLOPT_CONNECTTIMEOUT=>15,
                CURLOPT_HEADERFUNCTION=>static function($curl,string $line):int{
                    $trim=trim($line);
                    if($trim!==''&&!str_contains($trim,':')){
                        if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$trim,$match))http_response_code((int)$match[1]);
                    }elseif(str_starts_with(strtolower($trim),'content-range:')||str_starts_with(strtolower($trim),'content-length:')){
                        header($trim,false);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk):int{
                    echo $chunk;
                    if(function_exists('flush'))flush();
                    return strlen($chunk);
                },
            ));
            curl_exec($curl);
            curl_close($curl);
            exit;
        }
        $args=array('timeout'=>120,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.$token));
        if($range!=='')$args['headers']['Range']=$range;
        $response=wp_remote_get($url,$args);
        if(is_wp_error($response))throw new \RuntimeException('recording_stream_failed');
        http_response_code((int)wp_remote_retrieve_response_code($response));
        echo (string)wp_remote_retrieve_body($response);
        exit;
    }
}
