<?php
$model = $args['model'] ?? array();
$teacher_tz = $model['teacher_timezone'] ?? ( $model['profile']['timezone'] ?? '' );
dzn_theme_teacher_portal_component( 'tour', array( 'auto' => empty( $model['is_demo'] ) ) );
$announcement = $model['announcement'] ?? array();
$announcement_states = array( 'general', 'technical', 'reminder', 'none' );
$announcement_valid = in_array( $announcement['state'] ?? 'none', $announcement_states, true );
?>
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
<?php dzn_theme_teacher_portal_component( 'post-intro-outcomes', array( 'items' => $model['post_intro_outcomes'] ?? array() ) ); ?>
<?php dzn_theme_teacher_portal_component( 'classes', array( 'items' => $model['classes'] ?? array(), 'today_items' => $model['today_classes'] ?? array(), 'upcoming_items' => $model['upcoming_classes'] ?? array(), 'model' => $model, 'timezone' => $teacher_tz ) ); ?>
<?php dzn_theme_teacher_portal_component( 'calendar', array( 'items' => $model['calendar'] ?? array(), 'model' => $model, 'timezone' => $teacher_tz ) ); ?>
