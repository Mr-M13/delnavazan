<?php
/**
 * Read-only operational diagnostics from the Platform's own diagnostics service.
 *
 * Every value is printed exactly as the Platform reports it. Where a value is
 * missing the section says so instead of showing a zero that would read as
 * "healthy".
 *
 * @package DelnavazanTheme
 */

$diagnostics = isset( $args['diagnostics'] ) && is_array( $args['diagnostics'] ) ? $args['diagnostics'] : array();
$state       = isset( $diagnostics['state'] ) ? (string) $diagnostics['state'] : 'unavailable';

// No diagnostics capability: the operator sees navigation only.
if ( 'restricted' === $state ) {
	return;
}

$summary = ( 'ok' === $state && isset( $diagnostics['summary'] ) && is_array( $diagnostics['summary'] ) ) ? $diagnostics['summary'] : null;

/**
 * Render a bounded list of code/count rows from a diagnostics table.
 *
 * @param array  $rows   Row set from the Platform summary.
 * @param string $column Column holding the code.
 * @param int    $limit  Maximum rows rendered.
 */
$dzn_ops_row_list = static function ( $rows, $column, $limit = 8 ) {
	$rows = is_array( $rows ) ? array_values( $rows ) : array();
	if ( ! $rows ) {
		echo '<p class="dzn-ops-empty">' . esc_html__( 'ردیفی ثبت نشده است.', 'delnavazan-theme' ) . '</p>';
		return;
	}

	$shown = array_slice( $rows, 0, $limit );
	?>
	<ul class="dzn-ops-rows">
		<?php foreach ( $shown as $row ) : ?>
			<li>
				<span class="dzn-ops-rows__code" dir="ltr"><?php echo esc_html( isset( $row[ $column ] ) ? (string) $row[ $column ] : '' ); ?></span>
				<span class="dzn-ops-rows__count"><?php echo esc_html( (string) ( $row['total'] ?? '' ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
	if ( count( $rows ) > $limit ) {
		echo '<p class="dzn-ops-empty">' . esc_html( sprintf( __( 'و %d ردیف دیگر.', 'delnavazan-theme' ), count( $rows ) - $limit ) ) . '</p>';
	}
};
?>
<section class="dzn-ops-diagnostics" aria-labelledby="dzn-ops-diagnostics-title">
	<h2 id="dzn-ops-diagnostics-title"><?php esc_html_e( 'شمارنده‌های عملیاتی', 'delnavazan-theme' ); ?></h2>

	<?php if ( null === $summary ) : ?>
		<?php
		dzn_theme_component(
			'state',
			array(
				'state'   => 'error',
				'title'   => 'شمارنده‌های عملیاتی در دسترس نیست',
				'message' => 'سرویس تشخیص پلتفرم پاسخ معتبری برنگرداند. تا زمانی که این خواندن برقرار نشود، عددی نمایش داده نمی‌شود.',
			)
		);
		?>
	<?php else : ?>
		<ul class="dzn-ops-metrics">
			<?php
			$metrics = array(
				array( 'label' => 'پیوندهای منقضی‌شدهٔ چرخش‌نشده', 'value' => (int) ( $summary['expired_not_rotated'] ?? 0 ) ),
				array( 'label' => 'واگذاری‌های در جریان', 'value' => (int) ( $summary['delegations_in_flight'] ?? 0 ) ),
				array( 'label' => 'واگذاری‌های رهاشده', 'value' => (int) ( $summary['delegations_abandoned'] ?? 0 ) ),
				array( 'label' => 'تأییدهای بدون نتیجهٔ ثبت‌شده', 'value' => (int) ( $summary['confirmed_unresolved'] ?? 0 ) ),
				array( 'label' => 'ردهای دسترسی ثبت‌شده', 'value' => (int) ( $summary['access_denials'] ?? 0 ) ),
				array( 'label' => 'پذیرش پیوندهای عمومی', 'value' => ! empty( $summary['public_actions_enabled'] ) ? 'فعال' : 'غیرفعال' ),
			);
			foreach ( $metrics as $metric ) :
				?>
				<li>
					<span class="dzn-ops-metrics__value"><?php echo esc_html( (string) $metric['value'] ); ?></span>
					<span class="dzn-ops-metrics__label"><?php echo esc_html( $metric['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="dzn-ops-diagnostics__grid">
			<article>
				<h3><?php esc_html_e( 'پیوندها بر پایهٔ کاربرد و وضعیت', 'delnavazan-theme' ); ?></h3>
				<?php $dzn_ops_row_list( $summary['capabilities_by_purpose_state'] ?? array(), 'purpose' ); ?>
			</article>
			<article>
				<h3><?php esc_html_e( 'استفاده‌ها بر پایهٔ نتیجه', 'delnavazan-theme' ); ?></h3>
				<?php $dzn_ops_row_list( $summary['redemptions_by_outcome'] ?? array(), 'purpose' ); ?>
			</article>
			<article>
				<h3><?php esc_html_e( 'درخواست‌های ردشدهٔ پیوند', 'delnavazan-theme' ); ?></h3>
				<?php $dzn_ops_row_list( $summary['refusals_by_reason'] ?? array(), 'reason_code' ); ?>
			</article>
			<article>
				<h3><?php esc_html_e( 'اقدام‌های ردشده', 'delnavazan-theme' ); ?></h3>
				<?php $dzn_ops_row_list( $summary['action_refusals_by_reason'] ?? array(), 'outcome_reason_code' ); ?>
			</article>
			<article>
				<h3><?php esc_html_e( 'دسترسی‌های ردشده', 'delnavazan-theme' ); ?></h3>
				<?php $dzn_ops_row_list( $summary['denials_by_surface_reason'] ?? array(), 'surface' ); ?>
			</article>
		</div>

		<?php $invariants = isset( $summary['structural_invariants'] ) && is_array( $summary['structural_invariants'] ) ? $summary['structural_invariants'] : array(); ?>
		<?php if ( $invariants ) : ?>
			<p class="dzn-ops-invariants" dir="auto">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: provider calls, 2: outbox writes, 3: theme writes. */
						__( 'قواعد ساختاری: فراخوانی ارائه‌دهنده %1$d · نوشتن صف %2$d · نوشتن پوسته %3$d', 'delnavazan-theme' ),
						(int) ( $invariants['provider_calls'] ?? 0 ),
						(int) ( $invariants['outbox_writes'] ?? 0 ),
						(int) ( $invariants['theme_writes'] ?? 0 )
					)
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
