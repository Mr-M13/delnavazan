<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceNotificationOutboxRepository;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;

/**
 * Durable Student finance notifications.
 *
 * The aggregate-based notification authority has no authoritative payment-receipt event or recipient
 * binding. This outbox therefore stores one idempotent, retryable intent per finance event and delivers
 * it through the existing branded Delnavazan email renderer. A send is recorded as transport accepted,
 * never as delivered. A notification failure never reverses the financial transaction that produced it.
 */
final class FinanceNotificationService {
    public const HOOK = 'dzn_finance_notification_dispatch';
    public const STATE_PENDING='pending', STATE_LEASED='leased', STATE_TRANSPORT_ACCEPTED='transport_accepted', STATE_RETRYABLE='retryable', STATE_FAILED='failed';
    public function __construct(private ?FinanceNotificationOutboxRepository $outbox=null){$this->outbox??=new FinanceNotificationOutboxRepository();}
    public static function register(): void {
        add_action('dzn_phase_2a2r1_after_settlement', array(__CLASS__, 'onSettlement'), 10, 1);
        add_action('dzn_phase_2a2r2_after_refund_review_event_insert', array(__CLASS__, 'onRefundEvent'), 10, 2);
        add_action(self::HOOK, array(__CLASS__, 'runDue'));
        add_action('init', array(__CLASS__, 'schedule'), 20);
    }
    public static function schedule(): void {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && !wp_next_scheduled(self::HOOK)) wp_schedule_event(time()+300,'hourly',self::HOOK);
    }
    public static function runDue(): void { try { (new self())->processDue(20); } catch (\Throwable) { /* durable row keeps the failure visible */ } }
    public static function onSettlement(int $purchaseId): void { try { (new self())->enqueueSettlement($purchaseId); } catch (\Throwable) { /* never reverse a committed payment */ } }
    public static function onRefundEvent(string $operation,int $caseId): void { try { (new self())->enqueueRefund($operation,$caseId); } catch (\Throwable) { /* never reverse a committed review */ } }

    public function enqueue(string $eventKey,string $kind,int $studentId,string $recipient,string $locale,string $subject,string $body): array {
        $eventKey=trim($eventKey);$recipient=trim($recipient);
        if($eventKey===''||$recipient===''||!is_email($recipient))return array('queued'=>false,'reason'=>'notification_recipient_unavailable');
        $existing=$this->outbox->byEventKey($eventKey);
        if($existing)return array('queued'=>true,'reason'=>'already_queued','intent_id'=>(int)$existing->id,'state'=>(string)$existing->state);
        $now=gmdate('Y-m-d H:i:s');$actor=max(0,(int)get_current_user_id());
        try{$id=$this->outbox->insert(array('uid'=>Identifier::uid(),'event_key'=>$eventKey,'kind'=>$kind,'student_id'=>$studentId,'recipient_email'=>$recipient,'locale'=>$locale,'subject'=>$subject,'body'=>$body,'state'=>self::STATE_PENDING,'attempt_count'=>0,'max_attempts'=>5,'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'transport_accepted_at'=>null,'delivered_at'=>null,'intent_version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor));}
        catch(\Throwable $error){$existing=$this->outbox->byEventKey($eventKey);if($existing)return array('queued'=>true,'reason'=>'already_queued','intent_id'=>(int)$existing->id,'state'=>(string)$existing->state);throw $error;}
        if(function_exists('wp_schedule_single_event'))wp_schedule_single_event(time()+60,self::HOOK);
        return array('queued'=>true,'reason'=>'queued','intent_id'=>$id,'state'=>self::STATE_PENDING);
    }

    public function processDue(int $limit=20): array {
        $limit=max(1,min(100,$limit));$processed=0;$accepted=0;$retryable=0;$failed=0;
        while($processed<$limit){$claim=$this->claimNext();if($claim===null)break;$processed++;$result=$this->processClaim($claim);if(!empty($result['transport_accepted']))$accepted++;elseif((string)($result['state']??'')===self::STATE_FAILED)$failed++;else$retryable++;}
        return array('processed'=>$processed,'transport_accepted'=>$accepted,'retryable'=>$retryable,'failed'=>$failed);
    }

    private function enqueueSettlement(int $purchaseId): void {
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT purchase.id purchase_id,offer_row.beneficiary_student_id student_id,offer_row.currency,
                    student.email,student.locale,COALESCE(NULLIF(course.name_en,''),course.name_fa,'Course') course_name,
                    settlement.uid settlement_uid,settlement.amount_minor,settlement.settled_at,obligation.obligation_sequence
             FROM {$p}commercial_purchases purchase
             INNER JOIN {$p}commercial_offers offer_row ON offer_row.id=purchase.offer_id
             INNER JOIN {$p}commercial_offer_obligations obligation ON obligation.offer_id=offer_row.id
             INNER JOIN {$p}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id
             LEFT JOIN {$p}students student ON student.id=offer_row.beneficiary_student_id
             LEFT JOIN {$p}courses course ON course.id=offer_row.course_id
             WHERE purchase.id=%d ORDER BY settlement.id DESC LIMIT 1",$purchaseId));
        if(!$row||trim((string)($row->email??''))==='')return;
        $amount=PricingMoney::format((int)$row->amount_minor,(string)$row->currency,true);
        $subject='پرداخت شما در دلنوازان ثبت شد';
        $body="پرداخت {$amount} برای «{$row->course_name}» ثبت شد.\n\n"
            ."تاریخ ثبت: {$row->settled_at}\nقسط: {$row->obligation_sequence}\n\n"
            ."رسید و سابقهٔ پرداخت در بخش «پرداخت» پرتال هنرجو در دسترس است.\n"
            ."در صورت نیاز به بررسی، با پشتیبانی دلنوازان تماس بگیرید.";
        $this->enqueue('payment_received:'.$row->purchase_id.':'.$row->settlement_uid,'payment_received',(int)$row->student_id,(string)$row->email,(string)($row->locale?:'fa'),$subject,$body);
    }

    private function enqueueRefund(string $operation,int $caseId): void {
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT review.id,review.amount_minor,review.currency,review.state,offer_row.beneficiary_student_id student_id,
                    student.email,student.locale,COALESCE(NULLIF(course.name_en,''),course.name_fa,'Course') course_name,offer_row.uid offer_uid
             FROM {$p}refund_review_cases review
             INNER JOIN {$p}commercial_purchases purchase ON purchase.id=review.purchase_id
             INNER JOIN {$p}commercial_offers offer_row ON offer_row.id=purchase.offer_id
             LEFT JOIN {$p}students student ON student.id=offer_row.beneficiary_student_id
             LEFT JOIN {$p}courses course ON course.id=offer_row.course_id
             WHERE review.id=%d",$caseId));
        if(!$row||trim((string)($row->email??''))==='')return;
        $amount=PricingMoney::format((int)$row->amount_minor,(string)$row->currency,true);
        $kind=match($operation){'record_refund_evidence'=>'refund_recorded','route_for_review'=>'refund_in_review','resolve'=>'refund_completed','dismiss'=>'refund_dismissed',default=>'refund_update'};
        $subject=match($kind){'refund_recorded'=>'درخواست بازپرداخت شما ثبت شد','refund_in_review'=>'درخواست بازپرداخت شما در حال بررسی است','refund_completed'=>'بازپرداخت شما تکمیل شد','refund_dismissed'=>'درخواست بازپرداخت شما رد شد',default=>'به‌روزرسانی بازپرداخت'};
        $body="وضعیت بازپرداخت {$amount} برای «{$row->course_name}»: {$subject}.\n\n"
            ."وضعیت ثبت‌شده: {$row->state}\n\n"
            ."این پیام فقط یک به‌روزرسانی وضعیت است. مبلغ بازپرداخت در سابقهٔ پرداخت پرتال هنرجو قابل مشاهده است.";
        $this->enqueue('refund:'.$operation.':'.$caseId,$kind,(int)$row->student_id,(string)$row->email,(string)($row->locale?:'fa'),$subject,$body);
    }

    private function claimNext(): ?array {
        $this->outbox->begin();
        try{$now=gmdate('Y-m-d H:i:s');$candidates=$this->outbox->claimable($now,1);if($candidates===array()){$this->outbox->commit();return null;}$row=$this->outbox->find((int)$candidates[0]->id,true);if(!$row){$this->outbox->commit();return null;}$state=(string)$row->state;$expired=$state===self::STATE_LEASED&&$row->lease_expires_at!==null&&(string)$row->lease_expires_at<=$now;if(!in_array($state,array(self::STATE_PENDING,self::STATE_RETRYABLE),true)&&!$expired){$this->outbox->commit();return null;}$actor=(int)$row->created_by;$token=Identifier::uid();$digest=hash('sha256','finance-notification-lease:'.$token);$attempt=(int)$row->attempt_count+1;$this->outbox->update((int)$row->id,(int)$row->intent_version,array('state'=>self::STATE_LEASED,'attempt_count'=>$attempt,'lease_token_digest'=>$digest,'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+300),'updated_at'=>$now,'updated_by'=>$actor));$this->outbox->commit();return array('row'=>$this->outbox->find((int)$row->id),'token'=>$digest);}catch(\Throwable $e){$this->outbox->rollback();throw $e;}
    }

    private function processClaim(array $claim): array {
        $row=$claim['row'];$now=gmdate('Y-m-d H:i:s');$accepted=false;
        try{$accepted=DelnavazanEmailRenderer::send((string)$row->recipient_email,(string)$row->subject,(string)$row->body,array('locale'=>(string)$row->locale,'footer_note'=>'این پیام دربارهٔ وضعیت مالی حساب شما در دلنوازان است.'));}catch(\Throwable){$accepted=false;}
        $this->outbox->begin();
        try{$fresh=$this->outbox->find((int)$row->id,true);if(!$fresh)throw new \RuntimeException('finance_notification_required');$attemptCount=(int)$fresh->attempt_count;$max=(int)$fresh->max_attempts;$actor=(int)$fresh->created_by;if($accepted){$this->outbox->update((int)$fresh->id,(int)$fresh->intent_version,array('state'=>self::STATE_TRANSPORT_ACCEPTED,'transport_accepted_at'=>$now,'delivered_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'next_attempt_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor));$state=self::STATE_TRANSPORT_ACCEPTED;}elseif($attemptCount>=$max){$this->outbox->update((int)$fresh->id,(int)$fresh->intent_version,array('state'=>self::STATE_FAILED,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'finance_notification_transport_failed','next_attempt_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor));$state=self::STATE_FAILED;}else{$delay=min(3600,300*(2**max(0,$attemptCount-1)));$this->outbox->update((int)$fresh->id,(int)$fresh->intent_version,array('state'=>self::STATE_RETRYABLE,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'finance_notification_transport_failed','next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'updated_at'=>$now,'updated_by'=>$actor));$state=self::STATE_RETRYABLE;}$this->outbox->commit();}catch(\Throwable $e){$this->outbox->rollback();throw $e;}
        return array('state'=>$state,'transport_accepted'=>$accepted);
    }
}
