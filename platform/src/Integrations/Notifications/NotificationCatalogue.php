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
 */
final class NotificationCatalogue {
    public const VERSION='2026-11-01.1';
    public const PRIMARY_LOCALE='fa-IR';
    public const SUPPORTED_LOCALES=array('fa-IR','en');

    /** Notification categories, their editorial tone and their channel policy class. */
    public const CATEGORIES=array(
        'security'=>array('label'=>'Security & Access','description'=>'Security and one-time messages; cannot be disabled and are never marketing.','policy'=>'security'),
        'account'=>array('label'=>'Account','description'=>'Essential account messages a student or teacher needs in order to continue.','policy'=>'essential'),
        'booking'=>array('label'=>'Intro Lessons','description'=>'Intro lesson requests, confirmations, declines and enrolment follow-up.','policy'=>'operational'),
        'lesson'=>array('label'=>'Classes & Sessions','description'=>'Lesson changes, replacement sessions, absence and operational class notices.','policy'=>'operational'),
        'payment'=>array('label'=>'Payments & Renewals','description'=>'Financial and renewal messages; require legal review before activation.','policy'=>'financial'),
        'optional'=>array('label'=>'Optional Announcements','description'=>'Non-essential news and offers; marketing consent only.','policy'=>'marketing'),
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

    /** @return array<string,array> */
    public static function events():array{
        static $events=null;
        if($events!==null)return $events;
        $events=array();
        foreach(self::studentEvents() as $event)$events[$event['key']]=$event;
        foreach(self::teacherEvents() as $event)$events[$event['key']]=$event;
        foreach(self::adminEvents() as $event)$events[$event['key']]=$event;
        foreach(self::declaredFinancialEvents() as $event)$events[$event['key']]=$event;
        ksort($events,SORT_STRING);
        return $events;
    }

    public static function get(string $key):?array{return self::events()[$key]??null;}
    /** @return string[] */
    public static function keys():array{return array_keys(self::events());}
    /** @return string[] */
    public static function variables(string $key):array{return array_values((array)(self::get($key)['variables']??array()));}
    public static function category(string $category):?array{return self::CATEGORIES[$category]??null;}
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
        return null;
    }
    /** The example values the dashboard previews with; never real recipient data. */
    public static function previewVariables(string $eventKey):array{
        $event=self::get($eventKey);
        $examples=array(
            'name'=>'باران','student'=>'باران','teacher'=>'استاد ندا','course'=>'تار','when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰',
            'timezone'=>'Asia/Tehran','join_url'=>'https://meet.example.invalid/abc-defg-hij','portal_url'=>'https://example.invalid/student-portal/',
            'teacher_portal_url'=>'https://example.invalid/teacher-portal/','claim_url'=>'https://example.invalid/claim/','claim_code'=>'[one-time code]',
            'invitation_code'=>'[one-time code]','payment_url'=>'https://example.invalid/pay/','amount'=>'۲٬۵۰۰٬۰۰۰ تومان','due_date'=>'۱۵ آبان ۱۴۰۵',
            'paid_date'=>'۱۲ آبان ۱۴۰۵','previous_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰','new_when'=>'یکشنبه ۱۱ آبان ۱۴۰۵، ساعت ۱۸:۰۰','current_when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰',
            'term'=>'ترم پاییز ۱۴۰۵','reason'=>'پرداخت ناموفق بود',
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
    public static function isMandatory(string $eventKey):bool{
        $event=self::get($eventKey);
        if($event===null)return true;
        $category=(string)($event['category']??'');
        return !empty($event['mandatory'])||in_array($category,array('security','account','payment'),true);
    }
    public static function categoryPolicy(string $eventKey):string{
        $event=self::get($eventKey);
        return (string)(self::CATEGORIES[(string)($event['category']??'')]['policy']??'operational');
    }

    // ------------------------------------------------------------------ student events

    private static function studentEvents():array{
        $out=array();
        $out[]=self::event('booking.confirmed.student','Intro Lesson Confirmed','booking','student','BookingCalendarProjectionService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','when','join_url','portal_url'),'booking.confirmed',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس آشنایی شما تأیید شد','body'=>"درود {{name}}،\n\nکلاس آشنایی «{{course}}» با {{teacher}} تأیید شد.\n\nزمان کلاس: {{when}}\nلینک جلسه: {{join_url}}\n\nلطفاً کمی پیش از زمان کلاس آماده باشید. پیوند جلسه را در اختیار دیگران قرار ندهید.\n\nبرای دیدن برنامهٔ کلاس‌ها به پرتال هنرجو بروید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آشنایی تأیید شد','body'=>'کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} برگزار می‌شود.'),
                'sms'=>array('body'=>'دلنوازان: کلاس آشنایی {{course}} با {{teacher}} در {{when}} تأیید شد. پرتال: {{portal_url}}'),
                'whatsapp'=>array('body'=>'درود {{name}}، کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} تأیید شد. پیوند جلسه: {{join_url}} — پرتال: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your introductory lesson is confirmed','body'=>"Hello {{name}},\n\nYour introductory {{course}} lesson with {{teacher}} is confirmed.\n\nTime: {{when}}\nMeeting link: {{join_url}}\n\nPlease join a few minutes early and keep the meeting link private.\n\nOpen your Student Portal:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson confirmed','body'=>'Your introductory {{course}} lesson with {{teacher}} is scheduled for {{when}}.'),
            ),
        ));
        $out[]=self::event('booking.declined.student','Intro Lesson Declined','booking','student','BookingTeacherResponseService','queue',array('email','website'),'operational',true,array('name','teacher','course','portal_url'),'booking.declined',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست کلاس آشنایی پذیرفته نشد','body'=>"درود {{name}}،\n\nمتأسفانه {{teacher}} در این زمان نمی‌تواند کلاس آشنایی «{{course}}» را بپذیرد. درخواست شما تأیید نشد و هیچ رویداد تقویم یا لینک جلسه‌ای ساخته نشد.\n\nمی‌توانید زمان دیگری را در پرتال هنرجو انتخاب کنید:\n{{portal_url}}\n\nاگر نیاز به کمک دارید، از راه‌های ارتباطی همین پرتال با ما در تماس باشید.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'درخواست کلاس آشنایی پذیرفته نشد','body'=>'{{teacher}} در این زمان نمی‌تواند کلاس «{{course}}» را بپذیرد. می‌توانید زمان تازه‌ای انتخاب کنید.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory lesson request update','body'=>"Hello {{name}},\n\n{{teacher}} is unable to accept your introductory {{course}} lesson at this time. The request was not confirmed and no calendar event or meeting link was created.\n\nChoose another time in your Student Portal:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson request not accepted','body'=>'{{teacher}} is unable to accept the {{course}} lesson at this time.'),
            ),
        ));
        $out[]=self::event('post_intro.invitation.student','Enrolment Ready','booking','student','PostIntroPreparationService','queue',array('email','website'),'operational',true,array('name','teacher','course','portal_url'),'post_intro.invitation',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · ثبت‌نام شما آمادهٔ تکمیل است','body'=>"درود {{name}}،\n\n{{teacher}} تأیید کرد که کلاس آشنایی «{{course}}» برگزار شده است. اکنون می‌توانید با همان مدرس، ثبت‌نام خود را تکمیل کنید.\n\nبرای ادامهٔ ثبت‌نام و پرداخت به پرتال هنرجو بروید:\n{{portal_url}}\n\nپس از تأیید پرداخت، ثبت‌نام، برنامهٔ هفتگی و کلاس‌های آیندهٔ شما به‌صورت خودکار فعال می‌شود و تأیید دوبارهٔ مدرس لازم نیست.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'ثبت‌نام شما آمادهٔ تکمیل است','body'=>'کلاس آشنایی «{{course}}» با {{teacher}} برگزار شد. برای تکمیل ثبت‌نام به پرتال هنرجو بروید.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your Delnavazan enrolment is ready','body'=>"Hello {{name}},\n\n{{teacher}} confirmed that your introductory {{course}} lesson was delivered. You can now complete payment and confirm your enrolment with the same Teacher.\n\nOpen your Student Portal:\n{{portal_url}}\n\nYour enrolment, weekly schedule and future lessons are activated automatically after the payment is verified.\n\nDelnavazan"),
                'website'=>array('subject'=>'Your enrolment is ready','body'=>'Your introductory {{course}} lesson was delivered. Complete your enrolment in the Student Portal.'),
            ),
        ));
        $out[]=self::event('post_intro.activated.student','Enrolment Activated','account','student','PostIntroActivationService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','portal_url'),'post_intro.activated',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · ثبت‌نام شما فعال شد','body'=>"درود {{name}}،\n\nپرداخت شما تأیید شد و ثبت‌نام «{{course}}» با {{teacher}} فعال است. برنامهٔ هفتگی و کلاس‌های آیندهٔ شما در پرتال هنرجو نمایش داده می‌شود.\n\nمشاهدهٔ برنامه و کلاس‌ها:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'ثبت‌نام شما فعال شد','body'=>'ثبت‌نام «{{course}}» با {{teacher}} فعال شد و برنامهٔ کلاس‌ها در پرتال نمایش داده می‌شود.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، ثبت‌نام «{{course}}» شما با استاد {{teacher}} فعال شد. برنامهٔ کلاس‌ها در پرتال هنرجو در دسترس است: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your Delnavazan enrolment is active','body'=>"Hello {{name}},\n\nYour payment was verified and your {{course}} enrolment with {{teacher}} is now active. Your weekly schedule and future lessons are available in your Student Portal.\n\nOpen your Student Portal:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Your enrolment is active','body'=>'Your {{course}} enrolment with {{teacher}} is active.'),
            ),
        ));
        $out[]=self::event('booking.requested.student','Intro Lesson Request Received','booking','student','BookingRequestSubmissionService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','when','portal_url'),'booking.requested',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست کلاس آشنایی شما ثبت شد','body'=>"درود {{name}}،\n\nدرخواست کلاس آشنایی «{{course}}» شما ثبت شد.\n\nزمان درخواستی: {{when}}\n\nپس از تأیید مدرس، اطلاعات نهایی کلاس برای شما ارسال می‌شود.\n\nجزئیات در پرتال هنرجو:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'درخواست کلاس آشنایی ثبت شد','body'=>'درخواست کلاس آشنایی «{{course}}» در {{when}} ثبت شد و در انتظار تأیید مدرس است.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، درخواست رزرو کلاس {{course}} شما در زمان {{when}} ثبت شد. پس از تأیید مدرس، اطلاعات نهایی برای شما ارسال می‌شود. پرتال: {{portal_url}}'),
                'sms'=>array('body'=>'دلنوازان: درخواست کلاس {{course}} در {{when}} ثبت شد. پرتال: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your introductory lesson request was received','body'=>"Hello {{name}},\n\nYour introductory {{course}} lesson request was received.\n\nRequested time: {{when}}\n\nWe will send the final class details after the Teacher confirms.\n\nOpen your Student Portal:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson request received','body'=>'Your {{course}} lesson request for {{when}} is awaiting Teacher confirmation.'),
            ),
        ));
        $out[]=self::event('lesson.reminder.student','Class Reminder','lesson','student','LessonReminderService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','teacher','when','portal_url'),'lesson.reminder',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · یادآوری کلاس','body'=>"درود {{name}}،\n\nیادآوری می‌کنیم که کلاس «{{course}}» شما با {{teacher}} در {{when}} برگزار می‌شود.\n\nبرای مشاهدهٔ جزئیات و ورود به کلاس به پرتال هنرجو بروید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'یادآوری کلاس','body'=>'کلاس «{{course}}» شما با {{teacher}} در {{when}} برگزار می‌شود.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، کلاس {{course}} شما با استاد {{teacher}} در {{when}} برگزار می‌شود. برای ورود به کلاس به پرتال هنرجو بروید: {{portal_url}}'),
                'sms'=>array('body'=>'دلنوازان: یادآوری کلاس {{course}} با {{teacher}} در {{when}}. پرتال: {{portal_url}}'),
            ),
        ));
        $out[]=self::event('lesson.cancelled.student','Class Cancelled','lesson','student','LessonCancellationService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','teacher','when','portal_url'),'lesson.cancelled',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس لغو شد','body'=>"درود {{name}}،\n\nکلاس «{{course}}» شما با {{teacher}} در {{when}} لغو شد.\n\nاگر برای هماهنگی جلسهٔ جبرانی به کمک نیاز دارید، از پرتال هنرجو با ما در تماس باشید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس لغو شد','body'=>'کلاس «{{course}}» با {{teacher}} در {{when}} لغو شد.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، کلاس {{course}} شما با استاد {{teacher}} در زمان {{when}} لغو شد. برای هماهنگی جلسهٔ جبرانی به پرتال هنرجو مراجعه کنید: {{portal_url}}'),
                'sms'=>array('body'=>'دلنوازان: کلاس {{course}} با {{teacher}} در {{when}} لغو شد. پرتال: {{portal_url}}'),
            ),
        ));
        $out[]=self::event('lesson.access.student','Class Access Ready','lesson','student','ClassAccessService','queue',array('email','website','whatsapp','sms'),'operational',true,array('name','course','teacher','when','portal_url'),'lesson.access',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس شما آمادهٔ برگزاری است','body'=>"درود {{name}}،\n\nکلاس «{{course}}» شما با {{teacher}} در {{when}} آمادهٔ برگزاری است.\n\nبرای ورود به کلاس به پرتال هنرجو بروید و پیوند جلسه را در اختیار دیگران قرار ندهید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آمادهٔ برگزاری است','body'=>'کلاس «{{course}}» با {{teacher}} در {{when}} آمادهٔ برگزاری است.'),
                'whatsapp'=>array('body'=>'درود {{name}} عزیز، کلاس {{course}} شما با استاد {{teacher}} در {{when}} آمادهٔ برگزاری است. برای ورود به کلاس به پرتال هنرجو بروید: {{portal_url}}'),
                'sms'=>array('body'=>'دلنوازان: کلاس {{course}} با {{teacher}} در {{when}} آماده است. پرتال: {{portal_url}}'),
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
            'en'=>array('email'=>array('subject'=>'Delnavazan · your account invitation','body'=>"Hello,\n\nYour Delnavazan account invitation is ready. Enter this one-time code on the invitation page:\n\n{{claim_code}}\n\nThis code can be used once and must not be shared. If you did not expect this message, contact Delnavazan support.\n\nDelnavazan")),
        ));
        return $out;
    }

    // ------------------------------------------------------------------ teacher events

    private static function teacherEvents():array{
        $out=array();
        $out[]=self::event('attendance.absence_reported.teacher','Student Absence Notice','lesson','teacher','CanonicalAttendanceNotificationService','queue',array('email','website'),'operational',true,array('teacher','student','course','when','timezone','teacher_portal_url'),'attendance.absence_reported.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · اطلاع غیبت هنرجو','body'=>"درود {{teacher}}،\n\n{{student}} برای جلسهٔ «{{course}}» در {{when}} ({{timezone}}) اطلاع غیبت فرستاده است.\n\nلطفاً با هنرجو هماهنگ کنید و در صورت توافق، زمان جایگزین همین جلسه را در پرتال مدرس ثبت کنید:\n{{teacher_portal_url}}\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'اطلاع غیبت هنرجو','body'=>'{{student}} برای جلسهٔ «{{course}}» در {{when}} اطلاع غیبت فرستاده است.'),
            ),
        ));
        $out[]=self::event('attendance.missed_class_claim.teacher','Missed Class Claim','lesson','teacher','CanonicalAttendanceNotificationService','queue',array('email','website'),'operational',true,array('teacher','student','course','when','timezone','teacher_portal_url'),'attendance.missed_class_claim.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · اعلام جلسهٔ ازدست‌رفته','body'=>"درود {{teacher}}،\n\n{{student}} اعلام کرده است که جلسهٔ «{{course}}» در {{when}} ({{timezone}}) را از دست داده است.\n\nلطفاً برای جلسهٔ جبرانی با هنرجو هماهنگ کنید و زمان تازه را در پرتال مدرس ثبت کنید:\n{{teacher_portal_url}}\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'اعلام جلسهٔ ازدست‌رفته','body'=>'{{student}} اعلام کرده جلسهٔ «{{course}}» در {{when}} را از دست داده است. زمان جلسهٔ جبرانی را پیشنهاد دهید.'),
            ),
        ));
        $out[]=self::event('post_intro.activated.teacher','New Student Class Activated','account','teacher','PostIntroActivationService','queue',array('email','website'),'operational',true,array('teacher','teacher_portal_url'),'post_intro.activated.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس شما با هنرجوی جدید فعال شد','body'=>"درود {{teacher}}،\n\nثبت‌نام هنرجو پس از تأیید پرداخت فعال شد. کلاس‌های آینده در پرتال مدرس نمایش داده می‌شوند.\n\nمشاهدهٔ کلاس‌ها:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس شما با هنرجوی جدید فعال شد','body'=>'ثبت‌نام هنرجو پس از تأیید پرداخت فعال شد و کلاس‌های آینده در پرتال مدرس نمایش داده می‌شوند.'),
            ),
        ));
        $out[]=self::event('teacher.invitation_issued.teacher','Teacher Invitation','security','teacher','TeacherInvitationDeliveryWorker','dedicated',array('email'),'security',true,array('invitation_code','claim_url'),'teacher.invitation',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دعوت به همکاری با دلنوازان','body'=>"درود،\n\nبرای شما دعوت‌نامهٔ همکاری با آکادمی موسیقی دلنوازان صادر شده است.\n\nبرای شروع، پیوند زیر را باز کنید و کد یک‌بارمصرف را وارد کنید:\n{{claim_url}}\n\nکد دعوت: {{invitation_code}}\n\nاین کد فقط یک‌بار قابل استفاده است و باید محرمانه بماند. اگر این دعوت را انتظار نداشتید، این پیام را نادیده بگیرید.\n\nبا احترام، دلنوازان"),
            ),
        ));
        $out[]=self::event('booking.new.teacher','New Booking for Teacher','booking','teacher','BookingTeacherDeliveryService','queue',array('email','website','whatsapp','sms'),'operational',true,array('teacher','course','student','when','teacher_portal_url'),'booking.new.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · رزرو تازه برای کلاس شما','body'=>"درود {{teacher}}،\n\nرزرو تازه‌ای برای کلاس «{{course}}» با هنرجو {{student}} ثبت شد.\n\nزمان کلاس: {{when}}\n\nبرای بررسی جزئیات به پرتال مدرس بروید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'رزرو تازه برای کلاس شما','body'=>'رزرو تازه‌ای برای کلاس «{{course}}» با هنرجو {{student}} در {{when}} ثبت شد.'),
                'whatsapp'=>array('body'=>'درود استاد {{teacher}} عزیز، رزرو تازه‌ای برای کلاس {{course}} با هنرجو {{student}} در {{when}} ثبت شد. برای بررسی جزئیات به پرتال مدرس مراجعه کنید: {{teacher_portal_url}}'),
                'sms'=>array('body'=>'دلنوازان: رزرو تازه {{course}} با {{student}} در {{when}}. پرتال مدرس: {{teacher_portal_url}}'),
            ),
        ));
        self::appendRescheduleTeacher($out);
        return $out;
    }

    // ------------------------------------------------------------------ admin events

    private static function adminEvents():array{
        $out=array();
        $out[]=self::event('system.inbox_check.admin','Portal Notification Check','account','admin','notification_management','queue',array('website'),'operational',false,array('when'),'system.inbox_check.admin',array(
            self::PRIMARY_LOCALE=>array('website'=>array('subject'=>'اعلان آزمایشی دلنوازان','body'=>'این یک اعلان آزمایشی داخلی است. زمان ثبت: {{when}}. هیچ ایمیل، واتس‌اپ یا پیامکی ارسال نشده است.')),
        ));
        foreach(array('change'=>array('Lesson Change','تغییر زمان کلاس'),'replacement'=>array('Replacement Session','جلسهٔ جبرانی')) as $kind=>[$label,$faLabel]){
            foreach(array('proposed'=>array('Proposal Created','{{when}} ({{timezone}}) پیشنهاد شد و در انتظار پاسخ هنرجو است.'),
                'accepted'=>array('Proposal Accepted','هنرجو پیشنهاد را پذیرفت و زمان {{when}} ({{timezone}}) قطعی شد.'),
                'rejected'=>array('Proposal Rejected','هنرجو پیشنهاد را رد کرد. زمان فعلی کلاس بدون تغییر می‌ماند.'),
                'failed'=>array('Proposal Closed','پیشنهاد ثبت نشد و بسته شد؛ مورد برای بررسی در صف مدیر قرار گرفت.'),
                'cancelled'=>array('Proposal Cancelled','پیشنهاد توسط مدیر لغو شد.')) as $state=>$content){
                $timeVariables=in_array($state,array('proposed','accepted'),true)?array('when','timezone'):array();
                $out[]=self::event('reschedule.'.$kind.'.'.$state.'.admin','Admin · '.$label.' · '.$content[0],'lesson','admin','LessonRescheduleService','queue',array('email'),'operational',true,$timeVariables,'reschedule.'.$kind.'.'.$state.'.admin',array(
                    self::PRIMARY_LOCALE=>array('email'=>array('subject'=>'دلنوازان · '.$faLabel,'body'=>"درود،\n\n".$content[1]."\n\nاین پیام فقط برای اطلاع مدیر آموزشگاه است.\n\nدلنوازان")),
                ));
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ declared financial intents

    private static function declaredFinancialEvents():array{
        $definitions=array(
            'renewal.automatic_upcoming.student'=>array('Automatic Renewal Upcoming','AUTOMATIC_RENEWAL_UPCOMING','تمدید خودکار در پیش است'),
            'renewal.automatic_charged.student'=>array('Automatic Renewal Charged','AUTOMATIC_RENEWAL_CHARGED','تمدید خودکار انجام شد'),
            'renewal.automatic_failed.student'=>array('Automatic Renewal Failed','AUTOMATIC_RENEWAL_FAILED','تمدید خودکار ناموفق بود'),
            'renewal.manual_payment_required.student'=>array('Renewal Payment Required','MANUAL_RENEWAL_PAYMENT_REQUIRED','پرداخت تمدید دوره لازم است'),
            'renewal.guarantee_deadline_approaching.student'=>array('Renewal Deadline Approaching','GUARANTEE_DEADLINE_APPROACHING','مهلت تمدید نزدیک است'),
            'renewal.term_lapsed.student'=>array('Term Lapsed','TERM_LAPSED','دوره منقضی شد'),
            'payment.failed.student'=>array('Payment Failed','PAYMENT_FAILED','پرداخت انجام نشد'),
            'payment.recovered.student'=>array('Payment Recovered','PAYMENT_RECOVERED','پرداخت بازیابی شد'),
            'refund.review_required.student'=>array('Refund Under Review','REFUND_REVIEW_REQUIRED','درخواست بازپرداخت در حال بررسی است'),
            'refund.resolved.student'=>array('Refund Resolved','REFUND_RESOLVED','نتیجهٔ درخواست بازپرداخت'),
        );
        $out=array();
        foreach($definitions as $key=>[$label,$intent,$faLabel]){
            $event=self::event($key,$label,'payment','student','canonical:'.$intent,'canonical',array('email','website','whatsapp'),'financial',true,array('name','amount','due_date','portal_url','course','paid_date'),strtolower(str_replace('.student','',$key)),array(
                self::PRIMARY_LOCALE=>array(
                    'email'=>array('subject'=>'دلنوازان · '.$faLabel,'body'=>"درود {{name}}،\n\n".$faLabel.".\n\nمبلغ: {{amount}}\nمهلت: {{due_date}}\n\nبرای مشاهدهٔ جزئیات و اقدام به پرتال هنرجو بروید:\n{{portal_url}}\n\nاین متن پیش‌فرض است و پیش از فعال‌سازی باید از نظر مالی و حقوقی بازبینی شود.\n\nبا احترام، دلنوازان"),
                    'website'=>array('subject'=>$faLabel,'body'=>$faLabel.' — جزئیات در پرتال هنرجو.'),
                    'whatsapp'=>array('body'=>"درود {{name}} عزیز،\n\n".$faLabel.".\n\nمبلغ: {{amount}}\nمهلت: {{due_date}}\n\nبرای مشاهدهٔ جزئیات و اقدام به پرتال هنرجو بروید:\n{{portal_url}}"),
                ),
                'en'=>array('email'=>array('subject'=>'Delnavazan · '.$label,'body'=>"Hello {{name}},\n\n".$label.".\n\nAmount: {{amount}}\nDeadline: {{due_date}}\n\nOpen your Student Portal:\n{{portal_url}}\n\nThis default requires financial and legal review before activation.\n\nDelnavazan")),
            ));
            // The owning finance service publishes the intent, but no workflow/template is registered,
            // so the message is produced and not yet dispatchable: partial, never "implemented".
            $event['implementation']='partial';
            $out[]=$event;
        }
        return $out;
    }

    // ------------------------------------------------------------------ reschedule families

    private static function appendRescheduleStudent(array &$out):void{
        foreach(array('change'=>array('Lesson Change','تغییر زمان کلاس','reschedule.change'),'replacement'=>array('Replacement Session','جلسهٔ جبرانی','reschedule.replacement')) as $kind=>[$label,$faLabel,$prefix]){
            $messages=array(
                'proposed'=>array('subject'=>'دلنوازان · '.$faLabel,'body'=>"درود {{name}}،\n\nبرای شما یک زمان تازه برای ".('replacement'===$kind?'جلسهٔ جبرانی':'کلاس')." پیشنهاد شد:\n{{when}} ({{timezone}})\n\nلطفاً در پرتال هنرجو آن را بپذیرید یا رد کنید:\n{{portal_url}}\n\nتا پاسخ شما، زمان فعلی کلاس تغییر نمی‌کند.\n\nبا احترام، دلنوازان"),
                'accepted'=>array('subject'=>'دلنوازان · تأیید زمان پیشنهادی','body'=>"درود {{name}}،\n\nزمان پیشنهادی تأیید شد.\n\nزمان جدید: {{when}} ({{timezone}})\n\nجزئیات در پرتال هنرجو در دسترس است:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'rejected'=>array('subject'=>'دلنوازان · زمان پیشنهادی رد شد','body'=>"درود {{name}}،\n\nزمان پیشنهادی رد شد و زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nزمان فعلی: {{when}}\n\nاگر زمان تازه‌ای مناسب شماست، از پرتال هنرجو با مدرس هماهنگ کنید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'failed'=>array('subject'=>'دلنوازان · زمان پیشنهادی ثبت نشد','body'=>"درود {{name}}،\n\nزمان پیشنهادی ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nلطفاً زمان تازه‌ای پیشنهاد کنید یا با پشتیبانی دلنوازان تماس بگیرید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'cancelled'=>array('subject'=>'دلنوازان · پیشنهاد زمان لغو شد','body'=>"درود {{name}}،\n\nپیشنهاد زمان توسط مدیر لغو شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nجزئیات در پرتال هنرجو:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
            );
            foreach($messages as $state=>$copy){
                $studentVariables=array('name','portal_url');
                if($state==='proposed')$studentVariables=array_merge($studentVariables,array('when','timezone','teacher','course','current_when','new_when'));
                elseif($state==='accepted')$studentVariables=array_merge($studentVariables,array('when','timezone','course','teacher','previous_when','new_when'));
                elseif($state==='rejected')$studentVariables=array_merge($studentVariables,array('course','teacher','when'));
                $out[]=self::event($prefix.'.'.$state.'.student',$label.' · '.ucfirst($state),'lesson','student','LessonRescheduleService','queue',array('email','website','whatsapp'),'operational',true,$studentVariables,$prefix.'.'.$state,array(
                    self::PRIMARY_LOCALE=>array('email'=>$copy,'website'=>array('subject'=>$faLabel,'body'=>$copy['body']),'whatsapp'=>array('body'=>$copy['body'])),
                ));
            }
        }
    }

    private static function appendRescheduleTeacher(array &$out):void{
        foreach(array('change'=>array('Lesson Change','تغییر زمان کلاس','reschedule.change'),'replacement'=>array('Replacement Session','جلسهٔ جبرانی','reschedule.replacement')) as $kind=>[$label,$faLabel,$prefix]){
            $messages=array(
                'proposed'=>array('subject'=>'دلنوازان · '.$faLabel,'body'=>"درود {{teacher}}،\n\nدرخواست ".('replacement'===$kind?'جلسهٔ جبرانی':'تغییر زمان')." ثبت شد.\n\nزمان پیشنهادی: {{when}} ({{timezone}})\n\nتا پاسخ هنرجو، این پیشنهاد قطعی نیست. وضعیت را در پرتال مدرس ببینید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'accepted'=>array('subject'=>'دلنوازان · هنرجو زمان پیشنهادی را پذیرفت','body'=>"درود {{teacher}}،\n\nهنرجو زمان پیشنهادی را پذیرفت و زمان {{when}} ({{timezone}}) قطعی شد.\n\nمشاهدهٔ کلاس‌ها:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'rejected'=>array('subject'=>'دلنوازان · هنرجو زمان پیشنهادی را رد کرد','body'=>"درود {{teacher}}،\n\nهنرجو زمان پیشنهادی را رد کرد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nزمان فعلی: {{when}}\n\nلطفاً با هنرجو هماهنگ کنید و زمان تازه‌ای پیشنهاد دهید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'failed'=>array('subject'=>'دلنوازان · پیشنهاد زمان ثبت نشد','body'=>"درود {{teacher}}،\n\nپیشنهاد زمان ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nدر صورت نیاز، زمان تازه‌ای پیشنهاد دهید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'cancelled'=>array('subject'=>'دلنوازان · پیشنهاد زمان لغو شد','body'=>"درود {{teacher}}،\n\nپیشنهاد زمان توسط مدیر لغو شد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nجزئیات در پرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
            );
            foreach($messages as $state=>$copy){
                $teacherVariables=array('teacher','teacher_portal_url');
                if($state==='proposed')$teacherVariables=array_merge($teacherVariables,array('when','timezone','course','student','current_when','new_when'));
                elseif($state==='accepted')$teacherVariables=array_merge($teacherVariables,array('when','timezone','course','student','previous_when','new_when'));
                elseif($state==='rejected')$teacherVariables=array_merge($teacherVariables,array('course','student','when'));
                $out[]=self::event($prefix.'.'.$state.'.teacher',$label.' · '.ucfirst($state),'lesson','teacher','LessonRescheduleService','queue',array('email','website','whatsapp'),'operational',true,$teacherVariables,$prefix.'.'.$state,array(
                    self::PRIMARY_LOCALE=>array('email'=>$copy,'website'=>array('subject'=>$faLabel,'body'=>$copy['body']),'whatsapp'=>array('body'=>$copy['body'])),
                ));
            }
        }
    }

    // ------------------------------------------------------------------ shape

    private static function event(string $key,string $label,string $category,string $audience,string $producer,string $authority,array $channels,string $consent,bool $mandatory,array $variables,string $templateKey,array $defaults):array{
        if(!isset(self::CATEGORIES[$category]))throw new \InvalidArgumentException('notification_category_invalid');
        if(!isset(self::AUTHORITIES[$authority]))throw new \InvalidArgumentException('notification_authority_invalid');
        foreach($variables as $variable)if(preg_match('/^[a-z][a-z0-9_]{0,63}$/',$variable)!==1)throw new \InvalidArgumentException('notification_variable_invalid');
        return array(
            'key'=>$key,'label'=>$label,'category'=>$category,'audience'=>$audience,'producer'=>$producer,
            'authority'=>$authority,'channels'=>$channels,'consent'=>$consent,'mandatory'=>$mandatory,
            'variables'=>array_values(array_unique($variables)),'template_key'=>$templateKey,
            'implementation'=>str_starts_with($producer,'canonical:')?'declared':'implemented',
            'defaults'=>$defaults,
            'provider_templates'=>array(
                'whatsapp'=>array('provider'=>'meta_whatsapp_cloud','name'=>null,'language'=>null,'state'=>in_array('whatsapp',$channels,true)?'not_mapped':'unsupported'),
                'sms'=>array('provider'=>'telnyx','state'=>in_array('sms',$channels,true)?'configured_only':'unsupported'),
            ),
        );
    }
}
