<?php
/**
 * One-page Teacher quick-start.
 *
 * A read-only review of the daily Delnavazan workflow. It deliberately has no step
 * buttons, jump targets or progress actions: the guide explains the work, and the
 * real portal screens remain the place where each action is taken.
 *
 * @package DelnavazanTheme
 */
$model = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$hub = is_array( $model['induction'] ?? null ) ? $model['induction'] : array();
$support_url = (string) ( $model['induction_support_url'] ?? '' );
$steps = is_array( $hub['modules'] ?? null ) ? $hub['modules'] : array();
$by_id = array();
foreach ( $steps as $step ) {
	if ( is_array( $step ) && isset( $step['id'] ) ) {
		$by_id[ (string) $step['id'] ] = $step;
	}
}
$groups = array(
	'prep' => array(
		'title' => 'از شروع همکاری تا برنامهٔ کلاس',
		'description' => 'ابتدا حساب و زمان‌های تدریس را آماده کنید؛ سپس درخواست آشنایی و برنامهٔ روزانه را پیگیری کنید.',
		'ids' => array( 'settings', 'new-enrolment', 'today' ),
	),
	'class' => array(
		'title' => 'برگزاری کلاس',
		'description' => 'با هنرجو وارد جلسه شوید و نتیجهٔ واقعی آن را ثبت کنید.',
		'ids' => array( 'join', 'attendance' ),
	),
	'changes' => array(
		'title' => 'تغییرها و موارد خاص',
		'description' => 'تغییر زمان با پیشنهاد مدرس و پذیرش هنرجو انجام می‌شود.',
		'ids' => array( 'absence', 'reschedule' ),
	),
	'followup' => array(
		'title' => 'پیگیری ساعت‌ها و مالی',
		'description' => 'کار ثبت‌شده و صورت‌حساب را بررسی کنید و زمان‌های پرتقاضا را بشناسید.',
		'ids' => array( 'finance', 'golden-hour' ),
	),
);
?>
<section class="dzn-tp-section dzn-tp-quickstart" aria-labelledby="tp-quickstart-title">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">راهنمای تدریس</p><h2 id="tp-quickstart-title">مسیر همکاری با دلنوازان</h2></div>
	</div>
	<p class="dzn-tp-help">از آماده‌سازی حساب تا برگزاری و پیگیری کلاس، در هر گام می‌بینید هنرجو چه تجربه‌ای دارد و مسئولیت شما چیست. کارهای واقعی را از بخش مربوط در پرتال انجام دهید.</p>
	<?php foreach ( $groups as $group_id => $group ) : ?>
		<?php
		$group_steps = array();
		foreach ( $group['ids'] as $step_id ) {
			if ( isset( $by_id[ $step_id ] ) ) { $group_steps[] = $by_id[ $step_id ]; }
		}
		if ( ! $group_steps ) { continue; }
		?>
		<section class="dzn-tp-quickstart-phase" aria-labelledby="tp-phase-<?php echo esc_attr( $group_id ); ?>">
			<div class="dzn-tp-quickstart-phase__heading">
				<h3 id="tp-phase-<?php echo esc_attr( $group_id ); ?>"><?php echo esc_html( $group['title'] ); ?></h3>
				<p><?php echo esc_html( $group['description'] ); ?></p>
			</div>
			<ol class="dzn-tp-quickstart-list">
				<?php foreach ( $group_steps as $step ) : ?>
					<?php $step_id = (string) ( $step['id'] ?? '' ); ?>
					<li id="tp-step-<?php echo esc_attr( $step_id ); ?>" class="dzn-tp-quickstart-card">
						<div class="dzn-tp-quickstart-card__number" aria-hidden="true"><?php echo esc_html( number_format_i18n( (int) ( $step['order'] ?? 0 ) ) ); ?></div>
						<div class="dzn-tp-quickstart-card__body">
							<h4><?php echo esc_html( (string) ( $step['title'] ?? '' ) ); ?></h4>
							<p><strong>مسیر هنرجو:</strong> <?php echo esc_html( (string) ( $step['student_side'] ?? '' ) ); ?></p>
							<p><strong>کار شما:</strong> <?php echo esc_html( (string) ( $step['description'] ?? '' ) ); ?></p>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endforeach; ?>
	<nav class="dzn-tp-quickstart-footer" aria-label="پایان راهنمای تدریس">
		<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( home_url( '/teacher-portal/' ) ); ?>">بازگشت به پرتال مدرس</a>
		<?php if ( '' !== $support_url ) : ?><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer">نیاز به کمک دارم</a><?php endif; ?>
	</nav>
</section>
