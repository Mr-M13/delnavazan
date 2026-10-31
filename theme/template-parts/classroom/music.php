<?php
/**
 * Delnavazan LiveKit classroom.
 *
 * The page carries no lesson identifier or provider credential. A short-lived signed launch
 * ticket in the URL fragment is exchanged by the browser for a room-scoped token; every exchange
 * revalidates the canonical lesson on the server.
 *
 * @package DelnavazanTheme
 */
$logo = get_theme_file_uri( 'assets/images/delnavazan-logo.png' );
?>
<main id="main-content" class="dzn-classroom" tabindex="-1" aria-labelledby="dzn-classroom-title">
	<header class="dzn-classroom__header">
		<div class="dzn-classroom__brand">
			<span class="dzn-classroom__logo-frame"><img src="<?php echo esc_url( $logo ); ?>" alt="دلنوازان" width="132" height="36"></span>
			<div>
				<p class="dzn-classroom__eyebrow">کلاس زندهٔ دلنوازان</p>
				<h1 id="dzn-classroom-title">کلاس موسیقی</h1>
			</div>
		</div>
		<div class="dzn-classroom__status-stack">
			<p id="dzn-classroom-connection" class="dzn-classroom__connection" data-state="connecting" role="status" aria-live="polite"><span class="dzn-classroom__dot" aria-hidden="true"></span><span class="dzn-classroom__connection-text">در حال آماده‌سازی کلاس…</span></p>
			<p id="dzn-classroom-lesson-status" class="dzn-classroom__lesson-status" hidden></p>
		</div>
	</header>

	<section class="dzn-classroom__meta" aria-label="اطلاعات کلاس">
		<div class="dzn-classroom__meta-main">
			<p class="dzn-classroom__course" id="dzn-classroom-course">کلاس موسیقی</p>
			<p class="dzn-classroom__people"><span id="dzn-classroom-teacher">مدرس</span><span aria-hidden="true"> · </span><span id="dzn-classroom-student">هنرجو</span></p>
		</div>
		<div class="dzn-classroom__meta-side">
			<span class="dzn-classroom__role" id="dzn-classroom-role" hidden></span>
			<span class="dzn-classroom__participants" id="dzn-classroom-participants" aria-live="polite">۰ از ۲ نفر</span>
		</div>
	</section>

	<p id="dzn-classroom-alert" class="dzn-classroom__alert" role="alert" hidden></p>

	<section class="dzn-classroom__stage" aria-label="ویدئوهای کلاس">
		<article class="dzn-classroom__tile dzn-classroom__tile--remote">
			<div id="dzn-classroom-remote-video" class="dzn-classroom__remote-media"></div>
			<div id="dzn-classroom-remote-audio" class="dzn-classroom__remote-audio"></div>
			<div class="dzn-classroom__tile-shade" aria-hidden="true"></div>
			<figure class="dzn-classroom-watermark" aria-hidden="true"><img src="<?php echo esc_url( $logo ); ?>" alt=""></figure>
			<p class="dzn-classroom__tile-placeholder" id="dzn-classroom-remote-placeholder">در انتظار ورود مدرس…</p>
			<p class="dzn-classroom__tile-label"><span class="dzn-classroom__tile-dot" aria-hidden="true"></span><span id="dzn-classroom-remote-label">شرکت‌کننده</span></p>
		</article>
		<article class="dzn-classroom__tile dzn-classroom__tile--local">
			<video id="dzn-classroom-local" autoplay muted playsinline aria-label="ویدئوی شما"></video>
			<div class="dzn-classroom__tile-shade" aria-hidden="true"></div>
			<figure class="dzn-classroom-watermark" aria-hidden="true"><img src="<?php echo esc_url( $logo ); ?>" alt=""></figure>
			<p class="dzn-classroom__tile-label"><span class="dzn-classroom__tile-dot" aria-hidden="true"></span><span id="dzn-classroom-local-label">شما</span></p>
			<p class="dzn-classroom__tile-placeholder" id="dzn-classroom-local-placeholder">دوربین خاموش است</p>
		</article>
	</section>

	<section class="dzn-classroom__controls" aria-label="کنترل‌های کلاس">
		<div class="dzn-classroom__control-group">
			<button type="button" id="dzn-classroom-mic" class="dzn-classroom__control" aria-pressed="true" disabled>
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.9V22h2v-3.1A7 7 0 0 0 19 12h-2Z"/></svg>
				<span>میکروفن روشن</span>
			</button>
			<button type="button" id="dzn-classroom-camera" class="dzn-classroom__control" aria-pressed="true" disabled>
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 6h10a2 2 0 0 1 2 2v2.5l4-2.5v8l-4-2.5V16a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z"/></svg>
				<span>دوربین روشن</span>
			</button>
		</div>
		<div class="dzn-classroom__control-group">
			<label class="dzn-classroom__switch" for="dzn-classroom-music">
				<input id="dzn-classroom-music" type="checkbox" disabled>
				<span class="dzn-classroom__switch-track" aria-hidden="true"></span>
				<span class="dzn-classroom__switch-copy"><strong>حالت موسیقی</strong><small>استریو و کیفیت بالاتر، در صورت پشتیبانی دستگاه</small></span>
			</label>
		</div>
		<div class="dzn-classroom__control-group dzn-classroom__devices">
			<label>میکروفن <select id="dzn-classroom-mic-device" disabled><option>در حال شناسایی…</option></select></label>
			<label>دوربین <select id="dzn-classroom-camera-device" disabled><option>در حال شناسایی…</option></select></label>
		</div>
		<div class="dzn-classroom__control-group">
			<button type="button" id="dzn-classroom-rotate" class="dzn-classroom__control" aria-pressed="false" title="چرخش نمایش ویدئو" disabled>
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7.1 7.1A7 7 0 1 1 5.3 13H3.2A9 9 0 1 0 6 5.2V2H4v6h6V6H7.1v1.1Z"/></svg>
				<span>چرخش نمایش</span>
			</button>
			<button type="button" id="dzn-classroom-fullscreen" class="dzn-classroom__control" aria-pressed="false" title="تمام‌صفحه" disabled>
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 9V4h5v2H6v3H4Zm16 0h-2V6h-3V4h5v5ZM4 15h2v3h3v2H4v-5Zm14 3v-3h2v5h-5v-2h3Z"/></svg>
				<span>تمام‌صفحه</span>
			</button>
		</div>
		<div class="dzn-classroom__control-group dzn-classroom__control-group--end">
			<form id="dzn-classroom-meet" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=dzn_livekit_meet_fallback' ) ); ?>" hidden>
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'dzn_livekit_meet_fallback' ) ); ?>">
				<input type="hidden" name="ticket" value="">
				<button class="dzn-classroom__control dzn-classroom__control--backup" type="submit">ورود با گوگل میت</button>
			</form>
			<button type="button" id="dzn-classroom-leave" class="dzn-classroom__control dzn-classroom__control--leave" disabled>خروج از کلاس</button>
		</div>
	</section>

	<p class="dzn-classroom__notice">حالت موسیقی تلاش می‌کند حذف نویز، کنترل خودکار صدا و حذف پژواک را خاموش کند. مرورگر یا دستگاه شما ممکن است همهٔ این تنظیمات را نپذیرد؛ صدا همچنان با کدک فشردهٔ Opus منتقل می‌شود.</p>
	<noscript><p class="dzn-classroom__alert">برای ورود به کلاس زنده، جاوااسکریپت را در مرورگر خود فعال کنید.</p></noscript>
</main>
