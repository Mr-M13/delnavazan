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
    public const VERSION='2026-10-29.1';
    public const PRIMARY_LOCALE='fa-IR';
    public const SUPPORTED_LOCALES=array('fa-IR','en');

    /** Notification categories, their editorial tone and their channel policy class. */
    public const CATEGORIES=array(
        'security'=>array('label'=>'امنیت و دسترسی','description'=>'پیام‌های امنیتی و یک‌بارمصرف؛ غیرقابل لغو و بدون بازاریابی.','policy'=>'security'),
        'account'=>array('label'=>'حساب کاربری','description'=>'پیام‌های ضروری حساب که ادامهٔ کار هنرجو یا مدرس به آن‌ها وابسته است.','policy'=>'essential'),
        'booking'=>array('label'=>'کلاس آشنایی','description'=>'درخواست، تأیید یا رد کلاس آشنایی و پیگیری ثبت‌نام.','policy'=>'operational'),
        'lesson'=>array('label'=>'کلاس و جلسه','description'=>'تغییر زمان، جلسهٔ جبرانی، غیبت و یادآوری‌های عملیاتی کلاس.','policy'=>'operational'),
        'payment'=>array('label'=>'پرداخت و تمدید','description'=>'پیام‌های مالی و تمدید دوره؛ نیازمند بازبینی حقوقی پیش از فعال‌سازی.','policy'=>'financial'),
        'optional'=>array('label'=>'اطلاع‌رسانی اختیاری','description'=>'خبرها و پیشنهادهای غیرضروری؛ فقط با رضایت بازاریابی.','policy'=>'marketing'),
    );

    /** Delivery authorities. A message is never re-sent by a second authority. */
    public const AUTHORITIES=array(
        'canonical'=>'سرویس اعلان کانونیک (S)',
        'queue'=>'صف اختصاصی همان رویداد',
        'dedicated'=>'تحویل امن اختصاصی (راز یک‌بارمصرف)',
    );

    /** Implementation truth: implemented is not the same as configured or provider-verified. */
    public const IMPLEMENTATION=array(
        'implemented'=>'پیاده‌سازی‌شده',
        'declared'=>'اعلام‌شده؛ تولیدکننده فعال نشده',
        'partial'=>'جزئی',
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
            'term'=>'ترم پاییز ۱۴۰۵','reason'=>'پرداخت ناموفق بود',
        );
        $out=array();
        foreach(self::variables($eventKey) as $variable)$out[$variable]=$examples[$variable]??'['.$variable.']';
        return $out;
    }
    /** Synthetic data must never be mistaken for a real message. */
    public static function previewNotice():string{
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
        $portal='https://delnavazan.com/student-portal/';
        $out=array();
        $out[]=self::event('booking.confirmed.student','تأیید کلاس آشنایی','booking','student','BookingCalendarProjectionService','queue',array('email','website','whatsapp'),'operational',true,array('name','teacher','course','when','join_url','portal_url'),'booking.confirmed',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس آشنایی شما تأیید شد','body'=>"سلام {{name}}،\n\nکلاس آشنایی «{{course}}» با {{teacher}} تأیید شد.\n\nزمان کلاس: {{when}}\nلینک جلسه: {{join_url}}\n\nلطفاً کمی پیش از زمان کلاس آماده باشید. پیوند جلسه را در اختیار دیگران قرار ندهید.\n\nبرای دیدن برنامهٔ کلاس‌ها به پرتال هنرجو بروید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس آشنایی تأیید شد','body'=>'کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} برگزار می‌شود.'),
                'sms'=>array('body'=>'دلنوازان: کلاس آشنایی {{course}} با {{teacher}} در {{when}} تأیید شد. پرتال: {{portal_url}}'),
                'whatsapp'=>array('body'=>'سلام {{name}}، کلاس آشنایی «{{course}}» با {{teacher}} در {{when}} تأیید شد. پیوند جلسه: {{join_url}} — پرتال: {{portal_url}}'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · your introductory lesson is confirmed','body'=>"Hello {{name}},\n\nYour introductory {{course}} lesson with {{teacher}} is confirmed.\n\nTime: {{when}}\nMeeting link: {{join_url}}\n\nPlease join a few minutes early and keep the meeting link private.\n\nOpen your Student Portal:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson confirmed','body'=>'Your introductory {{course}} lesson with {{teacher}} is scheduled for {{when}}.'),
            ),
        ));
        $out[]=self::event('booking.declined.student','رد درخواست کلاس آشنایی','booking','student','BookingTeacherResponseService','queue',array('email','website'),'operational',true,array('name','teacher','course','portal_url'),'booking.declined',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · درخواست کلاس آشنایی پذیرفته نشد','body'=>"سلام {{name}}،\n\nمتأسفانه {{teacher}} در این زمان نمی‌تواند کلاس آشنایی «{{course}}» را بپذیرد. درخواست شما تأیید نشد و هیچ رویداد تقویم یا لینک جلسه‌ای ساخته نشد.\n\nمی‌توانید زمان دیگری را در پرتال هنرجو انتخاب کنید:\n{{portal_url}}\n\nاگر نیاز به کمک دارید، از راه‌های ارتباطی همین پرتال با ما در تماس باشید.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'درخواست کلاس آشنایی پذیرفته نشد','body'=>'{{teacher}} در این زمان نمی‌تواند کلاس «{{course}}» را بپذیرد. می‌توانید زمان تازه‌ای انتخاب کنید.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Delnavazan · introductory lesson request update','body'=>"Hello {{name}},\n\n{{teacher}} is unable to accept your introductory {{course}} lesson at this time. The request was not confirmed and no calendar event or meeting link was created.\n\nChoose another time in your Student Portal:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Introductory lesson request not accepted','body'=>'{{teacher}} is unable to accept the {{course}} lesson at this time.'),
            ),
        ));
        $out[]=self::event('post_intro.invitation.student','دعوت به تکمیل ثبت‌نام','booking','student','PostIntroPreparationService','queue',array('email','website'),'operational',true,array('name','teacher','course','portal_url'),'post_intro.invitation',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · ثبت‌نام شما آمادهٔ تکمیل است','body'=>"سلام {{name}}،\n\n{{teacher}} تأیید کرد که جلسهٔ آشنایی «{{course}}» برگزار شده است. اکنون می‌توانید با همان مدرس، ثبت‌نام خود را تکمیل کنید.\n\nبرای ادامهٔ ثبت‌نام و پرداخت به پرتال هنرجو بروید:\n{{portal_url}}\n\nپس از تأیید پرداخت، ثبت‌نام، برنامهٔ هفتگی و کلاس‌های آیندهٔ شما به‌صورت خودکار فعال می‌شود و تأیید دوبارهٔ مدرس لازم نیست.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'ثبت‌نام شما آمادهٔ تکمیل است','body'=>'جلسهٔ آشنایی «{{course}}» با {{teacher}} برگزار شد. برای تکمیل ثبت‌نام به پرتال هنرجو بروید.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your Delnavazan enrolment is ready','body'=>"Hello {{name}},\n\n{{teacher}} confirmed that your introductory {{course}} lesson was delivered. You can now complete payment and confirm your enrolment with the same Teacher.\n\nOpen your Student Portal:\n{{portal_url}}\n\nYour enrolment, weekly schedule and future lessons are activated automatically after the payment is verified.\n\nDelnavazan"),
                'website'=>array('subject'=>'Your enrolment is ready','body'=>'Your introductory {{course}} lesson was delivered. Complete your enrolment in the Student Portal.'),
            ),
        ));
        $out[]=self::event('post_intro.activated.student','فعال‌شدن ثبت‌نام پس از پرداخت','account','student','PostIntroActivationService','queue',array('email','website'),'operational',true,array('name','teacher','course','portal_url'),'post_intro.activated',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · ثبت‌نام شما فعال شد','body'=>"سلام {{name}}،\n\nپرداخت شما تأیید شد و ثبت‌نام «{{course}}» با {{teacher}} فعال است. برنامهٔ هفتگی و کلاس‌های آیندهٔ شما در پرتال هنرجو نمایش داده می‌شود.\n\nمشاهدهٔ برنامه و کلاس‌ها:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'ثبت‌نام شما فعال شد','body'=>'ثبت‌نام «{{course}}» با {{teacher}} فعال شد و برنامهٔ کلاس‌ها در پرتال نمایش داده می‌شود.'),
            ),
            'en'=>array(
                'email'=>array('subject'=>'Your Delnavazan enrolment is active','body'=>"Hello {{name}},\n\nYour payment was verified and your {{course}} enrolment with {{teacher}} is now active. Your weekly schedule and future lessons are available in your Student Portal.\n\nOpen your Student Portal:\n{{portal_url}}\n\nDelnavazan"),
                'website'=>array('subject'=>'Your enrolment is active','body'=>'Your {{course}} enrolment with {{teacher}} is active.'),
            ),
        ));
        self::appendRescheduleStudent($out);
        $out[]=self::event('account.claim_issued.student','صدور دعوت‌نامهٔ حساب هنرجو','security','student','StudentAccountClaimNotificationService','canonical',array('email','whatsapp','sms'),'security',true,array('claim_code'),'account.claim_issued',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · دعوت‌نامهٔ حساب کاربری شما','body'=>"سلام،\n\nدعوت‌نامهٔ حساب کاربری دلنوازان برای شما صادر شد. برای فعال‌سازی حساب، کد یک‌بارمصرف زیر را در صفحهٔ دعوت‌نامه وارد کنید:\n\n{{claim_code}}\n\nاین کد فقط یک‌بار قابل استفاده است و برای دیگران قابل بازگویی نیست. اگر این درخواست را شما آغاز نکرده‌اید، این پیام را نادیده بگیرید و با پشتیبانی دلنوازان تماس بگیرید.\n\nبا احترام، دلنوازان"),
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
        $portal='https://delnavazan.com/teacher-portal/';
        $out=array();
        $out[]=self::event('attendance.absence_reported.teacher','اطلاع غیبت هنرجو از قبل','lesson','teacher','CanonicalAttendanceNotificationService','queue',array('email','website'),'operational',true,array('teacher','student','course','when','timezone','teacher_portal_url'),'attendance.absence_reported.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · اطلاع غیبت هنرجو','body'=>"سلام {{teacher}}،\n\n{{student}} برای جلسهٔ «{{course}}» در {{when}} ({{timezone}}) اطلاع غیبت فرستاده است.\n\nلطفاً با هنرجو هماهنگ کنید و در صورت توافق، زمان جایگزین همین جلسه را در پرتال مدرس ثبت کنید:\n{{teacher_portal_url}}\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'اطلاع غیبت هنرجو','body'=>'{{student}} برای جلسهٔ «{{course}}» در {{when}} اطلاع غیبت فرستاده است.'),
            ),
        ));
        $out[]=self::event('attendance.missed_class_claim.teacher','اعلام ازدست‌رفتن جلسه','lesson','teacher','CanonicalAttendanceNotificationService','queue',array('email','website'),'operational',true,array('teacher','student','course','when','timezone','teacher_portal_url'),'attendance.missed_class_claim.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · اعلام جلسهٔ ازدست‌رفته','body'=>"سلام {{teacher}}،\n\n{{student}} اعلام کرده است که جلسهٔ «{{course}}» در {{when}} ({{timezone}}) را از دست داده است.\n\nلطفاً برای جلسهٔ جبرانی با هنرجو هماهنگ کنید و زمان تازه را در پرتال مدرس ثبت کنید:\n{{teacher_portal_url}}\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'اعلام جلسهٔ ازدست‌رفته','body'=>'{{student}} اعلام کرده جلسهٔ «{{course}}» در {{when}} را از دست داده است. زمان جلسهٔ جبرانی را پیشنهاد دهید.'),
            ),
        ));
        $out[]=self::event('post_intro.activated.teacher','فعال‌شدن کلاس هنرجوی جدید','account','teacher','PostIntroActivationService','queue',array('email','website'),'operational',true,array('teacher','teacher_portal_url'),'post_intro.activated.teacher',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دلنوازان · کلاس شما با هنرجوی جدید فعال شد','body'=>"سلام {{teacher}}،\n\nثبت‌نام هنرجو پس از تأیید پرداخت فعال شد. کلاس‌های آینده در پرتال مدرس نمایش داده می‌شوند.\n\nمشاهدهٔ کلاس‌ها:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'website'=>array('subject'=>'کلاس شما با هنرجوی جدید فعال شد','body'=>'ثبت‌نام هنرجو پس از تأیید پرداخت فعال شد و کلاس‌های آینده در پرتال مدرس نمایش داده می‌شوند.'),
            ),
        ));
        $out[]=self::event('teacher.invitation_issued.teacher','دعوت‌نامهٔ همکاری مدرس','security','teacher','TeacherInvitationDeliveryWorker','dedicated',array('email'),'security',true,array('invitation_code','claim_url'),'teacher.invitation',array(
            self::PRIMARY_LOCALE=>array(
                'email'=>array('subject'=>'دعوت به همکاری با دلنوازان','body'=>"سلام،\n\nبرای شما دعوت‌نامهٔ همکاری با آکادمی موسیقی دلنوازان صادر شده است.\n\nبرای شروع، پیوند زیر را باز کنید و کد یک‌بارمصرف را وارد کنید:\n{{claim_url}}\n\nکد دعوت: {{invitation_code}}\n\nاین کد فقط یک‌بار قابل استفاده است و باید محرمانه بماند. اگر این دعوت را انتظار نداشتید، این پیام را نادیده بگیرید.\n\nبا احترام، دلنوازان"),
            ),
        ));
        self::appendRescheduleTeacher($out);
        return $out;
    }

    // ------------------------------------------------------------------ admin events

    private static function adminEvents():array{
        $out=array();
        $out[]=self::event('system.inbox_check.admin','بررسی اعلان پرتال','account','admin','notification_management','queue',array('website'),'operational',false,array('when'),'system.inbox_check.admin',array(
            self::PRIMARY_LOCALE=>array('website'=>array('subject'=>'اعلان آزمایشی دلنوازان','body'=>'این یک اعلان آزمایشی داخلی است. زمان ثبت: {{when}}. هیچ ایمیل، واتس‌اپ یا پیامکی ارسال نشده است.')),
        ));
        foreach(array('change'=>'تغییر زمان کلاس','replacement'=>'جلسهٔ جبرانی') as $kind=>$label){
            foreach(array('proposed'=>array('پیشنهاد تازه','{{when}} ({{timezone}}) پیشنهاد شد و در انتظار پاسخ هنرجو است.'),
                'accepted'=>array('پذیرش پیشنهاد','هنرجو پیشنهاد را پذیرفت و زمان {{when}} ({{timezone}}) قطعی شد.'),
                'rejected'=>array('رد پیشنهاد','هنرجو پیشنهاد را رد کرد. زمان فعلی کلاس بدون تغییر می‌ماند.'),
                'failed'=>array('بسته‌شدن پیشنهاد','پیشنهاد ثبت نشد و بسته شد؛ مورد برای بررسی در صف مدیر قرار گرفت.'),
                'cancelled'=>array('لغو پیشنهاد','پیشنهاد توسط مدیر لغو شد.')) as $state=>$content){
                $timeVariables=in_array($state,array('proposed','accepted'),true)?array('when','timezone'):array();
                $out[]=self::event('reschedule.'.$kind.'.'.$state.'.admin','مدیر · '.$label.' · '.$content[0],'lesson','admin','LessonRescheduleService','queue',array('email'),'operational',true,$timeVariables,'reschedule.'.$kind.'.'.$state.'.admin',array(
                    self::PRIMARY_LOCALE=>array('email'=>array('subject'=>'دلنوازان · '.$label.' · '.$content[0],'body'=>"سلام،\n\n".$content[1]."\n\nاین پیام فقط برای اطلاع مدیر آموزشگاه است.\n\nدلنوازان")),
                ));
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ declared financial intents

    private static function declaredFinancialEvents():array{
        $definitions=array(
            'renewal.automatic_upcoming.student'=>array('تمدید خودکار در پیش است','AUTOMATIC_RENEWAL_UPCOMING'),
            'renewal.automatic_charged.student'=>array('تمدید خودکار انجام شد','AUTOMATIC_RENEWAL_CHARGED'),
            'renewal.automatic_failed.student'=>array('تمدید خودکار ناموفق بود','AUTOMATIC_RENEWAL_FAILED'),
            'renewal.manual_payment_required.student'=>array('پرداخت تمدید دوره لازم است','MANUAL_RENEWAL_PAYMENT_REQUIRED'),
            'renewal.guarantee_deadline_approaching.student'=>array('مهلت تمدید نزدیک است','GUARANTEE_DEADLINE_APPROACHING'),
            'renewal.term_lapsed.student'=>array('دوره منقضی شد','TERM_LAPSED'),
            'payment.failed.student'=>array('پرداخت انجام نشد','PAYMENT_FAILED'),
            'payment.recovered.student'=>array('پرداخت بازیابی شد','PAYMENT_RECOVERED'),
            'refund.review_required.student'=>array('درخواست بازپرداخت در حال بررسی است','REFUND_REVIEW_REQUIRED'),
            'refund.resolved.student'=>array('نتیجهٔ درخواست بازپرداخت','REFUND_RESOLVED'),
        );
        $out=array();
        foreach($definitions as $key=>[$label,$intent]){
            $event=self::event($key,$label,'payment','student','canonical:'.$intent,'canonical',array('email','website'),'financial',true,array('name','amount','due_date','portal_url'),strtolower(str_replace('.student','',$key)),array(
                self::PRIMARY_LOCALE=>array(
                    'email'=>array('subject'=>'دلنوازان · '.$label,'body'=>"سلام {{name}}،\n\n".$label.".\n\nمبلغ: {{amount}}\nمهلت: {{due_date}}\n\nبرای مشاهدهٔ جزئیات و اقدام به پرتال هنرجو بروید:\n{{portal_url}}\n\nاین متن پیش‌فرض است و پیش از فعال‌سازی باید از نظر مالی و حقوقی بازبینی شود.\n\nبا احترام، دلنوازان"),
                    'website'=>array('subject'=>$label,'body'=>$label.' — جزئیات در پرتال هنرجو.'),
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
        foreach(array('change'=>array('تغییر زمان کلاس','reschedule.change'),'replacement'=>array('جلسهٔ جبرانی','reschedule.replacement')) as $kind=>[$label,$prefix]){
            $messages=array(
                'proposed'=>array('subject'=>'دلنوازان · '.$label,'body'=>"سلام {{name}}،\n\nبرای شما یک زمان تازه برای ".('replacement'===$kind?'جلسهٔ جبرانی':'کلاس')." پیشنهاد شد:\n{{when}} ({{timezone}})\n\nلطفاً در پرتال هنرجو آن را بپذیرید یا رد کنید:\n{{portal_url}}\n\nتا پاسخ شما، زمان فعلی کلاس تغییر نمی‌کند.\n\nبا احترام، دلنوازان"),
                'accepted'=>array('subject'=>'دلنوازان · تأیید زمان پیشنهادی','body'=>"سلام {{name}}،\n\nزمان پیشنهادی تأیید شد.\n\nزمان جدید: {{when}} ({{timezone}})\n\nجزئیات در پرتال هنرجو در دسترس است:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'rejected'=>array('subject'=>'دلنوازان · زمان پیشنهادی رد شد','body'=>"سلام {{name}}،\n\nزمان پیشنهادی رد شد و زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nاگر زمان تازه‌ای مناسب شماست، از پرتال هنرجو با مدرس هماهنگ کنید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'failed'=>array('subject'=>'دلنوازان · زمان پیشنهادی ثبت نشد','body'=>"سلام {{name}}،\n\nزمان پیشنهادی ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nلطفاً زمان تازه‌ای پیشنهاد کنید یا با پشتیبانی دلنوازان تماس بگیرید:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
                'cancelled'=>array('subject'=>'دلنوازان · پیشنهاد زمان لغو شد','body'=>"سلام {{name}}،\n\nپیشنهاد زمان توسط مدیر لغو شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.\n\nجزئیات در پرتال هنرجو:\n{{portal_url}}\n\nبا احترام، دلنوازان"),
            );
            foreach($messages as $state=>$copy){
                $studentVariables=array_merge(array('name','portal_url'),in_array($state,array('proposed','accepted'),true)?array('when','timezone'):array());
                $out[]=self::event($prefix.'.'.$state.'.student',$label.' · '.$state,'lesson','student','LessonRescheduleService','queue',array('email','website'),'operational',true,$studentVariables,$prefix.'.'.$state,array(
                    self::PRIMARY_LOCALE=>array('email'=>$copy,'website'=>array('subject'=>$label,'body'=>$copy['body'])),
                ));
            }
        }
    }

    private static function appendRescheduleTeacher(array &$out):void{
        foreach(array('change'=>array('تغییر زمان کلاس','reschedule.change'),'replacement'=>array('جلسهٔ جبرانی','reschedule.replacement')) as $kind=>[$label,$prefix]){
            $messages=array(
                'proposed'=>array('subject'=>'دلنوازان · '.$label,'body'=>"سلام {{teacher}}،\n\nدرخواست ".('replacement'===$kind?'جلسهٔ جبرانی':'تغییر زمان')." ثبت شد.\n\nزمان پیشنهادی: {{when}} ({{timezone}})\n\nتا پاسخ هنرجو، این پیشنهاد قطعی نیست. وضعیت را در پرتال مدرس ببینید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'accepted'=>array('subject'=>'دلنوازان · هنرجو زمان پیشنهادی را پذیرفت','body'=>"سلام {{teacher}}،\n\nهنرجو زمان پیشنهادی را پذیرفت و زمان {{when}} ({{timezone}}) قطعی شد.\n\nمشاهدهٔ کلاس‌ها:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'rejected'=>array('subject'=>'دلنوازان · هنرجو زمان پیشنهادی را رد کرد','body'=>"سلام {{teacher}}،\n\nهنرجو زمان پیشنهادی را رد کرد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nلطفاً با هنرجو هماهنگ کنید و زمان تازه‌ای پیشنهاد دهید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'failed'=>array('subject'=>'دلنوازان · پیشنهاد زمان ثبت نشد','body'=>"سلام {{teacher}}،\n\nپیشنهاد زمان ثبت نشد و بسته شد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nدر صورت نیاز، زمان تازه‌ای پیشنهاد دهید:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
                'cancelled'=>array('subject'=>'دلنوازان · پیشنهاد زمان لغو شد','body'=>"سلام {{teacher}}،\n\nپیشنهاد زمان توسط مدیر لغو شد. زمان فعلی کلاس بدون تغییر می‌ماند.\n\nجزئیات در پرتال مدرس:\n{{teacher_portal_url}}\n\nبا احترام، دلنوازان"),
            );
            foreach($messages as $state=>$copy){
                $teacherVariables=array_merge(array('teacher','teacher_portal_url'),in_array($state,array('proposed','accepted'),true)?array('when','timezone'):array());
                $out[]=self::event($prefix.'.'.$state.'.teacher',$label.' · '.$state,'lesson','teacher','LessonRescheduleService','queue',array('email','website'),'operational',true,$teacherVariables,$prefix.'.'.$state,array(
                    self::PRIMARY_LOCALE=>array('email'=>$copy,'website'=>array('subject'=>$label,'body'=>$copy['body'])),
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
