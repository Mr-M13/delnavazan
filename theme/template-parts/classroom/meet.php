<?php
/**
 * Branded Delnavazan Google Meet lesson entry page.
 *
 * Students reach Google Meet directly from their portal. A Teacher keeps this branded page so the
 * canonical pre-class context (term timeline, outstanding actions and the private note from the
 * previous class) is available before the lesson, and the same page becomes the post-class finish
 * page where the private note is written and attendance is confirmed.
 *
 * The page carries lesson metadata only. The Join button posts to the authenticated,
 * nonce-protected join controller, which re-proves ownership and the join window before opening the
 * vaulted Meet URL.
 *
 * @package DelnavazanTheme
 */
$logo = get_theme_file_uri( 'assets/images/delnavazan-logo.png' );
$lesson_uid = sanitize_text_field( (string) ( $_GET['lesson_uid'] ?? '' ) );
$schedule_uid = sanitize_text_field( (string) ( $_GET['schedule_version_uid'] ?? '' ) );
$confirmation_uid = sanitize_text_field( (string) ( $_GET['confirmation_uid'] ?? '' ) );
$is_booking = '' !== $confirmation_uid;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$user_id = (int) get_current_user_id();
$teacher_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT teacher_id FROM {$p}teacher_principal_links WHERE wordpress_user_id=%d AND status='active' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1", $user_id ) );
$student_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT student_id FROM {$p}student_principal_links WHERE wordpress_user_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL ORDER BY id DESC LIMIT 1", $user_id ) );
$role = $teacher_id > 0 ? 'teacher' : ( $student_id > 0 ? 'student' : '' );

$lesson = null;
$schedule = null;
if ( ! $is_booking && preg_match( '/^[0-9A-Z]{26}$/D', $lesson_uid ) && preg_match( '/^[0-9A-Z]{26}$/D', $schedule_uid ) ) {
	$lesson = $wpdb->get_row( $wpdb->prepare(
		"SELECT l.*,t.display_name AS teacher_name,s.display_name AS student_name,COALESCE(NULLIF(c.name_fa,''),c.name_en,'') AS course_name
		 FROM {$p}lessons l
		 INNER JOIN {$p}teachers t ON t.id=l.teacher_id
		INNER JOIN {$p}students s ON s.id=l.student_id
		LEFT JOIN {$p}courses c ON c.id=l.course_id
		WHERE l.uid=%s AND l.archived_at IS NULL LIMIT 1",
		$lesson_uid
	) );
	if ( is_object( $lesson ) ) {
		$schedule = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s AND lesson_id=%d AND applicable_slot=1 LIMIT 1",
			$schedule_uid,
			(int) $lesson->id
		) );
	}
}
$booking = null;
if ( $is_booking && preg_match( '/^[0-9A-Z]{26}$/D', $confirmation_uid ) ) {
	$booking = $wpdb->get_row( $wpdb->prepare(
		"SELECT c.*,t.display_name AS teacher_name,s.full_name AS student_name
		 FROM {$p}booking_request_confirmations c
		 INNER JOIN {$p}teachers t ON t.id=c.teacher_id
		 LEFT JOIN {$p}booking_request_contact_snapshots s ON s.booking_request_id=c.booking_request_id AND s.snapshot_sequence=1
		 WHERE c.uid=%s LIMIT 1",
		$confirmation_uid
	) );
}
$owned = false;
if ( $lesson && $role === 'teacher' && (int) $lesson->teacher_id === $teacher_id ) { $owned = true; }
if ( $lesson && $role === 'student' && (int) $lesson->student_id === $student_id ) { $owned = true; }
if ( $booking && 'teacher' === $role && $teacher_id > 0 && (int) $booking->teacher_id === $teacher_id ) { $owned = true; }
if ( $booking && 'student' === $role && $student_id > 0 ) {
    $repository_class = '\\Delnavazan\\Platform\\Core\\Infrastructure\\Repository\\BookingTeacherDeliveryRepository';
    if ( class_exists( $repository_class ) ) {
        try { $owned = null !== ( new $repository_class() )->confirmedForParticipant( $confirmation_uid, 'student', $student_id ); }
        catch ( Throwable $e ) { $owned = false; }
    }
}
// Do not expose another participant's names, lesson time or briefing to a guessed URL.
$has_details = $owned && ( ( $lesson && $schedule ) || $booking );

