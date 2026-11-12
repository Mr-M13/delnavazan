<?php
/**
 * The one reusable introductory-request card.
 *
 * It is rendered for a request assigned to this Teacher and for an unassigned opportunity.
 * Presentation only: the selected Student preference is posted to the existing canonical
 * acceptance authority. Colour classification is advisory and never grants scheduling authority.
 */
$item        = is_array( $args['item'] ?? null ) ? $args['item'] : array();
$opportunity = ! empty( $args['opportunity'] ) || 'intro_open_request' === ( $item['state'] ?? '' );
$index       = (int) ( $args['index'] ?? 0 );
$demo        = ! empty( $args['demo'] );
if ( ! dzn_theme_teacher_portal_attention_valid( $item ) ) { return; }

$preferences = is_array( $item['requested_preferences'] ?? null ) ? $item['requested_preferences'] : array();
$preferences = array_values( array_filter( $preferences, static fn( $preference ) => is_array( $preference ) && (int) ( $preference['id'] ?? 0 ) > 0 ) );
if ( ! $preferences ) { return; }

$expired        = ! $opportunity && ! empty( $item['response_expired'] );
$student        = trim( (string) ( $item['student_name'] ?? $item['student'] ?? '' ) );
$instrument     = trim( (string) ( $item['instrument'] ?? '' ) );
$country        = trim( (string) ( $item['country_label'] ?? $item['country'] ?? '' ) );
$request_date   = trim( (string) ( $item['request_date_label'] ?? '' ) );
$deadline       = trim( (string) ( $item['deadline_sentence'] ?? '' ) );
$deadline_iso   = trim( (string) ( $item['response_deadline_iso'] ?? '' ) );
$remaining      = ! empty( $item['response_expired'] ) ? 'مهلت پاسخ گذشته است' : (string) ( $item['response_remaining_label'] ?? '' );
if ( '' === $remaining && '' !== $deadline_iso ) { $remaining = dzn_theme_platform_teacher_response_remaining_label( (int) ( $item['response_remaining_seconds'] ?? 0 ) ); }
$action_url     = $opportunity ? (string) ( $item['claim_action_url'] ?? '' ) : (string) ( $item['response_action_url'] ?? '' );
$nonce          = $opportunity ? (string) ( $item['claim_nonce'] ?? '' ) : (string) ( $item['response_nonce'] ?? '' );
$student_line   = $student;
if ( '' !== $instrument ) { $student_line .= ( '' !== $student_line ? ' · ' : '' ) . $instrument; }
if ( '' !== $country ) { $student_line .= ( '' !== $student_line ? ' · ' : '' ) . $country; }
$card_id        = 'tp-intro-' . $index . '-' . substr( md5( (string) ( $item['ref'] ?? '' ) ), 0, 8 );
$reject_id      = $card_id . '-reject';
$reasons        = array(
	'times_unsuitable'    => 'زمان‌های پیشنهادی مناسب نیست',
	'no_capacity'        => 'در حال حاضر ظرفیت ندارم',
	'cannot_teach_student' => 'امکان تدریس به این هنرجو را ندارم',
	'other'              => 'دلیل دیگر',
);
$accept_fields  = $opportunity
	? '<input type="hidden" name="claim_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><input type="hidden" name="request_uid" value="' . esc_attr( (string) ( $item['request_uid'] ?? '' ) ) . '"><input type="hidden" name="decision" value="accept">'
	: '<input type="hidden" name="response_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><input type="hidden" name="delivery_uid" value="' . esc_attr( (string) ( $item['delivery_uid'] ?? '' ) ) . '"><input type="hidden" name="decision" value="accept">';
$reject_fields  = $opportunity
	? '<input type="hidden" name="claim_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><input type="hidden" name="request_uid" value="' . esc_attr( (string) ( $item['request_uid'] ?? '' ) ) . '"><input type="hidden" name="decision" value="decline">'
	: '<input type="hidden" name="response_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><input type="hidden" name="delivery_uid" value="' . esc_attr( (string) ( $item['delivery_uid'] ?? '' ) ) . '"><input type="hidden" name="decision" value="decline">';
