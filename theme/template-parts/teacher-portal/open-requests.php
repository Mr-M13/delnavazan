<?php
/**
 * Unmatched requests for instruments the Teacher teaches.
 *
 * These requests were not offered through allocation, so every visible time is presented as an
 * availability exception. The claim action still revalidates Teacher/Student occupancy and
 * continuation holds on the server; this template creates no domain state itself.
 */
$items   = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context = dzn_theme_teacher_portal_context();
$demo    = ! empty( $context['demo'] );
if ( ! $items ) { return; }
?>
<section class="dzn-tp-section dzn-tp-open-requests" aria-labelledby="tp-open-requests">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">خارج از زمان‌های در دسترس شما</p><h2 id="tp-open-requests">درخواست‌های بدون مدرس برای سازهای شما</h2></div>
		<span class="dzn-tp-count-badge"><?php echo esc_html( sprintf( '%d مورد', count( $items ) ) ); ?></span>
	</div>
	<p class="dzn-tp-help">این درخواست‌ها به مدرس دیگری ارجاع نشده‌اند. با انتخاب یکی از زمان‌های درخواستی هنرجو و پذیرش، کلاس را به‌صورت استثنایی و خارج از زمان‌های در دسترس اعلام‌شدهٔ خود ثبت می‌کنید. تداخل با کلاس‌های شما همچنان بررسی می‌شود.</p>
	<div class="dzn-tp-attention-grid">
	<?php foreach ( $items as $item ) : ?>
		<?php
		$valid       = dzn_theme_teacher_portal_attention_valid( $item );
		$preferences = $valid && is_array( $item['requested_preferences'] ?? null ) ? $item['requested_preferences'] : array();
		$first_rendered = false;
		?>
		<article class="dzn-tp-attention-card is-<?php echo esc_attr( $valid ? (string) ( $item['state'] ?? 'intro_open_request' ) : 'unknown' ); ?>">
			<p class="dzn-tp-state"><span aria-hidden="true">◆</span> <?php echo esc_html( $valid ? (string) ( $item['due'] ?? 'خارج از زمان‌های در دسترس' ) : 'وضعیت ناشناخته' ); ?></p>
			<h3><?php echo esc_html( $valid ? (string) ( $item['title'] ?? '' ) : 'این مورد در دسترس نیست' ); ?></h3>
			<p class="dzn-tp-attention-context"><strong><?php echo esc_html( $valid ? (string) ( $item['context'] ?? '' ) : '' ); ?></strong></p>
			<p class="dzn-tp-attention-msg"><?php echo esc_html( $valid ? (string) ( $item['message'] ?? '' ) : 'برای امنیت، همهٔ اقدام‌ها پنهان شدند.' ); ?></p>
			<?php if ( $valid && ! $demo ) : ?>
				<form method="post" action="<?php echo esc_url( (string) ( $item['claim_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit>
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['claim_nonce'] ?? '' ) ); ?>">
					<input type="hidden" name="claim_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
					<input type="hidden" name="request_uid" value="<?php echo esc_attr( (string) ( $item['request_uid'] ?? '' ) ); ?>">
					<?php if ( $preferences ) : ?>
						<fieldset class="dzn-tp-preference-list"><legend>زمان درخواستی هنرجو را انتخاب کنید</legend>
						<?php foreach ( $preferences as $preference ) : ?>
							<?php $preference_id = (int) ( $preference['id'] ?? 0 ); if ( $preference_id < 1 ) { continue; } ?>
							<label class="dzn-tp-preference"><input type="radio" name="requested_time_id" value="<?php echo esc_attr( (string) $preference_id ); ?>"<?php echo $first_rendered ? '' : ' checked'; ?>><span class="dzn-tp-preference__label"><?php echo esc_html( (string) ( $preference['label'] ?? '' ) ); ?></span><span class="dzn-tp-preference__coverage is-<?php echo esc_attr( (string) ( $preference['coverage'] ?? 'outside' ) ); ?>"><?php echo esc_html( (string) ( $preference['coverage_label'] ?? '' ) ); ?></span></label>
							<?php $first_rendered = true; ?>
						<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
					<div class="dzn-tp-actions"><button class="dzn-button" type="submit" data-dzn-tp-busy-label="در حال ثبت پذیرش استثنایی…"><?php echo esc_html( (string) ( $item['primary'] ?? 'پذیرش این درخواست' ) ); ?></button></div>
					<p class="dzn-tp-action-status" role="status" aria-live="polite"></p>
				</form>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
	</div>
</section>
