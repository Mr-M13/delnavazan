<?php
/**
 * Shared Student Portal shell.
 *
 * @package DelnavazanTheme
 */

$screen = isset( $args['screen'] ) && in_array( $args['screen'], array( 'home', 'account', 'schedule', 'payments' ), true ) ? $args['screen'] : 'home';
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
$notification_unread = isset( $model['notification_unread_count'] ) ? max( 0, (int) $model['notification_unread_count'] ) : 0;
$notifications_available = ! empty( $model['notifications_available'] );
$notification_account_url = add_query_arg( 'portal-view', 'account', home_url( '/student-portal/' ) ) . '#dzn-portal-notifications';
$screen_title = 'account' === $screen ? 'حساب کاربری' : ( 'payments' === $screen ? 'پرداخت' : 'کلاس‌ها' );
$heading = 'account' === $screen ? 'حساب کاربری' : ( 'payments' === $screen ? 'پرداخت و اشتراک' : ( 'درود بر شما' . ( '' !== $first_name ? '، ' . $first_name : '' ) ) );
?>
<main id="main-content" class="site-main dzn-portal dzn-portal--<?php echo esc_attr( $screen ); ?>" tabindex="-1">
	<header class="dzn-appbar">
		<div class="dzn-appbar__inner">
			<p class="dzn-appbar__title"><?php echo esc_html( $screen_title ); ?></p>
			<?php if ( is_user_logged_in() && $notifications_available ) : ?>
				<a class="dzn-appbar__bell<?php echo $notification_unread > 0 ? ' has-unread' : ''; ?>" href="<?php echo esc_url( $notification_account_url ); ?>" aria-label="<?php echo esc_attr( $notification_unread > 0 ? 'اعلان‌ها، ' . number_format_i18n( $notification_unread ) . ' خوانده‌نشده' : 'اعلان‌ها' ); ?>">
					<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 22a2.6 2.6 0 0 0 2.6-2.6h-5.2A2.6 2.6 0 0 0 12 22Zm7-6.2V11a7 7 0 0 0-5.3-6.8V3.5a1.7 1.7 0 1 0-3.4 0v.7A7 7 0 0 0 5 11v4.8l-1.7 1.7v1h17.4v-1Z"/></svg>
					<?php if ( $notification_unread > 0 ) : ?><span class="dzn-appbar__badge"><?php echo esc_html( number_format_i18n( $notification_unread ) ); ?></span><?php endif; ?>
				</a>
			<?php endif; ?>
			<?php if ( is_user_logged_in() ) : ?>
				<a class="dzn-appbar__action" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'خروج', 'delnavazan-theme' ); ?></a>
			<?php endif; ?>
		</div>
	</header>
	<div class="dzn-portal__masthead">
		<div class="dzn-container dzn-portal__masthead-inner">
			<h1 class="dzn-portal__title"><?php echo esc_html( $heading ); ?></h1>
			<?php dzn_theme_portal_component( 'navigation', array( 'items' => $navigation, 'id' => 'dzn-portal-nav-desktop' ) ); ?>
			<?php if ( is_user_logged_in() ) : ?>
				<p class="dzn-portal__account"><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'خروج', 'delnavazan-theme' ); ?></a></p>
			<?php endif; ?>
		</div>
	</div>

	<div class="dzn-container dzn-portal__content">
		<?php if ( 'ok' === $state && ! empty( $model['provisioning'] ) ) : ?>
			<?php $provisioning = $model['provisioning']; ?>
			<div class="dzn-portal__provisioning" role="status">
				<strong><?php echo esc_html( (string) ( $provisioning['title'] ?? '' ) ); ?></strong>
				<p><?php echo esc_html( (string) ( $provisioning['message'] ?? '' ) ); ?></p>
				<?php if ( ! empty( $provisioning['course_reference'] ) || ! empty( $provisioning['course_name'] ) ) : ?><p class="dzn-portal__provisioning-course"><?php echo esc_html( (string) ( ! empty( $provisioning['course_name'] ) ? $provisioning['course_name'] : $provisioning['course_reference'] ) ); ?></p><?php endif; ?>
			</div>
		<?php endif; ?>
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
		<?php elseif ( 'schedule' === $screen ) : ?>
			<?php dzn_theme_portal_component( 'reschedule', array( 'items' => $model['reschedule_proposals'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'schedule', array( 'items' => $model['schedule'] ?? array(), 'timezone_label' => $model['timezone_label'] ?? '' ) ); ?>
		<?php elseif ( 'payments' === $screen ) : ?>
			<?php dzn_theme_portal_component( 'payments', array( 'payments' => $model['payments'] ?? array() ) ); ?>
		<?php elseif ( 'account' === $screen ) : ?>
			<?php dzn_theme_portal_component( 'profile', array( 'profile' => $model['profile'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'recording-choices', array( 'recording' => $model['recording_choices'] ?? array() ) ); ?>
			<?php if ( ! in_array( (string) ( $model['payments']['state'] ?? '' ), array( 'disabled', 'unavailable' ), true ) ) : ?>
				<?php dzn_theme_portal_component( 'payments', array( 'payments' => $model['payments'] ?? array() ) ); ?>
			<?php endif; ?>
			<?php
			dzn_theme_portal_component(
				'notifications',
				array(
					'items'       => $model['notifications'] ?? array(),
					'archive_url' => $model['notification_archive_url'] ?? '',
					'available'   => ! empty( $model['notifications_available'] ),
					'unread'      => $notification_unread,
					'actions'     => $model['notification_actions'] ?? array(),
				)
			);
			?>
			<?php dzn_theme_portal_component( 'account-status', array( 'status' => $model['account_status'] ?? array() ) ); ?>
		<?php else : ?>
			<?php dzn_theme_portal_component( 'reschedule', array( 'items' => $model['reschedule_proposals'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'term-timeline', array( 'term' => $model['term'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'schedule', array( 'items' => $model['schedule'] ?? array(), 'timezone_label' => $model['timezone_label'] ?? '' ) ); ?>
			<?php dzn_theme_portal_component( 'booking-requests', array( 'requests' => $model['booking_requests'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'post-intro', array( 'items' => $model['post_intro'] ?? array(), 'recording' => $model['recording_choices'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'announcement', array( 'announcement' => $model['announcement'] ?? array() ) ); ?>
			<?php dzn_theme_portal_component( 'support', array() ); ?>
		<?php endif; ?>
	</div>
	<?php if ( $navigation ) : ?>
		<nav id="dzn-portal-nav-mobile" class="dzn-portal-tabs" aria-label="<?php esc_attr_e( 'پیمایش هنرجو', 'delnavazan-theme' ); ?>">
			<ul>
				<?php foreach ( array_slice( $navigation, 0, 4 ) as $item ) : ?>
					<?php
					$label = isset( $item['label'] ) ? (string) $item['label'] : '';
					$url = isset( $item['url'] ) ? (string) $item['url'] : '';
					$current = ! empty( $item['current'] );
					if ( ! $label || ! $url ) { continue; }
					?>
					<li>
						<a class="dzn-portal-tabs__link<?php echo $current ? ' is-current' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>>
							<span class="dzn-portal-tabs__label"><?php echo esc_html( $label ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>
</main>
