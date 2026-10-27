<?php
$screen = isset( $args['screen'] ) && in_array( $args['screen'], array( 'home', 'classes', 'finance', 'settings', 'onboarding' ), true ) ? $args['screen'] : 'home';
$model = isset( $args['model'] ) && is_array( $args['model'] ) ? $args['model'] : array();
$teacher = isset( $model['teacher'] ) && is_array( $model['teacher'] ) ? $model['teacher'] : array();
$title = 'settings' === $screen ? 'تنظیمات' : ( 'finance' === $screen ? 'مالی' : ( 'classes' === $screen ? 'کلاس‌ها' : ( 'onboarding' === $screen ? 'شروع همکاری با دلنوازان' : ( ! empty( $teacher['first_name'] ) ? sprintf( 'امروز · %s', $teacher['first_name'] ) : 'امروز' ) ) ) );
$lede = 'settings' === $screen ? 'پروفایل، دسترسی و ترجیحات شما' : ( 'finance' === $screen ? 'سوابق مالی بر پایهٔ اطلاعات معتبر سامانه' : ( 'classes' === $screen ? 'کلاس‌های جاری، پیش رو و اخیر با اقدام‌های مجاز' : ( 'onboarding' === $screen ? 'اطلاعات را مرحله‌به‌مرحله تکمیل و برای بررسی مدیر ارسال کنید.' : 'درخواست‌ها، کلاس‌های آینده و خلاصهٔ تدریس شما.' ) ) );
$navigation = isset( $model['navigation'] ) && is_array( $model['navigation'] ) ? $model['navigation'] : array();
$show_tabs = ! empty( $model['available'] ) && 'onboarding' !== $screen && $navigation;
?>
<main id="main-content" class="site-main dzn-teacher-portal dzn-teacher-portal--<?php echo esc_attr( $screen ); ?>" tabindex="-1" dir="rtl">
 <header class="dzn-tp-appbar">
  <div class="dzn-tp-appbar__inner">
   <a class="dzn-tp-appbar__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">دلنوازان</a>
   <p class="dzn-tp-appbar__title"><?php echo esc_html( $title ); ?></p>
   <?php if ( is_user_logged_in() ) : ?><a class="dzn-tp-appbar__action" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">خروج</a><?php endif; ?>
  </div>
 </header>
 <header class="dzn-tp-masthead"><div class="dzn-container dzn-tp-masthead__inner"><div><p class="dzn-tp-kicker">مدرس دلنوازان</p><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $lede ); ?></p></div>
 <nav id="dzn-tp-nav-desktop" aria-label="پیمایش پرتال مدرس"><ul><?php foreach ( $navigation as $item ) : ?><li><a href="<?php echo esc_url( $item['url'] ?? '' ); ?>"<?php if ( ! empty( $item['current'] ) ) : ?> aria-current="page"<?php endif; ?>><?php echo esc_html( $item['label'] ?? '' ); ?></a></li><?php endforeach; ?></ul></nav><?php if ( is_user_logged_in() ) : ?><p class="dzn-tp-account"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">صفحهٔ اصلی</a> <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">خروج از حساب</a></p><?php endif; ?></div></header>
 <div class="dzn-container dzn-tp-content">
 <?php if ( ! empty( $model['is_demo'] ) ) : ?><p class="dzn-tp-demo" role="status">پیش‌نمایش نمایشی — همهٔ اطلاعات ساختگی‌اند و هیچ اقدامی ذخیره نمی‌شود.</p><?php endif; ?>
 <?php if ( ! empty( $model['available'] ) ) : foreach ( dzn_theme_teacher_portal_notices( $screen ) as $notice ) : ?><p class="dzn-tp-notice is-<?php echo esc_attr( $notice[0] ); ?>" role="<?php echo 'error' === $notice[0] ? 'alert' : 'status'; ?>"><?php echo esc_html( $notice[1] ); ?></p><?php endforeach; endif; ?>
 <?php if ( empty( $model['available'] ) ) :
  $state = (string) ( $model['state'] ?? 'error' );
  $messages = array(
   'not_linked' => array( 'این حساب هنوز به پرتال مدرس متصل نیست', 'برای این حساب، پیوند معتبر مدرس پیدا نشد. اگر دعوت‌نامهٔ همکاری دارید، فرایند دعوت را کامل کنید.' ),
   'onboarding_required' => array( 'شروع همکاری هنوز کامل نشده است', 'پیش از دسترسی به پرتال مدرس، پروفایل و زمان‌های تدریس را کامل کنید، برای بررسی بفرستید و منتظر نتیجهٔ مدیر بمانید.' ),
   'signed_out' => array( 'برای ادامه وارد شوید', 'برای دسترسی به پرتال مدرس ابتدا وارد حساب خود شوید.' ),
   'error' => array( 'اطلاعات مدرس در دسترس نیست', 'در حال حاضر نتوانستیم اطلاعات معتبر مدرس را از سامانه دریافت کنیم. لطفاً بعداً دوباره تلاش کنید.' ),
  );
  $message = $messages[ $state ] ?? $messages['error'];
  $portal_url = home_url( '/teacher-portal/' );
  $recovery = array(
   'signed_out' => array( 'ورود به حساب', wp_login_url( $portal_url ) ),
   'onboarding_required' => array( 'ادامهٔ شروع همکاری', add_query_arg( 'teacher-view', 'onboarding', $portal_url ) ),
   'not_linked' => array( 'ثبت دعوت‌نامهٔ همکاری', home_url( '/teacher-invitation/' ) ),
   'error' => array( 'تلاش دوباره', $portal_url ),
  );
 ?><section class="dzn-tp-section dzn-tp-unavailable dzn-tp-unavailable--<?php echo esc_attr( $state ); ?>" role="<?php echo 'error' === $state ? 'alert' : 'status'; ?>"><h2><?php echo esc_html( $message[0] ); ?></h2><p><?php echo esc_html( $message[1] ); ?></p><div class="dzn-tp-unavailable__actions"><?php if ( isset( $recovery[ $state ] ) ) : ?><a class="dzn-button" href="<?php echo esc_url( $recovery[ $state ][1] ); ?>"><?php echo esc_html( $recovery[ $state ][0] ); ?></a><?php endif; ?><?php if ( 'not_linked' === $state ) : ?><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>">صفحهٔ اصلی</a><?php endif; ?></div></section>
 <?php elseif ( 'settings' === $screen ) : dzn_theme_teacher_portal_component( 'account', array( 'model' => $model ) );
 elseif ( 'finance' === $screen ) : dzn_theme_teacher_portal_component( 'finance', array( 'model' => $model ) );
 elseif ( 'classes' === $screen ) : dzn_theme_teacher_portal_component( 'classes', array( 'items' => $model['classes'] ?? array(), 'model' => $model, 'timezone' => $model['teacher_timezone'] ?? '' ) );
 elseif ( 'onboarding' === $screen ) : dzn_theme_teacher_portal_component( 'onboarding', array( 'model' => $model ) );
 else : dzn_theme_teacher_portal_component( 'home', array( 'model' => $model ) ); endif; ?>
 </div>
 <?php if ( $show_tabs ) : ?>
 <nav id="dzn-tp-nav-mobile" class="dzn-tp-tabs" aria-label="پیمایش پرتال مدرس">
  <ul>
   <?php foreach ( array_slice( $navigation, 0, 4 ) as $item ) : ?>
    <?php $label = (string) ( $item['label'] ?? '' ); $url = (string) ( $item['url'] ?? '' ); if ( '' === $label || '' === $url ) { continue; } ?>
    <li><a class="dzn-tp-tabs__link<?php echo ! empty( $item['current'] ) ? ' is-current' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo ! empty( $item['current'] ) ? ' aria-current="page"' : ''; ?>><span class="dzn-tp-tabs__label"><?php echo esc_html( $label ); ?></span></a></li>
   <?php endforeach; ?>
  </ul>
 </nav>
 <?php endif; ?>
</main>
