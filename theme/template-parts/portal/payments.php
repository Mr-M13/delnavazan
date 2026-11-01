<?php
/**
 * Billing/subscription presentation with explicit authority separation.
 *
 * When the owner-bound commercial read returns obligations, the existing
 * checkout script renders the interactive plan and posts to the existing REST
 * controller. The server-rendered list remains available without JavaScript.
 * The Theme never calculates a plan, fee or instalment.
 *
 * @package DelnavazanTheme
 */

$payments = isset( $args['payments'] ) && is_array( $args['payments'] ) ? $args['payments'] : array();
$items = isset( $payments['items'] ) && is_array( $payments['items'] ) ? $payments['items'] : array();
$checkout_enabled = ! empty( $payments['checkout_enabled'] );
$disabled = 'disabled' === (string) ( $payments['state'] ?? '' );
$unavailable = 'unavailable' === (string) ( $payments['state'] ?? ( $checkout_enabled ? 'ok' : 'unavailable' ) );
$history = isset( $args['history'] ) && is_array( $args['history'] ) ? $args['history'] : array();
$history_items = isset( $history['items'] ) && is_array( $history['items'] ) ? $history['items'] : array();
$history_available = 'ok' === (string) ( $history['state'] ?? '' );
$format_history_money = static function ( int $minor, string $currency ): string {
	if ( class_exists( '\Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney' ) ) {
		try { return \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( $minor, $currency, true ); } catch ( Throwable ) {}
	}
	return number_format( $minor ) . ( $currency !== '' ? ' ' . $currency : '' );
};
$history_labels = array(
	'paid' => 'پرداخت شده',
	'partially_refunded' => 'بازپرداخت جزئی',
	'refunded' => 'بازپرداخت شده',
	'outstanding' => 'در انتظار پرداخت',
	'overdue' => 'سررسید گذشته',
	'open' => 'بازپرداخت — در بررسی',
	'review_required' => 'بازپرداخت — نیازمند تصمیم',
	'resolved' => 'بازپرداخت — تکمیل شده',
	'dismissed' => 'بازپرداخت — رد شده',
	'accepted' => 'بازپرداخت ثبت شده',
);
$render_history = static function ( array $rows ) use ( $format_history_money, $history_labels ): void {
	if ( ! $rows ) {
		echo '<p class="dzn-portal-empty">' . esc_html__( 'سابقهٔ پرداختی برای نمایش ثبت نشده است.', 'delnavazan-theme' ) . '</p>';
		return;
	}
	echo '<div class="dzn-payment-list dzn-payment-history">';
	foreach ( $rows as $row ) {
		$kind = (string) ( $row['kind'] ?? '' );
		$status = (string) ( $row['status'] ?? '' );
		$amount = $format_history_money( (int) ( $row['amount_minor'] ?? 0 ), (string) ( $row['currency'] ?? '' ) );
		$title = 'refund' === $kind ? 'بازپرداخت' : ( 'outstanding' === $kind ? 'قسط در انتظار پرداخت' : 'پرداخت' );
		$meta = array();
		if ( '' !== (string) ( $row['course'] ?? '' ) ) { $meta[] = (string) $row['course']; }
		if ( (int) ( $row['instalment'] ?? 0 ) > 0 ) { $meta[] = 'قسط ' . (int) $row['instalment']; }
		if ( 'payment' === $kind ) { $meta[] = 'manual' === (string) ( $row['method'] ?? '' ) ? 'پرداخت دستی' : 'پرداخت آنلاین'; }
		echo '<div class="dzn-payment-row">';
		echo '<div><strong>' . esc_html( $title ) . '</strong><span>' . esc_html( implode( ' · ', $meta ) ) . '</span>';
		if ( '' !== (string) ( $row['date'] ?? '' ) ) { echo '<span>' . esc_html( (string) $row['date'] ) . '</span>'; }
		echo '</div>';
		echo '<span>' . esc_html( $amount ) . '</span>';
		if ( ! empty( $row['receipt_url'] ) ) { echo '<a href="' . esc_url( (string) $row['receipt_url'] ) . '">' . esc_html__( 'دریافت رسید', 'delnavazan-theme' ) . '</a>'; }
		echo '<span class="dzn-status dzn-status--muted">' . esc_html( $history_labels[ $status ] ?? $status ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
};
$render_items = static function ( array $rows ): void {
	if ( ! $rows ) {
		echo '<p class="dzn-portal-empty">' . esc_html__( 'سابقهٔ پرداخت تازه‌ای برای نمایش ثبت نشده است.', 'delnavazan-theme' ) . '</p>';
		return;
	}
	echo '<div class="dzn-payment-list">';
	foreach ( $rows as $item ) {
		echo '<div class="dzn-payment-row">';
		echo '<div><strong>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</strong><span>' . esc_html( (string) ( $item['date'] ?? '' ) ) . '</span></div>';
		echo '<span>' . esc_html( (string) ( $item['amount'] ?? '' ) ) . '</span>';
		if ( ! empty( $item['receipt_url'] ) ) {
			echo '<a href="' . esc_url( (string) $item['receipt_url'] ) . '">' . esc_html__( 'دریافت رسید', 'delnavazan-theme' ) . '</a>';
		}
		echo '<span class="dzn-status dzn-status--muted">' . esc_html( (string) ( $item['state'] ?? '' ) ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
};
?>
<section class="dzn-portal-section dzn-payments" aria-labelledby="dzn-payments-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'مالی', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-payments-title"><?php esc_html_e( 'پرداخت و اشتراک', 'delnavazan-theme' ); ?></h2>
		</div>
		<span class="dzn-status dzn-status--muted"><?php echo $disabled ? esc_html__( 'پرداخت آنلاین غیرفعال', 'delnavazan-theme' ) : ( $unavailable ? esc_html__( 'در دسترس نیست', 'delnavazan-theme' ) : esc_html__( 'وضعیت پرداخت ترم', 'delnavazan-theme' ) ); ?></span>
	</div>
	<?php
	/*
	 * Advance payment is only a presentation of an already-issued future-Term obligation.
	 * The existing secure checkout below remains the only payment surface; this block never
	 * creates an invoice, booking or obligation.
	 */
	$future_term_available = ! empty( $payments['future_term_available'] );
	$future_term_label = (string) ( $payments['future_term_label'] ?? '' );
	$future_term_action = (string) ( $payments['future_term_action_url'] ?? '#dzn-payments-title' );
	?>
	<div class="dzn-payments__future-term">
		<?php if ( $future_term_available ) : ?>
			<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $future_term_action ); ?>"><?php echo esc_html( 'پرداخت پیشاپیش ترم آینده' . ( '' !== $future_term_label ? ' · ' . $future_term_label : '' ) ); ?></a>
			<p id="dzn-future-term-note" class="dzn-portal-help"><?php esc_html_e( 'مبلغ و اقساط این پرداخت از تعهد معتبر ترم آینده خوانده می‌شود و در همان فهرست امن پرداخت زیر قابل پرداخت است.', 'delnavazan-theme' ); ?></p>
		<?php else : ?>
			<button class="dzn-button dzn-button--secondary" type="button" disabled aria-describedby="dzn-future-term-note"><?php esc_html_e( 'پرداخت پیشاپیش ترم آینده', 'delnavazan-theme' ); ?></button>
			<p id="dzn-future-term-note" class="dzn-portal-help"><?php esc_html_e( 'پرداخت پیشاپیش ترم آینده زمانی فعال می‌شود که تعهد ترم آینده از سوی آموزشگاه در سامانه صادر شود. تا آن زمان این پرداخت در دسترس نیست؛ برای هماهنگی با پشتیبانی دلنوازان تماس بگیرید.', 'delnavazan-theme' ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( $disabled || $unavailable ) : ?>
		<p class="dzn-portal-empty"><?php echo esc_html( $disabled ? 'پرداخت آنلاین برای این حساب فعال نیست و مدیریت مالی از طریق دلنوازان انجام می‌شود. برای هر پرداخت یا صورتحساب با پشتیبانی تماس بگیرید.' : (string) ( $payments['status'] ?? 'وضعیت پرداخت هنوز از منبع مالی معتبر خوانده نشده است.' ) ); ?></p>
		<section class="dzn-payments__history" aria-labelledby="dzn-payment-history-title">
			<h3 id="dzn-payment-history-title"><?php esc_html_e( 'سابقهٔ پرداخت', 'delnavazan-theme' ); ?></h3>
			<?php $render_history( $history_available ? $history_items : array() ); ?>
		</section>
	<?php else : ?>
	<div class="dzn-payments__summary">
		<div><p class="dzn-status-row__label"><?php esc_html_e( 'وضعیت پرداخت ترم', 'delnavazan-theme' ); ?></p><p><?php echo esc_html( (string) ( $payments['status'] ?? 'از منبع معتبر دریافت نشده' ) ); ?></p></div>
	</div>
	<?php if ( $checkout_enabled ) : ?>
		<div class="dzn-checkout" data-dzn-student-checkout dir="rtl">
			<p class="dzn-checkout__status" data-checkout-status aria-live="polite"><?php esc_html_e( 'در حال دریافت وضعیت پرداخت…', 'delnavazan-theme' ); ?></p>

			<?php
			// Initial server-rendered promotion state is derived only from the authoritative
			// commercial read. The browser never supplies an amount or a discount.
			$promo_rows = isset( $payments['obligations'] ) && is_array( $payments['obligations'] ) ? $payments['obligations'] : array();
			$promo = array( 'enabled' => true, 'applied' => false );
			foreach ( $promo_rows as $promo_row ) {
				if ( ! is_array( $promo_row ) || empty( $promo_row['offer_has_promotion'] ) ) { continue; }
				$promo = array(
					'enabled'            => true,
					'applied'            => true,
					'original_minor'     => (int) ( $promo_row['offer_base_amount_minor'] ?? 0 ),
					'discount_minor'     => (int) ( $promo_row['offer_promotion_amount_minor'] ?? 0 ),
					'final_minor'        => (int) ( $promo_row['offer_amount_due_minor'] ?? 0 ),
					'percentage'         => isset( $promo_row['offer_promotion_percentage'] ) && $promo_row['offer_promotion_percentage'] !== null ? (float) $promo_row['offer_promotion_percentage'] : null,
					'currency'           => (string) ( $promo_row['offer_currency'] ?? ( $promo_row['currency'] ?? '' ) ),
				);
				break;
			}
			$promoApplied = ! empty( $promo['applied'] );
			$promoDiscount = (int) ( $promo['discount_minor'] ?? 0 );
			$promoOriginal = (int) ( $promo['original_minor'] ?? 0 );
			$promoFinal = (int) ( $promo['final_minor'] ?? 0 );
			$promoPercentage = array_key_exists( 'percentage', $promo ) ? $promo['percentage'] : null;
			$promoCurrency = (string) ( $promo['currency'] ?? '' );
			$format_promo = static function ( int $minor, string $currency ): string {
				if ( $currency !== '' && class_exists( '\Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney' ) ) {
					return \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( $minor, $currency, true );
				}
				return number_format( $minor ) . ( $currency !== '' ? ' ' . $currency : '' );
			};
			?>

			<div class="dzn-checkout__promotion">
				<label class="dzn-checkout__promotion-label" for="dzn-promotion-code"><?php esc_html_e( 'کد تخفیف', 'delnavazan-theme' ); ?></label>
				<div class="dzn-checkout__promotion-inputs">
					<input type="text" id="dzn-promotion-code" class="dzn-checkout__promotion-code" maxlength="64" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'کد تخفیف را وارد کنید', 'delnavazan-theme' ); ?>"<?php echo $promoApplied ? ' disabled' : ''; ?> />
					<button type="button" id="dzn-promotion-apply" class="dzn-button dzn-button--secondary dzn-checkout__promotion-apply"<?php echo $promoApplied ? ' disabled' : ''; ?>>
						<?php echo $promoApplied ? esc_html__( 'اعمال شده', 'delnavazan-theme' ) : esc_html__( 'اعمال', 'delnavazan-theme' ); ?>
					</button>
				</div>
				<p class="dzn-checkout__promotion-status" data-promotion-status aria-live="polite" aria-atomic="true">
					<?php if ( $promoApplied && $promoDiscount > 0 ) : ?>
						<?php
						$promo_label = null !== $promoPercentage
							? sprintf( esc_html__( 'تخفیف %s٪ اعمال شده است.', 'delnavazan-theme' ), esc_html( number_format( $promoPercentage, 0 ) ) )
							: sprintf( esc_html__( 'تخفیف %s اعمال شده است.', 'delnavazan-theme' ), esc_html( $format_promo( $promoDiscount, $promoCurrency ) ) );
						echo $promo_label . ' ' . esc_html( sprintf( __( 'مبلغ اصلی: %1$s · مبلغ قابل پرداخت: %2$s', 'delnavazan-theme' ), $format_promo( $promoOriginal, $promoCurrency ), $format_promo( $promoFinal, $promoCurrency ) ) );
						?>
					<?php else : ?>
						<?php esc_html_e( 'کد تخفیفی اعمال نشده است.', 'delnavazan-theme' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="dzn-checkout__list" data-checkout-list></div>
		</div>
		<noscript><?php $render_items( $items ); ?></noscript>
	<?php else : ?>
		<?php $render_items( $items ); ?>
	<?php endif; ?>
	<section class="dzn-payments__history" aria-labelledby="dzn-payment-history-title">
		<h3 id="dzn-payment-history-title"><?php esc_html_e( 'سابقهٔ پرداخت', 'delnavazan-theme' ); ?></h3>
		<?php $render_history( $history_available ? $history_items : array() ); ?>
	</section>
	<div class="dzn-payments__boundary">
		<p><strong><?php esc_html_e( 'لغو اشتراک، لغو ترم و لغو یک کلاس سه کار جدا هستند.', 'delnavazan-theme' ); ?></strong></p>
		<p><?php esc_html_e( 'هیچ‌کدام در این پوسته اجرا نمی‌شود و دکمهٔ لغو فقط پس از اتصال به اختیار معتبر نمایش داده خواهد شد.', 'delnavazan-theme' ); ?></p>
	</div>
	<?php endif; ?>
</section>
