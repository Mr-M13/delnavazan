<?php
/**
 * Post-introductory outcome controls for the Teacher Portal.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$status = sanitize_key( (string) ( $_GET['post_intro_outcome'] ?? '' ) );
$messages = array(
	'recorded' => 'نتیجهٔ کلاس آشنایی ثبت شد.',
	'unavailable' => 'ثبت نتیجه در حال حاضر ممکن نیست. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
);
if ( ! $items && ! isset( $messages[ $status ] ) ) {
	return;
}
?>
<section class="dzn-tp-section" aria-labelledby="dzn-tp-post-intro">
	<div class="dzn-tp-heading">
		<div>
			<p class="dzn-tp-kicker">پس از کلاس آشنایی</p>
			<h2 id="dzn-tp-post-intro">نتیجهٔ کلاس آشنایی</h2>
		</div>
	</div>
	<?php if ( isset( $messages[ $status ] ) ) : ?>
		<p class="dzn-portal-action-status" aria-live="polite"><?php echo esc_html( $messages[ $status ] ); ?></p>
	<?php endif; ?>
	<?php if ( $items ) : ?>
		<p class="dzn-portal-help">پس از پایان زمان کلاس، نتیجهٔ واقعی برگزاری را ثبت کنید. فقط «برگزار شد» اجازهٔ ادامهٔ خودکار ثبت‌نام را می‌دهد.</p>
		<?php foreach ( $items as $item ) : ?>
			<article class="dzn-tp-class">
				<div>
					<strong><?php echo esc_html( (string) ( $item['student'] ?? '' ) ); ?></strong>
					<p><?php echo esc_html( trim( (string) ( $item['course'] ?? '' ) . ' · ' . (string) ( $item['when'] ?? '' ) ) ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( (string) ( $item['action_url'] ?? '' ) ); ?>">
					<input type="hidden" name="action" value="dzn_post_intro_outcome">
					<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) ( $item['confirmation_uid'] ?? '' ) ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['nonce'] ?? '' ) ); ?>">
					<select name="outcome" aria-label="نتیجهٔ کلاس">
						<option value="delivered">برگزار شد</option>
						<option value="student_no_show">هنرجو حاضر نشد</option>
						<option value="student_cancelled">هنرجو لغو کرد</option>
						<option value="teacher_cancelled">مدرس لغو کرد</option>
					</select>
					<button class="dzn-button" type="submit">ثبت نتیجه</button>
				</form>
			</article>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
