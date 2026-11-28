<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * The single Delnavazan notification definition catalogue.
 *
 * One entry describes one business event for one audience: who produces it, who receives it, which
 * channels are permitted, which variables the message may use, which delivery authority owns it and
 * what the reviewed Persian and English copy is. The catalogue is intentionally transport-neutral and
 * WordPress-free so it can be read by the dashboard, the template library, the copy resolver and the
 * withdrawal-free contract tests.
 *
 * It does not replace the canonical Phase 2A.2-S template/workflow authority: `authority => canonical`
 * entries map onto that model, while `dedicated` entries stay with the class that owns their one-time
 * secret or operational queue. The catalogue only describes them; it never sends.
 *
 * Editorial rules for every default below:
 * - Persian is the primary voice and «درود» replaces «سلام».
 * - English is a faithful translation with the same meaning, placeholders and instructions.
 * - Customer copy never contains event keys, internal codes or review notes.
 * - Every supported event/channel/language pair carries its own copy; nothing is shared generically.
 * - `review` records the approvals that are still outstanding. It is administration metadata and is
 *   never rendered into a message.
 */
final class NotificationCatalogue {
    public const VERSION='2026-11-19.3';
    public const PRIMARY_LOCALE='fa-IR';
    public const SUPPORTED_LOCALES=array('fa-IR','en');

    /**
     * Every notification exposes every supported delivery method. The administrator decides which
     * methods are on for an event; a method is never removed from the workspace because a copy or a
     * producer is not ready yet. Readiness reports what each route still needs, and the copy is
     * editable before the route is enabled.
     */
    public const CHANNELS=array('website','email','whatsapp','sms');

    /**
     * Delivery truth for the mobile methods: the event keys whose producer actually calls a mobile
     * transport today, and which mobile channels it calls. The catalogue deliberately offers every
     * method on every event for future use, but an administrator must never be told a method is
     * "ready" when no code can send it. Security/account one-time-code events are intentionally absent.
     *
     * Keep this list in step with the producers; the notification management contract proves every
     * mapped event exists and no security event is included.
     *
     * @var array<string,string[]>
     */
    public const MOBILE_PRODUCER_CHANNELS=array(
        'booking.requested.student'=>array('whatsapp'),
        'booking.submitted.admin'=>array('whatsapp'),
        'booking.confirmed.student'=>array('whatsapp'),
        'booking.declined.student'=>array('whatsapp'),
        'booking.cancelled.student'=>array('whatsapp'),
        'booking.cancelled.teacher'=>array('sms'),
        'booking.new.teacher'=>array('sms'),
        'booking.hold.teacher'=>array('sms'),
        'booking.payment_lapsed.teacher'=>array('sms'),
        'booking.rescheduled.student'=>array('whatsapp'),
        'booking.rescheduled.teacher'=>array('sms'),
        'booking.payment_lapsed.student'=>array('whatsapp'),
        'booking.payment_conflict.student'=>array('whatsapp'),
        'booking.terminal.student'=>array('whatsapp'),
        'post_intro.activated.student'=>array('whatsapp'),
        'post_intro.survey.student'=>array('whatsapp'),
        'post_intro.invitation.student'=>array('whatsapp'),
        'lesson.access.student'=>array('whatsapp'),
        'lesson.reminder.student'=>array('whatsapp'),
        'lesson.cancelled.student'=>array('whatsapp'),
        'reschedule.change.proposed.student'=>array('whatsapp'),
        'reschedule.change.accepted.student'=>array('whatsapp'),
        'reschedule.change.rejected.student'=>array('whatsapp'),
        'reschedule.change.failed.student'=>array('whatsapp'),
        'reschedule.change.cancelled.student'=>array('whatsapp'),
        'reschedule.replacement.proposed.student'=>array('whatsapp'),
        'reschedule.replacement.accepted.student'=>array('whatsapp'),
        'reschedule.replacement.rejected.student'=>array('whatsapp'),
        'reschedule.replacement.failed.student'=>array('whatsapp'),
        'reschedule.replacement.cancelled.student'=>array('whatsapp'),
        'reschedule.change.proposed.teacher'=>array('sms'),
        'reschedule.change.accepted.teacher'=>array('sms'),
        'reschedule.change.rejected.teacher'=>array('sms'),
        'reschedule.change.failed.teacher'=>array('sms'),
        'reschedule.change.cancelled.teacher'=>array('sms'),
        'reschedule.replacement.proposed.teacher'=>array('sms'),
        'reschedule.replacement.accepted.teacher'=>array('sms'),
        'reschedule.replacement.rejected.teacher'=>array('sms'),
        'reschedule.replacement.failed.teacher'=>array('sms'),
        'reschedule.replacement.cancelled.teacher'=>array('sms'),
        'attendance.absence_reported.teacher'=>array('sms'),
        'attendance.missed_class_claim.teacher'=>array('sms'),
        'booking.expired.teacher'=>array('sms'),
        'lesson.access.teacher'=>array('sms'),
        'lesson.reminder.teacher'=>array('sms'),
        'lesson.cancelled.teacher'=>array('sms'),
        'renewal.automatic_upcoming.student'=>array('whatsapp'),
        'renewal.automatic_charged.student'=>array('whatsapp'),
        'renewal.automatic_failed.student'=>array('whatsapp'),
        'renewal.guarantee_deadline_approaching.student'=>array('whatsapp'),
        'renewal.manual_payment_required.student'=>array('whatsapp'),
        'renewal.term_lapsed.student'=>array('whatsapp'),
        'renewal.reminder.student'=>array('whatsapp'),
        'payment.failed.student'=>array('whatsapp'),
        'payment.recovered.student'=>array('whatsapp'),
        'refund.review_required.student'=>array('whatsapp'),
        'refund.resolved.student'=>array('whatsapp'),
    );

    /** @return string[] The mobile channels a real producer can send for this event. */
    public static function mobileProducerChannels(string $eventKey):array{
        return array_values((array)(self::MOBILE_PRODUCER_CHANNELS[$eventKey]??array()));
    }

    /** Whether a real producer sends this mobile method for this event today. */
    public static function producesMobileChannel(string $eventKey,string $channel):bool{
        return in_array($channel,self::mobileProducerChannels($eventKey),true);
    }

    /** One-time secrets that a derived copy must never carry into another channel. */
    private const SECRET_VARIABLES=array('claim_code','claim_url','invitation_code');

    /** Notification categories, their editorial tone and their channel policy class. */
    public const CATEGORIES=array(
        'security'=>array('label'=>'Security & Access','description'=>'Security and one-time messages; administrator-controlled but never marketing.','policy'=>'security'),
        'account'=>array('label'=>'Account','description'=>'Essential account messages a student or teacher needs in order to continue.','policy'=>'essential'),
        'booking'=>array('label'=>'Intro Lessons','description'=>'Intro lesson requests, confirmations, declines and enrolment follow-up.','policy'=>'operational'),
        'lesson'=>array('label'=>'Classes & Sessions','description'=>'Lesson changes, replacement sessions, absence and operational class notices.','policy'=>'operational'),
        'payment'=>array('label'=>'Payments & Renewals','description'=>'Financial and renewal messages; editorially approved like any other operational message.','policy'=>'financial'),
        'optional'=>array('label'=>'Optional Announcements','description'=>'Non-essential news and offers; marketing consent only.','policy'=>'marketing'),
    );

    /** Task groups organise the administration workspace; categories remain the stable consent and channel-policy boundary. */
    public const TASKS=array(
        'enrolment_booking'=>array('label'=>'Enrolment & Booking','description'=>'Intro lessons, offers, enrolment preparation and booking coordination.'),
        'classes_scheduling'=>array('label'=>'Classes & Scheduling','description'=>'Lessons, attendance, replacement sessions and timetable changes.'),
        'finance_payments'=>array('label'=>'Finance & Payments','description'=>'Payment outcomes, invoices and payment actions.'),
        'renewals'=>array('label'=>'Renewals','description'=>'Upcoming, completed and failed term renewals.'),
        'accounts_onboarding'=>array('label'=>'Accounts & Onboarding','description'=>'Account access, invitations, consent and new-account setup.'),
        'support'=>array('label'=>'Support','description'=>'Member help and non-routine assistance.'),
        'system'=>array('label'=>'System','description'=>'Internal checks and operational administration notices.'),
    );

    /** Delivery authorities. A message is never re-sent by a second authority. */
    public const AUTHORITIES=array(
        'canonical'=>'Canonical notification service (S)',
        'queue'=>'Event-owned delivery queue',
        'dedicated'=>'Dedicated secure delivery (one-time secret)',
    );

    /** Implementation truth: implemented is not the same as configured or provider-verified. */
    public const IMPLEMENTATION=array(
        'implemented'=>'Implemented',
        'declared'=>'Declared; no active producer',
        'partial'=>'Partial',
    );

    /** Outstanding approvals that keep a template review-only. Administration metadata, never copy. */
    public const REVIEW=array(
        'security'=>'Security review required',
        'dedicated_authority'=>'Owned by a dedicated secure authority',
        'provider_approval'=>'Provider template approval required',
    );

    /** @return array<string,array> */
    public static function events():array{
        static $events=null;
        if($events!==null)return $events;
        $events=array();
        foreach(self::studentEvents() as $event)$events[$event['key']]=$event;
        foreach(self::teacherEvents() as $event)$events[$event['key']]=$event;
        foreach(self::adminEvents() as $event)$events[$event['key']]=$event;
        foreach(self::renewalAutomationEvents() as $event)$events[$event['key']]=$event;
        foreach(self::paymentRefundEvents() as $event)$events[$event['key']]=$event;
        foreach(self::consentEvents() as $event)$events[$event['key']]=$event;
        ksort($events,SORT_STRING);
        return $events;
    }

    public static function get(string $key):?array{return self::events()[$key]??null;}
    /** @return string[] */
    public static function keys():array{return array_keys(self::events());}
    /** @return string[] */
    public static function variables(string $key):array{return array_values((array)(self::get($key)['variables']??array()));}
    public static function category(string $category):?array{return self::CATEGORIES[$category]??null;}
    public static function task(string $task):?array{return self::TASKS[$task]??null;}
    /** Stable business-work grouping for the administration projection; never a send policy. */
    public static function taskKey(string $eventKey):string{
        if(str_starts_with($eventKey,'booking.')||str_starts_with($eventKey,'post_intro.'))return 'enrolment_booking';
        if(str_starts_with($eventKey,'lesson.')||str_starts_with($eventKey,'attendance.')||str_starts_with($eventKey,'reschedule.'))return 'classes_scheduling';
        if(str_starts_with($eventKey,'renewal.'))return 'renewals';
        if(str_starts_with($eventKey,'payment.'))return 'finance_payments';
        if(str_starts_with($eventKey,'account.')||str_starts_with($eventKey,'teacher.invitation_')||str_starts_with($eventKey,'consent.'))return 'accounts_onboarding';
        if(str_starts_with($eventKey,'system.'))return 'system';
        return 'support';
    }
    public static function taskForEvent(string $eventKey):?array{return self::task(self::taskKey($eventKey));}
    public static function templateKey(string $eventKey,string $channel,string $locale):string{
        $key=$eventKey.'.'.$channel.'.'.self::normaliseLocale($locale);
        if(strlen($key)>64)throw new \InvalidArgumentException('notification_template_key_too_long');
        return $key;
    }
    public static function normaliseLocale(string $locale):string{
        $locale=trim($locale);
        if($locale===''||$locale==='fa'||$locale==='fa_IR'||$locale==='fa-ir'||str_starts_with(strtolower($locale),'fa'))return self::PRIMARY_LOCALE;
        if(str_starts_with(strtolower($locale),'en'))return 'en';
        return self::PRIMARY_LOCALE;
    }
    /** @return array{subject:string,body:string}|null */
    public static function defaultCopy(string $eventKey,string $channel,string $locale):?array{
        $event=self::get($eventKey);
        if($event===null)return null;
        $locale=self::normaliseLocale($locale);
        $defaults=(array)($event['defaults']??array());
        if(isset($defaults[$locale][$channel]))return (array)$defaults[$locale][$channel];
        if(isset($defaults[self::PRIMARY_LOCALE][$channel]))return (array)$defaults[self::PRIMARY_LOCALE][$channel];
        return self::derivedCopy($eventKey,$channel,$locale);
    }

    /**
     * The copy authored in exactly this language, or null. The administration workflow uses this rather
     * than `defaultCopy` so a missing English translation is reported instead of silently rendering the
     * Persian wording to an English recipient.
     *
     * An event that does not carry an explicit default for a method still gets a derived, editable
     * default from its other authored copy in the same language. The derivation never invents facts:
     * it reuses the event's own copy, keeps the required placeholders and never carries a one-time
     * secret into another channel.
     *
     * @return array{subject:string,body:string}|null
     */
    public static function exactCopy(string $eventKey,string $channel,string $locale):?array{
        $event=self::get($eventKey);
        if($event===null)return null;
        $locale=self::normaliseLocale($locale);
        $copy=$event['defaults'][$locale][$channel]??null;
        if(is_array($copy))return (array)$copy;
        return self::derivedCopy($eventKey,$channel,$locale);
    }

    /**
     * Build a usable default for a method the event has no authored copy for. The source is the
     * event's own longest-form copy in the same language, in an order that keeps the derived message
     * short enough for the target channel. A security/dedicated event is stripped of every one-time
     * secret first, so a portal, WhatsApp or SMS copy can never expose the claim code or claim link.
     *
     * @return array{subject:string,body:string}|null
     */
    private static function derivedCopy(string $eventKey,string $channel,string $locale):?array{
        $event=self::get($eventKey);
        if($event===null)return null;
        $locale=self::normaliseLocale($locale);
        $defaults=(array)($event['defaults']??array());
        $sourceChannel=self::derivationSource($defaults,$locale,$channel);
        if($sourceChannel===null)return null;
        $source=(array)$defaults[$locale][$sourceChannel];
        $body=trim((string)($source['body']??''));
        if($body==='')return null;
        $subject=trim((string)($source['subject']??''));
        if($subject==='')$subject=(string)($event['label']??$eventKey);
        $secure=(string)($event['authority']??'')==='dedicated'||(string)($event['consent']??'')==='security'||$eventKey==='account.claim_issued.student';
        if($secure)$body=self::withoutSecrets($body);
        $variables=(array)($event['variables']??array());
        $link=self::derivedLinkVariable($event,$secure);
        if($channel==='website'){
            // The inbox row is a short summary, not a copy of the email. Take the first paragraph,
            // drop a greeting/signature if the source was the email, and keep one safe destination.
            $summary=trim((string)preg_replace('/\s+/u',' ',$body));
            $summary=(string)preg_replace('/^(درود[^،]*،|Hello[^,]*,\s*)/u','',$summary);
            $summary=(string)preg_replace('/(با احترام، دلنوازان|Delnavazan)$/u','',$summary);
            $summary=trim($summary);
            if(mb_strlen($summary)>300)$summary=mb_substr($summary,0,300);
            $body=$summary;
            if($link!==null&&!str_contains($body,'{{'.$link.'}}'))$body.=' '.($locale==='en'?'Details':'جزئیات').': {{'.$link.'}}';
            return array('subject'=>mb_substr($subject,0,60),'body'=>$body);
        }
        if($channel==='sms'){
            // SMS is a short notification, never the email body. Use the state-specific opening of
            // the event's own copy (placeholders removed), then re-add only the required time and one
            // safe destination, so the message stays inside two UCS-2 segments and never collides
            // with a sibling state's SMS.
            $excerpt=trim((string)preg_replace('/\s+/u',' ',(string)preg_replace('/\{\{[^}]*\}\}/','',$body)));
            $parts=array(mb_substr($excerpt,0,80));
            if(in_array('when',$variables,true))$parts[]='{{when}}';
            if($link!==null)$parts[]='{{'.$link.'}}';
            return array('body'=>($locale==='en'?'Delnavazan: ':'دلنوازان: ').implode(' — ',array_filter($parts)));
        }
        if($channel==='whatsapp'){
            $body=trim((string)preg_replace('/\s+/u',' ',$body));
            if(mb_strlen($body)>600)$body=mb_substr($body,0,600);
            if($link!==null&&!str_contains($body,'{{'.$link.'}}'))$body.=' '.($locale==='en'?'Details':'جزئیات').': {{'.$link.'}}';
            return array('body'=>($locale==='en'?'Hello, ':'درود، ').$body);
        }
        if($channel==='email'){
            if($locale==='en'){
                if(preg_match('/^\s*Hello\b/i',$body)!==1)$body="Hello,\n\n".$body;
                $body.="\n\nDelnavazan";
            }else{
                if(str_starts_with(ltrim($body),'درود')!==true)$body="درود،\n\n".$body;
                $body.="\n\nبا احترام، دلنوازان";
            }
            if($link!==null&&!str_contains($body,'{{'.$link.'}}'))$body.=($locale==='en'?"\n\nDetails:\n": "\n\nجزئیات:\n").'{{'.$link.'}}';
        }
        if($channel==='email')$subject=mb_substr($subject,0,60);
        return array('subject'=>$subject,'body'=>$body);
    }

    /** @param array<string,array<string,array<string,string>>> $defaults */
    private static function derivationSource(array $defaults,string $locale,string $channel):?string{
        $order=match($channel){
            'website'=>array('email','whatsapp','sms'),
            'email'=>array('website','whatsapp','sms'),
            'whatsapp'=>array('website','sms','email'),
            'sms'=>array('website','whatsapp','email'),
            default=>array(),
        };
        foreach($order as $candidate){
            $copy=$defaults[$locale][$candidate]??null;
            if(is_array($copy)&&trim((string)($copy['body']??''))!=='')return $candidate;
        }
        return null;
    }

    /** The one destination a derived message may safely use; never a one-time claim link. */
    private static function derivedLinkVariable(array $event,bool $secure):?string{
        $variables=(array)($event['variables']??array());
        $order=$secure?array('portal_url','teacher_portal_url','payment_url','survey_url','join_url'):array('portal_url','teacher_portal_url','payment_url','survey_url','join_url','claim_url');
        foreach($order as $code)if(in_array($code,$variables,true))return $code;
        return null;
    }

    private static function withoutSecrets(string $body):string{
        foreach(self::SECRET_VARIABLES as $variable){
            $body=str_replace('{{'.$variable.'}}','',$body);
        }
        return trim((string)preg_replace('/[ \t]+\n/u',"\n",$body));
    }