$course = is_object( $lesson ) ? ( $lesson->course_name ?? 'کلاس موسیقی دلنوازان' ) : 'کلاس موسیقی دلنوازان';
$teacher_name = is_object( $lesson ) ? ( $lesson->teacher_name ?? '' ) : ( is_object( $booking ) ? ( $booking->teacher_name ?? '' ) : '' );
$student_name = is_object( $lesson ) ? ( $lesson->student_name ?? '' ) : ( is_object( $booking ) ? ( $booking->student_name ?? '' ) : '' );
if ( '' === $teacher_name ) { $teacher_name = 'مدرس دلنوازان'; }
if ( '' === $student_name ) { $student_name = 'هنرجو'; }
$starts_at = is_object( $schedule ) ? ( $schedule->starts_at_utc ?? '' ) : ( is_object( $booking ) ? ( $booking->starts_at_utc ?? '' ) : '' );
$ends_at = is_object( $schedule ) ? ( $schedule->ends_at_utc ?? '' ) : ( is_object( $booking ) ? ( $booking->ends_at_utc ?? '' ) : '' );
$timezone = wp_timezone();
$date_label = '';
$time_label = '';
if ( $starts_at !== '' ) {
	$start = new DateTimeImmutable( $starts_at, new DateTimeZone( 'UTC' ) );
	$end = new DateTimeImmutable( $ends_at !== '' ? $ends_at : $starts_at, new DateTimeZone( 'UTC' ) );
	$start = $start->setTimezone( $timezone );
	$end = $end->setTimezone( $timezone );
	$date_label = $start->format( 'l j F Y' );
	$time_label = $start->format( 'H:i' ) . '–' . $end->format( 'H:i' );
}
$join_action = $role === 'teacher' ? 'dzn_teacher_join_class' : 'dzn_student_join_class';
$join_nonce_action = $role === 'teacher' ? 'dzn_teacher_join_class' : 'dzn_student_join_class';
if ( $is_booking ) {
	$join_action = $role === 'teacher' ? 'dzn_teacher_join_booking_class' : 'dzn_student_join_booking_class';
	$join_nonce_action = $join_action;
}
$join_available = $has_details && $owned && in_array( $role, array( 'teacher', 'student' ), true );
$recording_available = false;
$join_service_class = '\\Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\MeetClassroomJoinService';
if ( $owned && '' !== $lesson_uid && class_exists( $join_service_class ) ) {
	try { $recording_available = ( new $join_service_class() )->hasPlayableRecordingForLessonUid( $lesson_uid ); }
	catch ( Throwable $e ) { $recording_available = false; }
}

$briefing = null;
if ( $owned && 'teacher' === $role && ! $is_booking ) {
	$briefing_class = '\\Delnavazan\\Platform\\Core\\Application\\TeacherClassroomBriefingReadService';
	if ( class_exists( $briefing_class ) ) {
		try { $briefing = ( new $briefing_class() )->forLesson( $lesson_uid, $schedule_uid ); }
		catch ( Throwable $e ) { $briefing = null; }
	}
}
$ends_timestamp = '' !== $ends_at ? (int) strtotime( $ends_at . ' UTC' ) : 0;
$class_ended = $ends_timestamp > 0 && $ends_timestamp <= time();
$finish_requested = 'finish' === sanitize_key( (string) ( $_GET['view'] ?? '' ) );
$show_finish = 'teacher' === $role && ! $is_booking && ( $finish_requested || $class_ended );
$finish_url = add_query_arg(
	array( 'lesson_uid' => $lesson_uid, 'schedule_version_uid' => $schedule_uid, 'view' => 'finish' ),
	home_url( '/music-classroom/' )
);
$note_status = sanitize_key( (string) ( $_GET['note_status'] ?? '' ) );

