<?php
/**
 * Direct support links, never a messaging system.
 *
 * @package DelnavazanTheme
 */

$contact = isset( $args['contact'] ) && is_array( $args['contact'] ) ? $args['contact'] : array();
$links = array(
	array( 'key' => 'whatsapp_url', 'label' => 'واتساپ', 'note' => 'پیام مستقیم به پشتیبانی' ),
	array( 'key' => 'email_url', 'label' => 'ایمیل', 'note' => 'برای درخواست‌های غیرفوری' ),
	array( 'key' => 'instagram_url', 'label' => 'اینستاگرام', 'note' => 'خبرها و جمع دلنوازان' ),
);
?>
<section class="dzn-portal-section dzn-contact" aria-labelledby="dzn-contact-title">
	<div>
		<p class="dzn-portal-kicker"><?php esc_html_e( 'همراه شما هستیم', 'delnavazan-theme' ); ?></p>
		<h2 id="dzn-contact-title"><?php esc_html_e( 'تماس با دلنوازان', 'delnavazan-theme' ); ?></h2>
		<p><?php esc_html_e( 'این راه‌ها برای تماس مستقیم‌اند؛ پرتال پیام‌رسان دوطرفه ندارد.', 'delnavazan-theme' ); ?></p>
	</div>
	<ul class="dzn-contact__links">
		<?php foreach ( $links as $link ) : ?>
			<?php if ( empty( $contact[ $link['key'] ] ) ) { continue; } ?>
			<?php $opens_new_window = 'email_url' !== $link['key']; ?>
			<li><a href="<?php echo esc_url( $contact[ $link['key'] ] ); ?>"<?php if ( $opens_new_window ) : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><strong><?php echo esc_html( $link['label'] ); ?></strong><span><?php echo esc_html( $link['note'] ); ?></span></a></li>
		<?php endforeach; ?>
	</ul>
</section>
