<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;

/** An offer survives mail failure; a unique obligation retries transport refusal. */
final class BookingAllocationNotificationWorker {
    public const HOOK='dzn_booking_allocation_notification_dispatch';
    public static function register():void{add_action(self::HOOK,array(__CLASS__,'run'));add_action('init',array(__CLASS__,'ensureScheduled'));}
    public static function ensureScheduled():void{if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+90,'dzn_two_minutes',self::HOOK);}
    public static function run():void{$repo=new BookingAllocationRepository();foreach($repo->dueNotifications(gmdate('Y-m-d H:i:s')) as $id)try{self::dispatch((int)$id,$repo);}catch(\Throwable $error){do_action('dzn_booking_allocation_notification_failed',(int)$id,get_class($error));}}
    public static function dispatch(int $id,?BookingAllocationRepository $repo=null):void{
        $repo??=new BookingAllocationRepository();$now=gmdate('Y-m-d H:i:s');
        $repo->begin();
        try{
            $row=$repo->notification($id,true);
            if(!$row||!in_array($row->state,array('queued','retryable','sending'),true)){$repo->commit();return;}
            $active=$row->offer_state==='pending'&&(int)$row->active_slot===1&&(string)$row->deadline_at>$now&&$row->lifecycle_status==='submitted'&&$row->resolution_state==='unresolved'&&$row->privacy_erased_at===null;
            if(!$active){$repo->updateNotification($id,(string)$row->state,array('state'=>'cancelled','next_attempt_at'=>null,'updated_at'=>$now));$repo->commit();return;}
            if($row->state==='sending'){
                // A previous process may have handed mail to the provider. Never
                // blindly resend an outcome-unknown message.
                $repo->updateNotification($id,'sending',array('state'=>'failed','last_error_code'=>'handoff_unknown','next_attempt_at'=>null,'updated_at'=>$now));$repo->commit();return;
            }
            $repo->updateNotification($id,(string)$row->state,array('state'=>'sending','attempt_count'=>(int)$row->attempt_count+1,'next_attempt_at'=>null,'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+600),'updated_at'=>$now));
            $repo->commit();
        }catch(\Throwable $error){$repo->rollback();throw $error;}
        $portal=home_url('/teacher-portal/');$zone=(string)($row->teacher_timezone?:'UTC');
        try{$when=(new \DateTimeImmutable((string)$row->starts_at_utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i').' '.$zone;}
        catch(\Throwable){$when=(string)$row->starts_at_utc.' UTC';}
        try{$deadlineLocal=(new \DateTimeImmutable((string)$row->deadline_at,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i').' '.$zone;}
        catch(\Throwable){$deadlineLocal=(string)$row->deadline_at.' UTC';}
        $variables=array('teacher'=>(string)$row->teacher_name,'course'=>(string)$row->course_name,'student'=>(string)$row->full_name,'when'=>$when,'teacher_portal_url'=>$portal,'response_window'=>'۲۴ ساعت','response_deadline'=>$deadlineLocal);
        PortalNotificationRecorder::teacher((int)$row->teacher_id,'booking.new.teacher','booking_offer',(int)$row->offer_id,$variables,$portal);
        $user=$row->wordpress_user_id?get_userdata((int)$row->wordpress_user_id):null;
        $accepted=false;
        $suppressed=false;
        if($user&&is_email((string)$user->user_email)){
            $fallback=array('subject'=>'دلنوازان · درخواست تازهٔ کلاس آشنایی','body'=>"درود {$row->teacher_name}،\n\nدرخواست تازه‌ای برای کلاس «{$row->course_name}» با هنرجو {$row->full_name} در {$when} ثبت شد.\nمهلت پاسخ ۲۴ ساعت است (تا {$deadlineLocal}). اگر در این بازه پاسخی ثبت نشود، درخواست به مدرس در دسترس بعدی ارجاع می‌شود.\n\n{$portal}\n\nدلنوازان");
            $copy=NotificationCopyResolver::email('booking.new.teacher',NotificationCatalogue::PRIMARY_LOCALE,$variables,$fallback);
            if(!str_contains((string)$copy['body'],'مهلت پاسخ'))$copy['body']=rtrim((string)$copy['body'])."\n\n".'لطفاً حداکثر تا '.$variables['response_window'].' ('.$variables['response_deadline'].') پاسخ دهید. اگر در این بازه پاسخی ثبت نشود، درخواست به مدرس در دسترس بعدی ارجاع می‌شود.';
            $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
            if(!preg_match('/^[a-z0-9.-]+$/D',$host))$host='delnavazan.invalid';
            $headers=array('Message-ID: <booking-offer-'.(int)$row->offer_id.'@'.$host.'>','X-Delnavazan-Offer-ID: '.(int)$row->offer_id);
            if(!(new NotificationTemplateLibrary())->eventChannelEnabled('booking.new.teacher','email')){$suppressed=true;}
            else{try{$accepted=DelnavazanEmailRenderer::send((string)$user->user_email,$copy['subject'],$copy['body'],array('locale'=>NotificationCatalogue::PRIMARY_LOCALE,'cta'=>array('url'=>$portal,'label'=>'پرتال مدرس')),$headers);}
            catch(\Throwable){$accepted=false;}}
        }
        $repo->begin();
        try{
            $locked=$repo->notification($id,true);
            if(!$locked||$locked->state!=='sending')throw new \RuntimeException('Offer notification lease changed');
            $now=gmdate('Y-m-d H:i:s');$attempt=(int)$locked->attempt_count;
            $data=$suppressed?array('state'=>'cancelled','last_error_code'=>'email_disabled_by_administrator','next_attempt_at'=>null):($accepted?array('state'=>'handed_off','handed_off_at'=>$now,'last_error_code'=>null,'next_attempt_at'=>null):($attempt>=3?array('state'=>'failed','last_error_code'=>'send_refused','next_attempt_at'=>null):array('state'=>'retryable','last_error_code'=>'send_refused','next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+300))));
            $repo->updateNotification($id,'sending',$data+array('lease_expires_at'=>null,'updated_at'=>$now));$repo->commit();
        }catch(\Throwable $error){$repo->rollback();throw $error;}
    }
}
