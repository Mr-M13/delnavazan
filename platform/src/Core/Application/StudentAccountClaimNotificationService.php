<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\ReadModel\StudentAccountClaimNotificationSubjectReadModel;
use Delnavazan\Platform\Core\Infrastructure\ReadModel\StudentNotificationRecipientReadModel;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\StudentAccountClaimInvitationRepository;

/**
 * The #221 → #220 composition root.  One InnoDB transaction creates a generation, its single
 * channel-neutral intent and the observed notification/snapshot.  The claim code crosses this class
 * only as an in-memory template value and is encrypted by #220 before this transaction can commit.
 */
final class StudentAccountClaimNotificationService {
    public function __construct(
        private ?StudentAccountClaimInvitationRepository $claims=null,
        private ?StudentAccountClaimInvitationService $issuance=null,
        private ?NotificationService $notifications=null,
        private ?NotificationRepository $notificationRecords=null
    ){
        $this->claims??=new StudentAccountClaimInvitationRepository();
        $this->issuance??=new StudentAccountClaimInvitationService($this->claims);
        $this->notifications??=new NotificationService(null,null,null,null,null,null,new StudentNotificationRecipientReadModel(),new StudentAccountClaimNotificationSubjectReadModel());
        $this->notificationRecords??=new NotificationRepository();
    }

    /** Issue and observe the one claim notification.  The result never contains a raw claim code. */
    public function issue(int $studentId,int $ttl=604800,?string $command=null):array{
        if(!current_user_can('dzn_issue_student_account_claims'))throw new \RuntimeException('Access denied');
        $actor=(int)get_current_user_id();if($actor<1)throw new \RuntimeException('Authenticated issuer required');
        $command=trim((string)($command??'student-claim-issue:'.$studentId.':'.\Delnavazan\Platform\Core\Support\Identifier::uid()));
        if($command===''||strlen($command)>191)throw new \InvalidArgumentException('Invalid issuance command');
        $this->claims->begin();
        try{
            $issued=$this->issuance->issueInTransaction($studentId,$ttl,$command,$actor);
            $intentKey=hash_hmac('sha256','student_account_claim_intent:'.(int)$issued['generation_id'],wp_salt('dzn_student_account_claim'));
            if(!empty($issued['idempotent'])){
                $outbox=$this->claims->notificationIntentForKeyForUpdate($intentKey);
                if(!$outbox||$outbox->notification_id===null)throw new \RuntimeException('student_account_claim_notification_incomplete');
                $notification=$this->notificationRecords->find((int)$outbox->notification_id,true);
                if(!$notification)throw new \RuntimeException('student_account_claim_notification_incomplete');
                $this->claims->commit();
                return $this->replay($issued,$outbox,$notification);
            }
            $now=NotificationSupport::now();
            $outboxId=$this->claims->insertNotificationIntent((int)$issued['invitation_id'],$intentKey,$now);
            do_action('dzn_student_account_claim_notification_after_intent_insert',(int)$issued['generation_id'],$outboxId);
            // The only raw secret value is held in this local variable until #220 authenticates/encrypts it.
            $secret=(string)$issued['secret'];unset($issued['secret']);
            $observed=$this->notifications->observeTrustedIntentInTransaction($outboxId,array(
                'evidence_channel'=>'system','evidence_reference'=>'student-account-claim:'.hash('sha256',$command),'evidence_at'=>$now,
                'observed_at'=>$now,'template_parameters'=>array('claim_code'=>$secret),'variable_codes'=>array('claim_code'),
            ),'student-account-claim-observe:'.(int)$issued['generation_id']);
            unset($secret);
            do_action('dzn_student_account_claim_notification_after_snapshot_insert',(int)$issued['generation_id'],(int)$observed['notification_id']);
            $this->claims->commit();
            return array('idempotent'=>false,'invitation_id'=>(int)$issued['invitation_id'],'generation_id'=>(int)$issued['generation_id'],'outbox_id'=>$outboxId,'notification_id'=>(int)$observed['notification_id'],'state'=>(string)$observed['state'],'expires_at'=>(string)$issued['expires_at']);
        }catch(\Throwable $error){
            $this->claims->rollback();
            throw $error;
        }
    }
    private function replay(array $issued,object $outbox,object $notification):array{
        return array('idempotent'=>true,'invitation_id'=>(int)$issued['invitation_id'],'generation_id'=>(int)$issued['generation_id'],'outbox_id'=>(int)$outbox->id,'notification_id'=>(int)$notification->id,'state'=>(string)$notification->state,'expires_at'=>(string)($notification->expires_at??''));
    }
}