$brief_timezone = is_array( $briefing ) ? (string) ( $briefing['lesson']['schedule_timezone'] ?? $timezone->getName() ) : $timezone->getName();
$brief_format = static function ( string $utc, string $format = 'j F Y · H:i' ) use ( $brief_timezone ): string {
	if ( '' === trim( $utc ) ) { return ''; }
	try {
		$when = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		return $when->setTimezone( new DateTimeZone( $brief_timezone ) )->format( $format );
	} catch ( Throwable $e ) {
		return '';
	}
};
$term = is_array( $briefing['term'] ?? null ) ? $briefing['term'] : array();
$timeline = is_array( $term['timeline'] ?? null ) ? $term['timeline'] : array();
$attention = is_array( $briefing['attention'] ?? null ) ? $briefing['attention'] : array();
$previous_note = is_array( $briefing['previous_note'] ?? null ) ? $briefing['previous_note'] : null;
$current_note = is_array( $briefing['note'] ?? null ) ? $briefing['note'] : null;
$completion = is_array( $briefing['completion'] ?? null ) ? $briefing['completion'] : array();
$persian_digits = static function ( int $value ): string {
	return function_exists( 'dzn_theme_platform_persian_digits' ) ? dzn_theme_platform_persian_digits( $value ) : (string) $value;
};
$timeline_state = static function ( array $row ) use ( $brief_format ): array {
	$end = '' !== (string) ( $row['ends_at_utc'] ?? '' ) ? (int) strtotime( (string) $row['ends_at_utc'] . ' UTC' ) : 0;
	$outcome = strtolower( trim( (string) ( $row['delivery_outcome'] ?? '' ) ) );
	$attendance = strtolower( trim( (string) ( $row['attendance_state'] ?? '' ) ) );
	$lifecycle = (string) ( $row['lifecycle_state'] ?? '' );
	if ( 'cancelled' === $lifecycle ) { return array( 'لغو شده', 'muted' ); }
	if ( in_array( $outcome, array( 'delivered', 'completed' ), true ) ) { return array( 'برگزار شد', 'success' ); }
	if ( '' !== $outcome ) { return array( 'وضعیت برگزاری ثبت شده', 'warning' ); }
	if ( in_array( $attendance, array( 'open', 'ready_for_review', 'settlement_pending' ), true ) ) { return array( 'در انتظار بررسی حضور', 'warning' ); }
	if ( $end > 0 && $end <= time() ) { return array( 'در انتظار تأیید حضور', 'warning' ); }
	return array( 'پیش رو', 'information' );
};
?>
<main id="main-content" class="dzn-meet-entry" tabindex="-1">
	<header class="dzn-meet-entry__header">
		<div class="dzn-meet-entry__brand">
			<span class="dzn-meet-entry__logo"><img src="<?php echo esc_url( $logo ); ?>" alt="دلنوازان" width="132" height="36"></span>
			<div>
				<p class="dzn-meet-entry__eyebrow"><?php echo $show_finish ? 'پایان کلاس دلنوازان' : 'کلاس زندهٔ دلنوازان'; ?></p>
				<h1><?php echo $show_finish ? 'ثبت گزارش پایان کلاس' : 'ورود به کلاس Google Meet'; ?></h1>
			</div>
		</div>
		<p class="dzn-meet-entry__badge"><span aria-hidden="true">●</span> کلاس با حساب سازمانی دلنوازان ساخته می‌شود</p>
	</header>

	<?php if ( ! $has_details ) : ?>
		<section class="dzn-meet-entry__card">
			<h2>پیوند کلاس در دسترس نیست</h2>
			<p>برای ورود، از کارت کلاس در پرتال خود دوباره «ورود به کلاس» را انتخاب کنید. اگر مشکل ادامه داشت با پشتیبانی دلنوازان تماس بگیرید.</p>
			<p><a class="dzn-meet-entry__button dzn-meet-entry__button--secondary" href="<?php echo esc_url( home_url( $role === 'teacher' ? '/teacher-portal/' : '/student-portal/' ) ); ?>">بازگشت به پرتال</a></p>
		</section>
	<?php else : ?>
		<section class="dzn-meet-entry__card" aria-labelledby="dzn-meet-entry-title">
			<div class="dzn-meet-entry__summary">
				<div>
					<p class="dzn-meet-entry__kicker"><?php echo $is_booking ? 'جلسهٔ آشنایی' : 'کلاس برنامه‌ریزی‌شده'; ?></p>
					<h2 id="dzn-meet-entry-title"><?php echo esc_html( $course ); ?></h2>
					<p class="dzn-meet-entry__people"><?php echo esc_html( $teacher_name ); ?><span aria-hidden="true"> · </span><?php echo esc_html( $student_name ); ?></p>
				</div>
				<?php if ( $date_label !== '' ) : ?>
					<dl class="dzn-meet-entry__when">
						<div><dt>روز</dt><dd><time datetime="<?php echo esc_attr( $starts_at ); ?>"><?php echo esc_html( $date_label ); ?></time></dd></div>
						<div><dt>ساعت</dt><dd><bdi><time datetime="<?php echo esc_attr( $starts_at ); ?>"><?php echo esc_html( $time_label ); ?></time></bdi></dd></div>
					</dl>
				<?php endif; ?>
			</div>

			<?php if ( $show_finish ) : ?>
				<?php if ( 'saved' === $note_status ) : ?>
					<p class="dzn-meet-entry__notice dzn-meet-entry__notice--success" role="status">یادداشت خصوصی شما برای این جلسه ذخیره شد. این یادداشت فقط برای شما و تیم مجاز دیده می‌شود.</p>
				<?php elseif ( 'note_too_long' === $note_status ) : ?>
					<p class="dzn-meet-entry__notice dzn-meet-entry__notice--warning" role="alert">یادداشت بیش از اندازهٔ مجاز طولانی است. متن کوتاه‌تری ذخیره کنید.</p>
				<?php elseif ( 'note_unavailable' === $note_status ) : ?>
					<p class="dzn-meet-entry__notice dzn-meet-entry__notice--warning" role="alert">ذخیرهٔ یادداشت در این لحظه ممکن نشد. دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.</p>
				<?php endif; ?>
				<div class="dzn-meet-entry__finish">
					<section class="dzn-meet-entry__finish-note" aria-labelledby="dzn-finish-note-title">
						<h2 id="dzn-finish-note-title">یادداشت خصوصی این جلسه</h2>
						<p>این یادداشت فقط برای مدرس و تیم مجاز نمایش داده می‌شود و هنرجو آن را نمی‌بیند. در ابتدای جلسهٔ بعد، همین یادداشت به شما نشان داده می‌شود.</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzn-meet-entry__note-form">
							<input type="hidden" name="action" value="dzn_teacher_save_class_note">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'dzn_teacher_save_class_note' ) ); ?>">
							<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( $lesson_uid ); ?>">
							<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( $schedule_uid ); ?>">
							<label for="dzn-finish-note">یادداشت شما از این کلاس</label>
							<textarea id="dzn-finish-note" name="note_text" rows="5" maxlength="5000" placeholder="مثلاً: تمرین ریتم ادامه پیدا کند؛ جلسهٔ بعد با تمرین شنیداری شروع شود."><?php echo esc_textarea( (string) ( $current_note['text'] ?? '' ) ); ?></textarea>
							<button class="dzn-meet-entry__button dzn-meet-entry__button--secondary" type="submit">ذخیرهٔ یادداشت</button>
						</form>
					</section>

					<section class="dzn-meet-entry__finish-attendance" aria-labelledby="dzn-finish-attendance-title">
						<h2 id="dzn-finish-attendance-title">تأیید برگزاری و حضور</h2>
						<?php if ( ! empty( $completion['available'] ) ) : ?>
							<p>اگر این کلاس در زمان مقرر برگزار شد، برگزاری و حضور را تأیید کنید. این تأیید مبنای ثبت حضور و مالی مدرس است.</p>
							<form method="post" action="<?php echo esc_url( (string) ( $completion['action_url'] ?? '' ) ); ?>" class="dzn-meet-entry__attendance-form">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $completion['nonce'] ?? '' ) ); ?>">
								<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( $lesson_uid ); ?>">
								<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( $schedule_uid ); ?>">
								<button class="dzn-meet-entry__button" type="submit">تأیید برگزاری و حضور</button>
							</form>
						<?php else : ?>
							<p class="dzn-meet-entry__notice" role="status"><?php echo esc_html( (string) ( $completion['message'] ?? 'این کلاس در وضعیت قابل گزارش نیست.' ) ); ?></p>
							<?php if ( '' !== (string) ( $completion['outcome_label'] ?? '' ) ) : ?><p class="dzn-meet-entry__status-line"><strong>وضعیت ثبت‌شده:</strong> <?php echo esc_html( (string) $completion['outcome_label'] ); ?></p><?php endif; ?>
						<?php endif; ?>
					</section>
				</div>
				<?php if ( $join_available ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" rel="noopener noreferrer" class="dzn-meet-entry__rejoin" data-dzn-finish-url="<?php echo esc_url( $is_booking ? '' : $finish_url ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( $join_action ); ?>">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( $join_nonce_action ) ); ?>">
						<?php if ( $is_booking ) : ?>
							<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( $confirmation_uid ); ?>">
						<?php else : ?>
							<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( $lesson_uid ); ?>">
							<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( $schedule_uid ); ?>">
						<?php endif; ?>
						<button class="dzn-meet-entry__button dzn-meet-entry__button--secondary" type="submit">ورود دوباره به کلاس</button>
						<p class="dzn-meet-entry__help">اگر کلاس هنوز ادامه دارد، می‌توانید دوباره وارد Google Meet شوید.</p>
					</form>
				<?php endif; ?>
				<p class="dzn-meet-entry__back"><a href="<?php echo esc_url( home_url( '/teacher-portal/' ) ); ?>">بازگشت به پرتال مدرس</a></p>
			<?php else : ?>
				<?php if ( $join_available ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" rel="noopener noreferrer" class="dzn-meet-entry__join" data-dzn-finish-url="<?php echo esc_url( $is_booking ? '' : $finish_url ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( $join_action ); ?>">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( $join_nonce_action ) ); ?>">
						<?php if ( $is_booking ) : ?>
							<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( $confirmation_uid ); ?>">
						<?php else : ?>
							<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( $lesson_uid ); ?>">
							<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( $schedule_uid ); ?>">
						<?php endif; ?>
						<button class="dzn-meet-entry__button" type="submit" data-dzn-meet-join>ورود به کلاس</button>
						<p class="dzn-meet-entry__help">پیوند امن پس از تأیید مالکیت و زمان ورود در سرور باز می‌شود. در Android ورود در همان برگه انجام می‌شود؛ پس از کلاس به این صفحه برگردید. در رایانه Google Meet در پنجرهٔ تازه باز می‌شود و با بستن آن، صفحهٔ پایان کلاس به‌صورت خودکار باز می‌شود.</p>
					</form>
				<?php else : ?>
					<p class="dzn-meet-entry__notice" role="status">این کلاس برای حساب شما در دسترس نیست یا زمان ورود آن نرسیده است. برای راهنمایی با پشتیبانی دلنوازان تماس بگیرید.</p>
				<?php endif; ?>
				<?php if ( ! $is_booking && $owned ) : ?>
					<p class="dzn-meet-entry__back"><a href="<?php echo esc_url( $finish_url ); ?>">بازگشت پس از کلاس: ثبت یادداشت و تأیید حضور</a></p>
				<?php endif; ?>
				<?php if ( $recording_available ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzn-meet-entry__recording">
						<input type="hidden" name="action" value="dzn_meet_recording_play">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'dzn_meet_recording_play' ) ); ?>">
						<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) $lesson_uid ); ?>">
						<button class="dzn-meet-entry__button dzn-meet-entry__button--secondary" type="submit">تماشای ضبط کلاس</button>
						<p class="dzn-meet-entry__help">پخش فقط برای افراد مجاز و تا زمانی که انتخاب ضبط هنرجو معتبر باشد فعال است.</p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php if ( is_array( $briefing ) ) : ?>
			<?php if ( $attention ) : ?>
				<section class="dzn-meet-entry__card dzn-meet-entry__attention" aria-labelledby="dzn-meet-attention-title">
					<h2 id="dzn-meet-attention-title">کارهای نیازمند توجه</h2>
					<p class="dzn-meet-entry__help">این موارد از وضعیت ثبت‌شدهٔ ترم و همین هنرجو خوانده شده‌اند؛ هیچ‌کدام به‌صورت خودکار تغییر داده نمی‌شود.</p>
					<ul class="dzn-meet-entry__attention-list">
						<?php foreach ( $attention as $item ) : ?>
							<li>
								<div>
									<h3><?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?></h3>
									<p><?php echo esc_html( (string) ( $item['message'] ?? '' ) ); ?></p>
									<?php $when_label = $brief_format( (string) ( $item['when'] ?? '' ) ); ?>
									<?php if ( '' !== $when_label ) : ?><p class="dzn-meet-entry__attention-when"><?php echo esc_html( $when_label ); ?><?php if ( '' !== (string) ( $item['lesson_reference'] ?? '' ) ) : ?> · <?php echo esc_html( (string) $item['lesson_reference'] ); ?><?php endif; ?></p><?php endif; ?>
								</div>
								<?php if ( '' !== (string) ( $item['action_url'] ?? '' ) ) : ?><a class="dzn-meet-entry__text-action" href="<?php echo esc_url( (string) $item['action_url'] ); ?>"><?php echo esc_html( (string) ( $item['action_label'] ?? 'مشاهده' ) ); ?></a><?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section class="dzn-meet-entry__card dzn-meet-entry__brief" aria-labelledby="dzn-meet-brief-title">
				<h2 id="dzn-meet-brief-title">آماده‌سازی این کلاس</h2>
				<div class="dzn-meet-entry__brief-grid">
					<article class="dzn-meet-entry__brief-card">
						<h3>یادداشت جلسهٔ گذشته</h3>
						<?php if ( is_array( $previous_note ) ) : ?>
							<blockquote><?php echo nl2br( esc_html( (string) $previous_note['text'] ) ); ?></blockquote>
							<?php $previous_when = $brief_format( (string) ( $previous_note['starts_at_utc'] ?? '' ) ); ?>
							<p class="dzn-meet-entry__help"><?php echo esc_html( 'ثبت‌شده برای جلسهٔ ' . ( '' !== $previous_when ? $previous_when : 'گذشته' ) ); ?><?php if ( '' !== (string) ( $previous_note['lesson_reference'] ?? '' ) ) : ?> · <?php echo esc_html( (string) $previous_note['lesson_reference'] ); ?><?php endif; ?></p>
						<?php else : ?>
							<p>یادداشت خصوصی برای جلسهٔ گذشتهٔ این هنرجو ثبت نشده است.</p>
						<?php endif; ?>
						<p><a class="dzn-meet-entry__text-action" href="<?php echo esc_url( $finish_url ); ?>">ثبت یادداشت و پایان کلاس</a></p>
					</article>
					<article class="dzn-meet-entry__brief-card">
						<h3>ترم هنرجو</h3>
						<?php if ( (int) ( $term['total'] ?? 0 ) > 0 ) : ?>
							<p class="dzn-meet-entry__term-line"><?php echo esc_html( 'ترم ' . $persian_digits( (int) ( $term['sequence'] ?? 0 ) ) . ' · ' . $persian_digits( (int) $term['completed'] ) . ' از ' . $persian_digits( (int) $term['total'] ) . ' جلسه برگزار شده · ' . $persian_digits( (int) $term['remaining'] ) . ' جلسه مانده' ); ?></p>
							<?php if ( (int) ( $term['replacement_available'] ?? 0 ) > 0 ) : ?><p class="dzn-meet-entry__help"><?php echo esc_html( $persian_digits( (int) $term['replacement_available'] ) . ' جلسهٔ جبرانی قابل استفاده' ); ?></p><?php endif; ?>
							<ol class="dzn-meet-entry__timeline" aria-label="خط زمانی ترم هنرجو">
								<?php foreach ( $timeline as $timeline_row ) : ?>
									<?php
									list( $state_label, $state_tone ) = $timeline_state( $timeline_row );
									$is_current = ! empty( $timeline_row['is_current'] );
									$timeline_when = $brief_format( (string) ( $timeline_row['starts_at_utc'] ?? '' ), 'j F · H:i' );
									?>
									<li class="<?php echo $is_current ? 'is-current' : ''; ?>">
										<span class="dzn-meet-entry__timeline-dot" aria-hidden="true"></span>
										<span class="dzn-meet-entry__timeline-seq"><?php echo esc_html( $persian_digits( (int) ( $timeline_row['sequence'] ?? 0 ) ) ); ?></span>
										<span class="dzn-meet-entry__timeline-when"><?php echo esc_html( $timeline_when ); ?></span>
										<span class="dzn-meet-entry__timeline-state dzn-meet-entry__timeline-state--<?php echo esc_attr( $state_tone ); ?>"><?php echo esc_html( $is_current ? 'این جلسه' : $state_label ); ?></span>
									</li>
								<?php endforeach; ?>
							</ol>
						<?php else : ?>
							<p>اطلاعات ترم برای این هنرجو در دسترس نیست.</p>
						<?php endif; ?>
					</article>
				</div>
			</section>
		<?php elseif ( 'teacher' === $role && ! $is_booking ) : ?>
			<section class="dzn-meet-entry__card dzn-meet-entry__brief" aria-labelledby="dzn-meet-brief-fallback-title">
				<h2 id="dzn-meet-brief-fallback-title">آماده‌سازی این کلاس</h2>
				<div class="dzn-meet-entry__brief-grid">
					<article class="dzn-meet-entry__brief-card">
						<h3>یادداشت جلسهٔ گذشته</h3>
						<p>یادداشت خصوصی این هنرجو در این لحظه در دسترس نیست. اگر یادداشتی ثبت شده باشد، با بارگذاری دوبارهٔ صفحه نمایش داده می‌شود.</p>
						<p><a class="dzn-meet-entry__text-action" href="<?php echo esc_url( $finish_url ); ?>">ثبت یادداشت و پایان کلاس</a></p>
					</article>
				</div>
			</section>
		<?php endif; ?>

		<section class="dzn-meet-entry__card dzn-meet-entry__guidance" aria-labelledby="dzn-meet-entry-guidance">
			<h2 id="dzn-meet-entry-guidance">راهنمای شروع و پایان کلاس</h2>
			<div class="dzn-meet-entry__grid">
				<article>
					<h3>اگر ورود به کلاس مشکل داشت</h3>
					<p>اگر هنرجو نتوانست وارد شود، Google Meet همین کلاس را باز کنید، پیوند نوار نشانی را کپی کنید و مستقیم از طریق واتس‌اپ برای او بفرستید. اگر پیوند کلاس برای شما یا هنرجو ساخته نشده است، با حساب Google خود یک جلسهٔ عادی Google Meet بسازید و پیوند آن را فقط برای همین کلاس از طریق واتس‌اپ بفرستید تا کلاس برگزار شود. پس از کلاس، مشکل را به دلنوازان اطلاع دهید تا بررسی و برطرف شود.</p>
				</article>
				<article>
					<h3>اگر صدا مشکل داشت</h3>
					<p>نام و جای تنظیمات در موبایل، رایانه و مرورگرها متفاوت است. در تنظیمات «صدا»، «صوت» یا «Voice»، گزینهٔ Studio sound، حذف نویز یا Noise suppression را پیدا و غیرفعال کنید؛ سپس صدا را دوباره بررسی کنید.</p>
				</article>
				<article>
					<h3>پس از پایان کلاس</h3>
					<p>پس از برگزاری، «تأیید برگزاری و حضور» را ثبت کنید. تا وقتی این کار انجام نشود، کلاس به‌عنوان برگزارشده برای پرداخت در نظر گرفته نمی‌شود و پیگیری دستی آن دشوار خواهد بود.</p>
				</article>
			</div>
		</section>
	<?php endif; ?>
</main>
