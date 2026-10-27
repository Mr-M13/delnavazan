<?php
/**
 * Student schedule: active first, then upcoming, then ended.
 *
 * Every action comes from the authorised read model. A canonical term lesson
 * has no student join endpoint in the pinned Platform, so it never receives a
 * primary Join control; the row explains the safe next step instead.
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

$groups = array();
foreach ( $items as $item ) {
	if ( ! is_array( $item ) ) { continue; }
	$key = (string) ( $item['day_key'] ?? '' );
	if ( '' === $key ) { $key = 'unknown'; }
	if ( ! isset( $groups[ $key ] ) ) {
		$groups[ $key ] = array( 'label' => (string) ( $item['date'] ?? 'تاریخ نامشخص' ), 'items' => array() );
	}
	$groups[ $key ]['items'][] = $item;
}
?>
<section class="dzn-portal-section dzn-schedule" aria-labelledby="dzn-schedule-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'برنامهٔ شما', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-schedule-title"><?php esc_html_e( 'کلاس‌های جاری، پیش رو و گذشته', 'delnavazan-theme' ); ?></h2>
		</div>
		<?php if ( '' !== $timezone_label ) : ?><p class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone_label ); ?></p><?php endif; ?>
	</div>

	<?php if ( ! $items ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'هنوز کلاسی در برنامهٔ شما ثبت نشده است. پس از نهایی‌شدن زمان‌ها، همین‌جا نمایش داده می‌شود.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
		<?php foreach ( $groups as $key => $group ) : ?>
			<section class="dzn-schedule-day" aria-labelledby="dzn-schedule-day-<?php echo esc_attr( $key ); ?>">
				<h3 id="dzn-schedule-day-<?php echo esc_attr( $key ); ?>"><time datetime="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['label'] ); ?></time></h3>
				<ol class="dzn-schedule-list">
					<?php foreach ( $group['items'] as $item ) : ?>
						<?php
						$state = (string) ( $item['state'] ?? 'unknown' );
						$presentation = $labels[ $state ] ?? array( 'وضعیت نامشخص', 'warning' );
						$is_intro = 'intro' === ( $item['kind'] ?? '' );
						?>
						<li class="dzn-schedule-row is-<?php echo esc_attr( $state ); ?>">
							<div class="dzn-schedule-row__time">
								<time datetime="<?php echo esc_attr( (string) ( $item['starts_at_iso'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $item['time'] ?? '' ) ); ?></time>
							</div>
							<div class="dzn-schedule-row__main">
								<h4><?php echo esc_html( (string) ( $item['course'] ?? '' ) ); ?></h4>
								<?php if ( ! empty( $item['teacher'] ) ) : ?><p class="dzn-schedule-row__teacher"><?php echo esc_html( sprintf( 'با %s', $item['teacher'] ) ); ?></p><?php endif; ?>
								<p class="dzn-status dzn-status--<?php echo esc_attr( $presentation[1] ); ?>"><?php echo esc_html( $presentation[0] ); ?></p>
								<?php if ( $is_intro ) : ?><p class="dzn-portal-help"><?php esc_html_e( 'جلسهٔ معارفه؛ شرایط آن از منبع معتبر خوانده شده است.', 'delnavazan-theme' ); ?></p><?php endif; ?>
							</div>
							<div class="dzn-schedule-row__actions">
								<?php if ( ! empty( $item['join_available'] ) && ! empty( $item['join_action_url'] ) ) : ?>
									<form method="post" target="_blank" rel="noopener noreferrer" action="<?php echo esc_url( (string) $item['join_action_url'] ); ?>">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['join_nonce'] ?? '' ) ); ?>">
										<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) ( $item['confirmation_uid'] ?? '' ) ); ?>">
										<button class="dzn-button" type="submit"><?php esc_html_e( 'ورود به کلاس', 'delnavazan-theme' ); ?></button>
									</form>
								<?php elseif ( $is_intro && ! empty( $item['join_message'] ) ) : ?>
									<p class="dzn-portal-help"><?php echo esc_html( (string) $item['join_message'] ); ?></p>
								<?php elseif ( ! $is_intro && in_array( $state, array( 'in_progress', 'starting_soon' ), true ) ) : ?>
									<p class="dzn-portal-help"><?php esc_html_e( 'ورود به کلاس‌های ترم از این نسخه فعال نیست؛ برای هماهنگی با پشتیبانی دلنوازان تماس بگیرید.', 'delnavazan-theme' ); ?></p>
								<?php endif; ?>
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
								<?php endif; ?>
								<?php if ( ! empty( $item['calendar_export_url'] ) ) : ?><a class="dzn-portal-text-action" href="<?php echo esc_url( (string) $item['calendar_export_url'] ); ?>"><?php esc_html_e( 'افزودن به تقویم', 'delnavazan-theme' ); ?></a><?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
