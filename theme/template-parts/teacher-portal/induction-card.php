<?php
/**
 * Compact quick-start entry card for the Teacher dashboard.
 * The full one-page guide is never rendered here.
 *
 * @package DelnavazanTheme
 */
$model = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$hub = is_array( $model['induction'] ?? null ) ? $model['induction'] : array();
if ( ! $hub || empty( $hub['modules'] ) ) { return; }
$guide_url = (string) ( $model['induction_hub_url'] ?? $model['induction_continue_url'] ?? '' );
if ( '' === $guide_url ) { return; }
?>
<section class="dzn-tp-section dzn-tp-induction-entry" aria-labelledby="tp-induction-card-title">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">راهنمای سریع</p><h2 id="tp-induction-card-title">شروع تدریس در دلنوازان</h2></div>
	</div>
	<p class="dzn-tp-help">یک مرور کوتاه از جریان کار: کلاس امروز، درخواست تازه، ورود، حضور، غیبت، تغییر زمان، تنظیمات، مالی و ساعت طلایی.</p>
	<div class="dzn-tp-actions">
		<a class="dzn-button" href="<?php echo esc_url( $guide_url ); ?>">مرور راهنمای تدریس</a>
		<button class="dzn-button dzn-button--secondary" type="button" data-dzn-tp-induction-skip>فعلاً رد کن</button>
	</div>
</section>
