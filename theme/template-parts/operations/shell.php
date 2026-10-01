<?php
/**
 * Operations portal shell.
 *
 * @package DelnavazanTheme
 */

$model  = isset( $args['model'] ) && is_array( $args['model'] ) ? $args['model'] : array();
$state  = isset( $model['state'] ) && in_array( (string) $model['state'], array( 'ok', 'signed_out', 'restricted' ), true )
	? (string) $model['state']
	: 'restricted';
$groups = ( 'ok' === $state && isset( $model['groups'] ) && is_array( $model['groups'] ) ) ? $model['groups'] : array();
$count  = 'ok' === $state ? (int) ( $model['count'] ?? 0 ) : 0;
?>
<main id="main-content" class="site-main dzn-ops" tabindex="-1">
	<header class="dzn-ops__masthead">
		<div class="dzn-container dzn-ops__masthead-inner">
			<div>
				<p class="dzn-eyebrow"><?php esc_html_e( 'دلنوازان', 'delnavazan-theme' ); ?></p>
				<h1 class="dzn-ops__title"><?php esc_html_e( 'عملیات آموزشگاه', 'delnavazan-theme' ); ?></h1>
				<p class="dzn-ops__lede"><?php esc_html_e( 'این نما فقط داده‌های معتبر پلتفرم را نمایش می‌دهد. هر اقدامی که وضعیت را تغییر می‌دهد، در صفحهٔ رسمی خودش در وردپرس انجام می‌شود.', 'delnavazan-theme' ); ?></p>
			</div>
			<p class="dzn-ops__account">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'صفحهٔ اصلی', 'delnavazan-theme' ); ?></a>
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>"><?php esc_html_e( 'داشبورد', 'delnavazan-theme' ); ?></a>
					<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'خروج از حساب', 'delnavazan-theme' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	</header>

	<div class="dzn-container dzn-ops__content">
		<?php if ( 'signed_out' === $state ) : ?>
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'empty',
					'title'   => 'ورود لازم است',
					'message' => 'برای دسترسی به عملیات آموزشگاه، ابتدا با حساب مجاز خود وارد شوید.',
				)
			);
			?>
			<p class="dzn-ops__actions">
				<a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( wp_login_url( home_url( '/admin-operations/' ) ) ); ?>"><?php esc_html_e( 'ورود به حساب', 'delnavazan-theme' ); ?></a>
			</p>
		<?php elseif ( 'restricted' === $state ) : ?>
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'empty',
					'title'   => 'دسترسی محدود است',
					'message' => 'این بخش فقط برای کاربران مجاز آموزشگاه در دسترس است. اگر به‌تازگی مجوزی دریافت کرده‌اید، دسترسی شما بر اساس مجوزهای فعلی پلتفرم نمایش داده می‌شود.',
				)
			);
			?>
			<p class="dzn-ops__actions">
				<a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>"><?php esc_html_e( 'بازگشت به داشبورد', 'delnavazan-theme' ); ?></a>
			</p>
		<?php else : ?>
			<p class="dzn-ops__summary" role="status">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of operations screens available to the current operator. */
						__( '%d صفحهٔ عملیاتی در دسترس این حساب است.', 'delnavazan-theme' ),
						$count
					)
				);
				?>
			</p>
			<?php dzn_theme_operations_component( 'navigation', array( 'groups' => $groups ) ); ?>
			<?php dzn_theme_operations_component( 'diagnostics', array( 'diagnostics' => $model['diagnostics'] ?? array() ) ); ?>
		<?php endif; ?>
	</div>
</main>
