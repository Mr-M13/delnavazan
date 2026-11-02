<?php
/**
 * Site footer.
 *
 * Public contact values come from the canonical Platform brand identity.
 *
 * @package DelnavazanTheme
 */

$footer_brand         = dzn_theme_brand_identity();
$footer_logo_uri      = get_theme_file_uri( 'assets/images/delnavazan-logo.png' );
$footer_instagram_uri = get_theme_file_uri( 'assets/images/Insta-gold.webp' );
$footer_whatsapp_uri  = get_theme_file_uri( 'assets/images/WhatsApp-gold.webp' );
$footer_email_uri     = get_theme_file_uri( 'assets/images/Mail-gold.webp' );
?>
<footer id="colophon" class="site-footer" role="contentinfo">
	<div class="dzn-container site-footer__top">
		<div class="site-footer__identity">
			<div class="site-footer__logo-frame">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'خانهٔ %s', 'delnavazan-theme' ), $footer_brand['name'] ) ); ?>">
					<img class="site-footer__logo-image" src="<?php echo esc_url( $footer_logo_uri ); ?>" alt="<?php echo esc_attr( $footer_brand['name'] ); ?>">
				</a>
			</div>
		</div>
		<div class="site-footer__actions" aria-label="<?php echo esc_attr( sprintf( __( 'راه‌های تماس با %s', 'delnavazan-theme' ), $footer_brand['name'] ) ); ?>">
			<a class="site-footer__action site-footer__action--whatsapp" href="<?php echo esc_url( $footer_brand['whatsapp_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'گفت‌وگو با %s در واتساپ', 'delnavazan-theme' ), $footer_brand['name'] ) ); ?>">
				<img class="site-footer__action-icon" src="<?php echo esc_url( $footer_whatsapp_uri ); ?>" alt="">
			</a>
			<a class="site-footer__action site-footer__action--email" href="<?php echo esc_url( $footer_brand['support_mailto'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'ارسال ایمیل به %s', 'delnavazan-theme' ), $footer_brand['name'] ) ); ?>">
				<img class="site-footer__action-icon" src="<?php echo esc_url( $footer_email_uri ); ?>" alt="">
			</a>
			<a class="site-footer__action site-footer__action--instagram" href="<?php echo esc_url( $footer_brand['instagram_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'اینستاگرام %s', 'delnavazan-theme' ), $footer_brand['name'] ) ); ?>">
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
		<?php dzn_theme_footer_policy_links(); ?>
		<div class="site-footer__legal">
			<p><?php echo esc_html( sprintf( __( '© %1$s %2$s. همهٔ حقوق محفوظ است.', 'delnavazan-theme' ), wp_date( 'Y' ), get_bloginfo( 'name' ) ) ); ?></p>
		</div>
	</div>
</footer>
<?php if ( is_front_page() ) : ?>
	<a class="dzn-floating-whatsapp" href="<?php echo esc_url( $footer_brand['whatsapp_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'گفت‌وگو با %s در واتساپ', 'delnavazan-theme' ), $footer_brand['name'] ) ); ?>">
		<img class="dzn-floating-whatsapp__icon" src="<?php echo esc_url( $footer_whatsapp_uri ); ?>" alt="">
		<span class="dzn-floating-whatsapp__label"><?php esc_html_e( 'پیام در واتساپ', 'delnavazan-theme' ); ?></span>
	</a>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
