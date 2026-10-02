<?php
/**
 * Site footer.
 *
 * @package DelnavazanTheme
 */

$footer_logo_uri      = get_theme_file_uri( 'assets/images/delnavazan-logo.png' );
$footer_instagram_uri = get_theme_file_uri( 'assets/images/Insta-gold.webp' );
$footer_whatsapp_uri  = get_theme_file_uri( 'assets/images/WhatsApp-gold.webp' );
$footer_email_uri     = get_theme_file_uri( 'assets/images/Mail-gold.webp' );
?>
<footer class="site-footer" role="contentinfo">
	<div class="dzn-container site-footer__top">
		<div class="site-footer__identity">
			<div class="site-footer__logo-frame">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'خانهٔ دلنوازان', 'delnavazan-theme' ); ?>">
					<img class="site-footer__logo-image" src="<?php echo esc_url( $footer_logo_uri ); ?>" alt="<?php esc_attr_e( 'دلنوازان', 'delnavazan-theme' ); ?>">
				</a>
			</div>
		</div>
		<div class="site-footer__actions" aria-label="<?php esc_attr_e( 'راه‌های تماس با دلنوازان', 'delnavazan-theme' ); ?>">
			<a class="site-footer__action site-footer__action--whatsapp" href="https://wa.me/61413413004" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'گفت‌وگو با دلنوازان در واتساپ', 'delnavazan-theme' ); ?>">
				<img class="site-footer__action-icon" src="<?php echo esc_url( $footer_whatsapp_uri ); ?>" alt="">
			</a>
			<a class="site-footer__action site-footer__action--email" href="mailto:delnavazan@mail.com" aria-label="<?php esc_attr_e( 'ارسال ایمیل به دلنوازان', 'delnavazan-theme' ); ?>">
				<img class="site-footer__action-icon" src="<?php echo esc_url( $footer_email_uri ); ?>" alt="">
			</a>
			<a class="site-footer__action site-footer__action--instagram" href="https://www.instagram.com/insta.delnavazan/" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'اینستاگرام دلنوازان', 'delnavazan-theme' ); ?>">
				<img class="site-footer__action-icon" src="<?php echo esc_url( $footer_instagram_uri ); ?>" alt="">
			</a>
		</div>
		
<nav class="site-footer__navigation" aria-label="<?php esc_attr_e( 'منوی پایین سایت', 'delnavazan-theme' ); ?>">
	<?php
	wp_nav_menu(
		array(
			'theme_location' => 'footer',
			'container'      => false,
			'menu_class'     => 'site-footer__menu',
			'fallback_cb'    => false,
		)
	);
	?>
</nav>
		<div class="site-footer__legal">
			<p><?php echo esc_html( sprintf( __( '© %1$s %2$s. همهٔ حقوق محفوظ است.', 'delnavazan-theme' ), wp_date( 'Y' ), get_bloginfo( 'name' ) ) ); ?></p>
		</div>
	</div>
</footer>
<?php if ( is_front_page() ) : ?>
	<a class="dzn-floating-whatsapp" href="https://wa.me/61413413004" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'گفت‌وگو با دلنوازان در واتساپ', 'delnavazan-theme' ); ?>">
		<img class="dzn-floating-whatsapp__icon" src="<?php echo esc_url( $footer_whatsapp_uri ); ?>" alt="">
		<span class="dzn-floating-whatsapp__label"><?php esc_html_e( 'پیام در واتساپ', 'delnavazan-theme' ); ?></span>
	</a>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