    /**
     * The approvals still outstanding for an event. Empty means the copy is publishable through the
     * normal workflow; a non-empty list keeps it review-only and is shown to the administrator.
     *
     * @return string[]
     */
    public static function reviewFor(string $eventKey):array{
        $event=self::get($eventKey);
        return is_array($event['review']??null)?array_values($event['review']):array();
    }

    /** @return string[] Human-readable review reasons for one event. */
    public static function reviewReasons(string $eventKey):array{
        $out=array();
        foreach(self::reviewFor($eventKey) as $code)$out[]=(string)(self::REVIEW[$code]??$code);
        return $out;
    }

    /**
     * Coverage of the authored defaults. Pure catalogue maths: it proves every supported
     * event/channel/language pair has its own copy and never counts a Persian fallback as English.
     *
     * @return array{events:int,channel_pairs:int,required:int,present:int,missing:array<int,array>}
     */
    public static function coverage():array{
        $events=0;$pairs=0;$required=0;$present=0;$missing=array();
        foreach(self::events() as $eventKey=>$event){
            $events++;
            foreach((array)$event['channels'] as $channel){
                $pairs++;
                foreach(self::SUPPORTED_LOCALES as $locale){
                    $required++;
                    $copy=self::exactCopy($eventKey,(string)$channel,$locale);
                    if(is_array($copy)&&trim((string)($copy['body']??''))!==''){$present++;continue;}
                    $missing[]=array('event_key'=>$eventKey,'channel'=>(string)$channel,'locale'=>$locale,'template_key'=>self::templateKey($eventKey,(string)$channel,$locale));
                }
            }
        }
        return array('events'=>$events,'channel_pairs'=>$pairs,'required'=>$required,'present'=>$present,'missing'=>$missing);
    }

    /** The example values the dashboard previews with; never real recipient data. */
    public static function previewVariables(string $eventKey):array{
        $event=self::get($eventKey);
        $examples=array(
            'name'=>'باران','student'=>'باران','teacher'=>'استاد ندا','course'=>'تار','when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰',
            'timezone'=>'Asia/Tehran','join_url'=>'https://meet.example.invalid/abc-defg-hij','portal_url'=>'https://example.invalid/student-portal/',
            'teacher_portal_url'=>'https://example.invalid/teacher-portal/','claim_url'=>'https://example.invalid/claim/','claim_code'=>'[one-time code]',
            'invitation_code'=>'[one-time code]','payment_url'=>'https://example.invalid/pay/','amount'=>'۲٬۵۰۰٬۰۰۰ تومان','due_date'=>'۱۵ آبان ۱۴۰۵',
            'survey_url'=>'https://example.invalid/student-portal/?post_intro_survey=1#dzn-post-intro-survey',
            'hold_status_note'=>'مهلت نگه‌داشتن این جایگاه در حال پایان است.',
            'paid_date'=>'۱۲ آبان ۱۴۰۵','previous_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰','new_when'=>'یکشنبه ۱۱ آبان ۱۴۰۵، ساعت ۱۸:۰۰','current_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰',
            'term'=>'ترم پاییز ۱۴۰۵','reason'=>'پرداخت ناموفق بود',
            'remaining_sessions'=>'۴','term_end_date'=>'۱۵ آذر ۱۴۰۵',
            'instalment_note'=>'ترم جاری شما دو قسطی است و ۲ از ۲ قسط پرداخت شده است. ترم بعد را می‌توانید یکجا یا در دو قسط پرداخت کنید.',
            'response_window'=>'۶ ساعت','response_deadline'=>'پنجشنبه ۶ آبان ۱۴۰۵، ساعت ۱۷:۰۰',
        );
        $out=array();
        foreach(self::variables($eventKey) as $variable)$out[$variable]=$examples[$variable]??'['.$variable.']';
        return $out;
    }
    /** Synthetic data must never be mistaken for a real message. */
    public static function previewNotice(string $locale=self::PRIMARY_LOCALE):string{
        if(self::normaliseLocale($locale)==='en')return 'Preview with synthetic data — no message is sent.';
        return 'پیش‌نمایش با داده‌های ساختگی — هیچ پیامی ارسال نمی‌شود.';
    }
    /**
     * Sensitive classification. This is a presentation and review signal only: every notification,
     * including security, account and payment notices, remains administrator-controlled.
     */
    public static function isMandatory(string $eventKey):bool{
        $event=self::get($eventKey);
        if($event===null)return true;
        $category=(string)($event['category']??'');
        return in_array($category,array('security','account','payment'),true)||!empty($event['mandatory_override']);
    }
    public static function categoryPolicy(string $eventKey):string{
        $event=self::get($eventKey);
        return (string)(self::CATEGORIES[(string)($event['category']??'')]['policy']??'operational');
    }

    // ------------------------------------------------------------------ student events

