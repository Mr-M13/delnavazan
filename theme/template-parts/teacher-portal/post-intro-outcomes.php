<?php
/**
 * Post-introductory outcome controls for the Teacher Portal.
 *
 * The section only appears while a Teacher still has an outcome to record (or for the one-off
 * confirmation notice). Recorded outcomes are history: they are collapsed, and they are never the
 * only thing on the screen after a Teacher has already confirmed the class.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$recorded = isset( $args['recorded'] ) && is_array( $args['recorded'] ) ? $args['recorded'] : array();
$status = sanitize_key( (string) ( $_GET['post_intro_outcome'] ?? '' ) );
$messages = array(
	'recorded' => 'نتیجهٔ کلاس آشنایی ثبت شد.',
	'window_expired' => 'مهلت ثبت نتیجهٔ این کلاس آشنایی (۷ روز پس از پایان) گذشته است؛ برای اصلاح با پشتیبانی تماس بگیرید.',
	'unavailable' => 'ثبت نتیجه در حال حاضر ممکن نیست. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
);
if ( ! $items && ! isset( $messages[ $status ] ) ) {
	return;
}
?>
<section class="dzn-tp-section dzn-tp-post-intro" aria-labelledby="dzn-tp-post-intro">
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
		<p class="dzn-portal-help">پس از پایان زمان کلاس، نتیجهٔ واقعی برگزاری را ثبت کنید. مهلت ثبت دستی ۷ روز پس از پایان کلاس است. فقط «برگزار شد» اجازهٔ ادامهٔ خودکار ثبت‌نام را می‌دهد.</p>
		<div class="dzn-tp-outcome-list">
		<?php foreach ( $items as $item ) : ?>
			<article class="dzn-tp-outcome-card">
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
		</div>
		<?php if ( $recorded ) : ?>
			<details class="dzn-tp-outcome-history">
				<summary>نتیجه‌های ثبت‌شدهٔ اخیر <span class="dzn-tp-class-history__count"><?php echo esc_html( dzn_theme_teacher_portal_digits( count( $recorded ) ) ); ?></span></summary>
				<p class="dzn-portal-help">این کلاس‌ها بسته شده‌اند و فرم تأیید دوباره ندارند. اگر نتیجه‌ای خودکار ثبت شده باشد، منبع آن در همان ردیف مشخص است.</p>
				<?php foreach ( $recorded as $entry ) : ?>
					<article class="dzn-tp-outcome-card is-recorded">
						<div>
							<strong><?php echo esc_html( (string) ( $entry['student'] ?? '' ) ); ?></strong>
							<p><?php echo esc_html( trim( (string) ( $entry['course'] ?? '' ) . ' · ' . (string) ( $entry['when'] ?? '' ) ) ); ?></p>
						</div>
						<div>
							<span class="dzn-status-badge <?php echo 'provider_evidence' === (string) ( $entry['source_key'] ?? '' ) ? 'is-ok' : 'is-info'; ?>"><?php echo esc_html( (string) ( $entry['outcome'] ?? 'ثبت شد' ) ); ?></span>
							<p class="dzn-portal-help"><?php echo esc_html( (string) ( $entry['source'] ?? '' ) ); ?><?php echo '' !== (string) ( $entry['recorded_at'] ?? '' ) ? ' · ' . esc_html( (string) $entry['recorded_at'] ) : ''; ?></p>
						</div>
					</article>
				<?php endforeach; ?>
			</details>
		<?php endif; ?>
	<?php endif; ?>
</section>
