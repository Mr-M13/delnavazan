<?php
/**
 * One-page Teacher quick-start.
 *
 * Nine short steps. Each step records that the Teacher has seen it and jumps to the real portal
 * section with a highlight banner. There is no long tutorial, quiz or secondary module reader.
 *
 * @package DelnavazanTheme
 */
$model = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$hub = is_array( $model['induction'] ?? null ) ? $model['induction'] : array();
$actions = is_array( $model['induction_actions'] ?? null ) ? $model['induction_actions'] : array();
$hub_url = (string) ( $model['induction_hub_url'] ?? '' );
$support_url = (string) ( $model['induction_support_url'] ?? '' );
$notices = array(
	'این بخش را قبلاً دیده‌اید. همان بخش در پرتال مشخص شده است.' => 'success',
	'بخش بعدی راهنما در پرتال مشخص شد.' => 'success',
	'این بخش در راهنمای سریع وجود ندارد.' => 'error',
	'ثبت پیشرفت انجام نشد. اگر حساب شما هنوز آماده نیست، ابتدا راه‌اندازی حساب را کامل کنید.' => 'error',
);
$notice = isset( $_GET['dzn_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) : '';
$steps = is_array( $hub['modules'] ?? null ) ? $hub['modules'] : array();
$completed = (int) ( $hub['completed_count'] ?? 0 );
$total = (int) ( $hub['total_count'] ?? 0 );
$percent = (int) ( $hub['percent'] ?? 0 );
$all_done = $total > 0 && $completed >= $total;
?>
<section class="dzn-tp-section dzn-tp-quickstart" aria-labelledby="tp-quickstart-title">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">راهنمای سریع تدریس</p><h2 id="tp-quickstart-title">۹ نکته برای شروع تدریس</h2></div>
		<span class="dzn-tp-induction__count"><?php echo esc_html( sprintf( '%s از %s دیده شده', number_format_i18n( $completed ), number_format_i18n( $total ) ) ); ?></span>
	</div>
	<?php if ( isset( $notices[ $notice ] ) ) : ?><p class="dzn-tp-notice is-<?php echo esc_attr( $notices[ $notice ] ); ?>" role="<?php echo 'error' === $notices[ $notice ] ? 'alert' : 'status'; ?>"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
	<div class="dzn-tp-induction__progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $total ); ?>" aria-valuenow="<?php echo esc_attr( (string) $completed ); ?>" aria-label="پیشرفت راهنمای سریع"><span style="inline-size:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
	<p class="dzn-tp-help">هر مورد را بخوانید و روی «نمایش در پرتال» بزنید تا همان بخش در پرتال برای شما مشخص شود. لازم نیست همه را یک‌جا انجام دهید.</p>
	<?php if ( $all_done ) : ?><p class="dzn-tp-notice is-success">همهٔ نکته‌های راهنما را دیده‌اید. این صفحه برای مرور همیشه در دسترس است.</p><?php endif; ?>
	<ol class="dzn-tp-quickstart-list">
		<?php foreach ( $steps as $step ) : ?>
			<?php
			$step_id = (string) ( $step['id'] ?? '' );
			$state = (string) ( $step['state'] ?? 'not_started' );
			$done = 'completed' === $state;
			$reack = ! empty( $step['needs_reacknowledgement'] );
			?>
			<li id="tp-step-<?php echo esc_attr( $step_id ); ?>" class="dzn-tp-quickstart-card is-<?php echo esc_attr( $state ); ?>">
				<div class="dzn-tp-quickstart-card__number" aria-hidden="true"><?php echo esc_html( number_format_i18n( (int) ( $step['order'] ?? 0 ) ) ); ?></div>
				<div class="dzn-tp-quickstart-card__body">
					<div class="dzn-tp-quickstart-card__title-row">
						<h3><?php echo esc_html( (string) ( $step['title'] ?? '' ) ); ?></h3>
						<span class="dzn-tp-state dzn-tp-state--<?php echo esc_attr( $done ? 'ended' : 'upcoming' ); ?>"><?php echo esc_html( $done ? ( $reack ? 'نیازمند مرور تازه' : 'دیده شده' ) : 'دیده نشده' ); ?></span>
					</div>
					<p><?php echo esc_html( (string) ( $step['description'] ?? '' ) ); ?></p>
					<?php if ( ! empty( $step['points'] ) ) : ?><ul><?php foreach ( (array) $step['points'] as $point ) : ?><li><?php echo esc_html( (string) $point ); ?></li><?php endforeach; ?></ul><?php endif; ?>
				</div>
				<div class="dzn-tp-quickstart-card__action">
					<form method="post" action="<?php echo esc_url( (string) ( $actions['complete_url'] ?? '' ) ); ?>" data-dzn-tp-submit>
						<input type="hidden" name="module_id" value="<?php echo esc_attr( $step_id ); ?>">
						<?php dzn_theme_nonce_field( 'dzn_teacher_induction_complete' ); ?>
						<button class="dzn-button<?php echo $done ? ' dzn-button--secondary' : ''; ?>" type="submit" data-dzn-tp-busy-label="در حال باز کردن…"><?php echo esc_html( $done ? 'مرور در پرتال' : (string) ( $step['action_label'] ?? 'نمایش در پرتال' ) ); ?></button>
					</form>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
	<nav class="dzn-tp-quickstart-footer" aria-label="پایان راهنمای سریع">
		<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( home_url( '/teacher-portal/' ) ); ?>">بازگشت به امروز</a>
		<?php if ( '' !== $support_url ) : ?><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer">نیاز به کمک دارم</a><?php endif; ?>
	</nav>
</section>