    private static function studentEvents():array{
        $out=array();
        $out[]=self::event('booking.confirmed.student','Intro Lesson Confirmed','booking','student','BookingCalendarProjectionService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','when','portal_url'),'booking.confirmed',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس آشنایی شما تأیید شد','body'=>"درود {{name}}،\n\nکلاس آشنایی «{{course}}» با {{teacher}} تأیید شد.\n\nزمان کلاس: {{when}}\n\nاین کلاس در محیط کلاس آنلاین دلنوازان برگزار می‌شود. کمی پیش از زمان کلاس وارد حساب کاربری خود شوید و کلاس را از همان‌جا آغاز کنید. دسترسی کلاس را در اختیار دیگران قرار ندهید.\n\nبرای دیدن برنامهٔ کلاس‌ها وارد حساب کاربری خود شوید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آشنایی تأیید شد','body'=>'کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} برگزار می‌شود.'),
                'sms'=>array('body'=>'دلنوازان: کلاس آشنایی {{course}} با {{teacher}} در {{when}} تأیید شد. حساب: {{portal_url}}'),
                'whatsapp'=>array('body'=>'درود {{name}}، کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} تأیید شد. کلاس در محیط کلاس آنلاین دلنوازان برگزار می‌شود — حساب کاربری: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your orientation class is confirmed','body'=>"Hello {{name}},\n\nYour orientation {{course}} class with {{teacher}} is confirmed.\n\nTime: {{when}}\n\nThis class takes place in the Delnavazan online classroom. Open your account a few minutes early and start the class from there. Please keep class access private.\n\nOpen your account to see your class schedule:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Orientation class confirmed','body'=>'Your orientation {{course}} class with {{teacher}} is scheduled for {{when}}.'),
                'sms'=>array('body'=>'Delnavazan: orientation {{course}} with {{teacher}} on {{when}} is confirmed. Account: {{portal_url}}'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your orientation {{course}} class with {{teacher}} is confirmed for {{when}}. The class takes place in the Delnavazan online classroom — account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.declined.student','Intro Lesson Declined','booking','student','BookingTeacherResponseService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','portal_url'),'booking.declined',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست کلاس آشنایی پذیرفته نشد','body'=>"درود {{name}}،\n\nمتأسفانه {{teacher}} در این زمان نمی‌تواند کلاس آشنایی «{{course}}» را بپذیرد. درخواست شما تأیید نشد و هیچ رویداد تقویم یا لینک جلسه‌ای ساخته نشد.\n\nمی‌توانید زمان دیگری را در حساب کاربری انتخاب کنید:\n{{portal_url}}\n\nاگر نیاز به کمک دارید، از راه‌های ارتباطی حساب کاربری با ما در تماس باشید.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'درخواست کلاس آشنایی پذیرفته نشد','body'=>'{{teacher}} در این زمان نمی‌تواند کلاس «{{course}}» را بپذیرد. می‌توانید زمان تازه‌ای انتخاب کنید.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، متأسفانه {{teacher}} در این زمان نمی‌تواند کلاس آشنایی «{{course}}» را بپذیرد. می‌توانید از حساب کاربری زمان تازه‌ای انتخاب کنید: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory lesson request update','body'=>"Hello {{name}},\n\n{{teacher}} is unable to accept your introductory {{course}} lesson at this time. The request was not confirmed and no calendar event or meeting link was created.\n\nChoose another time in your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson request not accepted','body'=>'{{teacher}} is unable to accept the {{course}} lesson at this time. You can choose another time.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, unfortunately {{teacher}} cannot accept your introductory {{course}} lesson at this time. Choose another time in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.payment_lapsed.student','Intro Payment Deadline Lapsed','booking','student','IntroPaymentExpiryWorker','queue',array('email','website'),'operational',true,array('name','teacher','course','when','portal_url'),'booking.payment_lapsed.student',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · مهلت پرداخت کلاس آشنایی گذشت','body'=>"درود {{name}}،\n\nمهلت پرداخت هزینهٔ کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} به پایان رسید. چون پرداخت در این بازه ثبت نشد، این جایگاه آزاد شد و کلاس ساخته نشد.\n\nاگر همچنان می‌خواهید کلاس آشنایی را داشته باشید، از حساب کاربری زمان تازه‌ای انتخاب کنید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'مهلت پرداخت کلاس آشنایی گذشت','body'=>'مهلت پرداخت کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} گذشت و جایگاه آزاد شد. برای انتخاب زمان تازه به حساب کاربری بروید.'),
                'sms'=>array('body'=>'دلنوازان: مهلت پرداخت کلاس {{course}} با {{teacher}} برای {{when}} گذشت و جایگاه آزاد شد. {{portal_url}}'),
                'whatsapp'=>array('body'=>'درود {{name}}، مهلت پرداخت کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} گذشت و جایگاه آزاد شد. اگر همچنان می‌خواهید، از حساب کاربری زمان تازه انتخاب کنید: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory payment deadline passed','body'=>"Hello {{name}},\n\nThe payment deadline for your introductory {{course}} lesson with {{teacher}} at {{when}} has passed. No payment was recorded, so the place was released and no class was created.\n\nIf you still want the introductory lesson, choose a new time in your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory payment deadline passed','body'=>'The payment deadline for your {{course}} lesson with {{teacher}} at {{when}} passed and the place was released. Choose a new time in your account.'),
                'sms'=>array('body'=>'Delnavazan: payment deadline for {{course}} with {{teacher}} at {{when}} passed; the place was released. {{portal_url}}'),
                'whatsapp'=>array('body'=>'Hello {{name}}, the payment deadline for your introductory {{course}} lesson with {{teacher}} at {{when}} has passed and the place was released. Choose a new time in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.payment_conflict.student','Intro Payment Slot Conflict','booking','student','IntroPaymentService','queue',array('email','website'),'operational',true,array('name','teacher','course','when','portal_url'),'booking.payment_conflict.student',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · پرداخت ثبت شد؛ زمان در حال هماهنگی است','body'=>"درود {{name}}،\n\nپرداخت شما برای کلاس آشنایی «{{course}}» با {{teacher}} با موفقیت ثبت شد؛ اما پیش از نهایی‌شدن پرداخت، زمان {{when}} توسط رزرو دیگری گرفته شد.\n\nپرداخت شما ایمن است و به‌عنوان اعتبار نزد دلنوازان نگه داشته می‌شود. آموزشگاه برای هماهنگی زمان تازه با شما تماس می‌گیرد و نیازی به پرداخت دوباره نیست.\n\nجزئیات در حساب کاربری:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'پرداخت ثبت شد؛ زمان کلاس در حال هماهنگی است','body'=>'پرداخت شما ثبت شد اما زمان کلاس {{when}} پیش از نهایی‌شدن پرداخت گرفته شد. پرداخت شما ایمن است؛ آموزشگاه برای تعیین زمان تازه با شما تماس می‌گیرد.'),
                'sms'=>array('body'=>'دلنوازان: پرداخت {{course}} با {{teacher}} برای {{when}} ثبت شد اما زمان گرفته شد؛ مبلغ اعتبار است. {{portal_url}}'),
                'whatsapp'=>array('body'=>'درود {{name}}، پرداخت کلاس آشنایی «{{course}}» با {{teacher}} ثبت شد اما زمان {{when}} پیش از نهایی‌شدن پرداخت گرفته شد. پرداخت شما ایمن است و آموزشگاه برای زمان تازه تماس می‌گیرد: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · payment received; time being rearranged','body'=>"Hello {{name}},\n\nYour payment for the introductory {{course}} lesson with {{teacher}} was received, but the time {{when}} was taken by another booking before the payment settled.\n\nYour payment is safe and held as credit with Delnavazan. The academy will contact you to arrange a new time; you do not need to pay again.\n\nDetails in your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Payment received; class time being rearranged','body'=>'Your payment was received, but the class time {{when}} was taken before the payment settled. Your payment is safe; the academy will contact you to arrange a new time.'),
                'sms'=>array('body'=>'Delnavazan: {{course}} with {{teacher}} at {{when}} paid, but the time was taken; amount held as credit. {{portal_url}}'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your payment for the introductory {{course}} lesson with {{teacher}} at {{when}} was received, but the time was taken before it settled. Your payment is safe as credit and the academy will contact you: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.cancelled.student','Intro Lesson Cancelled','booking','student','IntroLessonLifecycleService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','when','portal_url'),'booking.cancelled',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس آشنایی لغو شد','body'=>"درود {{name}}،\n\nکلاس آشنایی «{{course}}» با {{teacher}} در {{when}} لغو شد. این لغو به معنی ثبت حضور یا پرداخت نیست.\n\nجزئیات در حساب کاربری:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آشنایی لغو شد','body'=>'کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} لغو شد.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} لغو شد. جزئیات در حساب کاربری شماست: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory lesson cancelled','body'=>"Hello {{name}},\n\nYour introductory {{course}} lesson with {{teacher}} for {{when}} has been cancelled. No attendance or payment decision was inferred.\n\nOpen your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson cancelled','body'=>'Your {{course}} lesson with {{teacher}} for {{when}} was cancelled.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your introductory {{course}} lesson with {{teacher}} for {{when}} has been cancelled. Details in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.rescheduled.student','Intro Lesson Rescheduled','booking','student','IntroLessonLifecycleService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','when','portal_url'),'booking.rescheduled',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · زمان کلاس آشنایی تغییر کرد','body'=>"درود {{name}}،\n\nزمان کلاس آشنایی «{{course}}» با {{teacher}} به {{when}} تغییر کرد.\n\nجزئیات در حساب کاربری:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'زمان کلاس آشنایی تغییر کرد','body'=>'زمان کلاس آشنایی «{{course}}» با {{teacher}} به {{when}} تغییر کرد.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، زمان کلاس آشنایی «{{course}}» با {{teacher}} به {{when}} تغییر کرد. جزئیات در حساب کاربری شماست: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory lesson rescheduled','body'=>"Hello {{name}},\n\nYour introductory {{course}} lesson with {{teacher}} has been rescheduled to {{when}}.\n\nOpen your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson rescheduled','body'=>'Your {{course}} lesson with {{teacher}} is now at {{when}}.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your introductory {{course}} lesson with {{teacher}} has been rescheduled to {{when}}. Details in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('post_intro.invitation.student','Enrolment Ready','booking','student','PostIntroPreparationService','queue',array('email','website'),'operational',true,array('name','teacher','course','portal_url'),'post_intro.invitation',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · ثبت‌نام شما آمادهٔ تکمیل است','body'=>"درود {{name}}،\n\nبرگزاری کلاس آشنایی «{{course}}» با {{teacher}} ثبت شد. اکنون می‌توانید با همان مدرس، ثبت‌نام خود را تکمیل کنید.\n\nاین جایگاه فقط حدود یک هفته برای شما نگه داشته می‌شود. اگر ثبت‌نام و پرداخت تکمیل نشود، جایگاه آزاد می‌شود و در دسترس هنرجویان دیگر قرار می‌گیرد.\n\nبرای ادامهٔ ثبت‌نام و پرداخت به حساب کاربری بروید:\n{{portal_url}}\n\nپس از تأیید پرداخت، ثبت‌نام، برنامهٔ هفتگی و کلاس‌های آیندهٔ شما به‌صورت خودکار فعال می‌شود و تأیید دوبارهٔ مدرس لازم نیست.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'ثبت‌نام شما آمادهٔ تکمیل است','body'=>'برگزاری کلاس آشنایی «{{course}}» با {{teacher}} ثبت شد. این جایگاه فقط حدود یک هفته نگه داشته می‌شود؛ اگر ثبت‌نام و پرداخت تکمیل نشود، جایگاه آزاد می‌شود. برای تکمیل ثبت‌نام به حساب کاربری بروید.'),
                'whatsapp'=>array('body'=>'درود {{name}}، ثبت‌نام «{{course}}» با {{teacher}} آمادهٔ تکمیل است. این جایگاه حدود یک هفته برای شما نگه داشته می‌شود. تکمیل ثبت‌نام و پرداخت: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your Delnavazan enrolment is ready','body'=>"Hello {{name}},\n\nYour introductory {{course}} lesson with {{teacher}} has been recorded as delivered. You can now complete payment and confirm your enrolment with the same Teacher.\n\nThis place is held for about one week only. If enrolment and payment are not completed, the place is released for other students.\n\nOpen your account:\n{{portal_url}}\n\nYour enrolment, weekly schedule and future lessons are activated automatically after the payment is verified, and no further Teacher approval is required.\n\nDelnavazan"),
                'website'=>array('subject'=>'Your enrolment is ready','body'=>'Your introductory {{course}} lesson with {{teacher}} has been recorded as delivered. This place is held for about one week only; if enrolment and payment are not completed, it is released for other students. Complete your enrolment in your account.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your enrolment in {{course}} with {{teacher}} is ready to complete. The place is held for about one week. Complete payment: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('post_intro.survey.student','Enrolment Follow-up','booking','student','PostIntroSurveyWorker','queue',array('email','website'),'operational',true,array('name','teacher','course','hold_status_note','survey_url','portal_url'),'post_intro.survey',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · فرصت ثبت‌نام شما','body'=>"درود {{name}}،\n\n{{hold_status_note}} ثبت‌نام کلاس «{{course}}» با {{teacher}} تکمیل نشده است.\n\nاگر تصمیم به ادامه نگرفته‌اید، لطفاً به ما بگویید چه چیزی مانع شد تا بتوانیم کمک کنیم. چند گزینهٔ پیشنهادی:\n- با این مدرس راحت نبودم و مایلم مدرس دیگری را امتحان کنم\n- زمان کلاس مناسب نبود\n- هزینه یا شرایط پرداخت مناسب نبود\n- دلیل دیگری داشتم\n\nمی‌توانید انتخاب کنید که با شما تماس بگیریم، فعلاً ادامه ندهید، یا فقط نظرتان را ثبت کنید.\n\nبرای ثبت پاسخ به حساب کاربری بروید:\n{{survey_url}}\n\nحساب کاربری:\n{{portal_url}}\n\nپاسخ شما فقط برای پیگیری و رفع نگرانی استفاده می‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'چرا ثبت‌نام را ادامه ندادید؟','body'=>'{{hold_status_note}} اگر ادامه نمی‌دهید، دلیل را از حساب کاربری برای کلاس «{{course}}» با {{teacher}} ثبت کنید تا بتوانیم کمک کنیم.'),
                'whatsapp'=>array('body'=>'درود {{name}}، ثبت‌نام «{{course}}» با {{teacher}} تکمیل نشده است. {{hold_status_note}} برای ثبت پاسخ به حساب کاربری بروید: {{survey_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your enrolment follow-up','body'=>"Hello {{name}},\n\n{{hold_status_note}} Your {{course}} enrolment with {{teacher}} is not complete.\n\nIf you have decided not to continue, please tell us what got in the way so we can help. Suggested reasons:\n- I did not feel comfortable with this Teacher and would like to try another\n- The class time did not work\n- The price or payment terms did not work\n- Something else\n\nYou can ask us to contact you, say you do not want to continue for now, or simply record your feedback.\n\nAnswer in your account:\n{{survey_url}}\n\nStudent account:\n{{portal_url}}\n\nYour answer is used only to follow up and resolve your concern.\n\nDelnavazan"),
                'website'=>array('subject'=>'Why did you not continue?','body'=>'{{hold_status_note}} If you are not continuing, record the reason in your account for {{course}} with {{teacher}} so we can help.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your {{course}} enrolment with {{teacher}} is not complete. {{hold_status_note}} Answer in your account: {{survey_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('renewal.reminder.student','Term Renewal Reminder','payment','student','RenewalReminderWorker','queue',array('email','website'),'financial',true,array('name','course','teacher','remaining_sessions','term_end_date','instalment_note','payment_url','portal_url'),'renewal.reminder',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · یادآوری تمدید ترم','body'=>"درود {{name}}،\n\nفقط {{remaining_sessions}} جلسه از ترم «{{course}}» با {{teacher}} باقی مانده است.\n\nبرای حفظ جایگاه کلاس، تمدید ترم را از بخش پرداخت حساب کاربری تکمیل کنید.\n\n{{instalment_note}}\n\nمهلت: {{term_end_date}}\n\nپرداخت و تمدید:\n{{payment_url}}\n\nحساب کاربری:\n{{portal_url}}\n\nاگر تا پایان ترم پرداخت را تکمیل نکنید یا با مدرس یا آکادمی هماهنگ نکنید، این جایگاه آزاد می‌شود و در دسترس هنرجویان دیگر قرار می‌گیرد.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'یادآوری تمدید ترم','body'=>'فقط {{remaining_sessions}} جلسه از ترم «{{course}}» باقی مانده است. {{instalment_note}} برای حفظ جایگاه، از بخش پرداخت حساب کاربری تمدید کنید.'),
                'whatsapp'=>array('body'=>'درود {{name}}، فقط {{remaining_sessions}} جلسه از ترم «{{course}}» باقی مانده است. برای حفظ جایگاه، تمدید را از بخش پرداخت حساب کاربری تکمیل کنید. مهلت: {{term_end_date}} — {{payment_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · term renewal reminder','body'=>"Hello {{name}},\n\nOnly {{remaining_sessions}} sessions remain in your {{course}} term with {{teacher}}.\n\nTo keep your place, complete the renewal from the payments section of your account.\n\n{{instalment_note}}\n\nDeadline: {{term_end_date}}\n\nPay and renew:\n{{payment_url}}\n\nStudent account:\n{{portal_url}}\n\nIf payment is not completed by the end of the term and you have not spoken with your Teacher or the academy, this place will be released for other students.\n\nDelnavazan"),
                'website'=>array('subject'=>'Term renewal reminder','body'=>'Only {{remaining_sessions}} sessions remain in your {{course}} term. {{instalment_note}} Renew from the payments section to keep your place.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, only {{remaining_sessions}} sessions remain in your {{course}} term. To keep your place, complete the renewal from the payments section of your account. Deadline: {{term_end_date}} — {{payment_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('post_intro.activated.student','Enrolment Activated','account','student','PostIntroActivationService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','portal_url'),'post_intro.activated',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · ثبت‌نام شما فعال شد','body'=>"درود {{name}}،\n\nپرداخت شما تأیید شد و ثبت‌نام «{{course}}» با {{teacher}} فعال است. برنامهٔ هفتگی و کلاس‌های آیندهٔ شما در حساب کاربری نمایش داده می‌شود.\n\nمشاهدهٔ برنامه و کلاس‌ها:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'ثبت‌نام شما فعال شد','body'=>'ثبت‌نام «{{course}}» با {{teacher}} فعال شد و برنامهٔ کلاس‌ها در حساب کاربری نمایش داده می‌شود.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، ثبت‌نام «{{course}}» شما با {{teacher}} فعال شد. برنامهٔ کلاس‌ها در حساب کاربری در دسترس است: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your Delnavazan enrolment is active','body'=>"Hello {{name}},\n\nYour payment was verified and your {{course}} enrolment with {{teacher}} is now active. Your weekly schedule and future lessons are available in your account.\n\nOpen your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Your enrolment is active','body'=>'Your {{course}} enrolment with {{teacher}} is active and your class schedule is shown in your account.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your {{course}} enrolment with {{teacher}} is active. Your class schedule is available in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.terminal.student','Intro Request Terminal Expiry','booking','student','BookingAllocationService','queue',array('email','website'),'operational',true,array('name','teacher','course','when','portal_url'),'booking.terminal.student',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · زمان درخواست کلاس آشنایی به پایان رسید','body'=>"درود {{name}}،\n\nمهلت پاسخ برای کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} به پایان رسید و این درخواست بسته شد. می‌توانید از حساب کاربری زمان تازه‌ای انتخاب کنید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'زمان درخواست کلاس آشنایی به پایان رسید','body'=>'مهلت پاسخ برای کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} به پایان رسید. برای انتخاب زمان تازه به حساب کاربری بروید.'),
                'sms'=>array('body'=>'دلنوازان: مهلت درخواست {{course}} با {{teacher}} برای {{when}} تمام شد. زمان تازه: {{portal_url}}'),
                'whatsapp'=>array('body'=>'درود {{name}}، مهلت پاسخ برای کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} به پایان رسید. می‌توانید از حساب کاربری زمان تازه‌ای انتخاب کنید: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory request deadline passed','body'=>"Hello {{name}},\n\nThe response deadline for your introductory {{course}} lesson with {{teacher}} at {{when}} has passed and the request is closed. You can choose a new time in your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory request deadline passed','body'=>'The response deadline for your {{course}} lesson with {{teacher}} at {{when}} passed. Choose a new time in your account.'),
                'sms'=>array('body'=>'Delnavazan: the request for {{course}} with {{teacher}} at {{when}} expired. New time: {{portal_url}}'),
                'whatsapp'=>array('body'=>'Hello {{name}}, the response deadline for your introductory {{course}} lesson with {{teacher}} at {{when}} passed. You can choose a new time in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.requested.student','Intro Lesson Request Received','booking','student','BookingRequestSubmissionService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','when','portal_url'),'booking.requested',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست کلاس آشنایی شما ثبت شد','body'=>"درود {{name}}،\n\nدرخواست کلاس آشنایی «{{course}}» شما ثبت شد.\n\nزمان درخواستی: {{when}}\n\nپس از تأیید مدرس، اطلاعات نهایی کلاس برای شما ارسال می‌شود.\n\nجزئیات در حساب کاربری:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'درخواست کلاس آشنایی ثبت شد','body'=>'درخواست کلاس آشنایی «{{course}}» در {{when}} ثبت شد و در انتظار تأیید مدرس است.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، درخواست رزرو کلاس {{course}} شما در زمان {{when}} ثبت شد. پس از تأیید مدرس، اطلاعات نهایی برای شما ارسال می‌شود. حساب: {{portal_url}} — دلنوازان'),
                'sms'=>array('body'=>'دلنوازان: درخواست کلاس {{course}} در {{when}} ثبت شد. حساب: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your introductory lesson request was received','body'=>"Hello {{name}},\n\nYour introductory {{course}} lesson request was received.\n\nRequested time: {{when}}\n\nWe will send the final class details after the Teacher confirms.\n\nOpen your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson request received','body'=>'Your {{course}} lesson request for {{when}} is awaiting Teacher confirmation.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your {{course}} lesson request for {{when}} was received. We will send the final details after the Teacher confirms. Account: {{portal_url}} — Delnavazan'),
                'sms'=>array('body'=>'Delnavazan: your {{course}} lesson request for {{when}} was received. Account: {{portal_url}}'),
            ),
        ));
        $out[]=self::event('lesson.reminder.student','Class Reminder','lesson','student','LessonLifecycleNotificationService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','teacher','when','portal_url'),'lesson.reminder',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · یادآوری کلاس','body'=>"درود {{name}}،\n\nیادآوری می‌کنیم که کلاس «{{course}}» شما با {{teacher}} در {{when}} برگزار می‌شود.\n\nپیش از ورود، در Google Meet ← Settings ← Audio گزینهٔ «Studio sound» یا «حذف نویز» را خاموش کنید تا صدای ساز درست شنیده شود. این تنظیم برای هر حساب و هر دستگاه جداگانه است.\n\nبرای مشاهدهٔ جزئیات و ورود به کلاس به حساب کاربری بروید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'یادآوری کلاس','body'=>'کلاس «{{course}}» شما با {{teacher}} در {{when}} برگزار می‌شود. پیش از ورود، «Studio sound» یا حذف نویز را در Google Meet ← Settings ← Audio خاموش کنید.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، کلاس {{course}} شما با {{teacher}} در {{when}} برگزار می‌شود. برای ورود به کلاس به حساب کاربری بروید: {{portal_url}} — دلنوازان'),
                'sms'=>array('body'=>'دلنوازان: یادآوری کلاس {{course}} با {{teacher}} در {{when}}. حساب: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · class reminder','body'=>"Hello {{name}},\n\nThis is a reminder that your {{course}} class with {{teacher}} takes place on {{when}}.\n\nBefore you join, turn off Studio sound or noise cancellation in Google Meet → Settings → Audio so the instrument is heard correctly. The setting is per Google account and per device.\n\nOpen your account for the details and to join the class:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Class reminder','body'=>'Your {{course}} class with {{teacher}} takes place on {{when}}. Before you join, turn off Studio sound or noise cancellation in Meet > Settings > Audio.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your {{course}} class with {{teacher}} takes place on {{when}}. Join the class from your account: {{portal_url}} — Delnavazan'),
                'sms'=>array('body'=>'Delnavazan: class reminder {{course}} with {{teacher}} on {{when}}. Account: {{portal_url}}'),
            ),
        ));
        $out[]=self::event('lesson.cancelled.student','Class Cancelled','lesson','student','LessonLifecycleNotificationService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','teacher','when','portal_url'),'lesson.cancelled',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس لغو شد','body'=>"درود {{name}}،\n\nکلاس «{{course}}» شما با {{teacher}} در {{when}} لغو شد.\n\nاگر برای هماهنگی جلسهٔ جبرانی به کمک نیاز دارید، از حساب کاربری با ما در تماس باشید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس لغو شد','body'=>'کلاس «{{course}}» با {{teacher}} در {{when}} لغو شد.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، کلاس {{course}} شما با {{teacher}} در زمان {{when}} لغو شد. برای هماهنگی جلسهٔ جبرانی به حساب کاربری مراجعه کنید: {{portal_url}} — دلنوازان'),
                'sms'=>array('body'=>'دلنوازان: کلاس {{course}} با {{teacher}} در {{when}} لغو شد. حساب: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · class cancelled','body'=>"Hello {{name}},\n\nYour {{course}} class with {{teacher}} on {{when}} has been cancelled.\n\nIf you need help arranging a replacement session, contact us through your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Class cancelled','body'=>'Your {{course}} class with {{teacher}} on {{when}} was cancelled.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your {{course}} class with {{teacher}} on {{when}} has been cancelled. Contact us from your account to arrange a replacement session: {{portal_url}} — Delnavazan'),
                'sms'=>array('body'=>'Delnavazan: class {{course}} with {{teacher}} on {{when}} was cancelled. Account: {{portal_url}}'),
            ),
        ));
        $out[]=self::event('lesson.access.student','Class Access Ready','lesson','student','LessonLifecycleNotificationService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','teacher','when','portal_url'),'lesson.access',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس شما آمادهٔ برگزاری است','body'=>"درود {{name}}،\n\nکلاس «{{course}}» شما با {{teacher}} در {{when}} آمادهٔ برگزاری است.\n\nبرای ورود به کلاس به حساب کاربری بروید و پیوند جلسه را در اختیار دیگران قرار ندهید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آمادهٔ برگزاری است','body'=>'کلاس «{{course}}» با {{teacher}} در {{when}} آمادهٔ برگزاری است.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، کلاس {{course}} شما با {{teacher}} در {{when}} آمادهٔ برگزاری است. برای ورود به کلاس به حساب کاربری بروید: {{portal_url}} — دلنوازان'),
                'sms'=>array('body'=>'دلنوازان: کلاس {{course}} با {{teacher}} در {{when}} آماده است. حساب: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your class is ready to start','body'=>"Hello {{name}},\n\nYour {{course}} class with {{teacher}} on {{when}} is ready to start.\n\nOpen your account to join, and please do not share the class link with anyone else:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Class ready to start','body'=>'Your {{course}} class with {{teacher}} on {{when}} is ready to start.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your {{course}} class with {{teacher}} on {{when}} is ready to start. Join from your account: {{portal_url}} — Delnavazan'),
                'sms'=>array('body'=>'Delnavazan: class {{course}} with {{teacher}} on {{when}} is ready. Account: {{portal_url}}'),
            ),
        ));
        self::appendRescheduleStudent($out);
        $out[]=self::event('account.claim_issued.student','Student Account Invitation','security','student','StudentAccountClaimNotificationService','canonical',array('email','whatsapp','sms'),'security',true,array('claim_code'),'account.claim_issued',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · دعوت‌نامهٔ حساب کاربری شما','body'=>"درود،\n\nدعوت‌نامهٔ حساب کاربری دلنوازان برای شما صادر شد. برای فعال‌سازی حساب، کد یک‌بارمصرف زیر را در صفحهٔ دعوت‌نامه وارد کنید:\n\n{{claim_code}}\n\nاین کد فقط یک‌بار قابل استفاده است و برای دیگران قابل بازگویی نیست. اگر این درخواست را شما آغاز نکرده‌اید، این پیام را نادیده بگیرید و با پشتیبانی دلنوازان تماس بگیرید.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'دعوت‌نامهٔ حساب کاربری','body'=>'دعوت‌نامهٔ حساب شما صادر شد. کد یک‌بارمصرف را فقط در صفحهٔ دعوت‌نامه وارد کنید.'),
                'sms'=>array('body'=>'دلنوازان: کد یک‌بارمصرف حساب شما: {{claim_code}}'),
                'whatsapp'=>array('body'=>'دلنوازان: کد یک‌بارمصرف حساب شما {{claim_code}} است. این کد را با کسی به اشتراک نگذارید.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your account invitation','body'=>"Hello,\n\nYour Delnavazan account invitation is ready. Enter this one-time code on the invitation page:\n\n{{claim_code}}\n\nThis code can be used once and must not be shared. If you did not expect this message, ignore it and contact Delnavazan support.\n\nDelnavazan"),
                'website'=>array('subject'=>'Your account invitation','body'=>'Your account invitation is ready. Enter the one-time code on the invitation page only.'),
                'sms'=>array('body'=>'Delnavazan: your one-time account code is {{claim_code}}'),
                'whatsapp'=>array('body'=>'Delnavazan: your one-time account code is {{claim_code}}. Do not share this code with anyone.'),
            ),
        ),array('security'));
        return $out;
    }

    // ------------------------------------------------------------------ teacher events

    private static function teacherEvents():array{
        $out=array();
        $out[]=self::event('attendance.absence_reported.teacher','Student Absence Notice','lesson','teacher','CanonicalAttendanceNotificationService','queue',array('email','website','sms'),'operational',true,array('teacher','student','course','when','timezone','teacher_portal_url'),'attendance.absence_reported.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · اطلاع غیبت هنرجو','body'=>"درود {{teacher}}،\n\n{{student}} برای جلسهٔ «{{course}}» در {{when}} ({{timezone}}) اطلاع غیبت فرستاده است.\n\nلطفاً با هنرجو هماهنگ کنید و در صورت توافق، زمان جایگزین همین جلسه را در پرتال مدرس ثبت کنید:\n{{teacher_portal_url}}\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'اطلاع غیبت هنرجو','body'=>'{{student}} برای جلسهٔ «{{course}}» در {{when}} اطلاع غیبت فرستاده است.'),
                'sms'=>array('body'=>'دلنوازان: {{student}} برای جلسهٔ {{course}} در {{when}} اطلاع غیبت فرستاده است. لطفاً در پرتال مدرس هماهنگ کنید.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · student absence notice','body'=>"Hello {{teacher}},\n\n{{student}} reported an absence for the {{course}} session on {{when}} ({{timezone}}).\n\nPlease coordinate with the student and, if you both agree, record the replacement time for this session in the Teacher Portal:\n{{teacher_portal_url}}\n\nThis request also appears under priority tasks in the Teacher Portal.\n\nDelnavazan"),
                'website'=>array('subject'=>'Student absence notice','body'=>'{{student}} reported an absence for the {{course}} session on {{when}}.'),
                'sms'=>array('body'=>'Delnavazan: {{student}} reported an absence for {{course}} on {{when}}. Coordinate in the Teacher Portal.'),
            ),
        ));
        $out[]=self::event('attendance.missed_class_claim.teacher','Missed Class Claim','lesson','teacher','CanonicalAttendanceNotificationService','queue',array('email','website','sms'),'operational',true,array('teacher','student','course','when','timezone','teacher_portal_url'),'attendance.missed_class_claim.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · اعلام جلسهٔ ازدست‌رفته','body'=>"درود {{teacher}}،\n\n{{student}} اعلام کرده است که جلسهٔ «{{course}}» در {{when}} ({{timezone}}) را از دست داده است.\n\nلطفاً برای جلسهٔ جبرانی با هنرجو هماهنگ کنید و زمان تازه را در پرتال مدرس ثبت کنید:\n{{teacher_portal_url}}\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'اعلام جلسهٔ ازدست‌رفته','body'=>'{{student}} اعلام کرده جلسهٔ «{{course}}» در {{when}} را از دست داده است. زمان جلسهٔ جبرانی را پیشنهاد دهید.'),
                'sms'=>array('body'=>'دلنوازان: {{student}} اعلام کرده جلسهٔ {{course}} در {{when}} را از دست داده است. لطفاً جلسهٔ جبرانی را در پرتال مدرس هماهنگ کنید.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · missed class claim','body'=>"Hello {{teacher}},\n\n{{student}} reported missing the {{course}} session on {{when}} ({{timezone}}).\n\nPlease coordinate a replacement session with the student and record the new time in the Teacher Portal:\n{{teacher_portal_url}}\n\nThis request also appears under priority tasks in the Teacher Portal.\n\nDelnavazan"),
                'website'=>array('subject'=>'Missed class claim','body'=>'{{student}} reported missing the {{course}} session on {{when}}. Propose a replacement time.'),
                'sms'=>array('body'=>'Delnavazan: {{student}} reported missing {{course}} on {{when}}. Coordinate a replacement in the Teacher Portal.'),
            ),
        ));
        $out[]=self::event('post_intro.activated.teacher','New Student Class Activated','account','teacher','PostIntroActivationService','queue',array('email','website'),'operational',true,array('teacher','teacher_portal_url'),'post_intro.activated.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس شما با هنرجوی جدید فعال شد','body'=>"درود {{teacher}}،\n\nثبت‌نام هنرجو پس از تأیید پرداخت فعال شد. کلاس‌های آینده در پرتال مدرس نمایش داده می‌شوند.\n\nمشاهدهٔ کلاس‌ها:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس شما با هنرجوی جدید فعال شد','body'=>'ثبت‌نام هنرجو پس از تأیید پرداخت فعال شد و کلاس‌های آینده در پرتال مدرس نمایش داده می‌شوند.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your class with a new student is active','body'=>"Hello {{teacher}},\n\nThe student enrolment became active after the payment was verified. Upcoming classes are shown in the Teacher Portal.\n\nView your classes:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Your class with a new student is active','body'=>'The student enrolment became active after the payment was verified and upcoming classes are shown in the Teacher Portal.'),
            ),
        ));
        $out[]=self::event('teacher.invitation_issued.teacher','Teacher Invitation','security','teacher','TeacherInvitationDeliveryWorker','dedicated',array('email','sms'),'security',true,array('invitation_code','claim_url'),'teacher.invitation',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دعوت به همکاری با دلنوازان','body'=>"درود،\n\nبرای شما دعوت‌نامهٔ همکاری با آکادمی موسیقی دلنوازان صادر شده است.\n\nبرای شروع، پیوند زیر را باز کنید و کد یک‌بارمصرف را وارد کنید:\n{{claim_url}}\n\nکد دعوت: {{invitation_code}}\n\nاین کد فقط یک‌بار قابل استفاده است و باید محرمانه بماند. اگر این دعوت را انتظار نداشتید، این پیام را نادیده بگیرید.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'دعوت به همکاری با دلنوازان','body'=>'دعوت‌نامهٔ همکاری برای شما صادر شد. پیوند و کد یک‌بارمصرف فقط در ایمیل شماست.'),
                'whatsapp'=>array('body'=>'دلنوازان: دعوت‌نامهٔ همکاری برای شما صادر شد. پیوند و کد یک‌بارمصرف در ایمیل شماست.'),
                'sms'=>array('body'=>'دلنوازان: دعوت‌نامهٔ همکاری برای شما صادر شد. کد و پیوند در ایمیل شماست.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'An invitation to teach with Delnavazan','body'=>"Hello,\n\nYou have been invited to teach with the Delnavazan music academy.\n\nTo begin, open the link below and enter the one-time code:\n{{claim_url}}\n\nInvitation code: {{invitation_code}}\n\nThis code can be used once and must stay confidential. If you did not expect this invitation, ignore this message.\n\nDelnavazan"),
                'website'=>array('subject'=>'An invitation to teach with Delnavazan','body'=>'Your teaching invitation has been issued. The one-time link and code are only in your email.'),
                'whatsapp'=>array('body'=>'Delnavazan: your teaching invitation has been issued. The one-time link and code are in your email.'),
                'sms'=>array('body'=>'Delnavazan: your teaching invitation has been issued. The code and link are in your email.'),
            ),
        ),array('security','dedicated_authority'));
        $out[]=self::event('booking.new.teacher','New Booking for Teacher','booking','teacher','BookingTeacherDeliveryService','queue',array('email','website','whatsapp','sms'),'operational',true,array('teacher','course','student','when','teacher_portal_url','response_window','response_deadline'),'booking.new.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · رزرو تازه برای کلاس شما','body'=>"درود {{teacher}}،\n\nرزرو تازه‌ای برای کلاس «{{course}}» با هنرجو {{student}} ثبت شد.\n\nزمان کلاس: {{when}}\n\nلطفاً حداکثر تا {{response_window}} (تا {{response_deadline}}) پاسخ دهید. اگر در این بازه پاسخی ثبت نشود، درخواست به مدرس در دسترس بعدی ارجاع می‌شود.\n\nبرای بررسی جزئیات به پرتال مدرس بروید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'رزرو تازه برای کلاس شما','body'=>'رزرو تازه‌ای برای کلاس «{{course}}» با هنرجو {{student}} در {{when}} ثبت شد. لطفاً تا {{response_window}} ({{response_deadline}}) پاسخ دهید؛ در غیر این صورت درخواست به مدرس در دسترس بعدی ارجاع می‌شود.'),
                'whatsapp'=>array('body'=>'درود {{teacher}} عزیز، رزرو تازه‌ای برای کلاس {{course}} با هنرجو {{student}} در {{when}} ثبت شد. مهلت پاسخ {{response_window}} است؛ در غیر این صورت درخواست به مدرس بعدی ارجاع می‌شود. پرتال مدرس: {{teacher_portal_url}} — دلنوازان'),
                'sms'=>array('body'=>'دلنوازان: رزرو تازه {{course}} با {{student}} در {{when}}. مهلت پاسخ {{response_window}}. پرتال مدرس: {{teacher_portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · new booking for your class','body'=>"Hello {{teacher}},\n\nA new booking for your {{course}} class with the student {{student}} has been received.\n\nClass time: {{when}}\n\nPlease respond within {{response_window}} (by {{response_deadline}}). If no response is recorded in that window, the request is offered to the next available Teacher.\n\nReview the details in the Teacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'New booking for your class','body'=>'A new booking for your {{course}} class with the student {{student}} was received for {{when}}. Please respond within {{response_window}} ({{response_deadline}}); otherwise the request is offered to the next available Teacher.'),
                'whatsapp'=>array('body'=>'Hello {{teacher}}, a new booking for your {{course}} class with the student {{student}} was received for {{when}}. Please respond within {{response_window}}; otherwise the request is offered to the next Teacher. Teacher Portal: {{teacher_portal_url}} — Delnavazan'),
                'sms'=>array('body'=>'Delnavazan: new {{course}} booking with {{student}}, {{when}}. Reply in {{response_window}}. Portal: {{teacher_portal_url}}'),
            ),
        ));
        $out[]=self::event('booking.expired.teacher','Intro Offer Expired','booking','teacher','BookingAllocationWorker','queue',array('website','sms'),'operational',true,array('teacher','teacher_portal_url'),'booking.expired.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'website'=>array('subject'=>'مهلت پاسخ درخواست آشنایی پایان یافت','body'=>'مهلت پاسخ این درخواست پایان یافته است. درخواست دیگر برای شما قابل پذیرش نیست و به مدرس بعدی یا هماهنگی آکادمی سپرده شده است.'),
                'sms'=>array('body'=>'دلنوازان: مهلت درخواست آشنایی پایان یافت و دیگر قابل پذیرش نیست. پرتال مدرس: {{teacher_portal_url}}'),
            ),
            'en'=>array(
                'website'=>array('subject'=>'Intro offer response deadline has passed','body'=>'The response deadline for this request has passed. It can no longer be accepted by you and has been passed to the next Teacher or to academy coordination.'),
                'sms'=>array('body'=>'Delnavazan: the intro offer has expired and is no longer available to you. Portal: {{teacher_portal_url}}'),
            ),
        ));
        $out[]=self::event('booking.payment_lapsed.teacher','Intro Payment Deadline Lapsed','booking','teacher','IntroPaymentExpiryWorker','queue',array('email','website'),'operational',true,array('teacher','student','course','when','teacher_portal_url'),'booking.payment_lapsed.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · مهلت پرداخت هنرجو گذشت','body'=>"درود {{teacher}}،\n\nمهلت پرداخت کلاس آشنایی «{{course}}» با هنرجو {{student}} در {{when}} گذشت. پرداخت ثبت نشد، بنابراین این جایگاه آزاد شد و رویداد تقویم یا کلاس آنلاین ساخته نمی‌شود.\n\nنیازی به اقدام نیست؛ در صورت هماهنگی تازه، آموزشگاه با شما تماس می‌گیرد.\n\nپرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'مهلت پرداخت هنرجو گذشت','body'=>'مهلت پرداخت کلاس آشنایی «{{course}}» با هنرجو {{student}} در {{when}} گذشت و جایگاه آزاد شد.'),
                'sms'=>array('body'=>'دلنوازان: مهلت پرداخت {{course}} با {{student}} برای {{when}} گذشت و جایگاه آزاد شد؛ نیازی به اقدام نیست. {{teacher_portal_url}}'),
                'whatsapp'=>array('body'=>'درود {{teacher}}، مهلت پرداخت کلاس آشنایی «{{course}}» با {{student}} در {{when}} گذشت و جایگاه آزاد شد. نیازی به اقدام نیست؛ در صورت هماهنگی تازه آموزشگاه تماس می‌گیرد: {{teacher_portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory payment deadline passed','body'=>"Hello {{teacher}},\n\nThe payment deadline for the introductory {{course}} lesson with {{student}} at {{when}} has passed. No payment was recorded, so the place was released and no calendar event or online class will be created.\n\nNo action is needed; the academy will contact you if a new arrangement is agreed.\n\nTeacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Student payment deadline passed','body'=>'The payment deadline for the introductory {{course}} lesson with {{student}} at {{when}} passed and the place was released.'),
                'sms'=>array('body'=>'Delnavazan: {{course}} with {{student}} at {{when}} payment deadline passed; place released. No action needed. {{teacher_portal_url}}'),
                'whatsapp'=>array('body'=>'Hello {{teacher}}, the payment deadline for the introductory {{course}} lesson with {{student}} at {{when}} has passed and the place was released. No action is needed; the academy will contact you if a new arrangement is agreed: {{teacher_portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.hold.teacher','Intro Request Held for You','booking','teacher','BookingAllocationService','queue',array('email','website'),'operational',true,array('teacher','student','course','when','response_deadline','teacher_portal_url'),'booking.hold.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست کلاس آشنایی برای شما نگه داشته شد','body'=>"درود {{teacher}}،\n\nدرخواست کلاس آشنایی «{{course}}» با هنرجو {{student}} در {{when}} همچنان برای شما باز است و تا {{response_deadline}} فرصت پاسخ دارید. اگر پاسخی ثبت نشود، این درخواست تا یک ساعت پیش از شروع کلاس برای شما نگه داشته می‌شود.\n\nپرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'درخواست کلاس آشنایی برای شما نگه داشته شد','body'=>'درخواست کلاس آشنایی «{{course}}» با هنرجو {{student}} در {{when}} همچنان برای شما باز است؛ تا {{response_deadline}} پاسخ دهید و در غیر این صورت تا یک ساعت پیش از کلاس نگه داشته می‌شود.'),
                'sms'=>array('body'=>'دلنوازان: درخواست {{course}} با {{student}} در {{when}} برای شما نگه داشته شد؛ پاسخ تا {{response_deadline}}. {{teacher_portal_url}}'),
                'whatsapp'=>array('body'=>'درود {{teacher}}، درخواست کلاس آشنایی «{{course}}» با هنرجو {{student}} در {{when}} همچنان برای شما باز است. تا {{response_deadline}} فرصت پاسخ دارید و در غیر این صورت تا یک ساعت پیش از کلاس نگه داشته می‌شود: {{teacher_portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · this request is held for you','body'=>"Hello {{teacher}},\n\nThe introductory {{course}} request from {{student}} at {{when}} is still open for you. Please respond by {{response_deadline}}. If no response is recorded, the request stays with you until one hour before the class.\n\nTeacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'This request is held for you','body'=>'The introductory {{course}} request from {{student}} at {{when}} is still open; reply by {{response_deadline}} and otherwise it stays with you until one hour before the class.'),
                'sms'=>array('body'=>'Delnavazan: {{course}} with {{student}} at {{when}} is held for you; reply by {{response_deadline}}. {{teacher_portal_url}}'),
                'whatsapp'=>array('body'=>'Hello {{teacher}}, the introductory {{course}} request from {{student}} at {{when}} is still open for you. Please reply by {{response_deadline}}; otherwise it stays with you until one hour before the class: {{teacher_portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.cancelled.teacher','Intro Lesson Cancelled','booking','teacher','IntroLessonLifecycleService','queue',array('email','website'),'operational',true,array('teacher','student','course','when','teacher_portal_url'),'booking.cancelled.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس آشنایی لغو شد','body'=>"درود {{teacher}}،\n\nکلاس آشنایی «{{course}}» با {{student}} در {{when}} لغو شد. این لغو به معنی ثبت حضور یا پرداخت نیست.\n\nپرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آشنایی لغو شد','body'=>'کلاس آشنایی «{{course}}» با {{student}} در {{when}} لغو شد.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory lesson cancelled','body'=>"Hello {{teacher}},\n\nThe introductory {{course}} lesson with {{student}} on {{when}} has been cancelled. No attendance or payment decision was inferred from this cancellation.\n\nTeacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson cancelled','body'=>'The introductory {{course}} lesson with {{student}} on {{when}} was cancelled.'),
            ),
        ));
        $out[]=self::event('booking.rescheduled.teacher','Intro Lesson Rescheduled','booking','teacher','IntroLessonLifecycleService','queue',array('email','website'),'operational',true,array('teacher','student','course','when','teacher_portal_url'),'booking.rescheduled.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · زمان کلاس آشنایی تغییر کرد','body'=>"درود {{teacher}}،\n\nزمان کلاس آشنایی «{{course}}» با {{student}} به {{when}} تغییر کرد.\n\nپرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'زمان کلاس آشنایی تغییر کرد','body'=>'زمان کلاس آشنایی «{{course}}» با {{student}} به {{when}} تغییر کرد.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory lesson rescheduled','body'=>"Hello {{teacher}},\n\nThe introductory {{course}} lesson with {{student}} has been rescheduled to {{when}}.\n\nTeacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson rescheduled','body'=>'The introductory {{course}} lesson with {{student}} is now at {{when}}.'),
            ),
        ));
        $out[]=self::event('lesson.access.teacher','Class Access Ready','lesson','teacher','LessonLifecycleNotificationService','dedicated',array('email','website','sms'),'operational',true,array('teacher','course','student','when','teacher_portal_url'),'lesson.access.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس شما آمادهٔ برگزاری است','body'=>"درود {{teacher}}،\n\nکلاس «{{course}}» شما با هنرجو {{student}} در {{when}} آمادهٔ برگزاری است.\n\nبرای ورود به کلاس به پرتال مدرس بروید و پیوند جلسه را در اختیار دیگران قرار ندهید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آمادهٔ برگزاری است','body'=>'کلاس «{{course}}» با هنرجو {{student}} در {{when}} آمادهٔ برگزاری است.'),
                'sms'=>array('body'=>'دلنوازان: کلاس {{course}} با {{student}} در {{when}} آماده است. پرتال مدرس: {{teacher_portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your class is ready to start','body'=>"Hello {{teacher}},\n\nYour {{course}} class with the student {{student}} on {{when}} is ready to start.\n\nOpen the Teacher Portal to join, and please do not share the class link with anyone else:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Class ready to start','body'=>'Your {{course}} class with {{student}} on {{when}} is ready to start.'),
                'sms'=>array('body'=>'Delnavazan: class {{course}} with {{student}} on {{when}} is ready. Teacher Portal: {{teacher_portal_url}}'),
            ),
        ),array('dedicated_authority'));
        $out[]=self::event('lesson.reminder.teacher','Class Reminder','lesson','teacher','LessonLifecycleNotificationService','dedicated',array('email','website','sms'),'operational',true,array('teacher','course','student','when','teacher_portal_url'),'lesson.reminder.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · یادآوری کلاس','body'=>"درود {{teacher}}،\n\nیادآوری می‌کنیم که کلاس «{{course}}» شما با هنرجو {{student}} در {{when}} برگزار می‌شود.\n\nپیش از ورود، در Google Meet ← Settings ← Audio گزینهٔ «Studio sound» یا «حذف نویز» را خاموش کنید تا صدای ساز درست شنیده شود. این تنظیم برای هر حساب و هر دستگاه جداگانه است.\n\nبرای مشاهدهٔ جزئیات و ورود به کلاس به پرتال مدرس بروید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'یادآوری کلاس','body'=>'کلاس «{{course}}» شما با هنرجو {{student}} در {{when}} برگزار می‌شود. پیش از ورود، «Studio sound» یا حذف نویز را در Google Meet ← Settings ← Audio خاموش کنید.'),
                'sms'=>array('body'=>'دلنوازان: یادآوری کلاس {{course}} با {{student}} در {{when}}. پرتال مدرس: {{teacher_portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · class reminder','body'=>"Hello {{teacher}},\n\nThis is a reminder that your {{course}} class with the student {{student}} takes place on {{when}}.\n\nBefore you join, turn off Studio sound or noise cancellation in Google Meet → Settings → Audio so the instrument is heard correctly. The setting is per Google account and per device.\n\nOpen the Teacher Portal for the details and to join the class:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Class reminder','body'=>'Your {{course}} class with {{student}} takes place on {{when}}. Before you join, turn off Studio sound or noise cancellation in Meet > Settings > Audio.'),
                'sms'=>array('body'=>'Delnavazan: class reminder {{course}} with {{student}} on {{when}}. Teacher Portal: {{teacher_portal_url}}'),
            ),
        ),array('dedicated_authority'));
        $out[]=self::event('lesson.cancelled.teacher','Class Cancelled','lesson','teacher','LessonLifecycleNotificationService','dedicated',array('email','website','sms'),'operational',true,array('teacher','course','student','when','teacher_portal_url'),'lesson.cancelled.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس لغو شد','body'=>"درود {{teacher}}،\n\nکلاس «{{course}}» شما با هنرجو {{student}} در {{when}} لغو شد.\n\nاگر برای هماهنگی جلسهٔ جبرانی به کمک نیاز دارید، از پرتال مدرس اقدام کنید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس لغو شد','body'=>'کلاس «{{course}}» با هنرجو {{student}} در {{when}} لغو شد.'),
                'sms'=>array('body'=>'دلنوازان: کلاس {{course}} با {{student}} در {{when}} لغو شد. پرتال مدرس: {{teacher_portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · class cancelled','body'=>"Hello {{teacher}},\n\nYour {{course}} class with the student {{student}} on {{when}} has been cancelled.\n\nIf you need help arranging a replacement session, act from the Teacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Class cancelled','body'=>'Your {{course}} class with {{student}} on {{when}} was cancelled.'),
                'sms'=>array('body'=>'Delnavazan: class {{course}} with {{student}} on {{when}} was cancelled. Teacher Portal: {{teacher_portal_url}}'),
            ),
        ),array('dedicated_authority'));
        self::appendRescheduleTeacher($out);
        return $out;
    }

    // ------------------------------------------------------------------ admin events

    private static function adminEvents():array{
        $out=array();
        $out[]=self::event('system.inbox_check.admin','Portal Notification Check','account','admin','notification_management','queue',array('website'),'operational',false,array('when'),'system.inbox_check.admin',array(
            self::PRIMARY_LOCALE=>array('website'=>array('subject'=>'اعلان آزمایشی دلنوازان','body'=>'این یک اعلان آزمایشی داخلی است. زمان ثبت: {{when}}. هیچ ایمیل، واتس‌اپ یا پیامکی ارسال نشده است.')),
            'en'=>array('website'=>array('subject'=>'Delnavazan internal test notification','body'=>'This is an internal test notification. Recorded at {{when}}. No email, WhatsApp or SMS message was sent.')),
        ));
        $out[]=self::event('booking.referral.admin','Intro Booking Needs Coordination','booking','admin','BookingAllocationWorker','queue',array('website'),'operational',true,array(),'booking.referral.admin',array(
            self::PRIMARY_LOCALE=>array('website'=>array('subject'=>'درخواست آشنایی نیازمند هماهنگی است','body'=>'مدرس مناسبی برای درخواست پیدا نشد یا نوبت همهٔ مدرس‌ها پایان یافت. درخواست را در صف هماهنگی بررسی کنید.')),
            'en'=>array('website'=>array('subject'=>'Intro booking needs coordination','body'=>'No suitable Teacher was found for the request, or every Teacher turn has ended. Review the request in the coordination queue.')),
        ));
        $out[]=self::event('booking.payment_conflict.admin','Intro Payment Slot Conflict','booking','admin','IntroPaymentService','queue',array('website','email'),'operational',true,array(),'booking.payment_conflict.admin',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · تعارض پرداخت کلاس آشنایی','body'=>"درود،\n\nیک پرداخت کلاس آشنایی پس از گرفته‌شدن جایگاه ثبت شد. مبلغ به‌عنوان اعتبار هنرجو نگه داشته شده و پروندهٔ هماهنگی برای تعیین زمان تازه باز شده است. لطفاً در میز هماهنگی بررسی کنید."),
                'website'=>array('subject'=>'تعارض پرداخت کلاس آشنایی','body'=>'پرداخت پس از گرفته‌شدن جایگاه ثبت شد؛ مبلغ به‌عنوان اعتبار نگه داشته شده و پروندهٔ هماهنگی باز است.'),
                'sms'=>array('body'=>'دلنوازان: پرداخت کلاس آشنایی پس از گرفته‌شدن جایگاه ثبت شد؛ مبلغ اعتبار است و پروندهٔ هماهنگی باز است.'),
                'whatsapp'=>array('body'=>'درود، پرداخت کلاس آشنایی پس از گرفته‌شدن جایگاه ثبت شد. مبلغ به‌عنوان اعتبار نگه داشته شده و پروندهٔ هماهنگی برای زمان تازه باز است. لطفاً میز هماهنگی را بررسی کنید — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory payment slot conflict','body'=>"Hello,\n\nAn introductory payment settled after the class time had already been taken. The amount is held as student credit and a coordination case is open to arrange a new time. Please review it in the coordination workspace."),
                'website'=>array('subject'=>'Introductory payment slot conflict','body'=>'A payment settled after the class time was taken; the amount is held as credit and a coordination case is open.'),
                'sms'=>array('body'=>'Delnavazan: an introductory payment settled after the time was taken; the amount is credit and a coordination case is open.'),
                'whatsapp'=>array('body'=>'Hello, an introductory payment settled after the class time had already been taken. The amount is held as student credit and a coordination case is open for a new time. Please review the coordination workspace — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.payment_unreconciled.admin','Intro Payment Captured After Closure','booking','admin','IntroPaymentService','queue',array('website','email'),'operational',true,array('reference'),'booking.payment_unreconciled.admin',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · پرداخت پس از بسته‌شدن تعهد','body'=>"درود،\n\nیک پرداخت تأییدشده برای تعهد کلاس آشنایی {{reference}} در حالی ثبت شد که آن تعهد پیش‌تر بسته شده بود. مبلغ دریافت شده و نیاز به بازبینی مالی دارد. هیچ کلاس یا رویداد تقویمی با این پرداخت ساخته نشد.\n\nلطفاً پرونده را در میز مالی بررسی کنید.\n\nدلنوازان"),
                'website'=>array('subject'=>'پرداخت پس از بسته‌شدن تعهد آشنایی','body'=>'یک پرداخت تأییدشده برای تعهد کلاس آشنایی {{reference}} پس از بسته‌شدن آن ثبت شد. مبلغ دریافت شده و نیاز به بازبینی مالی دارد؛ هیچ کلاسی با این رویداد تثبیت نشد.'),
                'sms'=>array('body'=>'دلنوازان: پرداخت تعهد {{reference}} پس از بسته‌شدن ثبت شد؛ نیاز به بازبینی مالی.'),
                'whatsapp'=>array('body'=>'درود، پرداخت تأییدشده برای تعهد کلاس آشنایی {{reference}} پس از بسته‌شدن آن ثبت شد. مبلغ دریافت شده و نیاز به بازبینی مالی دارد؛ هیچ کلاسی با این پرداخت تثبیت نشد. لطفاً میز مالی را بررسی کنید — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · payment recorded after obligation closed','body'=>"Hello,\n\nA verified payment for introductory obligation {{reference}} was recorded after the obligation had already closed. The amount was captured and needs finance reconciliation. No class or calendar event was created by this payment.\n\nPlease review the case in the finance workspace.\n\nDelnavazan"),
                'website'=>array('subject'=>'Payment recorded after introductory obligation closed','body'=>'A verified payment for introductory obligation {{reference}} was recorded after the obligation had already closed. The amount was captured and needs finance reconciliation; no class was secured by this event.'),
                'sms'=>array('body'=>'Delnavazan: payment for obligation {{reference}} recorded after closure; finance reconciliation required.'),
                'whatsapp'=>array('body'=>'Hello, a verified payment for introductory obligation {{reference}} was recorded after the obligation had already closed. The amount was captured and needs finance reconciliation; no class was secured by this payment. Please review the finance workspace — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.hold.admin','Intro Request Held for Sole Teacher','booking','admin','BookingAllocationService','queue',array('website','email'),'operational',true,array(),'booking.hold.admin',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست آشنایی برای تنها مدرس نگه داشته شد','body'=>"درود،\n\nهیچ مدرس دیگری برای این درخواست کلاس آشنایی باقی نمانده و مدرس فعلی هنوز در دسترس است. درخواست به‌جای ارجاع، برای همان مدرس نگه داشته شد تا یک ساعت پیش از شروع کلاس. لطفاً در میز هماهنگی بررسی کنید."),
                'website'=>array('subject'=>'درخواست آشنایی برای تنها مدرس نگه داشته شد','body'=>'هیچ مدرس دیگری باقی نمانده و درخواست برای مدرس فعلی تا یک ساعت پیش از کلاس نگه داشته شد.'),
                'sms'=>array('body'=>'دلنوازان: درخواست آشنایی برای تنها مدرس در دسترس نگه داشته شد تا یک ساعت پیش از کلاس. میز هماهنگی را بررسی کنید.'),
                'whatsapp'=>array('body'=>'درود، هیچ مدرس دیگری برای این درخواست کلاس آشنایی باقی نمانده و مدرس فعلی هنوز در دسترس است. درخواست به‌جای ارجاع، برای همان مدرس تا یک ساعت پیش از کلاس نگه داشته شد. لطفاً میز هماهنگی را بررسی کنید — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · request held for the sole Teacher','body'=>"Hello,\n\nNo other Teacher remains for this introductory request and the current Teacher is still available. The request was held for that Teacher until one hour before the class instead of being referred. Please review it in the coordination workspace."),
                'website'=>array('subject'=>'Request held for the sole Teacher','body'=>'No other Teacher remains; the request was held for the current Teacher until one hour before the class.'),
                'sms'=>array('body'=>'Delnavazan: the intro request is held for the only available Teacher until one hour before the class. Review coordination.'),
                'whatsapp'=>array('body'=>'Hello, no other Teacher remains for this introductory request and the current Teacher is still available. It was held for that Teacher until one hour before the class instead of being referred. Please review the coordination workspace — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.submitted.admin','New Intro Booking Received','booking','admin','BookingRequestSubmissionService','queue',array('website'),'operational',true,array('booking_reference','learner_name','instrument','course','requested_time','queue_url'),'booking.submitted.admin',array(
            self::PRIMARY_LOCALE=>array(
                'website'=>array('subject'=>'درخواست کلاس آشنایی جدید ثبت شد','body'=>'درخواست {{booking_reference}} از {{learner_name}} برای {{instrument}} ({{course}}) با زمان پیشنهادی {{requested_time}} ثبت شد و در انتظار هماهنگی است. صف هماهنگی: {{queue_url}}'),
                'whatsapp'=>array('body'=>'درخواست کلاس آشنایی جدید {{booking_reference}} — هنرجو {{learner_name}}، ساز {{instrument}}، زمان {{requested_time}}. صف هماهنگی: {{queue_url}} — دلنوازان'),
            ),
            'en'=>array(
                'website'=>array('subject'=>'New intro booking received','body'=>'Request {{booking_reference}} from {{learner_name}} for {{instrument}} ({{course}}) was submitted for {{requested_time}} and is awaiting coordination. Coordination queue: {{queue_url}}'),
                'whatsapp'=>array('body'=>'New intro request {{booking_reference}} — learner {{learner_name}}, instrument {{instrument}}, time {{requested_time}}. Coordination queue: {{queue_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('booking.escalation.admin','Intro Booking Escalated','booking','admin','BookingAllocationWorker','queue',array('website'),'operational',true,array(),'booking.escalation.admin',array(
            self::PRIMARY_LOCALE=>array('website'=>array('subject'=>'فوری · درخواست آشنایی در آستانهٔ توقف پاسخ','body'=>'درخواست آشنایی هنوز تأیید نشده و به آستانهٔ توقف (۱۲ ساعت پیش از شروع کلاس) رسیده است. در صف هماهنگی پیگیری کنید.')),
            'en'=>array('website'=>array('subject'=>'Urgent · intro request at the terminal threshold','body'=>'The intro request is still not confirmed and has reached the terminal threshold (12 hours before the class start). Follow up in the coordination queue.')),
        ));
        foreach(array(
            'change'=>array('label'=>'Lesson Change','faLabel'=>'تغییر زمان کلاس'),
            'replacement'=>array('label'=>'Replacement Session','faLabel'=>'جلسهٔ جبرانی'),
        ) as $kind=>$meta){
            $states=array(
                'proposed'=>array(
                    'label'=>'Proposal Created','fa_short'=>'پیشنهاد زمان','en_short'=>'proposal created','variables'=>array('when','timezone'),
                    'fa'=>'زمان {{when}} ({{timezone}}) پیشنهاد شد و در انتظار پاسخ هنرجو است.',
                    'en'=>'The time {{when}} ({{timezone}}) was proposed and is awaiting the student response.',
                ),
                'accepted'=>array(
                    'label'=>'Proposal Accepted','fa_short'=>'پذیرش پیشنهاد','en_short'=>'proposal accepted','variables'=>array('when','timezone'),
                    'fa'=>'هنرجو پیشنهاد را پذیرفت و زمان {{when}} ({{timezone}}) قطعی شد.',
                    'en'=>'The student accepted the proposal and the time {{when}} ({{timezone}}) is now confirmed.',
                ),
                'rejected'=>array(
                    'label'=>'Proposal Rejected','fa_short'=>'رد پیشنهاد','en_short'=>'proposal rejected','variables'=>array(),
                    'fa'=>'هنرجو پیشنهاد را رد کرد. زمان فعلی کلاس بدون تغییر می‌ماند.',
                    'en'=>'The student rejected the proposal. The current class time is unchanged.',
                ),
                'failed'=>array(
                    'label'=>'Proposal Closed','fa_short'=>'پیشنهاد ثبت‌نشده','en_short'=>'proposal closed','variables'=>array(),
                    'fa'=>'پیشنهاد ثبت نشد و بسته شد؛ مورد برای بررسی در صف مدیر قرار گرفت.',
                    'en'=>'The proposal could not be recorded and was closed; the case was placed in the administrator queue.',
                ),
                'cancelled'=>array(
                    'label'=>'Proposal Cancelled','fa_short'=>'لغو پیشنهاد','en_short'=>'proposal cancelled','variables'=>array(),
                    'fa'=>'پیشنهاد توسط مدیر لغو شد.',
                    'en'=>'The proposal was cancelled by an administrator.',
                ),
            );
            foreach($states as $state=>$content){
                $out[]=self::event('reschedule.'.$kind.'.'.$state.'.admin','Admin · '.$meta['label'].' · '.$content['label'],'lesson','admin','LessonRescheduleService','queue',array('email'),'operational',true,$content['variables'],'reschedule.'.$kind.'.'.$state.'.admin',array(
                    self::PRIMARY_LOCALE=>array('email'=>array('subject'=>'دلنوازان · '.$meta['faLabel'].' · '.$content['fa_short'],'body'=>"درود،\n\n".$meta['faLabel'].": ".$content['fa']."\n\nاین پیام فقط برای اطلاع مدیر آموزشگاه است.\n\nدلنوازان")),
                    'en'=>array('email'=>array('subject'=>'Delnavazan · '.$meta['label'].' · '.$content['en_short'],'body'=>"Hello,\n\n".$meta['label'].": ".$content['en']."\n\nThis message is for academy administrator awareness only.\n\nDelnavazan")),
                ));
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ consent lifecycle events

    /**
     * Consent notices are operational account messages. They never contain decision evidence,
     * guardian identity, recording contents or research material.
     */
    private static function consentEvents():array{
        $definitions=array(
            'consent.teacher_required.teacher'=>array(
                'label'=>'Teacher Consent Required','template'=>'consent_required','audience'=>'teacher','channels'=>array('email','website'),'review'=>array(),
                'variables'=>array('teacher','teacher_portal_url'),
                'fa_subject'=>'لطفاً انتخاب‌های ضبط کلاس و پژوهش را بررسی کنید',
                'fa_email'=>"درود {{teacher}}،\n\nبرای ادامهٔ همکاری لازم است انتخاب‌های ضبط کلاس و پژوهش را در پرتال مدرس بررسی کنید. هیچ کلاسی به‌صورت خودکار ضبط نمی‌شود.\n\nبررسی و ثبت انتخاب‌ها:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>'برای ادامهٔ همکاری، انتخاب‌های ضبط کلاس و پژوهش را در پرتال مدرس بررسی کنید.',
                'en_subject'=>'Please review your class recording and research choices',
                'en_email'=>"Hello {{teacher}},\n\nTo continue teaching with the academy, please review your class recording and research choices in the Teacher Portal. No class is recorded automatically.\n\nReview and record your choices:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>'To continue teaching, review your class recording and research choices in the Teacher Portal.',
            ),
            'consent.student_required.student'=>array(
                'label'=>'Student Consent Required','template'=>'consent_required','audience'=>'student','channels'=>array('email','website'),'review'=>array(),
                'variables'=>array('name','portal_url'),
                'fa_subject'=>'لطفاً انتخاب‌های ضبط کلاس و پژوهش را بررسی کنید',
                'fa_email'=>"درود {{name}}،\n\nکلاس‌ها به‌طور پیش‌فرض ضبط می‌شوند؛ می‌توانید ضبط را از حساب کاربری خاموش کنید و پژوهش را جداگانه انتخاب کنید.\n\nبررسی و ثبت انتخاب‌ها:\n{{portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>'کلاس‌ها به‌طور پیش‌فرض ضبط می‌شوند؛ می‌توانید ضبط را از حساب کاربری خاموش کنید و پژوهش را جداگانه انتخاب کنید.',
                'en_subject'=>'Please review your class recording and research choices',
                'en_email'=>"Hello {{name}},\n\nClasses are recorded by default. You can turn recording off in your account, and research is a separate choice.\n\nReview and record your choices:\n{{portal_url}}\n\nDelnavazan",
                'en_portal'=>'Classes are recorded by default. You can turn recording off in your account, and research is a separate choice.',
            ),
            'consent.guardian_required.guardian'=>array(
                'label'=>'Guardian Consent Required','template'=>'consent_required','audience'=>'guardian','channels'=>array('email','website'),'review'=>array(),
                'variables'=>array('portal_url'),
                'fa_subject'=>'تأیید سرپرست برای ضبط کلاس و پژوهش لازم است',
                'fa_email'=>"درود،\n\nبرای هنرجوی زیر ۱۸ سال، تصمیم دربارهٔ ضبط کلاس و پژوهش نیازمند تأیید سرپرست دارای اختیار است. لطفاً از حساب کاربری اقدام کنید:\n{{portal_url}}\n\nهیچ کلاسی بدون این تأیید ضبط نمی‌شود.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'برای هنرجوی زیر ۱۸ سال، تصمیم دربارهٔ ضبط کلاس و پژوهش نیازمند تأیید سرپرست دارای اختیار است. لطفاً از حساب کاربری اقدام کنید.',
                'en_subject'=>'Guardian approval is required for recording and research',
                'en_email'=>"Hello,\n\nFor a student under 18, the decision about class recording and research requires approval from a guardian with authority. Please act in the account:\n{{portal_url}}\n\nNo class is recorded without this approval.\n\nDelnavazan",
                'en_portal'=>'For a student under 18, the decision about class recording and research requires approval from a guardian with authority. Please act in the account.',
            ),
            'consent.recorded.teacher'=>array(
                'label'=>'Teacher Consent Recorded','template'=>'consent_recorded','audience'=>'teacher','channels'=>array('website'),'review'=>array(),
                'variables'=>array('teacher_portal_url'),
                'fa_subject'=>'انتخاب شما ثبت شد',
                'fa_email'=>"درود،\n\nانتخاب شما ثبت شد. تاریخچهٔ تصمیم‌ها در پرتال مدرس قابل مشاهده است:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>'انتخاب شما ثبت شد. تاریخچهٔ تصمیم‌ها در پرتال مدرس قابل مشاهده است.',
                'en_subject'=>'Your choice was recorded',
                'en_email'=>"Hello,\n\nYour choice was recorded. The decision history is available in the Teacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>'Your choice was recorded. The decision history is available in the Teacher Portal.',
            ),
            'consent.recorded.student'=>array(
                'label'=>'Student Consent Recorded','template'=>'consent_recorded','audience'=>'student','channels'=>array('website'),'review'=>array(),
                'variables'=>array('portal_url'),
                'fa_subject'=>'انتخاب شما ثبت شد',
                'fa_email'=>"درود،\n\nانتخاب شما ثبت شد. تاریخچهٔ تصمیم‌ها در حساب کاربری قابل مشاهده است:\n{{portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>'انتخاب شما ثبت شد. تاریخچهٔ تصمیم‌ها در حساب کاربری قابل مشاهده است.',
                'en_subject'=>'Your choice was recorded',
                'en_email'=>"Hello,\n\nYour choice was recorded. The decision history is available in your account:\n{{portal_url}}\n\nDelnavazan",
                'en_portal'=>'Your choice was recorded. The decision history is available in your account.',
            ),
            'consent.policy_updated.student'=>array(
                'label'=>'Student Consent Policy Updated','template'=>'consent_policy_updated','audience'=>'student','channels'=>array('email','website'),'review'=>array(),
                'variables'=>array('portal_url'),
                'fa_subject'=>'اطلاعیهٔ ضبط و پژوهش به‌روزرسانی شد',
                'fa_email'=>"درود،\n\nاطلاعیهٔ ضبط یا پژوهش به‌روزرسانی شده است. لطفاً نسخهٔ تازه را در حساب کاربری بررسی کنید:\n{{portal_url}}\n\nانتخاب‌های پیشین شما تغییر نکرده است؛ اگر بخواهید می‌توانید آن‌ها را به‌روزرسانی کنید.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'اطلاعیهٔ ضبط یا پژوهش به‌روزرسانی شده است. لطفاً نسخهٔ تازه را در حساب کاربری بررسی کنید.',
                'en_subject'=>'Recording and research notice updated',
                'en_email'=>"Hello,\n\nThe class recording or research notice has been updated. Please review the current version in your account:\n{{portal_url}}\n\nYour previous choices are unchanged; you can update them if you wish.\n\nDelnavazan",
                'en_portal'=>'The class recording or research notice has been updated. Please review the current version in your account.',
            ),
            'consent.policy_updated.teacher'=>array(
                'label'=>'Teacher Consent Policy Updated','template'=>'consent_policy_updated','audience'=>'teacher','channels'=>array('email','website'),'review'=>array(),
                'variables'=>array('teacher_portal_url'),
                'fa_subject'=>'اطلاعیهٔ ضبط و پژوهش به‌روزرسانی شد',
                'fa_email'=>"درود،\n\nاطلاعیهٔ ضبط یا پژوهش به‌روزرسانی شده است. لطفاً نسخهٔ تازه را در پرتال مدرس بررسی کنید:\n{{teacher_portal_url}}\n\nانتخاب‌های پیشین شما تغییر نکرده است؛ اگر بخواهید می‌توانید آن‌ها را به‌روزرسانی کنید.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'اطلاعیهٔ ضبط یا پژوهش به‌روزرسانی شده است. لطفاً نسخهٔ تازه را در پرتال مدرس بررسی کنید.',
                'en_subject'=>'Recording and research notice updated',
                'en_email'=>"Hello,\n\nThe class recording or research notice has been updated. Please review the current version in the Teacher Portal:\n{{teacher_portal_url}}\n\nYour previous choices are unchanged; you can update them if you wish.\n\nDelnavazan",
                'en_portal'=>'The class recording or research notice has been updated. Please review the current version in the Teacher Portal.',
            ),
            'consent.withdrawal_confirmed.teacher'=>array(
                'label'=>'Teacher Consent Withdrawal Confirmed','template'=>'consent_withdrawal','audience'=>'teacher','channels'=>array('email','website'),'review'=>array(),
                'variables'=>array('teacher_portal_url'),
                'fa_subject'=>'درخواست پس گرفتن رضایت ثبت شد',
                'fa_email'=>"درود،\n\nدرخواست پس گرفتن رضایت ثبت شد. پردازش‌های آیندهٔ وابسته به آن رضایت متوقف می‌شود. تصمیم‌های پیشین و سابقهٔ آموزشی شما بدون تغییر باقی می‌ماند.\n\nجزئیات در پرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>'درخواست پس گرفتن رضایت شما ثبت شد. پردازش‌های آیندهٔ وابسته متوقف می‌شود و سابقهٔ تدریس شما بدون تغییر می‌ماند.',
                'en_subject'=>'Consent withdrawal recorded',
                'en_email'=>"Hello,\n\nYour consent withdrawal request was recorded. Future processing that depends on that consent stops. Previous decisions and your teaching record remain unchanged.\n\nDetails in the Teacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>'Your consent withdrawal was recorded. Future dependent processing stops and your teaching record is unchanged.',
            ),
            'consent.withdrawal_confirmed.student'=>array(
                'label'=>'Student Consent Withdrawal Confirmed','template'=>'consent_withdrawal','audience'=>'student','channels'=>array('email','website'),'review'=>array(),
                'variables'=>array('portal_url'),
                'fa_subject'=>'درخواست پس گرفتن رضایت ثبت شد',
                'fa_email'=>"درود،\n\nدرخواست پس گرفتن رضایت ثبت شد. پردازش‌های آیندهٔ وابسته به آن رضایت متوقف می‌شود. تصمیم‌های پیشین و سابقهٔ آموزشی شما بدون تغییر باقی می‌ماند.\n\nجزئیات در حساب کاربری:\n{{portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>'درخواست پس گرفتن رضایت شما ثبت شد. پردازش‌های آیندهٔ وابسته متوقف می‌شود و سابقهٔ آموزشی شما بدون تغییر می‌ماند.',
                'en_subject'=>'Consent withdrawal recorded',
                'en_email'=>"Hello,\n\nYour consent withdrawal request was recorded. Future processing that depends on that consent stops. Previous decisions and your learning record remain unchanged.\n\nDetails in your account:\n{{portal_url}}\n\nDelnavazan",
                'en_portal'=>'Your consent withdrawal was recorded. Future dependent processing stops and your learning record is unchanged.',
            ),
            'consent.admin_attention.admin'=>array(
                'label'=>'Consent Action Required','template'=>'consent_attention','audience'=>'admin','channels'=>array('website'),'review'=>array(),
                'variables'=>array('when'),
                'fa_subject'=>'مورد رضایت نیازمند بررسی مدیر است',
                'fa_email'=>"درود،\n\nیک مورد رضایت یا پردازش پس از پس گرفتن نیازمند بررسی مدیر است. زمان: {{when}}\n\nدلنوازان",
                'fa_portal'=>'یک مورد رضایت یا پردازش پس از پس گرفتن نیازمند بررسی مدیر است. زمان: {{when}}',
                'en_subject'=>'Consent case requires administrator review',
                'en_email'=>"Hello,\n\nA consent case, or processing after a withdrawal, requires administrator review. Time: {{when}}\n\nDelnavazan",
                'en_portal'=>'A consent case, or processing after a withdrawal, requires administrator review. Time: {{when}}',
            ),
        );
        $out=array();
        foreach($definitions as $key=>$definition){
            $channels=array_values(array_unique((array)$definition['channels']));
            $defaults=array();
            foreach(self::SUPPORTED_LOCALES as $locale){
                $prefix=$locale===self::PRIMARY_LOCALE?'fa_':'en_';
                $copy=array();
                foreach($channels as $channel){
                    if($channel==='website'){$copy['website']=array('subject'=>(string)$definition[$prefix.'subject'],'body'=>(string)$definition[$prefix.'portal']);continue;}
                    if($channel==='email'){$copy['email']=array('subject'=>($locale===self::PRIMARY_LOCALE?'دلنوازان · ':'').(string)$definition[$prefix.'subject'],'body'=>(string)$definition[$prefix.'email']);continue;}
                }
                $defaults[$locale]=$copy;
            }
            $event=self::event($key,(string)$definition['label'],'account',(string)$definition['audience'],'ConsentNotificationService','queue',$channels,(string)$definition['template'],false,(array)$definition['variables'],(string)$definition['template'],$defaults,(array)$definition['review']);
            // The producer is registered but the consent worker does not yet publish every notice.
            $event['implementation']='partial';
            $out[]=$event;
        }
        return $out;
    }

    // ------------------------------------------------------------------ renewal automation events

    /**
     * The six renewal notices the dedicated RenewalAutomationNotificationService produces: the three
     * event-driven notices after the authoritative R2 aggregate fact commits and the three
     * clock-driven notices through the bounded RenewalAutomationWorker scheduler. Each is a student,
     * financial, mandatory event delivered over email, website and WhatsApp; the WhatsApp leg is
     * bound to the closed provider-template registry (verified at dispatch, never free-form).
     */
    private static function renewalAutomationEvents():array{
        $definitions=array(
            'renewal.automatic_upcoming.student'=>array('label'=>'Automatic Renewal Upcoming','intent'=>'AUTOMATIC_RENEWAL_UPCOMING',
                'fa_subject'=>'تمدید خودکار ترم در پیش است',
                'fa_email'=>"درود {{name}}،\n\nترم «{{course}}» شما رو به پایان است و تمدید خودکار آن در {{due_date}} انجام می‌شود.\n\nمبلغ تمدید: {{amount}}\n\nاگر نمی‌خواهید ترم را ادامه دهید یا می‌خواهید روش پرداخت را تغییر دهید، پیش از {{due_date}} از حساب کاربری اقدام کنید:\n{{portal_url}}\n\nنتیجهٔ تمدید پس از پردازش در همین حساب کاربری نمایش داده می‌شود.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'تمدید خودکار ترم «{{course}}» در {{due_date}} انجام می‌شود. مبلغ تمدید: {{amount}}.',
                'fa_whatsapp'=>"درود {{name}} عزیز، تمدید خودکار ترم «{{course}}» در {{due_date}} انجام می‌شود. مبلغ تمدید: {{amount}}. برای تغییر روش پرداخت یا ادامه‌ندادن، پیش از این تاریخ از حساب کاربری اقدام کنید: {{portal_url}} — دلنوازان",
                'en_subject'=>'Your automatic term renewal is coming up',
                'en_email'=>"Hello {{name}},\n\nYour {{course}} term is ending and its automatic renewal will be processed on {{due_date}}.\n\nRenewal amount: {{amount}}\n\nIf you do not want to continue the term, or you need a different payment method, act in your account before {{due_date}}:\n{{portal_url}}\n\nThe renewal result is shown in the same account after processing.\n\nDelnavazan",
                'en_portal'=>'Your {{course}} term renews automatically on {{due_date}}. Renewal amount: {{amount}}.',
                'en_whatsapp'=>"Hello {{name}}, your {{course}} term renews automatically on {{due_date}}. Renewal amount: {{amount}}. To change the payment method or stop continuing, act in your account before that date: {{portal_url}} — Delnavazan"),
            'renewal.automatic_charged.student'=>array('label'=>'Automatic Renewal Charged','intent'=>'AUTOMATIC_RENEWAL_CHARGED',
                'fa_subject'=>'تمدید خودکار انجام شد',
                'fa_email'=>"درود {{name}}،\n\nتمدید خودکار ترم «{{course}}» انجام شد.\n\nمبلغ پرداخت‌شده: {{amount}}\nتاریخ پرداخت: {{paid_date}}\n\nرسید و جزئیات پرداخت در حساب کاربری در دسترس است:\n{{portal_url}}\n\nاگر این پرداخت را انتظار نداشتید، از راه‌های ارتباطی حساب کاربری با ما در تماس باشید.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'تمدید خودکار ترم «{{course}}» با مبلغ {{amount}} در {{paid_date}} انجام شد.',
                'fa_whatsapp'=>"درود {{name}} عزیز، تمدید خودکار ترم «{{course}}» انجام شد. مبلغ: {{amount}}، تاریخ پرداخت: {{paid_date}}. جزئیات در حساب کاربری: {{portal_url}} — دلنوازان",
                'en_subject'=>'Your automatic term renewal was processed',
                'en_email'=>"Hello {{name}},\n\nYour automatic renewal for the {{course}} term was processed.\n\nAmount charged: {{amount}}\nPayment date: {{paid_date}}\n\nThe receipt and payment details are available in your account:\n{{portal_url}}\n\nIf you did not expect this payment, contact us through your account.\n\nDelnavazan",
                'en_portal'=>'Your {{course}} term was renewed automatically for {{amount}} on {{paid_date}}.',
                'en_whatsapp'=>"Hello {{name}}, your automatic renewal for the {{course}} term was processed. Amount: {{amount}}, payment date: {{paid_date}}. Details in your account: {{portal_url}} — Delnavazan"),
            'renewal.automatic_failed.student'=>array('label'=>'Automatic Renewal Failed','intent'=>'AUTOMATIC_RENEWAL_FAILED',
                'fa_subject'=>'تمدید خودکار ناموفق بود',
                'fa_email'=>"درود {{name}}،\n\nتمدید خودکار ترم «{{course}}» انجام نشد.\n\nمبلغ: {{amount}}\nمهلت اقدام: {{due_date}}\n\nبرای حفظ جایگاه کلاس، پرداخت تمدید را از بخش پرداخت حساب کاربری تکمیل کنید:\n{{portal_url}}\n\nاگر تا {{due_date}} پرداخت تکمیل نشود یا با مدرس یا آکادمی هماهنگ نکنید، این جایگاه آزاد می‌شود.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'تمدید خودکار ترم «{{course}}» انجام نشد. تا {{due_date}} از بخش پرداخت حساب کاربری اقدام کنید.',
                'fa_whatsapp'=>"درود {{name}} عزیز، تمدید خودکار ترم «{{course}}» انجام نشد. مبلغ: {{amount}}، مهلت اقدام: {{due_date}}. برای حفظ جایگاه از حساب کاربری پرداخت کنید: {{portal_url}} — دلنوازان",
                'en_subject'=>'Your automatic term renewal did not complete',
                'en_email'=>"Hello {{name}},\n\nYour automatic renewal for the {{course}} term did not complete.\n\nAmount: {{amount}}\nAction deadline: {{due_date}}\n\nTo keep your class place, complete the renewal payment from the payments section of your account:\n{{portal_url}}\n\nIf payment is not completed by {{due_date}} and you have not agreed otherwise with your Teacher or the academy, this place is released.\n\nDelnavazan",
                'en_portal'=>'Your automatic {{course}} renewal did not complete. Act in the payments section of your account by {{due_date}}.',
                'en_whatsapp'=>"Hello {{name}}, your automatic renewal for the {{course}} term did not complete. Amount: {{amount}}, action deadline: {{due_date}}. Pay from your account to keep your place: {{portal_url}} — Delnavazan"),
            'renewal.manual_payment_required.student'=>array('label'=>'Renewal Payment Required','intent'=>'MANUAL_RENEWAL_PAYMENT_REQUIRED',
                'fa_subject'=>'پرداخت تمدید ترم لازم است',
                'fa_email'=>"درود {{name}}،\n\nبرای ادامهٔ ترم «{{course}}»، پرداخت تمدید لازم است.\n\nمبلغ: {{amount}}\nمهلت: {{due_date}}\n\nپرداخت را از بخش پرداخت حساب کاربری انجام دهید:\n{{portal_url}}\n\nپس از تأیید پرداخت، ادامهٔ کلاس‌ها به‌صورت خودکار فعال می‌شود.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'برای ادامهٔ ترم «{{course}}» پرداخت تمدید لازم است. مبلغ: {{amount}}، مهلت: {{due_date}}.',
                'fa_whatsapp'=>"درود {{name}} عزیز، برای ادامهٔ ترم «{{course}}» پرداخت تمدید لازم است. مبلغ: {{amount}}، مهلت: {{due_date}}. پرداخت از حساب کاربری: {{portal_url}} — دلنوازان",
                'en_subject'=>'Your term renewal payment is required',
                'en_email'=>"Hello {{name}},\n\nA renewal payment is required to continue the {{course}} term.\n\nAmount: {{amount}}\nDeadline: {{due_date}}\n\nPay from the payments section of your account:\n{{portal_url}}\n\nYour classes continue automatically after the payment is verified.\n\nDelnavazan",
                'en_portal'=>'A renewal payment is required to continue the {{course}} term. Amount: {{amount}}, deadline: {{due_date}}.',
                'en_whatsapp'=>"Hello {{name}}, a renewal payment is required to continue the {{course}} term. Amount: {{amount}}, deadline: {{due_date}}. Pay from your account: {{portal_url}} — Delnavazan"),
            'renewal.guarantee_deadline_approaching.student'=>array('label'=>'Renewal Deadline Approaching','intent'=>'GUARANTEE_DEADLINE_APPROACHING',
                'fa_subject'=>'مهلت تمدید ترم نزدیک است',
                'fa_email'=>"درود {{name}}،\n\nمهلت نگه‌داشتن جایگاه شما در ترم «{{course}}» در {{due_date}} به پایان می‌رسد.\n\nمبلغ تمدید: {{amount}}\n\nبرای حفظ جایگاه، پیش از این تاریخ پرداخت را از حساب کاربری تکمیل کنید:\n{{portal_url}}\n\nپس از پایان مهلت، جایگاه آزاد می‌شود و در دسترس هنرجویان دیگر قرار می‌گیرد.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'مهلت نگه‌داشتن جایگاه شما در ترم «{{course}}» در {{due_date}} پایان می‌یابد.',
                'fa_whatsapp'=>"درود {{name}} عزیز، مهلت نگه‌داشتن جایگاه ترم «{{course}}» در {{due_date}} پایان می‌یابد. مبلغ تمدید: {{amount}}. پرداخت از حساب کاربری: {{portal_url}} — دلنوازان",
                'en_subject'=>'Your term renewal deadline is approaching',
                'en_email'=>"Hello {{name}},\n\nYour place in the {{course}} term is held until {{due_date}}.\n\nRenewal amount: {{amount}}\n\nTo keep your place, complete the payment in your account before that date:\n{{portal_url}}\n\nAfter the deadline the place is released for other students.\n\nDelnavazan",
                'en_portal'=>'Your place in the {{course}} term is held until {{due_date}}.',
                'en_whatsapp'=>"Hello {{name}}, your place in the {{course}} term is held until {{due_date}}. Renewal amount: {{amount}}. Pay from your account: {{portal_url}} — Delnavazan"),
            'renewal.term_lapsed.student'=>array('label'=>'Term Lapsed','intent'=>'TERM_LAPSED',
                'fa_subject'=>'دورهٔ شما به پایان رسید',
                'fa_email'=>"درود {{name}}،\n\nترم «{{course}}» به پایان رسید و تمدید آن تکمیل نشده است. جایگاه کلاس شما دیگر رزرو نیست و در دسترس هنرجویان دیگر قرار گرفته است.\n\nبرای شروع ترم تازه، از حساب کاربری اقدام کنید:\n{{portal_url}}\n\nاگر پیش از این پرداختی انجام داده‌اید و وضعیت آن در حساب کاربری به‌روز نیست، از راه‌های ارتباطی حساب کاربری با ما در تماس باشید.\n\nبا احترام، دلنوازان",
                'fa_portal'=>'ترم «{{course}}» به پایان رسید و تمدید آن تکمیل نشد. برای شروع ترم تازه از حساب کاربری اقدام کنید.',
                'fa_whatsapp'=>"درود {{name}} عزیز، ترم «{{course}}» به پایان رسید و تمدید آن تکمیل نشد. برای شروع ترم تازه از حساب کاربری اقدام کنید: {{portal_url}} — دلنوازان",
                'en_subject'=>'Your term has ended',
                'en_email'=>"Hello {{name}},\n\nYour {{course}} term has ended and the renewal was not completed. Your class place is no longer reserved and has been made available to other students.\n\nTo start a new term, act in your account:\n{{portal_url}}\n\nIf you already made a payment and its status is not reflected in your account, contact us through your account.\n\nDelnavazan",
                'en_portal'=>'Your {{course}} term has ended and the renewal was not completed. Start a new term from your account.',
                'en_whatsapp'=>"Hello {{name}}, your {{course}} term has ended and the renewal was not completed. Start a new term from your account: {{portal_url}} — Delnavazan"),
        );
        $variables=array(
            'renewal.automatic_upcoming.student'=>array('name','course','due_date','amount','portal_url'),
            'renewal.automatic_charged.student'=>array('name','course','amount','paid_date','portal_url'),
            'renewal.automatic_failed.student'=>array('name','course','amount','due_date','portal_url'),
            'renewal.manual_payment_required.student'=>array('name','course','amount','due_date','portal_url'),
            'renewal.guarantee_deadline_approaching.student'=>array('name','course','due_date','amount','portal_url'),
            'renewal.term_lapsed.student'=>array('name','course','portal_url'),
        );
        $out=array();
        foreach($definitions as $key=>$definition){
            $defaults=array();
            foreach(self::SUPPORTED_LOCALES as $locale){
                $prefix=$locale===self::PRIMARY_LOCALE?'fa_':'en_';
                $defaults[$locale]=array(
                    'email'=>array('subject'=>($locale===self::PRIMARY_LOCALE?'دلنوازان · ':'').(string)$definition[$prefix.'subject'],'body'=>(string)$definition[$prefix.'email']),
                    'website'=>array('subject'=>(string)$definition[$prefix.'subject'],'body'=>(string)$definition[$prefix.'portal']),
                    'whatsapp'=>array('body'=>(string)$definition[$prefix.'whatsapp']),
                );
            }
            $out[]=self::event($key,(string)$definition['label'],'payment','student','RenewalAutomationNotificationService','queue',array('email','website','whatsapp'),'financial',true,$variables[$key],strtolower(str_replace('.student','',$key)),$defaults);
        }
        return $out;
    }

    /**
     * The four payment/refund notices the dedicated PaymentRefundNotificationService produces after
     * the authoritative R2 finance aggregate fact commits. Each is a student, financial, mandatory
     * event delivered over email, website and WhatsApp; the WhatsApp leg is bound to the closed
     * provider-template registry (verified at dispatch, never free-form).
     */
    private static function paymentRefundEvents():array{
        $out=array();
        $out[]=self::event('payment.failed.student','Payment Failed','payment','student','PaymentRefundNotificationService','queue',array('email','website','whatsapp'),'financial',true,array('name','course','amount','portal_url'),'payment.failed',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · پرداخت انجام نشد','body'=>"درود {{name}}،\n\nپرداخت شما در ترم «{{course}}» انجام نشد.\n\nمبلغ: {{amount}}\n\nمی‌توانید پرداخت را دوباره از بخش پرداخت حساب کاربری انجام دهید:\n{{portal_url}}\n\nاگر مبلغی از حساب شما کسر شده باشد، وضعیت آن بر اساس نتیجهٔ بانک در حساب کاربری پیگیری و نمایش داده می‌شود. کلاس یا جلسهٔ شما با این پیام لغو نمی‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'پرداخت انجام نشد','body'=>'پرداخت ترم «{{course}}» انجام نشد. برای تلاش دوباره به بخش پرداخت حساب کاربری بروید.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، پرداخت ترم «{{course}}» انجام نشد. مبلغ: {{amount}}. برای تلاش دوباره از حساب کاربری اقدام کنید: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your payment did not complete','body'=>"Hello {{name}},\n\nYour payment for the {{course}} term did not complete.\n\nAmount: {{amount}}\n\nYou can try again from the payments section of your account:\n{{portal_url}}\n\nIf an amount was deducted from your account, its status is tracked and shown in your account based on the bank result. This message does not cancel your class or session.\n\nDelnavazan"),
                'website'=>array('subject'=>'Your payment did not complete','body'=>'Your {{course}} payment did not complete. Try again from the payments section of your account.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your payment for the {{course}} term did not complete. Amount: {{amount}}. Try again from your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('payment.recovered.student','Payment Recovered','payment','student','PaymentRefundNotificationService','queue',array('email','website','whatsapp'),'financial',true,array('name','course','amount','paid_date','portal_url'),'payment.recovered',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · پرداخت شما تأیید شد','body'=>"درود {{name}}،\n\nپرداخت شما برای ترم «{{course}}» تأیید شد.\n\nمبلغ: {{amount}}\nتاریخ: {{paid_date}}\n\nرسید و جزئیات پرداخت در حساب کاربری در دسترس است:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'پرداخت شما تأیید شد','body'=>'پرداخت ترم «{{course}}» با مبلغ {{amount}} در {{paid_date}} تأیید شد.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، پرداخت ترم «{{course}}» تأیید شد. مبلغ: {{amount}}، تاریخ: {{paid_date}}. جزئیات در حساب کاربری: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your payment was confirmed','body'=>"Hello {{name}},\n\nYour payment for the {{course}} term was confirmed.\n\nAmount: {{amount}}\nDate: {{paid_date}}\n\nThe receipt and payment details are available in your account:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Your payment was confirmed','body'=>'Your {{course}} payment of {{amount}} was confirmed on {{paid_date}}.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your {{course}} payment was confirmed. Amount: {{amount}}, date: {{paid_date}}. Details in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('refund.review_required.student','Refund Under Review','payment','student','PaymentRefundNotificationService','queue',array('email','website','whatsapp'),'financial',true,array('name','course','amount','portal_url'),'refund.review_required',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست بازپرداخت در حال بررسی است','body'=>"درود {{name}}،\n\nدرخواست بازپرداخت شما برای ترم «{{course}}» ثبت شد و در حال بررسی است.\n\nمبلغ درخواستی: {{amount}}\n\nنتیجهٔ بررسی، همراه با مبلغ نهایی و هر توضیح لازم، در حساب کاربری نمایش داده می‌شود:\n{{portal_url}}\n\nتا زمان اعلام نتیجه، وضعیت کلاس و پرداخت‌های شما تغییری نمی‌کند.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'درخواست بازپرداخت در حال بررسی است','body'=>'درخواست بازپرداخت شما ثبت شد و در حال بررسی است. مبلغ درخواستی: {{amount}}.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، درخواست بازپرداخت شما ثبت شد و در حال بررسی است. مبلغ درخواستی: {{amount}}. نتیجه در حساب کاربری اعلام می‌شود: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your refund request is under review','body'=>"Hello {{name}},\n\nYour refund request for the {{course}} term was recorded and is under review.\n\nRequested amount: {{amount}}\n\nThe review outcome, the final amount and any explanation are shown in your account:\n{{portal_url}}\n\nUntil the outcome is announced, your class and payment status are unchanged.\n\nDelnavazan"),
                'website'=>array('subject'=>'Your refund request is under review','body'=>'Your refund request was recorded and is under review. Requested amount: {{amount}}.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, your refund request was recorded and is under review. Requested amount: {{amount}}. The outcome is announced in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        $out[]=self::event('refund.resolved.student','Refund Resolved','payment','student','PaymentRefundNotificationService','queue',array('email','website','whatsapp'),'financial',true,array('name','course','amount','portal_url'),'refund.resolved',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · نتیجهٔ درخواست بازپرداخت','body'=>"درود {{name}}،\n\nبررسی درخواست بازپرداخت شما برای ترم «{{course}}» به پایان رسید.\n\nمبلغ درخواستی: {{amount}}\n\nنتیجهٔ نهایی و جزئیات مبلغ در حساب کاربری قابل مشاهده است:\n{{portal_url}}\n\nاگر پرسشی دارید، از راه‌های ارتباطی حساب کاربری با ما در تماس باشید.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'نتیجهٔ درخواست بازپرداخت','body'=>'بررسی درخواست بازپرداخت شما به پایان رسید. نتیجهٔ نهایی در حساب کاربری قابل مشاهده است.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، بررسی درخواست بازپرداخت شما به پایان رسید. مبلغ درخواستی: {{amount}}. نتیجهٔ نهایی در حساب کاربری: {{portal_url}} — دلنوازان'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'The outcome of your refund request','body'=>"Hello {{name}},\n\nThe review of your refund request for the {{course}} term is complete.\n\nRequested amount: {{amount}}\n\nThe final outcome and amount details are available in your account:\n{{portal_url}}\n\nIf you have a question, contact us through your account.\n\nDelnavazan"),
                'website'=>array('subject'=>'The outcome of your refund request','body'=>'The review of your refund request is complete. The final outcome is available in your account.'),
                'whatsapp'=>array('body'=>'Hello {{name}}, the review of your refund request is complete. Requested amount: {{amount}}. The final outcome is in your account: {{portal_url}} — Delnavazan'),
            ),
        ));
        return $out;
    }

    // ------------------------------------------------------------------ reschedule families

    private static function appendRescheduleStudent(array &$out):void{
        foreach(self::rescheduleKinds() as $kind=>$meta){
            foreach(self::rescheduleStudentCopy($kind) as $state=>$copy){
                $variables=array('name','portal_url');
                if($state==='proposed')$variables=array_merge($variables,array('when','timezone','teacher','course','current_when','new_when'));
                elseif($state==='accepted')$variables=array_merge($variables,array('when','timezone','course','teacher','previous_when','new_when'));
                elseif($state==='rejected')$variables=array_merge($variables,array('course','teacher','when'));
                $out[]=self::event($meta['prefix'].'.'.$state.'.student',ucfirst($meta['label']).' · '.ucfirst($state).' · Student','lesson','student','LessonRescheduleService','queue',array('email','website','whatsapp'),'operational',true,$variables,$meta['prefix'].'.'.$state,array(
                    self::PRIMARY_LOCALE=>array(
                        'email'=>array('subject'=>$copy['fa_subject'],'body'=>$copy['fa_email']),
                        'website'=>array('subject'=>$meta['fa'],'body'=>$copy['fa_portal']),
                        'whatsapp'=>array('body'=>$copy['fa_whatsapp']),
                    ),
                    'en'=>array(
                        'email'=>array('subject'=>$copy['en_subject'],'body'=>$copy['en_email']),
                        'website'=>array('subject'=>$meta['label'],'body'=>$copy['en_portal']),
                        'whatsapp'=>array('body'=>$copy['en_whatsapp']),
                    ),
                ));
            }
        }
    }

    private static function appendRescheduleTeacher(array &$out):void{
        foreach(self::rescheduleKinds() as $kind=>$meta){
            foreach(self::rescheduleTeacherCopy($kind) as $state=>$copy){
                $variables=array('teacher','teacher_portal_url');
                if($state==='proposed')$variables=array_merge($variables,array('when','timezone','course','student','current_when','new_when'));
                elseif($state==='accepted')$variables=array_merge($variables,array('when','timezone','course','student','previous_when','new_when'));
                elseif($state==='rejected')$variables=array_merge($variables,array('course','student','when'));
                $labelFa=$kind==='replacement'?'جلسهٔ جبرانی':'تغییر زمان کلاس';
                $labelEn=$kind==='replacement'?'replacement session':'lesson change';
                $faSms=array(
                    'proposed'=>'دلنوازان: پیشنهاد '.$labelFa.' برای {{when}} ثبت شد. پرتال مدرس: {{teacher_portal_url}}',
                    'accepted'=>'دلنوازان: هنرجو '.$labelFa.' را برای {{when}} پذیرفت. پرتال مدرس: {{teacher_portal_url}}',
                    'rejected'=>'دلنوازان: هنرجو پیشنهاد '.$labelFa.' را رد کرد و زمان فعلی {{when}} تغییر نمی‌کند. پرتال مدرس: {{teacher_portal_url}}',
                    'failed'=>'دلنوازان: پیشنهاد '.$labelFa.' ثبت نشد و بسته شد. پرتال مدرس: {{teacher_portal_url}}',
                    'cancelled'=>'دلنوازان: پیشنهاد '.$labelFa.' توسط مدیر لغو شد. پرتال مدرس: {{teacher_portal_url}}',
                );
                $enSms=array(
                    'proposed'=>'Delnavazan: {{when}} was proposed for your '.$labelEn.'. Teacher Portal: {{teacher_portal_url}}',
                    'accepted'=>'Delnavazan: the student accepted {{when}} for your '.$labelEn.'. Teacher Portal: {{teacher_portal_url}}',
                    'rejected'=>'Delnavazan: the student declined the '.$labelEn.' proposal; the time {{when}} is unchanged. Portal: {{teacher_portal_url}}',
                    'failed'=>'Delnavazan: the '.$labelEn.' proposal could not be recorded and was closed. Portal: {{teacher_portal_url}}',
                    'cancelled'=>'Delnavazan: the '.$labelEn.' proposal was cancelled by an administrator. Portal: {{teacher_portal_url}}',
                );
                $out[]=self::event($meta['prefix'].'.'.$state.'.teacher',ucfirst($meta['label']).' · '.ucfirst($state).' · Teacher','lesson','teacher','LessonRescheduleService','queue',array('email','website','sms'),'operational',true,$variables,$meta['prefix'].'.'.$state,array(
                    self::PRIMARY_LOCALE=>array(
                        'email'=>array('subject'=>$copy['fa_subject'],'body'=>$copy['fa_email']),
                        'website'=>array('subject'=>$meta['fa'],'body'=>$copy['fa_portal']),
                        'sms'=>array('body'=>$faSms[$state]),
                        'whatsapp'=>array('body'=>$copy['fa_whatsapp']),
                    ),
                    'en'=>array(
                        'email'=>array('subject'=>$copy['en_subject'],'body'=>$copy['en_email']),
                        'website'=>array('subject'=>$meta['label'],'body'=>$copy['en_portal']),
                        'sms'=>array('body'=>$enSms[$state]),
                        'whatsapp'=>array('body'=>$copy['en_whatsapp']),
                    ),
                ));
            }
        }
    }

    /**
     * Student copy for one reschedule family. Each state has its own email body, portal summary and
     * WhatsApp message, and every message names the family so a lesson-time change is never presented
     * with the wording of a replacement session (or the reverse).
     *
     * @return array<string,array<string,string>>
     */
    private static function rescheduleStudentCopy(string $kind):array{
        $labelFa=$kind==='replacement'?'جلسهٔ جبرانی':'تغییر زمان کلاس';
        $labelEn=$kind==='replacement'?'replacement session':'lesson time change';
        return array(
            'proposed'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa,
                'fa_email'=>"درود {{name}}،\n\nبرای ".$labelFa." شما یک زمان تازه پیشنهاد شد:\n{{when}} ({{timezone}})\n\nلطفاً در حساب کاربری آن را بپذیرید یا رد کنید:\n{{portal_url}}\n\nتا پاسخ شما، زمان فعلی کلاس تغییر نمی‌کند.\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — زمان تازه‌ای پیشنهاد شد: {{when}} ({{timezone}}). تا پاسخ شما زمان فعلی تغییر نمی‌کند.',
                'fa_whatsapp'=>'درود {{name}} عزیز، برای '.$labelFa.' شما زمان {{when}} ({{timezone}}) پیشنهاد شد. تا پاسخ شما زمان فعلی تغییر نمی‌کند. پذیرش یا رد در حساب کاربری: {{portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn,
                'en_email'=>"Hello {{name}},\n\nA new time has been proposed for your ".$labelEn.":\n{{when}} ({{timezone}})\n\nAccept or decline it in your account:\n{{portal_url}}\n\nYour current class time does not change until you respond.\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — a new time was proposed: {{when}} ({{timezone}}). Your current time is unchanged until you respond.',
                'en_whatsapp'=>'Hello {{name}}, a new time was proposed for your '.$labelEn.': {{when}} ({{timezone}}). Your current time is unchanged until you respond. Accept or decline in your account: {{portal_url}} — Delnavazan',
            ),
            'accepted'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · تأیید زمان پیشنهادی',
                'fa_email'=>"درود {{name}}،\n\nزمان پیشنهادی برای ".$labelFa." تأیید شد.\n\nزمان جدید: {{when}} ({{timezone}})\n\nجزئیات در حساب کاربری در دسترس است:\n{{portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — زمان پیشنهادی تأیید شد. زمان جدید: {{when}} ({{timezone}}).',
                'fa_whatsapp'=>'درود {{name}} عزیز، زمان پیشنهادی '.$labelFa.' تأیید شد. زمان جدید: {{when}} ({{timezone}}). جزئیات در حساب کاربری: {{portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time confirmed',
                'en_email'=>"Hello {{name}},\n\nThe proposed time for your ".$labelEn." was confirmed.\n\nNew time: {{when}} ({{timezone}})\n\nDetails are available in your account:\n{{portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the proposed time was confirmed. New time: {{when}} ({{timezone}}).',
                'en_whatsapp'=>'Hello {{name}}, the proposed time for your '.$labelEn.' was confirmed. New time: {{when}} ({{timezone}}). Details in your account: {{portal_url}} — Delnavazan',
            ),
            'rejected'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · زمان پیشنهادی رد شد',
                'fa_email'=>"درود {{name}}،\n\nزمان پیشنهادی برای ".$labelFa." رد شد و زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nزمان فعلی: {{when}}\n\nاگر زمان تازه‌ای مناسب شماست، از حساب کاربری با مدرس هماهنگ کنید:\n{{portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — زمان پیشنهادی رد شد و زمان فعلی کلاس بدون تغییر می‌ماند. زمان فعلی: {{when}}.',
                'fa_whatsapp'=>'درود {{name}} عزیز، زمان پیشنهادی '.$labelFa.' رد شد و زمان فعلی {{when}} تغییر نمی‌کند. هماهنگی زمان تازه در حساب کاربری: {{portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time declined',
                'en_email'=>"Hello {{name}},\n\nThe proposed time for your ".$labelEn." was declined and your current class time stays unchanged.\n\nCurrent time: {{when}}\n\nIf you have another time in mind, coordinate with your Teacher from your account:\n{{portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the proposed time was declined; your current class time is unchanged. Current time: {{when}}.',
                'en_whatsapp'=>'Hello {{name}}, the proposed time for your '.$labelEn.' was declined and your current time {{when}} is unchanged. Arrange a new time in your account: {{portal_url}} — Delnavazan',
            ),
            'failed'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · زمان پیشنهادی ثبت نشد',
                'fa_email'=>"درود {{name}}،\n\nزمان پیشنهادی برای ".$labelFa." ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nلطفاً زمان تازه‌ای پیشنهاد کنید یا با پشتیبانی دلنوازان تماس بگیرید:\n{{portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — زمان پیشنهادی ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر می‌ماند.',
                'fa_whatsapp'=>'درود {{name}} عزیز، زمان پیشنهادی '.$labelFa.' ثبت نشد و بسته شد. زمان فعلی کلاس تغییر نمی‌کند. پیگیری در حساب کاربری: {{portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time not recorded',
                'en_email'=>"Hello {{name}},\n\nThe proposed time for your ".$labelEn." could not be recorded and was closed. Your current class time stays unchanged.\n\nPlease propose a new time, or contact Delnavazan support:\n{{portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the proposed time could not be recorded and was closed. Your current class time is unchanged.',
                'en_whatsapp'=>'Hello {{name}}, the proposed time for your '.$labelEn.' could not be recorded and was closed. Your current class time is unchanged. Follow up in your account: {{portal_url}} — Delnavazan',
            ),
            'cancelled'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · پیشنهاد زمان لغو شد',
                'fa_email'=>"درود {{name}}،\n\nپیشنهاد زمان برای ".$labelFa." توسط مدیر لغو شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nجزئیات در حساب کاربری:\n{{portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — پیشنهاد زمان توسط مدیر لغو شد. زمان فعلی کلاس شما بدون تغییر می‌ماند. جزئیات در حساب کاربری.',
                'fa_whatsapp'=>'درود {{name}} عزیز، پیشنهاد زمان '.$labelFa.' توسط مدیر لغو شد. زمان فعلی کلاس تغییر نمی‌کند. جزئیات در حساب کاربری: {{portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time cancelled',
                'en_email'=>"Hello {{name}},\n\nThe proposed time for your ".$labelEn." was cancelled by an administrator. Your current class time stays unchanged.\n\nDetails in your account:\n{{portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the proposed time was cancelled by an administrator. Your current class time is unchanged.',
                'en_whatsapp'=>'Hello {{name}}, the proposed time for your '.$labelEn.' was cancelled by an administrator. Your current class time is unchanged. Details in your account: {{portal_url}} — Delnavazan',
            ),
        );
    }

    /**
     * Teacher copy for one reschedule family. Same structure as the student copy, with the Teacher
     * Portal as the authoritative destination and the family named in every message.
     *
     * @return array<string,array<string,string>>
     */
    private static function rescheduleTeacherCopy(string $kind):array{
        $labelFa=$kind==='replacement'?'جلسهٔ جبرانی':'تغییر زمان کلاس';
        $labelEn=$kind==='replacement'?'replacement session':'lesson time change';
        return array(
            'proposed'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa,
                'fa_email'=>"درود {{teacher}}،\n\nدرخواست ".$labelFa." ثبت شد.\n\nزمان پیشنهادی: {{when}} ({{timezone}})\n\nتا پاسخ هنرجو، این پیشنهاد قطعی نیست. وضعیت را در پرتال مدرس ببینید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — درخواست ثبت شد. زمان پیشنهادی: {{when}} ({{timezone}}). تا پاسخ هنرجو قطعی نیست.',
                'fa_whatsapp'=>'درود {{teacher}} عزیز، درخواست '.$labelFa.' برای {{when}} ({{timezone}}) ثبت شد و تا پاسخ هنرجو قطعی نیست. پرتال مدرس: {{teacher_portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn,
                'en_email'=>"Hello {{teacher}},\n\nA ".$labelEn." request was recorded.\n\nProposed time: {{when}} ({{timezone}})\n\nThe proposal is not final until the student responds. Check the status in the Teacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the request was recorded. Proposed time: {{when}} ({{timezone}}). It is not final until the student responds.',
                'en_whatsapp'=>'Hello {{teacher}}, a '.$labelEn.' request for {{when}} ({{timezone}}) was recorded and is not final until the student responds. Teacher Portal: {{teacher_portal_url}} — Delnavazan',
            ),
            'accepted'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · هنرجو زمان پیشنهادی را پذیرفت',
                'fa_email'=>"درود {{teacher}}،\n\nهنرجو زمان پیشنهادی ".$labelFa." را پذیرفت و زمان {{when}} ({{timezone}}) قطعی شد.\n\nمشاهدهٔ کلاس‌ها:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — هنرجو زمان پیشنهادی را پذیرفت و زمان {{when}} ({{timezone}}) قطعی شد.',
                'fa_whatsapp'=>'درود {{teacher}} عزیز، هنرجو زمان پیشنهادی '.$labelFa.' را پذیرفت و {{when}} ({{timezone}}) قطعی شد. پرتال مدرس: {{teacher_portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time accepted',
                'en_email'=>"Hello {{teacher}},\n\nThe student accepted the proposed time for the ".$labelEn." and {{when}} ({{timezone}}) is now confirmed.\n\nView your classes:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the student accepted the proposed time and {{when}} ({{timezone}}) is confirmed.',
                'en_whatsapp'=>'Hello {{teacher}}, the student accepted the proposed time for the '.$labelEn.' and {{when}} ({{timezone}}) is confirmed. Teacher Portal: {{teacher_portal_url}} — Delnavazan',
            ),
            'rejected'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · هنرجو زمان پیشنهادی را رد کرد',
                'fa_email'=>"درود {{teacher}}،\n\nهنرجو زمان پیشنهادی ".$labelFa." را رد کرد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nزمان فعلی: {{when}}\n\nلطفاً با هنرجو هماهنگ کنید و زمان تازه‌ای پیشنهاد دهید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — هنرجو زمان پیشنهادی را رد کرد. زمان فعلی {{when}} بدون تغییر می‌ماند.',
                'fa_whatsapp'=>'درود {{teacher}} عزیز، هنرجو زمان پیشنهادی '.$labelFa.' را رد کرد و زمان فعلی {{when}} تغییر نمی‌کند. لطفاً زمان تازه‌ای پیشنهاد دهید: {{teacher_portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time declined',
                'en_email'=>"Hello {{teacher}},\n\nThe student declined the proposed time for the ".$labelEn.". The current class time stays unchanged.\n\nCurrent time: {{when}}\n\nPlease coordinate with the student and propose a new time:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the student declined the proposed time. The current time {{when}} is unchanged.',
                'en_whatsapp'=>'Hello {{teacher}}, the student declined the proposed time for the '.$labelEn.' and the current time {{when}} is unchanged. Please propose a new time: {{teacher_portal_url}} — Delnavazan',
            ),
            'failed'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · پیشنهاد زمان ثبت نشد',
                'fa_email'=>"درود {{teacher}}،\n\nپیشنهاد زمان برای ".$labelFa." ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nدر صورت نیاز، زمان تازه‌ای پیشنهاد دهید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — پیشنهاد زمان ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر می‌ماند.',
                'fa_whatsapp'=>'درود {{teacher}} عزیز، پیشنهاد زمان '.$labelFa.' ثبت نشد و بسته شد. زمان فعلی کلاس تغییر نمی‌کند. پرتال مدرس: {{teacher_portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time not recorded',
                'en_email'=>"Hello {{teacher}},\n\nThe proposed time for the ".$labelEn." could not be recorded and was closed. The current class time stays unchanged.\n\nIf needed, propose a new time:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the proposed time could not be recorded and was closed. The current class time is unchanged.',
                'en_whatsapp'=>'Hello {{teacher}}, the proposed time for the '.$labelEn.' could not be recorded and was closed. The current class time is unchanged. Teacher Portal: {{teacher_portal_url}} — Delnavazan',
            ),
            'cancelled'=>array(
                'fa_subject'=>'دلنوازان · '.$labelFa.' · پیشنهاد زمان لغو شد',
                'fa_email'=>"درود {{teacher}}،\n\nپیشنهاد زمان برای ".$labelFa." توسط مدیر لغو شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nجزئیات در پرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان",
                'fa_portal'=>$labelFa.' — پیشنهاد زمان توسط مدیر لغو شد. زمان فعلی کلاس شما بدون تغییر می‌ماند. جزئیات در پرتال مدرس.',
                'fa_whatsapp'=>'درود {{teacher}} عزیز، پیشنهاد زمان '.$labelFa.' توسط مدیر لغو شد. زمان فعلی کلاس تغییر نمی‌کند. پرتال مدرس: {{teacher_portal_url}} — دلنوازان',
                'en_subject'=>'Delnavazan · '.$labelEn.' · time cancelled',
                'en_email'=>"Hello {{teacher}},\n\nThe proposed time for the ".$labelEn." was cancelled by an administrator. The current class time stays unchanged.\n\nDetails in the Teacher Portal:\n{{teacher_portal_url}}\n\nDelnavazan",
                'en_portal'=>ucfirst($labelEn).' — the proposed time was cancelled by an administrator. The current class time is unchanged.',
                'en_whatsapp'=>'Hello {{teacher}}, the proposed time for the '.$labelEn.' was cancelled by an administrator. The current class time is unchanged. Teacher Portal: {{teacher_portal_url}} — Delnavazan',
            ),
        );
    }

    /** @return array<string,array{label:string,fa:string,prefix:string}> */
    private static function rescheduleKinds():array{
        return array(
            'change'=>array('label'=>'Lesson Change','fa'=>'تغییر زمان کلاس','prefix'=>'reschedule.change'),
            'replacement'=>array('label'=>'Replacement Session','fa'=>'جلسهٔ جبرانی','prefix'=>'reschedule.replacement'),
        );
    }

    // ------------------------------------------------------------------ shape

    private static function event(string $key,string $label,string $category,string $audience,string $producer,string $authority,array $channels,string $consent,bool $mandatory,array $variables,string $templateKey,array $defaults,array $review=array()):array{
        if(!isset(self::CATEGORIES[$category]))throw new \InvalidArgumentException('notification_category_invalid');
        if(!isset(self::AUTHORITIES[$authority]))throw new \InvalidArgumentException('notification_authority_invalid');
        foreach($variables as $variable)if(preg_match('/^[a-z][a-z0-9_]{0,63}$/',$variable)!==1)throw new \InvalidArgumentException('notification_variable_invalid');
        foreach($review as $code)if(!isset(self::REVIEW[$code]))throw new \InvalidArgumentException('notification_review_invalid');
        // The per-event list records which methods a producer was originally built for, but it no
        // longer removes a method from the administrator's workspace. Every event offers every
        // method; readiness decides whether a route can send, and the administrator decides whether
        // it is on. `$channels` stays in the signature so the historical declaration is readable.
        $channels=self::CHANNELS;
        return array(
            'key'=>$key,'label'=>$label,'purpose'=>self::purpose($key,$label,$audience),'category'=>$category,'audience'=>$audience,'producer'=>$producer,
            'authority'=>$authority,'channels'=>$channels,'consent'=>$consent,'mandatory'=>$mandatory,
            'variables'=>array_values(array_unique($variables)),'template_key'=>$templateKey,
            'implementation'=>str_starts_with($producer,'canonical:')?'declared':'implemented',
            // `canonical:<INTENT>` names a finance intent, not a class, so nothing dispatches it yet.
            'producer_registered'=>!str_starts_with($producer,'canonical:'),
            'review'=>array_values(array_unique($review)),
            'defaults'=>$defaults,
            'provider_templates'=>array(
                'whatsapp'=>array('provider'=>'meta_whatsapp_cloud','name'=>null,'language'=>null,'state'=>in_array('whatsapp',$channels,true)?'not_mapped':'unsupported'),
                'sms'=>array('provider'=>'telnyx','state'=>in_array('sms',$channels,true)?'configured_only':'unsupported'),
            ),
        );
    }

    private static function purpose(string $key,string $label,string $audience):string{
        if(str_starts_with($key,'reschedule.change.'))return 'Requests or records a lesson-time change and tells the recipient whether they must accept, decline or wait for the other party.';
        if(str_starts_with($key,'reschedule.replacement.'))return 'Coordinates a replacement session, including the proposed time, response deadline and resulting decision.';
        if(str_starts_with($key,'booking.payment_lapsed.'))return 'Explains that the payment deadline passed, the place was released and the student may choose a new time.';
        if(str_starts_with($key,'booking.payment_conflict.'))return 'Explains that the payment settled after the class time was taken, that the money is safe as credit, and who will arrange a new time.';
        if(str_starts_with($key,'booking.hold.'))return 'Explains that the same request is held for the sole available Teacher and states the real response deadline.';
        if(str_starts_with($key,'booking.terminal.'))return 'Explains that the terminal response deadline passed, the request closed and the student may choose a new time.';
        if(str_starts_with($key,'booking.confirmed.'))return $audience==='teacher'?'Confirms the accepted introductory booking and gives the Teacher the student, time and portal details.':'Confirms the student’s introductory lesson and provides the time and account link.';
        if(str_starts_with($key,'booking.requested.')||str_starts_with($key,'booking.new.'))return $audience==='teacher'?'Alerts the Teacher to a new student request and the deadline for responding.':'Confirms that the student’s booking request was received and is awaiting a decision.';
        if(str_starts_with($key,'booking.cancelled.'))return 'Explains that the introductory booking was cancelled, who initiated the change and what the recipient should do next.';
        if(str_starts_with($key,'booking.declined.'))return 'Explains that the booking request was declined and directs the student to the next available booking action.';
        if(str_starts_with($key,'booking.rescheduled.'))return 'Confirms the new introductory lesson time and directs the recipient to the account for the latest details.';
        if(str_starts_with($key,'booking.expired.'))return 'Tells the Teacher that the response window closed and the request moved on, so no further action is expected.';
        if(str_starts_with($key,'booking.referral.'))return 'Tells the operations team that a booking needs a human allocation decision.';
        if(str_starts_with($key,'booking.escalation.'))return 'Escalates a booking that has stayed unconfirmed beyond the operating threshold.';
        if(str_starts_with($key,'lesson.access.'))return $audience==='teacher'?'Tells the Teacher that their class access is ready and where to join it.':'Provides the authorised class access link and the essential lesson details for joining.';
        if(str_starts_with($key,'lesson.reminder.'))return $audience==='teacher'?'Reminds the Teacher that the class is about to start and where to join it.':'Reminds the student that the class is about to start and where to join it.';
        if(str_starts_with($key,'lesson.cancelled.'))return $audience==='teacher'?'Tells the Teacher that their class was cancelled and how a replacement session is arranged.':'Explains that the class was cancelled and how a replacement session is arranged.';
        if(str_starts_with($key,'attendance.'))return 'Records an attendance outcome and tells the recipient whether a follow-up or replacement action is required.';
        if(str_starts_with($key,'consent.'))return str_contains($key,'required')?'Requests the specific consent decision needed before the related recording or research action can proceed.':'Confirms a consent decision or policy change and points to its durable history.';
        if(str_starts_with($key,'renewal.automatic_upcoming'))return 'Warns the student that an automatic renewal will be processed and how to change or stop it.';
        if(str_starts_with($key,'renewal.automatic_charged'))return 'Confirms that an automatic renewal was processed and points to the receipt.';
        if(str_starts_with($key,'renewal.automatic_failed'))return 'Explains that an automatic renewal did not complete and how to complete it before the deadline.';
        if(str_starts_with($key,'renewal.manual_payment_required'))return 'Requests the renewal payment that continues the term and states the amount and deadline.';
        if(str_starts_with($key,'renewal.guarantee_deadline'))return 'Warns that the held place expires and what happens after the deadline.';
        if(str_starts_with($key,'renewal.term_lapsed'))return 'States that the term ended without renewal and how to start a new term.';
        if(str_starts_with($key,'renewal.reminder'))return 'Reminds the student how many sessions remain and how to renew before the place is released.';
        if(str_starts_with($key,'payment.failed'))return 'Explains that the payment did not complete and how to try again, without changing lesson or transaction state.';
        if(str_starts_with($key,'payment.recovered'))return 'Confirms a verified payment and points to its receipt.';
        if(str_starts_with($key,'refund.review_required'))return 'Confirms that a refund request was received and is under review, without promising an outcome.';
        if(str_starts_with($key,'refund.resolved'))return 'Tells the student that the refund review is complete and where the outcome is recorded.';
        if(str_starts_with($key,'account.')||str_starts_with($key,'teacher.invitation'))return 'Guides the recipient through the account-access or onboarding step required to continue securely.';
        if(str_starts_with($key,'post_intro.'))return 'Moves the student to the next post-introduction step and provides the account action needed to complete it.';
        if(str_starts_with($key,'system.'))return 'Proves the portal notification path end to end without contacting any recipient.';
        if($audience==='admin')return 'Gives the operations team a decision or exception requiring review, with the event time and affected participant.';
        return 'Delivers the recorded '.$label.' notice and identifies the next action available to the recipient.';
    }
}
