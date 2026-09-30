<?php
/**
 * Presentation-only feedback block.
 *
 * @package DelnavazanTheme
 */
?>
<section id="feedback" class="dzn-portal-section dzn-feedback" aria-labelledby="dzn-feedback-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'صدای شما', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-feedback-title"><?php esc_html_e( 'بازخورد به دلنوازان', 'delnavazan-theme' ); ?></h2>
		</div>
	</div>
	<p><?php esc_html_e( 'تجربهٔ کلاس یا پیشنهاد عمومی خود را در نسخهٔ آینده برای ما می‌فرستید.', 'delnavazan-theme' ); ?></p>
	<div class="dzn-feedback__fields" role="group" aria-labelledby="dzn-feedback-title">
		<div class="dzn-portal-control">
			<label for="dzn-feedback-category"><?php esc_html_e( 'موضوع', 'delnavazan-theme' ); ?></label>
			<select id="dzn-feedback-category">
				<option><?php esc_html_e( 'تجربهٔ کلاس', 'delnavazan-theme' ); ?></option>
				<option><?php esc_html_e( 'پیشنهاد برای دلنوازان', 'delnavazan-theme' ); ?></option>
			</select>
		</div>
		<fieldset class="dzn-rating">
			<legend><?php esc_html_e( 'امتیاز اختیاری', 'delnavazan-theme' ); ?></legend>
			<div class="dzn-rating__choices">
				<?php for ( $rating = 1; $rating <= 5; $rating++ ) : ?>
					<label><input type="radio" name="dzn-demo-rating" value="<?php echo esc_attr( $rating ); ?>"> <span><?php echo esc_html( $rating ); ?></span></label>
				<?php endfor; ?>
			</div>
		</fieldset>
		<div class="dzn-portal-control dzn-feedback__message">
			<label for="dzn-feedback-message"><?php esc_html_e( 'پیام', 'delnavazan-theme' ); ?></label>
			<textarea id="dzn-feedback-message" rows="4"></textarea>
		</div>
		<div>
			<button class="dzn-button" type="button" data-dzn-presentation-action aria-describedby="dzn-feedback-status"><?php esc_html_e( 'ارسال در آینده', 'delnavazan-theme' ); ?></button>
			<p id="dzn-feedback-status" class="dzn-portal-action-status" aria-live="polite"></p>
		</div>
	</div>
</section>
