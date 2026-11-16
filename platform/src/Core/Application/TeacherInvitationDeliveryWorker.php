<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
use Delnavazan\Platform\Integrations\Notifications\TeacherInvitationPresentation;
use Delnavazan\Platform\Integrations\SmsIrAdapter;

/**
 * Bounded legacy invitation-delivery worker. Invitation delivery deliberately remains outside
 * NotificationService: the one-time secret is created only at this transport boundary.
 *
 * The email leg is primary and terminal for the invitation outbox. Iranian teachers additionally
 * receive the same one-time invitation over SMS.ir, but that leg is deliberately independent: an SMS
 * skip or failure can never change the recorded email outcome or fail the invitation, and the SMS body
 * (which contains the one-time code) is never written to a log or audit surface.
 */
final class TeacherInvitationDeliveryWorker {
    public const HOOK='dzn_teacher_invitation_delivery_dispatch';
    public const SMS_EVENT='teacher.invitation_issued.teacher';

    public static function register():void{
        add_filter('cron_schedules',array(__CLASS__,'schedules'));
        add_action(self::HOOK,array(__CLASS__,'run'));
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'five_minutes',self::HOOK);
    }
    public static function schedules(array $schedules):array{
        $schedules['five_minutes']??=array('interval'=>300,'display'=>'Every five minutes');
        return $schedules;
    }
    public static function run():void{
        $environment=wp_get_environment_type();
        if(!in_array($environment,array('staging','production'),true))return;
        $rows=(new PrincipalInvitationRepository())->pendingDeliveryRows(10);
        foreach($rows as $row)self::deliver((int)$row->generation_id);
    }
    /** Explicitly dispatch a just-issued invitation where the live transport is allowed.
     * Returns an honest state: mail acceptance is not recipient delivery. */
    public static function dispatchNow(int $generationId):string{
        if(!in_array(wp_get_environment_type(),array('staging','production'),true))return 'queued';
        return self::deliver($generationId);
    }
    private static function deliver(int $generationId):string{
        $repository=new PrincipalInvitationRepository();
        if(!apply_filters('dzn_teacher_invitation_email_transport_configured',false)){
            try{$repository->failPendingDelivery($generationId,'email_transport_not_configured');}catch(\Throwable){}
            return 'failed';
        }
        $service=new PrincipalInvitationService();
        try { $claimUrl=NotificationActionLinkPolicy::currentTeacherInvitation(); }
        catch (\Throwable) { try{$repository->failPendingDelivery($generationId,'notification_action_link_unavailable');}catch(\Throwable){} return 'failed'; }
        try{
            $payload=$service->prepareDelivery($generationId);
            $copy=TeacherInvitationPresentation::values();
            $subject=TeacherInvitationPresentation::render($copy['subject'],$claimUrl,(string)$payload['secret']);
            $message=TeacherInvitationPresentation::render($copy['body'],$claimUrl,(string)$payload['secret']);
            $sent=DelnavazanEmailRenderer::send((string)$payload['recipient'],$subject,$message,array(
                'locale'=>'fa-IR',
                'audience'=>'teacher',
                'cta'=>array('label'=>'باز کردن صفحهٔ دعوت','url'=>$claimUrl),
                'footer_note'=>'این دعوت‌نامه فقط برای شما صادر شده است. کد یک‌بارمصرف را با دیگران به اشتراک نگذارید.',
            ));
            unset($message);
            $repository->finishPreparedDelivery($generationId,$sent?'delivered':'failed',$sent?null:'mail_transport_rejected');
            // SMS leg for Iranian teachers. Never throws into the caller and never changes the
            // email outcome above, so a provider failure cannot fail the invitation.
            self::attemptSms((int)($payload['teacher_id']??0),(int)$payload['invitation_id'],(int)$payload['generation_id'],$claimUrl,(string)$payload['secret'],$repository);
            unset($payload['secret']);
            return $sent?'accepted_by_transport':'failed';
        }catch(\Throwable $e){
            // Preparation is atomic and fail-closed. A prepared secret must never be
            // retried, and no transport context is logged because it can be sensitive.
            try{$repository->failPreparedDelivery($generationId,'mail_transport_exception');}catch(\Throwable){try{$repository->failPendingDelivery($generationId,'delivery_preparation_failed');}catch(\Throwable){}}
            return 'failed';
        }
    }

    /**
     * The pure Iranian-SMS eligibility decision. Returns a stable skip reason code, or null when the
     * teacher should receive the SMS. No WordPress, no transport and no repository, so it is unit-testable.
     */
    public static function smsSkipReason(?string $phone,?string $countryCode,bool $smsIrConfigured):?string{
        $raw=trim((string)$phone);
        if($raw==='')return 'no_phone';
        $normalized=PhoneNumberNormalizer::toE164($raw);
        if($normalized==='')return 'invalid_number';
        $iranian=str_starts_with($normalized,'+98')||strtoupper((string)$countryCode)==='IR';
        if(!$iranian)return 'non_iranian';
        if(!$smsIrConfigured)return 'sms_ir_not_configured';
        return null;
    }

    /** The rendered SMS body from the single catalogue source. Pure: no WordPress, no transport. */
    public static function smsBody(string $claimUrl,string $secret):string{
        $copy=NotificationCatalogue::exactCopy(self::SMS_EVENT,'sms',NotificationCatalogue::PRIMARY_LOCALE);
        return TeacherInvitationPresentation::render((string)($copy['body']??''),$claimUrl,$secret);
    }

    /** The independent SMS.ir leg. Recorded via a dedicated audit event; the body is never logged. */
    private static function attemptSms(int $teacherId,int $invitationId,int $generationId,string $claimUrl,string $secret,PrincipalInvitationRepository $repository):void{
        try{
            $smsIrConfigured=(new IntegrationSettingsService())->verified('sms_ir');
            $teacher=$teacherId>0?$repository->teacherContactForId($teacherId):null;
            $skip=self::smsSkipReason($teacher?(string)$teacher->phone:null,$teacher?(string)$teacher->country_code:null,$smsIrConfigured);
            if($skip!==null){$repository->recordSmsDelivery($invitationId,$generationId,'skipped',$skip);return;}
            $phone=PhoneNumberNormalizer::toE164((string)$teacher->phone);
            $text=self::smsBody($claimUrl,$secret);
            if($text===''){$repository->recordSmsDelivery($invitationId,$generationId,'skipped','sms_copy_unavailable');return;}
            $result=(new SmsIrAdapter())->send(array('to'=>$phone,'body'=>$text));
            unset($text);
            if(!empty($result['acknowledged'])){$repository->recordSmsDelivery($invitationId,$generationId,'accepted',null);}
            else{$repository->recordSmsDelivery($invitationId,$generationId,'refused',(string)($result['reason']??$result['permanent_failure']??'sms_ir_refused'));}
        }catch(\Throwable){
            // The SMS leg must never alter the email outcome or fail the invitation.
            try{$repository->recordSmsDelivery($invitationId,$generationId,'failed','sms_ir_exception');}catch(\Throwable){}
        }
    }
}
