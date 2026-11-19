<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService;
use Delnavazan\Platform\Core\Infrastructure\Repository\IntroPaymentRepository;

/**
 * Bounded expiry pass for unpaid introductory acceptances.
 *
 * The two-minute booking cron is already provisioned; this pass reuses it so an
 * obligation can never sit pending past its `payment_due_at`. The service owns
 * the transition, notification queueing and slot release, and is idempotent, so
 * a missed tick converges on the next one.
 */
final class IntroPaymentExpiryWorker {
    public const HOOK='dzn_intro_payment_expiry_dispatch';
    public static function register():void{
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }
    public static function ensureScheduled():void{
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+120,'dzn_two_minutes',self::HOOK);
    }
    public static function run():void{
        $now=gmdate('Y-m-d H:i:s');$repo=new IntroPaymentRepository();$service=new IntroPaymentService($repo);
        foreach($repo->dueObligations($now,25) as $obligationId){
            try{$service->expireLapsedObligation((int)$obligationId,$now);}
            catch(\Throwable $error){do_action('dzn_intro_payment_expiry_failed',(int)$obligationId,get_class($error));}
        }
    }
}
