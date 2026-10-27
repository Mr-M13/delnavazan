<?php
/*
Template Name: Teacher Onboarding — Recording Consent
*/
if ( ! defined( 'ABSPATH' ) ) exit;
$model = dzn_theme_recording_consent_preview_model();
?>
<section class="dzn-tp-section" aria-labelledby="tp-recording-consent">
    <div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ضبط و دادهٔ آموزشی</p><h2 id="tp-recording-consent">رضایت ضبط و استفاده برای آموزش مدل</h2></div></section>
    <p class="dzn-tp-help">کلاس‌ها ممکن است برای کنترل کیفیت و آموزش مدل ضبط شوند. صوت خام تا ۹۰ روز نگهداری می‌شود. مجموعهٔ آموزشی ناشناس است. هر زمان می‌توانید رضایت را پس بگیرید.</p>
    <form class="dzn-tp-form">
        <label><input type="checkbox" name="consent_recording" disabled> من با ضبط کلاس‌هایم برای کنترل کیفیت و آموزش مدل موافقم.</label>
        <label><input type="checkbox" name="consent_students" disabled> می‌دانم دانش‌آموزان نیز باید رضایت دهند و ضبط فقط در صورت رضایت همه طرفین انجام می‌شود.</label>
        <label><input type="checkbox" name="hardware_ack" disabled> از رابط صوتی خارجی / میکروفون USB استفاده می‌کنم و حالت موسیقی Meet را فعال خواهم کرد.</label>
        <button class="dzn-button" type="button" disabled>ادامه</button>
    </form>
</section>
