<?php
/**
 * Branded Delnavazan Google Meet lesson entry page.
 *
 * The page carries lesson metadata only. The Join button posts to the authenticated, nonce-protected
 * join controller, which re-proves ownership and the join window before opening the vaulted Meet URL.
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
		"SELECT l.*,t.display_name AS teacher_name,s.display_name AS student_name,c.name AS course_name
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
$has_details = ( $lesson && $schedule ) || $booking;
$owned = false;
if ( $lesson && $role === 'teacher' && (int) $lesson->teacher_id === $teacher_id ) { $owned = true; }
if ( $lesson && $role === 'student' && (int) $lesson->student_id === $student_id ) { $owned = true; }
if ( $booking && in_array( $role, array( 'teacher', 'student' ), true ) ) { $owned = true; }

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
if ( $owned && is_object( $lesson ) && (int) ( $lesson->id ?? 0 ) > 0 ) {
	$recording_available = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}meet_recording_artifacts a INNER JOIN {$p}meet_classrooms c ON c.id=a.classroom_id
		 WHERE c.lesson_id=%d AND c.active_slot=1 AND a.drive_file_id IS NOT NULL AND a.playback_verified_at IS NOT NULL LIMIT 1",
		(int) $lesson->id
	) );
}
?>
<main id="main-content" class="dzn-meet-entry" tabindex="-1">
	<header class="dzn-meet-entry__header">
		<div class="dzn-meet-entry__brand">
			<span class="dzn-meet-entry__logo"><img src="<?php echo esc_url( $logo ); ?>" alt="دلنوازان" width="132" height="36"></span>
			<div>
				<p class="dzn-meet-entry__eyebrow">کلاس زندهٔ دلنوازان</p>
				<h1>ورود به کلاس Google Meet</h1>
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

			<?php if ( $join_available ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" rel="noopener noreferrer" class="dzn-meet-entry__join">
					<input type="hidden" name="action" value="<?php echo esc_attr( $join_action ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( $join_nonce_action ) ); ?>">
					<?php if ( $is_booking ) : ?>
						<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( $confirmation_uid ); ?>">
					<?php else : ?>
						<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( $lesson_uid ); ?>">
						<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( $schedule_uid ); ?>">
					<?php endif; ?>
					<button class="dzn-meet-entry__button" type="submit" data-dzn-meet-join>ورود به کلاس</button>
					<p class="dzn-meet-entry__help">پیوند امن فقط پس از تأیید مالکیت و بازهٔ ورود در سرور باز می‌شود. Google Meet در پنجرهٔ تازه باز خواهد شد.</p>
				</form>
			<?php else : ?>
				<p class="dzn-meet-entry__notice" role="status">این کلاس برای حساب شما در دسترس نیست یا زمان ورود آن نرسیده است. برای راهنمایی با پشتیبانی دلنوازان تماس بگیرید.</p>
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
		</section>

		<section class="dzn-meet-entry__card dzn-meet-entry__guidance" aria-labelledby="dzn-meet-entry-guidance">
			<h2 id="dzn-meet-entry-guidance">راهنمای کوتاه</h2>
			<div class="dzn-meet-entry__grid">
				<article>
					<h3>پیش از ورود</h3>
					<p>میکروفن و هدفون را بررسی کنید. برای ساز، میکروفن نزدیک ساز و در فاصلهٔ ثابت باشد. استفاده از هدفون از پژواک جلوگیری می‌کند.</p>
				</article>
				<article>
					<h3>صدای ساز</h3>
					<p>در Google Meet، از منوی «تنظیمات صوتی» گزینهٔ «حذف نویز» را در صورت وجود خاموش کنید و «صدای موسیقی» یا «کیفیت اصلی» را انتخاب کنید. این تنظیمات در مرورگر و دستگاه شما در دسترس است و از API قابل کنترل نیست.</p>
				</article>
				<article>
					<h3>حریم و ضبط</h3>
					<p>ضبط کلاس فقط زمانی پیکربندی می‌شود که رضایت هنرجو و پذیرش شرایط مدرس معتبر باشد. فایل ضبط در Drive سازمانی دلنوازان نگه‌داری می‌شود و پخش آن فقط از همین پرتال انجام می‌شود.</p>
				</article>
				<article>
					<h3>موبایل و تبلت</h3>
					<p>برنامهٔ Google Meet را به‌روز نگه دارید. اگر پیوند در مرورگر باز نشد، همان پیوند را در برنامهٔ Meet باز کنید یا از دکمهٔ ورود دوباره استفاده کنید.</p>
				</article>
			</div>
			<p class="dzn-meet-entry__help">مدرس به‌عنوان میزبان مشترک و هنرجو به‌عنوان شرکت‌کنندهٔ عادی وارد می‌شود. میزبان اصلی لازم نیست در جلسه حاضر باشد.</p>
		</section>
	<?php endif; ?>
</main>
