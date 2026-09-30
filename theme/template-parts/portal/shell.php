<?php
/**
 * Shared Student Portal shell.
 *
 * @package DelnavazanTheme
 */

$screen = isset( $args['screen'] ) && in_array( $args['screen'], array( 'home', 'account' ), true ) ? $args['screen'] : 'home';
$model  = isset( $args['model'] ) && is_array( $args['model'] ) ? $args['model'] : array();
$available = ! empty( $model['available'] );
$student = isset( $model['student'] ) && is_array( $model['student'] ) ? $model['student'] : array();
$first_name = isset( $student['first_name'] ) ? (string) $student['first_name'] : '';
$navigation = isset( $model['navigation'] ) && is_array( $model['navigation'] ) ? $model['navigation'] : array();
?>
<main id="main-content" class="site-main dzn-portal dzn-portal--<?php echo esc_attr( $screen ); ?>" tabindex="-1">
	<div class="dzn-portal__masthead">
		<div class="dzn-container dzn-portal__masthead-inner">
			<div>
				<p class="dzn-portal__eyebrow"><?php esc_html_e( 'هنرجوی دلنوازان', 'delnavazan-theme' ); ?></p>
				<h1 class="dzn-portal__title">
					<?php
					if ( 'account' === $screen ) {
						esc_html_e( 'حساب کاربری', 'delnavazan-theme' );
					} elseif ( $first_name ) {
						echo esc_html( sprintf( 'سلام %s،', $first_name ) );
					} else {
						esc_html_e( 'خانهٔ هنرجو', 'delnavazan-theme' );
					}
					?>
				</h1>
				<p class="dzn-portal__lede">
					<?php echo esc_html( 'account' === $screen ? 'جزئیات شخصی، سابقه و بخش‌های آیندهٔ حساب شما' : 'کلاس بعدی و مسیر ترم را یک‌جا ببینید.' ); ?>
				</p>
			</div>
			<?php dzn_theme_portal_component( 'navigation', array( 'items' => $navigation ) ); ?>
		</div>
	</div>

	<div class="dzn-container dzn-portal__content">
		<?php if ( ! empty( $model['is_demo'] ) ) : ?>
			<p class="dzn-portal__demo-label" role="status"><?php esc_html_e( 'پیش‌نمایش نمایشی — اطلاعات ساختگی و غیرقابل ذخیره', 'delnavazan-theme' ); ?></p>
		<?php endif; ?>

		<?php if ( ! $available ) : ?>
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'empty',
					'title'   => 'اطلاعات هنوز آماده نیست',
					'message' => 'این صفحه پس از اتصال امن به نمای خواندنی هنرجو، اطلاعات معتبر را نمایش می‌دهد.',
				)
			);
			?>
		<?php elseif ( 'account' === $screen ) : ?>
			<?php dzn_theme_portal_component( 'profile', array( 'profile' => $model['profile'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'history', array( 'items' => $model['history'] ?? array(), 'account' => true ) ); ?>
			<?php dzn_theme_portal_component( 'payments', array( 'payments' => $model['payments'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'future-orders' ); ?>
			<?php
			dzn_theme_portal_component(
				'notifications',
				array(
					'items'       => $model['notifications'] ?? array(),
					'archive_url' => $model['notification_archive_url'] ?? '',
				)
			);
			?>
		<?php else : ?>
			<?php dzn_theme_portal_component( 'announcement', array( 'announcement' => $model['announcement'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'upcoming-lesson', array( 'lesson' => $model['upcoming_lesson'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'term-timeline', array( 'term' => $model['term'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'history', array( 'items' => $model['history'] ?? array(), 'account' => false ) ); ?>
			<?php dzn_theme_portal_component( 'feedback' ); ?>
			<?php dzn_theme_portal_component( 'contact', array( 'contact' => $model['contact'] ?? array() ) ); ?>
		<?php endif; ?>
	</div>
</main>
