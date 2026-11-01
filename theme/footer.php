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
			<a class="site-footer__action site-footer__action--phone" href="<?php echo esc_url( $footer_brand['phone_href'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'تماس تلفنی با %1$s: %2$s', 'delnavazan-theme' ), $footer_brand['name'], $footer_brand['phone_display'] ) ); ?>">
				<svg class="site-footer__action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="#D4AB4F" d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>
			</a>
			<a class="site-footer__action site-footer__action--whatsapp" href="<?php echo esc_url( $footer_brand['whatsapp_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'گفت‌وگو با %1$s در واتساپ: %2$s', 'delnavazan-theme' ), $footer_brand['name'], $footer_brand['phone_display'] ) ); ?>">
				<img class="site-footer__action-icon" src="<?php echo esc_url( $footer_whatsapp_uri ); ?>" alt="">
			</a>
			<a class="site-footer__action site-footer__action--email" href="<?php echo esc_url( $footer_brand['support_mailto'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'ارسال ایمیل به %1$s: %2$s', 'delnavazan-theme' ), $footer_brand['name'], $footer_brand['support_email'] ) ); ?>">
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
			<p class="site-footer__contact">
				<a href="<?php echo esc_url( $footer_brand['phone_href'] ); ?>" dir="ltr"><?php echo esc_html( $footer_brand['phone_display'] ); ?></a>
				<span aria-hidden="true">·</span>
				<a href="<?php echo esc_url( $footer_brand['support_mailto'] ); ?>" dir="ltr"><?php echo esc_html( $footer_brand['support_email'] ); ?></a>
			</p>
		</div>
	</div>
</footer>
<?php if ( is_front_page() ) : ?>
	<a class="dzn-floating-whatsapp" href="<?php echo esc_url( $footer_brand['whatsapp_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'گفت‌وگو با %1$s در واتساپ: %2$s', 'delnavazan-theme' ), $footer_brand['name'], $footer_brand['phone_display'] ) ); ?>">
		<img class="dzn-floating-whatsapp__icon" src="<?php echo esc_url( $footer_whatsapp_uri ); ?>" alt="">
		<span class="dzn-floating-whatsapp__label"><?php esc_html_e( 'پیام در واتساپ', 'delnavazan-theme' ); ?></span>
	</a>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
