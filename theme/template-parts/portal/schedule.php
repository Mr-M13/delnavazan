<?php
/**
 * Student schedule: today first, then future days, then past days.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$timezone_label = isset( $args['timezone_label'] ) ? (string) $args['timezone_label'] : '';
$labels = array(
	'in_progress'      => array( 'در حال برگزاری', 'accent' ),
	'starting_soon'    => array( 'به‌زودی آغاز می‌شود', 'accent' ),
	'upcoming'         => array( 'پیش رو', 'information' ),
	'ended'            => array( 'پایان یافته', 'muted' ),
	'unknown'          => array( 'زمان پایان نامشخص', 'warning' ),
);
$today = array();
$future = array();
$past = array();
foreach ( $items as $item ) {
	if ( ! is_array( $item ) ) { continue; }
	if ( ! empty( $item['is_today'] ) ) { $today[] = $item; }
	elseif ( ! empty( $item['is_future'] ) ) { $future[] = $item; }
	else { $past[] = $item; }
}
$group_days = static function ( array $rows ): array {
	usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['starts_at_utc'] ?? '' ), (string) ( $b['starts_at_utc'] ?? '' ) ) );
	$groups = array();
	foreach ( $rows as $row ) {
		$key = (string) ( $row['day_key'] ?? '' ) ?: 'unknown';
		if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'label' => (string) ( $row['date'] ?? 'تاریخ نامشخص' ), 'items' => array() ); }
		$groups[ $key ]['items'][] = $row;
	}
	return $groups;
};
$join_status = sanitize_key( (string) ( $_GET['join_status'] ?? '' ) );
$absence_status = sanitize_key( (string) ( $_GET['absence_status'] ?? '' ) );
$render_rows = static function ( array $rows ) use ( $labels ): void {
	foreach ( $rows as $item ) :
		$state = (string) ( $item['state'] ?? 'unknown' );
		$presentation = $labels[ $state ] ?? array( 'وضعیت نامشخص', 'warning' );
		$is_intro = 'intro' === ( $item['kind'] ?? '' );
		?>
		<li class="dzn-schedule-row is-<?php echo esc_attr( $state ); ?>">
			<div class="dzn-schedule-row__time"><time datetime="<?php echo esc_attr( (string) ( $item['starts_at_iso'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $item['time'] ?? '' ) ); ?></time></div>
			<div class="dzn-schedule-row__main">
				<h4><?php echo esc_html( (string) ( $item['course'] ?? '' ) ); ?><?php if ( 'replacement' === ( $item['lesson_kind'] ?? '' ) ) : ?> <span class="dzn-status dzn-status--information"><?php esc_html_e( 'جلسهٔ جبرانی', 'delnavazan-theme' ); ?></span><?php elseif ( ! empty( $item['rescheduled'] ) ) : ?> <span class="dzn-status dzn-status--information"><?php esc_html_e( 'زمان جدید', 'delnavazan-theme' ); ?></span><?php endif; ?></h4>
				<?php if ( ! empty( $item['teacher'] ) ) : ?><p class="dzn-schedule-row__teacher"><?php echo esc_html( sprintf( 'با %s', $item['teacher'] ) ); ?></p><?php endif; ?>
				<p class="dzn-status dzn-status--<?php echo esc_attr( $presentation[1] ); ?>"><?php echo esc_html( $presentation[0] ); ?></p>
				<?php if ( ! empty( $item['absence_label'] ) ) : ?><p class="dzn-status dzn-status--warning"><?php echo esc_html( (string) $item['absence_label'] ); ?></p><?php endif; ?>
				<?php if ( ! empty( $item['replacement_time'] ) ) : ?><p class="dzn-portal-help"><?php echo esc_html( sprintf( 'جلسهٔ جبرانی: %s', (string) $item['replacement_time'] ) ); ?></p><?php endif; ?>
				<?php if ( $is_intro ) : ?><p class="dzn-portal-help"><?php esc_html_e( 'کلاس آشنایی؛ شرایط آن از منبع معتبر خوانده شده است.', 'delnavazan-theme' ); ?></p><?php endif; ?>
			</div>
			<div class="dzn-schedule-row__actions">
				<?php
				get_template_part(
					'template-parts/components/classroom-actions',
					null,
					array(
						'item' => $item,
						'role' => 'student',
						'primary_class' => 'dzn-button',
					)
				);
				?>
				<?php if ( ! empty( $item['absence_available'] ) ) : ?>
					<details class="dzn-schedule-row__absence">
						<summary><?php esc_html_e( 'اطلاع غیبت', 'delnavazan-theme' ); ?></summary>
						<p class="dzn-portal-help"><?php esc_html_e( 'این اطلاع به‌تنهایی وضعیت نهایی حضور یا اعتبار جلسه را تعیین نمی‌کند.', 'delnavazan-theme' ); ?></p>
						<form method="post" action="<?php echo esc_url( (string) ( $item['absence_action_url'] ?? '' ) ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['absence_nonce'] ?? '' ) ); ?>">
							<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>">
							<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>">
							<button class="dzn-button dzn-button--secondary" type="submit"><?php esc_html_e( 'ثبت اطلاع غیبت', 'delnavazan-theme' ); ?></button>
						</form>
					</details>
				<?php elseif ( ! empty( $item['absence_message'] ) ) : ?>
					<p class="dzn-portal-help"><?php echo esc_html( (string) $item['absence_message'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $item['calendar_export_url'] ) ) : ?><a class="dzn-portal-text-action" href="<?php echo esc_url( (string) $item['calendar_export_url'] ); ?>"><?php esc_html_e( 'افزودن به تقویم', 'delnavazan-theme' ); ?></a><?php endif; ?>
			</div>
		</li>
	<?php endforeach;
};
?>
<?php if ( 'join_unavailable' === $join_status ) : ?><p class="dzn-portal-action-status" role="alert"><?php esc_html_e( 'ورود به کلاس ممکن نشد؛ لینک امن یا زمان ورود را بررسی کنید.', 'delnavazan-theme' ); ?></p><?php elseif ( 'livekit_unavailable' === $join_status ) : ?><p class="dzn-portal-action-status" role="alert"><?php esc_html_e( 'کلاس زندهٔ دلنوازان در این لحظه در دسترس نیست یا زمان ورود نرسیده است. دوباره تلاش کنید یا از گزینهٔ پشتیبان گوگل میت استفاده کنید.', 'delnavazan-theme' ); ?></p><?php elseif ( 'meet_unavailable' === $join_status ) : ?><p class="dzn-portal-action-status" role="alert"><?php esc_html_e( 'گزینهٔ پشتیبان گوگل میت برای این جلسه در دسترس نیست. لطفاً با پشتیبانی دلنوازان تماس بگیرید.', 'delnavazan-theme' ); ?></p><?php elseif ( 'absence_recorded' === $absence_status ) : ?><p class="dzn-portal-action-status" role="status"><?php esc_html_e( 'اطلاع غیبت شما ثبت شد.', 'delnavazan-theme' ); ?></p><?php elseif ( 'absence_recorded_no_replacement' === $absence_status ) : ?><p class="dzn-portal-action-status dzn-portal-action-status--warning" role="status"><?php esc_html_e( 'اطلاع غیبت شما ثبت شد؛ اما سهمیهٔ جلسات جبرانی این ترم تمام شده است و امکان هماهنگی جلسهٔ جبرانی برای این غیبت وجود ندارد. مورد برای بررسی مدیر ثبت شد.', 'delnavazan-theme' ); ?></p><?php elseif ( 'absence_unavailable' === $absence_status ) : ?><p class="dzn-portal-action-status" role="alert"><?php esc_html_e( 'ثبت اطلاع غیبت برای این جلسه ممکن نشد. لطفاً دوباره تلاش کنید.', 'delnavazan-theme' ); ?></p><?php endif; ?>
<section class="dzn-portal-section dzn-schedule" aria-labelledby="dzn-schedule-title">
	<div class="dzn-portal-section__heading">
		<div><p class="dzn-portal-kicker"><?php esc_html_e( 'برنامهٔ شما', 'delnavazan-theme' ); ?></p><h2 id="dzn-schedule-title"><?php esc_html_e( 'کلاس‌های امروز و آینده', 'delnavazan-theme' ); ?></h2></div>
		<?php if ( '' !== $timezone_label ) : ?><p class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone_label ); ?></p><?php endif; ?>
	</div>

	<section class="dzn-schedule-period dzn-schedule-period--today" aria-labelledby="dzn-schedule-today">
		<h3 id="dzn-schedule-today"><?php esc_html_e( 'کلاس‌های امروز', 'delnavazan-theme' ); ?></h3>
		<?php if ( ! $today ) : ?>
			<p class="dzn-portal-empty"><?php esc_html_e( 'کلاسی برای امروز ثبت نشده است.', 'delnavazan-theme' ); ?></p>
		<?php else : ?><ol class="dzn-schedule-list"><?php $render_rows( $today ); ?></ol><?php endif; ?>
	</section>

	<section class="dzn-schedule-period dzn-schedule-period--future" aria-labelledby="dzn-schedule-future">
		<h3 id="dzn-schedule-future"><?php esc_html_e( 'کلاس‌های آینده', 'delnavazan-theme' ); ?></h3>
		<?php if ( ! $future ) : ?>
			<p class="dzn-portal-empty"><?php esc_html_e( 'کلاسی برای روزهای آینده ثبت نشده است.', 'delnavazan-theme' ); ?></p>
		<?php else : foreach ( $group_days( $future ) as $key => $group ) : ?>
			<section class="dzn-schedule-day" aria-labelledby="dzn-schedule-future-<?php echo esc_attr( $key ); ?>">
				<h4 id="dzn-schedule-future-<?php echo esc_attr( $key ); ?>"><time datetime="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['label'] ); ?></time></h4>
				<ol class="dzn-schedule-list"><?php $render_rows( $group['items'] ); ?></ol>
			</section>
		<?php endforeach; endif; ?>
	</section>

	<?php if ( $past ) : ?>
	<section class="dzn-schedule-period dzn-schedule-period--past" aria-labelledby="dzn-schedule-past">
		<h3 id="dzn-schedule-past"><?php esc_html_e( 'کلاس‌های گذشته', 'delnavazan-theme' ); ?></h3>
		<details><summary><?php esc_html_e( 'نمایش سابقهٔ کلاس‌ها', 'delnavazan-theme' ); ?></summary>
			<?php foreach ( $group_days( $past ) as $key => $group ) : ?>
				<section class="dzn-schedule-day"><h4><time datetime="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['label'] ); ?></time></h4><ol class="dzn-schedule-list"><?php $render_rows( $group['items'] ); ?></ol></section>
			<?php endforeach; ?>
		</details>
	</section>
	<?php endif; ?>
</section>