?>
<article id="<?php echo esc_attr( $card_id ); ?>" class="dzn-tp-attention-card dzn-tp-intro-card <?php echo $opportunity ? 'is-intro_open_request is-opportunity' : 'is-intro_request'; ?><?php echo $expired ? ' is-expired' : ''; ?>" data-dzn-intro-card<?php echo $opportunity ? ' data-dzn-opportunity-card' : ''; ?>>
	<?php if ( ! $opportunity ) : ?><p class="dzn-tp-state"><span aria-hidden="true">◆</span> درخواست تازه</p><?php endif; ?>
	<?php if ( ! $opportunity ) : ?><h3>درخواست جدید کلاس آشنایی</h3><?php endif; ?>
	<p class="dzn-tp-intro-card__student"><?php echo esc_html( $student_line ); ?></p>

	<?php if ( $opportunity ) : ?>
		<?php if ( '' !== $request_date ) : ?><p class="dzn-tp-intro-card__deadline"><strong>تاریخ درخواست:</strong> <?php echo esc_html( $request_date ); ?></p><?php endif; ?>
	<?php else : ?>
		<?php if ( '' !== $deadline ) : ?><p class="dzn-tp-intro-card__deadline"><?php echo esc_html( $deadline ); ?></p><?php endif; ?>
		<?php if ( '' !== $deadline_iso ) : ?>
			<p class="dzn-tp-response-window<?php echo $expired ? ' is-expired' : ''; ?>" data-dzn-response-deadline="<?php echo esc_attr( $deadline_iso ); ?>" data-dzn-response-expired="<?php echo $expired ? '1' : '0'; ?>" role="timer" aria-live="polite">
				<strong data-dzn-response-countdown><?php echo esc_html( $remaining ); ?></strong>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! $action_url || ! $nonce ) : ?>
		<p class="dzn-tp-help">اطلاعات این درخواست کامل نیست؛ برای امنیت، اقدام‌ها غیرفعال شدند.</p>
	<?php elseif ( $expired ) : ?>
		<p class="dzn-tp-help dzn-tp-response-expired">مهلت پاسخ به پایان رسیده است؛ این درخواست دیگر از پرتال شما قابل پذیرش یا رد نیست و برای ارجاع به مدرس در دسترس بعدی در اختیار آموزشگاه است.</p>
	<?php else : ?>
		<form class="dzn-tp-intro-card__accept-form" method="post" action="<?php echo esc_url( $action_url ); ?>" data-dzn-tp-submit data-dzn-tp-intro-accept>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<?php echo $accept_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- composed from escaped values above. ?>
			<fieldset class="dzn-tp-preference-list">
				<legend><?php echo esc_html( $opportunity ? 'اولویت‌های هنرجو' : 'زمان‌های پیشنهادی هنرجو' ); ?></legend>
				<?php foreach ( $preferences as $preference ) : ?>
					<?php
					$preference_id = (int) ( $preference['id'] ?? 0 );
					if ( $preference_id < 1 ) { continue; }
					$available = ! empty( $preference['available'] );
					$label     = (string) ( $preference['label'] ?? '' );
					if ( '' === $label ) { $label = trim( (string) ( $preference['date_label'] ?? '' ) . ( '' !== (string) ( $preference['time_label'] ?? '' ) ? '، ' . (string) $preference['time_label'] : '' ) ); }
					?>
					<label class="dzn-tp-preference dzn-tp-preference--<?php echo esc_attr( $available ? 'available' : 'outside' ); ?>">
						<input type="radio" name="requested_time_id" value="<?php echo esc_attr( (string) $preference_id ); ?>" data-dzn-tp-preference-input required>
						<span class="dzn-tp-preference__label"><?php echo esc_html( $label ); ?></span>
						<span class="dzn-tp-preference__coverage is-<?php echo esc_attr( $available ? 'preferred' : 'outside' ); ?>"><span aria-hidden="true"><?php echo $available ? '✓' : '!'; ?></span> <?php echo esc_html( (string) ( $preference['coverage_label'] ?? ( $available ? 'هماهنگ با برنامهٔ شما' : 'خارج از برنامهٔ زمانی شما' ) ) ); ?></span>
					</label>
				<?php endforeach; ?>
				<p class="dzn-tp-preference-caption">زمان‌های سبز با برنامهٔ زمانی شما هماهنگ هستند. زمان‌های زرد خارج از ساعات اعلام‌شدهٔ شما هستند، اما اگر امکان برگزاری کلاس را دارید، می‌توانید آن‌ها را نیز انتخاب و تأیید کنید.</p>
			</fieldset>
			<div class="dzn-tp-actions dzn-tp-intro-card__actions">
				<button class="dzn-button dzn-tp-intro-card__accept" type="submit" data-dzn-tp-accept data-dzn-tp-busy-label="<?php echo esc_attr( $opportunity ? 'در حال ثبت پذیرش و ساخت کلاس…' : 'در حال ساخت رویداد و پیوند Meet… لطفاً صفحه را نبندید' ); ?>" disabled>تأیید درخواست</button>
				<button class="dzn-button dzn-button--secondary dzn-tp-intro-card__reject" type="button" data-dzn-tp-reject-toggle="<?php echo esc_attr( $reject_id ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $reject_id ); ?>">رد درخواست</button>
			</div>
			<p class="dzn-tp-help dzn-tp-intro-card__select-hint" data-dzn-tp-select-hint>برای تأیید، یکی از زمان‌های پیشنهادی را انتخاب کنید.</p>
			<p class="dzn-tp-action-status" role="status" aria-live="polite"></p>
		</form>

		<form id="<?php echo esc_attr( $reject_id ); ?>" class="dzn-tp-reject" method="post" action="<?php echo esc_url( $action_url ); ?>" data-dzn-tp-submit hidden>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<?php echo $reject_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- composed from escaped values above. ?>
			<fieldset class="dzn-tp-reject__reasons">
				<legend>دلیل رد درخواست</legend>
				<?php foreach ( $reasons as $value => $label ) : ?>
					<label class="dzn-tp-reject__reason"><input type="radio" name="reason_code" value="<?php echo esc_attr( $value ); ?>" required> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<label class="dzn-tp-reject__other" data-dzn-tp-other-reason hidden>توضیح دلیل
				<textarea name="reason_text" rows="2" maxlength="1000" data-dzn-tp-other-reason-input disabled></textarea>
			</label>
			<div class="dzn-tp-actions">
				<button class="dzn-button dzn-button--secondary" type="submit" data-dzn-tp-busy-label="در حال ثبت رد درخواست…">ثبت رد درخواست</button>
				<button class="dzn-button dzn-button--secondary" type="button" data-dzn-tp-reject-cancel="<?php echo esc_attr( $reject_id ); ?>">انصراف</button>
			</div>
			<p class="dzn-tp-action-status" role="status" aria-live="polite"></p>
		</form>
	<?php endif; ?>
</article>
