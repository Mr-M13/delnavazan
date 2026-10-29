<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;

/**
 * Persistent website inbox for students and teachers.
 *
 * It is deliberately separate from the canonical delivery aggregate: a notification is addressed to one
 * WordPress user, carries no contact value and no one-time secret, and is deduplicated by a keyed digest of
 * the authoritative business event. Marking it read is never an approval of a business action.
 */
final class PortalNotificationInbox {
    public const RETENTION_DAYS=365;
    private string $prefix;

    public function __construct(){
        global $wpdb;
        $this->prefix=$wpdb->prefix.'dzn_';
    }

    /**
     * Record one inbox notification. Returns the row id, or null when the event is not eligible for the
     * website channel or the recipient cannot be resolved. Repeated calls for the same business event
     * converge on the existing row.
     *
     * @param array<string,string> $variables
     */
    public function record(string $eventKey,string $audience,int $recipientId,string $sourceKind,int $sourceId,array $variables=array(),?string $linkUrl=null):?int{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null||!in_array('website',(array)$event['channels'],true))return null;
        if(!NotificationChannelPolicy::allows($eventKey,'website'))return null;
        if(!in_array($audience,array('student','teacher','admin'),true))return null;
        if($audience!=='admin'&&$recipientId<1)return null;
        $recipient=$audience==='admin'?array('wp_user_id'=>0,'locale'=>NotificationCatalogue::PRIMARY_LOCALE):$this->recipient($audience,$recipientId);
        if($recipient===null)return null;
        $locale=NotificationCatalogue::normaliseLocale((string)($recipient['locale']??NotificationCatalogue::PRIMARY_LOCALE));
        $copy=(new NotificationTemplateLibrary())->activeCopy($eventKey,'website',$locale);
        if(!is_array($copy))return null;
        $replace=static function(string $text)use($variables):string{
            return (string)preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',static function(array $m)use($variables):string{
                return array_key_exists($m[1],$variables)?(string)$variables[$m[1]]:'';
            },$text);
        };
        $title=trim($replace((string)($copy['subject']??'')));
        $body=trim($replace((string)($copy['body']??'')));
        if($title===''||$body==='')return null;
        $link=$this->safeLink($linkUrl??($variables['portal_url']??$variables['teacher_portal_url']??''));
        $dedupe=$this->dedupeKey($eventKey,$audience,$recipientId,$sourceKind,$sourceId);
        global $wpdb;
        $table=$this->prefix.'portal_notifications';
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE dedupe_key=%s LIMIT 1",$dedupe));
        if($existing!==null&&$existing!==false)return (int)$existing;
        $now=gmdate('Y-m-d H:i:s');
        $ok=$wpdb->insert($table,array(
            'uid'=>$this->uid(),'event_key'=>substr($eventKey,0,64),'category'=>substr((string)($event['category']??'lesson'),0,24),
            'audience'=>$audience,'recipient_id'=>$recipientId,'wp_user_id'=>(int)$recipient['wp_user_id'],
            'title'=>mb_substr($title,0,191),'body'=>mb_substr($body,0,4000),'link_url'=>$link,
            'source_kind'=>substr($sourceKind,0,32),'source_id'=>$sourceId,'dedupe_key'=>$dedupe,
            'read_at'=>null,'created_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',time()+self::RETENTION_DAYS*86400),
        ));
        if($ok===false){
            // A concurrent producer won the unique dedupe key; its row is the one to show.
            $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE dedupe_key=%s LIMIT 1",$dedupe));
            return $existing===null||$existing===false?null:(int)$existing;
        }
        return (int)$wpdb->insert_id;
    }

    /**
     * The authenticated user's own inbox and unread count.
     *
     * The theme calls this seam instead of resolving a WordPress principal itself, so portal
     * presentation keeps the platform's principal boundary.
     */
    public function forCurrentUser(int $limit=20):array{
        $userId=(int)get_current_user_id();
        return array('items'=>$this->forUser($userId,$limit),'unread'=>$this->unreadCount($userId),'user_id'=>$userId);
    }

    /** @return array<int,array> Own notifications only, newest first. */
    public function forUser(int $userId,int $limit=20,bool $unreadOnly=false):array{
        if($userId<1)return array();
        global $wpdb;
        $table=$this->prefix.'portal_notifications';
        $limit=max(1,min(100,$limit));
        $sql="SELECT id,uid,event_key,category,title,body,link_url,read_at,created_at FROM {$table} WHERE wp_user_id=%d AND (expires_at IS NULL OR expires_at>%s)";
        $args=array($userId,gmdate('Y-m-d H:i:s'));
        if($unreadOnly)$sql.=" AND read_at IS NULL";
        $sql.=' ORDER BY created_at DESC,id DESC LIMIT %d';
        $args[]=$limit;
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$args));
        if(!is_array($rows))return array();
        return array_map(static function($row):array{
            return array(
                'id'=>(int)$row->id,'uid'=>(string)$row->uid,'event_key'=>(string)$row->event_key,
                'category'=>(string)$row->category,'title'=>(string)$row->title,'body'=>(string)$row->body,
                'link_url'=>(string)($row->link_url??''),'read'=>(bool)($row->read_at!==null),
                'read_at'=>$row->read_at,'created_at'=>(string)$row->created_at,
                'is_unread'=>($row->read_at===null),
            );
        },$rows);
    }

    public function unreadCount(int $userId):int{
        if($userId<1)return 0;
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->prefix}portal_notifications WHERE wp_user_id=%d AND read_at IS NULL AND (expires_at IS NULL OR expires_at>%s)",$userId,gmdate('Y-m-d H:i:s')));
    }

    /** Ownership is enforced in the UPDATE, so one user can never mark another user's row. */
    public function markRead(int $userId,int $notificationId):bool{
        if($userId<1||$notificationId<1)return false;
        global $wpdb;
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}portal_notifications SET read_at=COALESCE(read_at,%s) WHERE id=%d AND wp_user_id=%d",gmdate('Y-m-d H:i:s'),$notificationId,$userId));
        return $changed!==false&&(int)$changed>0;
    }

    public function markAllRead(int $userId):int{
        if($userId<1)return 0;
        global $wpdb;
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$this->prefix}portal_notifications SET read_at=%s WHERE wp_user_id=%d AND read_at IS NULL",gmdate('Y-m-d H:i:s'),$userId));
        return $changed===false?0:(int)$changed;
    }

    /** System-generated admin notifications are never mixed into the student/teacher inbox query. */
    public function adminSummary():array{
        global $wpdb;
        $table=$this->prefix.'portal_notifications';
        $now=gmdate('Y-m-d H:i:s');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT audience,COUNT(*) total,SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) unread FROM {$table} WHERE (expires_at IS NULL OR expires_at>%s) GROUP BY audience",$now));
        $summary=array('student'=>array('total'=>0,'unread'=>0),'teacher'=>array('total'=>0,'unread'=>0),'admin'=>array('total'=>0,'unread'=>0));
        foreach((array)$rows as $row)$summary[(string)$row->audience]=array('total'=>(int)$row->total,'unread'=>(int)$row->unread);
        return $summary;
    }

    /** @return array<int,array> Recent rows for the admin monitor; no contact data is stored anyway. */
    public function recent(int $limit=50):array{
        global $wpdb;
        $limit=max(1,min(200,$limit));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,event_key,category,audience,recipient_id,wp_user_id,title,read_at,created_at FROM {$this->prefix}portal_notifications ORDER BY created_at DESC,id DESC LIMIT %d",$limit));
        if(!is_array($rows))return array();
        return array_map(static fn($row):array=>array(
            'id'=>(int)$row->id,'event_key'=>(string)$row->event_key,'category'=>(string)$row->category,
            'audience'=>(string)$row->audience,'recipient_id'=>(int)$row->recipient_id,'wp_user_id'=>(int)$row->wp_user_id,
            'title'=>(string)$row->title,'read'=>(bool)($row->read_at!==null),'created_at'=>(string)$row->created_at,
        ),$rows);
    }

    public function prune():int{
        global $wpdb;
        $changed=$wpdb->query($wpdb->prepare("DELETE FROM {$this->prefix}portal_notifications WHERE (expires_at IS NOT NULL AND expires_at<=%s) OR created_at<=%s",gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()-self::RETENTION_DAYS*86400)));
        return $changed===false?0:(int)$changed;
    }

    /** Resolve the linked WordPress user and locale from the authoritative identity table. */
    private function recipient(string $audience,int $recipientId):?array{
        global $wpdb;
        $entity=$audience==='student'?'students':'teachers';
        $row=$wpdb->get_row($wpdb->prepare("SELECT wordpress_user_id,locale FROM {$this->prefix}{$entity} WHERE id=%d AND status='active' AND archived_at IS NULL LIMIT 1",$recipientId));
        if(!$row)return null;
        $wpUserId=(int)($row->wordpress_user_id??0);
        // The canonical portal principal link is the authority for a portal account; the identity
        // column is only a compatibility fallback for older rows. Reading the link table here means an
        // inbox row reaches exactly the WordPress user the portal resolver will authenticate.
        $links=$audience==='student'?'student_principal_links':'teacher_principal_links';
        $idColumn=$audience==='student'?'student_id':'teacher_id';
        $linked=$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$this->prefix}{$links} WHERE {$idColumn}=%d AND status='active' AND revoked_at IS NULL".($audience==='student'?' AND active_slot=1':'').' ORDER BY id DESC LIMIT 1',$recipientId));
        if($linked!==null&&$linked!==false&&(int)$linked>0)$wpUserId=(int)$linked;
        if($wpUserId<1)return null;
        return array('wp_user_id'=>$wpUserId,'locale'=>(string)($row->locale??''));
    }

    private function safeLink(string $url):?string{
        $url=trim($url);
        if($url==='')return null;
        $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
        if(!in_array($scheme,array('http','https'),true))return null;
        return mb_substr($url,0,255);
    }

    private function dedupeKey(string $eventKey,string $audience,int $recipientId,string $sourceKind,int $sourceId):string{
        $salt=function_exists('wp_salt')?wp_salt('dzn_portal_notification'):'dzn_portal_notification';
        return hash_hmac('sha256','portal_notification:'.$eventKey.':'.$audience.':'.$recipientId.':'.$sourceKind.':'.$sourceId,$salt);
    }

    private function uid():string{
        if(class_exists('Delnavazan\\Platform\\Core\\Support\\Identifier'))return \Delnavazan\Platform\Core\Support\Identifier::uid();
        return substr(bin2hex(random_bytes(16)),0,26);
    }
}
