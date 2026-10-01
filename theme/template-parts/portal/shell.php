<?php
/**
 * Shared Student Portal shell.
 *
 * @package DelnavazanTheme
 */

$screen = isset( $args['screen'] ) && in_array( $args['screen'], array( 'home', 'account' ), true ) ? $args['screen'] : 'home';
$model  = isset( $args['model'] ) && is_array( $args['model'] ) ? $args['model'] : array();
$state  = isset( $model['state'] ) && in_array( (string) $model['state'], array( 'ok', 'signed_out', 'not_linked', 'error', 'no_data' ), true )
	? (string) $model['state']
	: ( ! empty( $model['available'] ) ? 'ok' : 'error' );
$available = 'ok' === $state;
$student = isset( $model['student'] ) && is_array( $model['student'] ) ? $model['student'] : array();
// Identity is only ever read for a working portal, so a non-ok state can never
// greet the visitor with a name from another principal.
$first_name = ( 'ok' === $state && isset( $student['first_name'] ) ) ? (string) $student['first_name'] : '';
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
			<?php if ( is_user_logged_in() ) : ?>
				<p class="dzn-portal__account">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'صفحهٔ اصلی', 'delnavazan-theme' ); ?></a>
					<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'خروج از حساب', 'delnavazan-theme' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="dzn-container dzn-portal__content">
		<?php if ( ! empty( $model['is_demo'] ) ) : ?>
			<p class="dzn-portal__demo-label" role="status"><?php esc_html_e( 'پیش‌نمایش نمایشی — اطلاعات ساختگی و غیرقابل ذخیره', 'delnavazan-theme' ); ?></p>
		<?php endif; ?>

		<?php if ( 'signed_out' === $state ) : ?>
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'empty',
					'title'   => 'برای دیدن پرتال هنرجو وارد شوید',
					'message' => 'این صفحه اطلاعات حساب هنرجو را نشان می‌دهد. با حساب خود وارد شوید تا کلاس‌ها، مسیر ترم و مشخصات شما نمایش داده شود.',
				)
			);
			?>
			<p class="dzn-portal__state-actions">
				<a class="dzn-button" href="<?php echo esc_url( wp_login_url( home_url( '/student-portal/' ) ) ); ?>"><?php esc_html_e( 'ورود به حساب', 'delnavazan-theme' ); ?></a>
			</p>
		<?php elseif ( 'not_linked' === $state ) : ?>
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'empty',
					'title'   => 'این حساب هنوز به پرتال هنرجو متصل نشده است',
					'message' => 'ورود شما موفق بود، اما پروندهٔ هنرجویی به این حساب متصل نیست. اگر فکر می‌کنید این اشتباه است، از راه‌های ارتباطی پایین همین صفحه با دلنوازان در تماس باشید.',
				)
			);
			?>
		<?php elseif ( 'no_data' === $state ) : ?>
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'empty',
					'title'   => 'هنوز ثبت‌نام فعالی برای این حساب ثبت نشده است',
					'message' => 'پس از ثبت‌نام و تأیید جلسهٔ معارفه، کلاس‌ها، مسیر ترم و سابقهٔ شما در همین صفحه نمایش داده می‌شود.',
				)
			);
			?>
			<p class="dzn-portal__state-actions">
				<a class="dzn-button" href="<?php echo esc_url( home_url( '/enrol/' ) ); ?>"><?php esc_html_e( 'شروع ثبت‌نام', 'delnavazan-theme' ); ?></a>
			</p>
		<?php elseif ( 'error' === $state ) : ?>
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'error',
					'title'   => 'نمایش پرتال در این لحظه ممکن نیست',
					'message' => 'اطلاعات هنرجو خوانده نشد. لطفاً کمی بعد دوباره تلاش کنید؛ اگر ادامه داشت از راه‌های ارتباطی پایین همین صفحه اطلاع دهید.',
				)
			);
			?>
			<p class="dzn-portal__state-actions">
				<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( home_url( '/student-portal/' ) ); ?>"><?php esc_html_e( 'تلاش دوباره', 'delnavazan-theme' ); ?></a>
			</p>
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
