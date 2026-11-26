<?php
/**
 * The operator's follow-up to-do list inside the Teacher portal.
 *
 * Presentation only: the three lists come from OwnerFollowupReadService, which reads the canonical
 * booking, onboarding and compensation ledgers. Nothing here links to wp-admin; the only action is
 * the guarded statement payment, which returns to this screen.
 *
 * @package DelnavazanTheme
 */
$items = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$intros = is_array( $items['intros'] ?? null ) ? $items['intros'] : array();
$onboarding = is_array( $items['onboarding'] ?? null ) ? $items['onboarding'] : array();
$payments = is_array( $items['payments'] ?? null ) ? $items['payments'] : array();
$failed = is_array( $items['failed'] ?? null ) ? $items['failed'] : array();
$phone_digits = static function ( string $phone ): string {
	$digits = preg_replace( '/[^0-9+]/', '', $phone );
	return is_string( $digits ) ? $digits : '';
};
$status = sanitize_key( (string) ( $_GET['followup'] ?? '' ) );
$messages = array(
	'paid' => array( 'پرداخت ثبت شد و در پروندهٔ مالی مدرس نمایش داده می‌شود.', 'success' ),
	'already_paid' => array( 'این صورت‌حساب پیش‌تر پرداخت شده است.', 'info' ),
	'missing' => array( 'این صورت‌حساب پیدا نشد؛ صفحه را تازه کنید.', 'error' ),
	'unavailable' => array( 'درخواست کامل نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'error' ),
	'failed' => array( 'پرداخت ثبت نشد. صفحه را تازه کنید و دوباره تلاش کنید.', 'error' ),
);
$stages = array(
	'new' => array( 'بدون پیشنهاد مدرس', 'is-waiting' ),
	'identity' => array( 'نیاز به بررسی هویت', 'is-alert' ),
	'teacher_match' => array( 'در انتظار پاسخ مدرس', 'is-pending' ),
	'teacher_agreed' => array( 'مدرس موافقت کرده', 'is-ok' ),
);
$totals = array();
foreach ( $payments as $row ) {
	$currency = (string) ( $row['currency'] ?? '' );
	$totals[ $currency ] = (int) ( $totals[ $currency ] ?? 0 ) + (int) ( $row['amount_minor'] ?? 0 );
}
$money = static function ( int $minor, string $currency ): string {
	return class_exists( '\\Delnavazan\\Platform\\Core\\Application\\Finance\\Pricing\\PricingMoney' )
		? \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( $minor, $currency, true )
		: dzn_theme_teacher_portal_digits( number_format( $minor / 100, 2 ) );
};
$count = static fn ( array $rows ): string => dzn_theme_teacher_portal_digits( count( $rows ) );
?>
<section class="dzn-tp-section dzn-tp-owner-followups" aria-labelledby="tp-owner-followups">
	<div class="dzn-tp-heading">
		<div>
			<p class="dzn-tp-kicker">کارهای امروز</p>
			<h2 id="tp-owner-followups">پیگیری‌های نیما</h2>
		</div>
	</div>
	<p class="dzn-tp-help dzn-tp-owner-followups__lede">سه فهرست کوتاه برای کارهای امروز. هر مورد با انجام شدن در بخش مدیریت، خودبه‌خود از این فهرست حذف می‌شود.</p>
	<?php if ( isset( $messages[ $status ] ) ) : ?>
		<p class="dzn-tp-action-status is-<?php echo esc_attr( $messages[ $status ][1] ); ?>" role="<?php echo 'error' === $messages[ $status ][1] ? 'alert' : 'status'; ?>"><?php echo esc_html( $messages[ $status ][0] ); ?></p>
	<?php endif; ?>

	<section class="dzn-tp-followup-group" aria-labelledby="tp-followup-intros">
		<div class="dzn-tp-class-section__heading">
			<h3 id="tp-followup-intros" class="dzn-tp-class-section__title">کلاس‌های آشنایی بدون مدرس</h3>
			<span class="dzn-tp-class-section__count"><?php echo esc_html( $count( $intros ) ); ?></span>
		</div>
		<p class="dzn-tp-help">درخواست‌هایی که هنوز مدرس نگرفته‌اند.</p>
		<?php if ( ! empty( $failed['intros'] ) ) : ?>
			<p class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status">این فهرست در این لحظه در دسترس نیست؛ صفحه را دوباره باز کنید.</p>
		<?php elseif ( ! $intros ) : ?>
			<p class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status">همهٔ درخواست‌های آشنایی مدرس دارند.</p>
		<?php else : ?>
			<ul class="dzn-tp-followup-list">
				<?php foreach ( $intros as $row ) : ?>
					<?php $stage = $stages[ (string) ( $row['stage_key'] ?? '' ) ] ?? array( 'در جریان', 'is-pending' ); ?>
					<li class="dzn-tp-followup-item">
						<div class="dzn-tp-followup-item__head">
							<p class="dzn-tp-followup-item__title"><?php echo esc_html( trim( (string) ( $row['learner'] ?? '' ) . ' · ' . (string) ( $row['instrument'] ?? '' ), ' ·' ) ); ?></p>
							<span class="dzn-tp-followup-badge <?php echo esc_attr( $stage[1] ); ?>"><?php echo esc_html( $stage[0] ); ?></span>
						</div>
						<p class="dzn-tp-followup-item__meta"><?php echo esc_html( (string) ( $row['when'] ?? '' ) ); ?></p>
						<p class="dzn-tp-followup-item__meta"><?php echo esc_html( (string) ( $row['reference'] ?? '' ) ); ?><?php echo '' !== (string) ( $row['age'] ?? '' ) ? ' · ' . esc_html( (string) $row['age'] ) : ''; ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="dzn-tp-followup-group" aria-labelledby="tp-followup-onboarding">
		<div class="dzn-tp-class-section__heading">
			<h3 id="tp-followup-onboarding" class="dzn-tp-class-section__title">مدرس‌هایی که پرونده‌شان کامل نشده</h3>
			<span class="dzn-tp-class-section__count"><?php echo esc_html( $count( $onboarding ) ); ?></span>
		</div>
		<p class="dzn-tp-help">تا وقتی این موارد کامل نشوند، مدرس نمی‌تواند کلاس بگیرد.</p>
		<?php if ( ! empty( $failed['onboarding'] ) ) : ?>
			<p class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status">وضعیت پروندهٔ مدرس‌ها در این لحظه در دسترس نیست؛ صفحه را دوباره باز کنید.</p>
		<?php elseif ( ! $onboarding ) : ?>
			<p class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status">همهٔ مدرس‌ها پروندهٔ کامل دارند.</p>
		<?php else : ?>
			<ul class="dzn-tp-followup-list">
				<?php foreach ( $onboarding as $row ) : ?>
					<?php $digits = $phone_digits( (string) ( $row['phone'] ?? '' ) ); ?>
					<li class="dzn-tp-followup-item">
						<div class="dzn-tp-followup-item__head">
							<p class="dzn-tp-followup-item__title"><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></p>
							<span class="dzn-tp-followup-badge is-pending"><?php echo esc_html( (string) ( $row['state'] ?? '' ) ); ?></span>
						</div>
						<ul class="dzn-tp-followup-outstanding">
							<?php foreach ( (array) ( $row['outstanding'] ?? array() ) as $item ) : ?><li><?php echo esc_html( (string) $item ); ?></li><?php endforeach; ?>
						</ul>
						<?php if ( '' !== $digits ) : ?>
							<div class="dzn-tp-followup-actions">
								<span class="dzn-tp-followup-phone" dir="ltr"><?php echo esc_html( (string) $row['phone'] ); ?></span>
								<a class="dzn-tp-followup-action" href="<?php echo esc_url( 'tel:' . $digits ); ?>" aria-label="تماس با <?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>"><span aria-hidden="true">✆</span> تماس</a>
								<a class="dzn-tp-followup-action" href="<?php echo esc_url( 'sms:' . $digits ); ?>" aria-label="پیامک به <?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>"><span aria-hidden="true">✉</span> پیامک</a>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="dzn-tp-followup-group" aria-labelledby="tp-followup-payments">
		<div class="dzn-tp-class-section__heading">
			<h3 id="tp-followup-payments" class="dzn-tp-class-section__title">پرداخت به مدرس‌ها</h3>
			<span class="dzn-tp-class-section__count"><?php echo esc_html( $count( $payments ) ); ?></span>
		</div>
		<p class="dzn-tp-help">صورت‌حساب‌های صادرشده‌ای که هنوز پرداخت نشده‌اند. ثبت پرداخت در پرتال مالی مدرس و در بخش مالی نمایش داده می‌شود.</p>
		<?php if ( ! empty( $failed['payments'] ) ) : ?>
			<p class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status">فهرست پرداخت‌ها در این لحظه در دسترس نیست؛ صفحه را دوباره باز کنید.</p>
		<?php elseif ( ! $payments ) : ?>
			<p class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status">پرداخت سررسیدشده‌ای باقی نمانده است.</p>
		<?php else : ?>
			<ul class="dzn-tp-followup-list">
				<?php foreach ( $payments as $row ) : ?>
					<?php $digits = $phone_digits( (string) ( $row['phone'] ?? '' ) ); ?>
					<li class="dzn-tp-followup-item">
						<div class="dzn-tp-followup-item__head">
							<p class="dzn-tp-followup-item__title"><?php echo esc_html( (string) ( $row['teacher'] ?? '' ) ); ?></p>
							<span class="dzn-tp-followup-amount"><?php echo esc_html( $money( (int) ( $row['amount_minor'] ?? 0 ), (string) ( $row['currency'] ?? '' ) ) ); ?></span>
						</div>
						<p class="dzn-tp-followup-item__meta"><?php echo esc_html( (string) ( $row['period'] ?? '' ) ); ?><?php echo '' !== (string) ( $row['teacher_reference'] ?? '' ) ? ' · ' . esc_html( (string) $row['teacher_reference'] ) : ''; ?></p>
						<?php if ( '' !== $digits ) : ?>
							<div class="dzn-tp-followup-actions">
								<span class="dzn-tp-followup-phone" dir="ltr"><?php echo esc_html( (string) $row['phone'] ); ?></span>
								<a class="dzn-tp-followup-action" href="<?php echo esc_url( 'tel:' . $digits ); ?>" aria-label="تماس با <?php echo esc_attr( (string) ( $row['teacher'] ?? '' ) ); ?>"><span aria-hidden="true">✆</span> تماس</a>
								<a class="dzn-tp-followup-action" href="<?php echo esc_url( 'sms:' . $digits ); ?>" aria-label="پیامک به <?php echo esc_attr( (string) ( $row['teacher'] ?? '' ) ); ?>"><span aria-hidden="true">✉</span> پیامک</a>
							</div>
						<?php endif; ?>
						<details class="dzn-tp-followup-pay">
							<summary class="dzn-button dzn-button--secondary">پرداخت شد</summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzn-tp-followup-pay__form" data-dzn-tp-submit>
								<input type="hidden" name="action" value="dzn_owner_followup_payment">
								<input type="hidden" name="statement_id" value="<?php echo esc_attr( (string) (int) ( $row['statement_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="command_key" value="<?php echo esc_attr( 'owner-followup-' . (int) ( $row['statement_id'] ?? 0 ) . '-' . wp_generate_uuid4() ); ?>">
								<?php wp_nonce_field( 'dzn_owner_followup_payment' ); ?>
								<label>کد پیگیری یا یادداشت (اختیاری)
									<input type="text" name="payment_reference" maxlength="120" autocomplete="off" placeholder="مثلاً حوالهٔ بانکی">
								</label>
								<button type="submit" class="dzn-button" data-dzn-tp-busy-label="در حال ثبت…">ثبت پرداخت</button>
							</form>
						</details>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $totals ) : ?>
				<p class="dzn-tp-followup-total">جمع باقی‌مانده:
					<?php $parts = array(); foreach ( $totals as $currency => $minor ) { $parts[] = $money( (int) $minor, (string) $currency ); } ?>
					<?php echo esc_html( implode( ' + ', $parts ) ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</section>
