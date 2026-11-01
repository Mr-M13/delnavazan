<?php
return array(
    'id' => 'attendance',
    'version' => 2,
    'acknowledgement_version' => 0,
    'title' => 'حضور و پایان کلاس',
    'short_title' => 'ثبت حضور',
    'description' => 'بعد از کلاس، اگر دکمهٔ گزارش نمایش داده شد، برگزاری و حضور را ثبت کنید. اگر ثبت نکنید، سامانه ممکن است کلاس را بعد از پایان «فرض تکمیل‌شده» حساب کند؛ این با حضور تأییدشده یکی نیست.',
    'portal_screen' => 'home',
    'highlight_selector' => '[data-dzn-highlight="attendance"]',
    'action_label' => 'نمایش محل ثبت حضور',
    'points' => array( 'ثبت شما شاهد بررسی است و پرداخت را خودکار نمی‌کند.' ),
    'completion_criteria' => 'این بخش را در پرتال دیدم.',
);
