<?php
$screen = isset( $args['screen'] ) && in_array( $args['screen'], array( 'home', 'account', 'onboarding' ), true ) ? $args['screen'] : 'home';
$model = isset( $args['model'] ) && is_array( $args['model'] ) ? $args['model'] : array();
$teacher = isset( $model['teacher'] ) && is_array( $model['teacher'] ) ? $model['teacher'] : array();
?>
<main id="main-content" class="site-main dzn-teacher-portal dzn-teacher-portal--<?php echo esc_attr( $screen ); ?>" tabindex="-1" dir="rtl">
 <header class="dzn-tp-masthead"><div class="dzn-container dzn-tp-masthead__inner"><div><p class="dzn-tp-kicker">مدرس دلنوازان</p><h1><?php echo esc_html( 'account' === $screen ? 'حساب کاربری مدرس' : ( 'onboarding' === $screen ? 'شروع همکاری با دلنوازان' : ( ! empty( $teacher['first_name'] ) ? sprintf( 'سلام %s،', $teacher['first_name'] ) : 'خانهٔ مدرس' ) ) ); ?></h1><p><?php echo esc_html( 'account' === $screen ? 'اطلاعات، ترجیحات و نمای کلی تدریس شما' : ( 'onboarding' === $screen ? 'راهنمای نمایشی آماده‌شدن برای تدریس' : 'کلاس‌ها و کارهای مهم امروز را یک‌جا ببینید.' ) ); ?></p></div>
 <nav aria-label="پیمایش پرتال مدرس"><ul><?php foreach ( $model['navigation'] ?? array() as $item ) : ?><li><a href="<?php echo esc_url( $item['url'] ?? '' ); ?>"<?php if ( ! empty( $item['current'] ) ) : ?> aria-current="page"<?php endif; ?>><?php echo esc_html( $item['label'] ?? '' ); ?></a></li><?php endforeach; ?></ul></nav></div></header>
 <div class="dzn-container dzn-tp-content">
 <?php if ( ! empty( $model['is_demo'] ) ) : ?><p class="dzn-tp-demo" role="status">پیش‌نمایش نمایشی — همهٔ اطلاعات ساختگی‌اند و هیچ اقدامی ذخیره نمی‌شود.</p><?php endif; ?>
 <?php if ( empty( $model['available'] ) ) : ?><section class="dzn-tp-section dzn-tp-unavailable"><h2>اطلاعات مدرس در دسترس نیست</h2><p>پس از اتصال امن نمای خواندنی مدرس، اطلاعات معتبر اینجا نمایش داده می‌شود.</p></section>
 <?php elseif ( 'account' === $screen ) : dzn_theme_teacher_portal_component( 'account', array( 'model' => $model ) );
 elseif ( 'onboarding' === $screen ) : dzn_theme_teacher_portal_component( 'onboarding', array( 'model' => $model ) );
 else : dzn_theme_teacher_portal_component( 'home', array( 'model' => $model ) ); endif; ?>
 </div>
</main>
