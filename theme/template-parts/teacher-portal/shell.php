<?php
$screen = isset( $args['screen'] ) && in_array( $args['screen'], array( 'home', 'classes', 'finance', 'settings', 'onboarding', 'induction' ), true ) ? $args['screen'] : 'home';
$model = isset( $args['model'] ) && is_array( $args['model'] ) ? $args['model'] : array();
$teacher = isset( $model['teacher'] ) && is_array( $model['teacher'] ) ? $model['teacher'] : array();
$teacher_first_name = trim( (string) ( $model['teacher']['first_name'] ?? '' ) );
$title = 'settings' === $screen ? 'تنظیمات' : ( 'finance' === $screen ? 'مالی' : ( 'classes' === $screen ? 'کلاس‌ها' : ( 'onboarding' === $screen ? 'شروع همکاری با دلنوازان' : ( 'induction' === $screen ? 'راهنمای تدریس' : 'امروز' ) ) ) );
$heading = 'home' === $screen ? 'درود بر شما' . ( '' !== $teacher_first_name ? '، ' . $teacher_first_name : '' ) : $title;
$navigation = isset( $model['navigation'] ) && is_array( $model['navigation'] ) ? $model['navigation'] : array();
$show_tabs = ! empty( $model['available'] ) && 'onboarding' !== $screen && $navigation;
$notification_unread = isset( $model['notification_unread_count'] ) ? max( 0, (int) $model['notification_unread_count'] ) : 0;
$notifications_available = ! empty( $model['notifications_available'] );
$notification_url = home_url( '/teacher-portal/' ) . '#dzn-tp-notifications';
?>
<main id="main-content" class="site-main dzn-teacher-portal dzn-teacher-portal--<?php echo esc_attr( $screen ); ?>" tabindex="-1" dir="rtl">
 <header class="dzn-tp-appbar">
  <div class="dzn-tp-appbar__inner">
   <p class="dzn-tp-appbar__title"><?php echo esc_html( $title ); ?></p>
   <?php if ( is_user_logged_in() && $notifications_available ) : ?><a class="dzn-tp-appbar__bell<?php echo $notification_unread > 0 ? ' has-unread' : ''; ?>" data-dzn-nav-notifications="1" href="<?php echo esc_url( $notification_url ); ?>" aria-label="<?php echo esc_attr( $notification_unread > 0 ? 'اعلان‌ها، ' . number_format_i18n( $notification_unread ) . ' خوانده‌نشده' : 'اعلان‌ها' ); ?>"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 22a2.6 2.6 0 0 0 2.6-2.6h-5.2A2.6 2.6 0 0 0 12 22Zm7-6.2V11a7 7 0 0 0-5.3-6.8V3.5a1.7 1.7 0 1 0-3.4 0v.7A7 7 0 0 0 5 11v4.8l-1.7 1.7v1h17.4v-1Z"/></svg><?php if ( $notification_unread > 0 ) : ?><span class="dzn-tp-appbar__badge"><?php echo esc_html( number_format_i18n( $notification_unread ) ); ?></span><?php endif; ?></a><?php endif; ?><?php if ( is_user_logged_in() ) : ?><a class="dzn-tp-appbar__action" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">خروج</a><?php endif; ?>
  </div>
 </header>
 <header class="dzn-tp-masthead"><div class="dzn-container dzn-tp-masthead__inner"><h1><?php echo esc_html( $heading ); ?></h1>
 <nav id="dzn-tp-nav-desktop" class="dzn-tp-nav-desktop" aria-label="پیمایش پرتال مدرس"><ul><?php foreach ( $navigation as $item ) : ?><li><a href="<?php echo esc_url( $item['url'] ?? '' ); ?>" data-dzn-nav-screen="<?php echo esc_attr( (string) ( $item['screen'] ?? '' ) ); ?>"<?php if ( ! empty( $item['current'] ) ) : ?> aria-current="page"<?php endif; ?>><?php echo esc_html( $item['label'] ?? '' ); ?></a></li><?php endforeach; ?></ul></nav><?php if ( is_user_logged_in() ) : ?><p class="dzn-tp-account"><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">خروج</a></p><?php endif; ?></div></header>
 <div class="dzn-container dzn-tp-content">
 <?php if ( ! empty( $model['is_demo'] ) ) : ?><p class="dzn-tp-demo" role="status">پیش‌نمایش نمایشی — همهٔ اطلاعات ساختگی‌اند و هیچ اقدامی ذخیره نمی‌شود.</p><?php endif; ?>
 <?php if ( ! empty( $model['available'] ) ) : foreach ( dzn_theme_teacher_portal_notices( $screen ) as $notice ) : ?><p class="dzn-tp-notice is-<?php echo esc_attr( $notice[0] ); ?>" role="<?php echo 'error' === $notice[0] ? 'alert' : 'status'; ?>"><?php echo esc_html( $notice[1] ); ?></p><?php endforeach; endif; ?>
 <?php if ( ! empty( $model['induction_highlight'] ) ) : dzn_theme_teacher_portal_component( 'induction-highlight', array( 'step' => $model['induction_highlight'] ) ); endif; ?>
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
 elseif ( 'classes' === $screen ) : dzn_theme_teacher_portal_component( 'classes', array( 'items' => array(), 'today_items' => $model['today_classes'] ?? array(), 'past_items' => $model['past_classes'] ?? array(), 'model' => $model, 'timezone' => $model['teacher_timezone'] ?? '' ) ); dzn_theme_teacher_portal_component( 'calendar', array( 'items' => $model['calendar'] ?? array(), 'model' => $model, 'timezone' => $model['teacher_timezone'] ?? '' ) );
 elseif ( 'onboarding' === $screen ) : dzn_theme_teacher_portal_component( 'onboarding', array( 'model' => $model ) );
 elseif ( 'induction' === $screen ) : dzn_theme_teacher_portal_component( 'induction', array( 'model' => $model ) );
 else : dzn_theme_teacher_portal_component( 'home', array( 'model' => $model ) ); endif; ?>
 </div>
 <?php if ( $show_tabs ) : ?>
 <nav id="dzn-tp-nav-mobile" class="dzn-tp-tabs" aria-label="پیمایش پرتال مدرس">
  <ul>
   <?php foreach ( array_slice( $navigation, 0, 4 ) as $item ) : ?>
    <?php $label = (string) ( $item['label'] ?? '' ); $url = (string) ( $item['url'] ?? '' ); if ( '' === $label || '' === $url ) { continue; } ?>
    <li><a class="dzn-tp-tabs__link<?php echo ! empty( $item['current'] ) ? ' is-current' : ''; ?>" href="<?php echo esc_url( $url ); ?>" data-dzn-nav-screen="<?php echo esc_attr( (string) ( $item['screen'] ?? '' ) ); ?>"<?php echo ! empty( $item['current'] ) ? ' aria-current="page"' : ''; ?>><span class="dzn-tp-tabs__label"><?php echo esc_html( $label ); ?></span></a></li>
   <?php endforeach; ?>
  </ul>
 </nav>
 <?php endif; ?>
</main>
