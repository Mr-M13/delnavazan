<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Append-only publication authority for recording and research notices.
 *
 * Publishing always creates a new immutable version; an existing row is never edited. Withdrawal
 * takes every published version of one audience/language offline so an older version cannot
 * silently become current again. This service never captures media and never records a decision.
 */
final class RecordingNoticePublicationService {
    public const AUDIENCES = array(
        RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,
        RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING,
        RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH,
        RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,
        RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH,
        RecordingDecisionAuthorityService::AUDIENCE_PRIVACY_TERMS,
    );
    public const LANGUAGES = array('en','fa');

    /** The currently effective published version, or null when none is available. */
    public function current(string $audience,string $language):?array {
        $this->assertAudience($audience);
        $this->assertLanguage($language);
        global $wpdb; $p=$wpdb->prefix.'dzn_'; $now=gmdate('Y-m-d H:i:s');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_notice_versions WHERE audience_code=%s AND language_code=%s AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL ORDER BY version_number DESC LIMIT 1",$audience,$language,$now,$now));
        return $row?get_object_vars($row):null;
    }

    /** Newest publication and withdrawal evidence for operator review. */
    public function recent(int $limit=30):array {
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        $limit=max(1,min(100,$limit));
        return (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recording_notice_versions ORDER BY created_at DESC,id DESC LIMIT %d",$limit));
    }

    /**
     * Publish a new immutable version. The version number is derived server-side and never supplied
     * by the caller. A concurrent insert can only cause a replay at the next version number.
     */
    public function publish(string $audience,string $language,string $body,int $actor):array {
        $this->assertAudience($audience);
        $this->assertLanguage($language);
        if($actor<1)throw new \InvalidArgumentException('recording_notice_actor_required');
        $body=trim((string)wp_kses_post($body));
        if(''===trim(wp_strip_all_tags($body)))throw new \InvalidArgumentException('recording_notice_body_required');
        global $wpdb; $p=$wpdb->prefix.'dzn_'; $now=gmdate('Y-m-d H:i:s');
        for($attempt=0;$attempt<5;$attempt++){
            $max=(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(version_number) FROM {$p}recording_notice_versions WHERE audience_code=%s AND language_code=%s",$audience,$language));
            $version=$max+1;
            $uid=Identifier::uid();
            $inserted=$wpdb->insert($p.'recording_notice_versions',array(
                'uid'=>$uid,
                'audience_code'=>$audience,
                'language_code'=>$language,
                'version_number'=>$version,
                'publication_state'=>'published',
                'body_text'=>$body,
                'text_digest'=>hash('sha256',$body),
                'published_at'=>$now,
                'expires_at'=>null,
                'withdrawn_at'=>null,
                'created_at'=>$now,
                'created_by'=>$actor,
            ));
            if($inserted===1){
                do_action('dzn_recording_notice_published',$audience,$language,$version,$uid,$actor);
                return array('uid'=>$uid,'audience'=>$audience,'language'=>$language,'version'=>$version,'published_at'=>$now);
            }
            if(!str_contains((string)$wpdb->last_error,'Duplicate'))break;
        }
        throw new \RuntimeException('recording_notice_persistence_failed');
    }

    /**
     * Withdraw every published version of one audience/language. Withdrawing only the newest row
     * would make an older published version current again, so the whole published set is closed.
     */
    public function withdrawAll(string $audience,string $language,int $actor):int {
        $this->assertAudience($audience);
        $this->assertLanguage($language);
        if($actor<1)throw new \InvalidArgumentException('recording_notice_actor_required');
        global $wpdb; $p=$wpdb->prefix.'dzn_'; $now=gmdate('Y-m-d H:i:s');
        $updated=$wpdb->query($wpdb->prepare("UPDATE {$p}recording_notice_versions SET withdrawn_at=%s WHERE audience_code=%s AND language_code=%s AND publication_state='published' AND withdrawn_at IS NULL",$now,$audience,$language));
        if($updated===false)throw new \RuntimeException('recording_notice_persistence_failed');
        return (int)$updated;
    }

    private function assertAudience(string $audience):void {
        if(!in_array($audience,self::AUDIENCES,true))throw new \InvalidArgumentException('recording_notice_audience_invalid');
    }

    private function assertLanguage(string $language):void {
        if(!in_array($language,self::LANGUAGES,true))throw new \InvalidArgumentException('recording_decision_language_invalid');
    }
}
