<?php
$model = $args['model'] ?? array();
$teacher_tz = $model['teacher_timezone'] ?? ( $model['profile']['timezone'] ?? '' );
dzn_theme_teacher_portal_component( 'tour', array( 'auto' => empty( $model['is_demo'] ) ) );
$announcement = $model['announcement'] ?? array();
$announcement_states = array( 'general', 'technical', 'reminder', 'none' );
$announcement_valid = in_array( $announcement['state'] ?? 'none', $announcement_states, true );
?>
<?php if ( ! empty( $model['statistics'] ) ) : ?>
<section class="dzn-tp-section" aria-label="خلاصهٔ تدریس">
 <div class="dzn-tp-heading">
  <div>
   <p class="dzn-tp-kicker">وضعیت کلی</p>
   <h2>خلاصهٔ تدریس</h2>
  </div>
  <?php if ( $teacher_tz ) : ?>
   <span class="dzn-timezone-label"><span aria-hidden="true">◷</span> منطقهٔ زمانی: <?php echo esc_html( $teacher_tz ); ?></span>
  <?php endif; ?>
 </div>
 <div class="dzn-tp-stats">
  <?php foreach ( array( 'active_students' => 'هنرجویان', 'upcoming' => 'کلاس‌های پیش رو', 'lessons_month' => 'کلاس این ماه', 'hours_month' => 'ساعت این ماه' ) as $key => $label ) : ?>
   <div class="dzn-tp-stat-card">
    <strong><?php echo esc_html( $model['statistics'][ $key ] ?? '—' ); ?></strong>
    <span><?php echo esc_html( $label ); ?></span>
   </div>
  <?php endforeach; ?>
 </div>
</section>
<?php endif; ?>
<?php if ( ! $announcement_valid ) : ?>
 <aside class="dzn-tp-banner is-error" role="alert">
  <strong>اعلان در دسترس نیست</strong>
  <p>وضعیت اعلان شناخته نشد.</p>
 </aside>
<?php elseif ( 'none' !== ( $announcement['state'] ?? 'none' ) ) : ?>
 <aside class="dzn-tp-banner is-<?php echo esc_attr( $announcement['state'] ); ?>" role="status">
  <span aria-hidden="true">●</span>
  <div>
   <strong><?php echo esc_html( $announcement['title'] ?? '' ); ?></strong>
   <p><?php echo esc_html( $announcement['message'] ?? '' ); ?></p>
  </div>
 </aside>
<?php endif; ?>
<?php dzn_theme_teacher_portal_component( 'attention', array( 'items' => $model['attention'] ?? array(), 'model' => $model, 'timezone' => $teacher_tz ) ); ?>
<?php dzn_theme_teacher_portal_component( 'classes', array( 'items' => $model['classes'] ?? array(), 'model' => $model, 'timezone' => $teacher_tz ) ); ?>
<?php dzn_theme_teacher_portal_component( 'calendar', array( 'items' => $model['calendar'] ?? array(), 'model' => $model, 'timezone' => $teacher_tz ) ); ?>
<?php dzn_theme_teacher_portal_component( 'help' ); ?>
