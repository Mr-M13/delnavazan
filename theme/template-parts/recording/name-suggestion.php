<?php
/** Optional project-name contribution, never a recording/research permission. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$messages = array(
    'received' => 'سپاسگزاریم! پیشنهاد شما ثبت شد و برای انتخاب نام پروژه بررسی می‌شود.',
    'invalid' => 'لطفاً نام پیشنهادی را با حداکثر ۱۲۰ نویسه وارد کنید.',
    'expired' => 'مهلت فرم تمام شده است. صفحه را تازه کنید و دوباره پیشنهادتان را بفرستید.',
    'limited' => 'لطفاً یک دقیقه صبر کنید و سپس پیشنهاد دیگری بفرستید.',
    'error' => 'پیشنهاد ثبت نشد. لطفاً دوباره تلاش کنید.',
);
$status = isset( $_GET['name-status'] ) && is_string( $_GET['name-status'] ) ? sanitize_key( $_GET['name-status'] ) : '';
?>
<section id="project-name" class="dzn-project__suggestion" lang="fa" dir="rtl" aria-labelledby="project-name-title">
    <h2 id="project-name-title">برای این پروژه چه نامی پیشنهاد می‌کنید؟</h2>
    <p>به دنبال نامی برای این پروژه هستیم؛ نامی که پیوند موسیقی ایرانی، یادگیری و پژوهش را بیان کند. پیشنهادتان را برای ما بفرستید. در آیندهٔ نزدیک، پس از بررسی پیشنهادها، نام پروژه را انتخاب می‌کنیم.</p>
    <?php if ( isset( $messages[ $status ] ) ) : ?>
        <p class="dzn-project__feedback" role="status"><?php echo esc_html( $messages[ $status ] ); ?></p>
    <?php endif; ?>
    <?php if ( class_exists( '\Delnavazan\Platform\Public\ResearchNameSuggestionController' ) ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="dzn_research_name">
        <?php dzn_theme_nonce_field( 'dzn_research_name', '_wpnonce', false ); ?>
        <div class="dzn-project__honeypot" aria-hidden="true"><label for="project-website">Website</label><input id="project-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
        <label for="project-name-input">نام پیشنهادی شما</label>
        <div class="dzn-project__suggestion-fields">
            <input id="project-name-input" name="project_name" type="text" maxlength="120" required dir="auto" aria-describedby="project-name-note" placeholder="نامی که برای این پروژه دوست دارید">
            <button class="dzn-button" type="submit">ارسال پیشنهاد</button>
        </div>
        <p id="project-name-note" class="dzn-project__suggestion-note">نیازی به ورود یا اطلاعات تماس نیست. پیشنهاد فقط برای بررسی در اختیار مدیران دلنوازان قرار می‌گیرد.</p>
    </form>
    <?php else : ?><p>فرم پیشنهاد نام اکنون در دسترس نیست. لطفاً بعداً دوباره مراجعه کنید.</p><?php endif; ?>
</section>
