<?php
/**
 * Shared public recording/research information body.
 *
 * Rendered by the Page Template and by the public virtual route so the
 * information links never depend on an operator creating a page first.
 *
 * @package DelnavazanTheme
 */

$project_url = (string) apply_filters( 'dzn_recording_project_information_url', '' );
$notices = array();
$authority = '\\Delnavazan\\Platform\\Core\\Application\\RecordingDecisionAuthorityService';
if ( class_exists( $authority ) ) {
	try {
		$service = new $authority();
		foreach ( array( 'teacher_terms' => 'Teacher terms', 'student_lesson_recording' => 'Student recording', 'student_research_model_training' => 'Student research' ) as $audience => $label ) {
			foreach ( array( 'en', 'fa' ) as $language ) {
				$notice = $service->publishedNotice( $audience, $language );
				if ( is_array( $notice ) ) { $notices[] = array_merge( $notice, array( 'label' => $label ) ); }
			}
		}
	} catch ( Throwable ) {
		$notices = array();
	}
}
?>
<main id="main-content" class="site-main dzn-container dzn-recording-info" tabindex="-1">
	<header class="dzn-recording-info__heading">
		<p class="dzn-eyebrow">Delnavazan · دلنوازان</p>
		<h1>Recording and research information<br><span lang="fa" dir="rtl">اطلاعات ضبط کلاس و پژوهش</span></h1>
		<p>Recording and research export are currently disabled. No lesson capture provider is connected and no recording is being collected through this page.</p>
	</header>

	<?php while ( have_posts() ) : the_post(); ?>
		<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
			<section class="dzn-recording-info__editor" aria-label="Editor-managed information">
				<?php the_content(); ?>
			</section>
		<?php endif; ?>
	<?php endwhile; ?>

	<div class="dzn-recording-info__grid">
		<section aria-labelledby="dzn-recording-info-en">
			<h2 id="dzn-recording-info-en" lang="en" dir="ltr">English</h2>
			<h3>What future lesson recording may involve</h3>
			<p>Delnavazan plans to record its own future online video lessons, including the teacher and student, to improve Persian music teaching and learning. This page describes a planned, disabled capability; it does not announce that recording is active.</p>
			<h3>Why Delnavazan collects data</h3>
			<p>Eligible lesson recordings and related learning data may be used to improve teaching and learning. Any research or model-training use is a separate, optional choice and is never implied by a lesson-recording choice.</p>
			<h3>Separate optional research and model-training programme</h3>
			<p>Research participation is optional. A student may separately choose to contribute eligible recordings and related learning data to Persian music research and to the development of future learning-support tools. Recording permission does not create research participation, and lack of a recording opt-out does not create research participation.</p>
			<h3>How choices can be changed</h3>
			<p>Adult students can change their recording and research choices in Account Settings. Teachers accept recording-related terms during teacher onboarding. A new choice is recorded as later evidence; earlier decisions are never rewritten.</p>
			<h3>Expected future Mbox local processing</h3>
			<p>Raw recordings are expected to be stored and processed later on the local Mbox hard drive under an approved retention and deletion process. No Mbox transfer, processing, deletion worker or provider capture is implemented or active in this release.</p>
			<h3>Current status</h3>
			<p>Recording capture and research export are currently disabled. A future legally approved default-on recording policy is not a choice or permission in this release and remains unavailable until separately approved legal-policy evidence exists.</p>
			<h3>Project information</h3>
			<?php if ( '' !== $project_url ) : ?>
				<p><a href="<?php echo esc_url( $project_url ); ?>">Project information page</a></p>
			<?php else : ?>
				<p><span class="dzn-recording-info__placeholder" aria-disabled="true">Project information page — link pending approval.</span></p>
			<?php endif; ?>
		</section>

		<section aria-labelledby="dzn-recording-info-fa" lang="fa" dir="rtl">
			<h2 id="dzn-recording-info-fa">فارسی</h2>
			<h3>ضبط درس‌های آینده چه چیزی را در بر می‌گیرد</h3>
			<p>دلنوازان قصد دارد درس‌های ویدیویی آنلاین آیندهٔ خود را شامل مدرس و هنرجو ضبط کند تا آموزش و یادگیری موسیقی ایرانی بهبود یابد. این صفحه یک قابلیت برنامه‌ریزی‌شده و غیرفعال را توضیح می‌دهد و به معنای فعال بودن ضبط نیست.</p>
			<h3>چرا دلنوازان داده جمع‌آوری می‌کند</h3>
			<p>ضبط‌های واجد شرایط و داده‌های یادگیری مرتبط ممکن است برای بهبود آموزش و یادگیری استفاده شوند. هر استفادهٔ پژوهشی یا آموزش مدل، انتخابی جدا و اختیاری است و هرگز از انتخاب ضبط کلاس نتیجه نمی‌شود.</p>
			<h3>برنامهٔ جدا و اختیاری پژوهش و آموزش مدل</h3>
			<p>مشارکت در پژوهش اختیاری است. هنرجو می‌تواند جداگانه انتخاب کند که ضبط‌های واجد شرایط و داده‌های یادگیری مرتبط را به پژوهش موسیقی ایرانی و توسعهٔ ابزارهای آیندهٔ پشتیبانی یادگیری کمک کند. اجازهٔ ضبط، مشارکت پژوهشی ایجاد نمی‌کند و نبودِ مخالفت با ضبط نیز مشارکت پژوهشی ایجاد نمی‌کند.</p>
			<h3>نحوهٔ تغییر انتخاب‌ها</h3>
			<p>هنرجویان بزرگسال می‌توانند انتخاب ضبط و پژوهش را در تنظیمات حساب تغییر دهند. مدرسان شرایط مربوط به ضبط را در فرایند راه‌اندازی حساب مدرس می‌پذیرند. انتخاب تازه به‌صورت شواهد بعدی ثبت می‌شود و تصمیم‌های قبلی هرگز بازنویسی نمی‌شوند.</p>
			<h3>پردازش محلی آینده روی Mbox</h3>
			<p>انتظار می‌رود ضبط‌های خام بعداً روی هارد Mbox و تحت فرایند تأییدشدهٔ نگهداری و حذف ذخیره و پردازش شوند. هیچ انتقال، پردازش، کارگر حذف یا ضبط از طریق ارائه‌دهنده در این نسخه پیاده‌سازی یا فعال نشده است.</p>
			<h3>وضعیت کنونی</h3>
			<p>ضبط و خروجی پژوهش در حال حاضر غیرفعال است. مدل پیش‌فرضِ روشنِ ضبط، حتی اگر بعداً از نظر حقوقی تأیید شود، در این نسخه انتخاب یا اجازه محسوب نمی‌شود و تا وجود شواهد سیاست حقوقی تأییدشده در دسترس نیست.</p>
			<h3>اطلاعات پروژه</h3>
			<?php if ( '' !== $project_url ) : ?>
				<p><a href="<?php echo esc_url( $project_url ); ?>">صفحهٔ اطلاعات پروژه</a></p>
			<?php else : ?>
				<p><span class="dzn-recording-info__placeholder" aria-disabled="true">صفحهٔ اطلاعات پروژه — پیوند پس از تأیید افزوده می‌شود.</span></p>
			<?php endif; ?>
		</section>
	</div>

	<section class="dzn-recording-info__notices" aria-labelledby="dzn-recording-info-notices">
		<h2 id="dzn-recording-info-notices">Published notices · اطلاعیه‌های منتشرشده</h2>
		<?php if ( $notices ) : ?>
			<ul>
				<?php foreach ( $notices as $notice ) : ?>
					<li><strong><?php echo esc_html( (string) ( $notice['label'] ?? '' ) ); ?></strong> · <?php echo esc_html( 'fa' === ( $notice['language'] ?? 'en' ) ? 'فارسی' : 'English' ); ?> · version <?php echo esc_html( (string) ( $notice['version'] ?? '' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p>No approved recording or research notice is published yet. Enrolment and Account Settings will show an unavailable state and will not create a decision.</p>
			<p>هنوز اطلاعیهٔ تأییدشده‌ای برای ضبط یا پژوهش منتشر نشده است. صفحهٔ ثبت‌نام و تنظیمات حساب وضعیت «در دسترس نیست» را نشان می‌دهند و هیچ تصمیمی ایجاد نمی‌کنند.</p>
		<?php endif; ?>
	</section>

	<p class="dzn-recording-info__actions">
		<a class="dzn-button" href="<?php echo esc_url( home_url( '/student-portal/?portal-view=account' ) ); ?>">Student Account Settings · تنظیمات حساب هنرجو</a>
		<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( home_url( '/teacher-portal/?teacher-view=onboarding' ) ); ?>">Teacher onboarding · راه‌اندازی مدرس</a>
	</p>
</main>
