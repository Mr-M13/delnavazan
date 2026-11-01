<?php
/**
 * Public Project explanation, shared by the canonical virtual route and Page Template.
 * Presentation only: this page does not grant permissions or activate recording.
 *
 * @package DelnavazanTheme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$project_copy = require __DIR__ . '/project-copy.php';
$notices = array();
$authority = \Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService::class;
if ( class_exists( $authority ) ) {
	try {
		$service = new $authority();
		foreach ( array( 'teacher_terms' => 'Teacher terms · شرایط مدرس', 'student_lesson_recording' => 'Student recording · ضبط هنرجو', 'student_research_model_training' => 'Student research · پژوهش هنرجو' ) as $audience => $label ) {
			foreach ( array( 'en', 'fa' ) as $language ) {
				$notice = $service->publishedNotice( $audience, $language );
				if ( is_array( $notice ) ) { $notices[] = array_merge( $notice, array( 'label' => $label ) ); }
			}
		}
	} catch ( Throwable ) { $notices = array(); }
}
$primary_language = is_rtl() ? 'fa' : 'en';
$language_order = 'fa' === $primary_language ? array( 'fa', 'en' ) : array( 'en', 'fa' );
$sources = array(
	array( 'UNESCO — Radif of Iranian music', 'https://ich.unesco.org/en/RL/radif-of-iranian-music-00279', 'Cultural heritage and master-to-disciple transmission · میراث فرهنگی و انتقال استاد به شاگرد' ),
	array( 'PeMPLab — Nava Dataset', 'https://navaalab.github.io/resources/dataset/nava.html', 'Dataset scope, approximate duration and research-access conditions · دامنه و شرایط دسترسی مجموعهٔ نوا' ),
	array( 'Kunstuniversität Graz — Corpus-Based Research on Persian Music / KDC', 'https://ethnomusikologie.kug.ac.at/en/projects/persian-music', 'Professional performance corpus and musician involvement · مجموعهٔ اجراهای حرفه‌ای با مشارکت موسیقی‌دانان' ),
	array( 'OAIC — APP 11: Security of personal information', 'https://www.oaic.gov.au/privacy/australian-privacy-principles/australian-privacy-principles-guidelines/chapter-11-app-11-security-of-personal-information', 'Retention, destruction and de-identification guidance · راهنمای نگهداری، حذف و ناشناس‌سازی' ),
	array( 'Creative Commons — Attribution 4.0 International', 'https://creativecommons.org/licenses/by/4.0/', 'Commercial reuse, attribution, irrevocability and rights limitations · استفادهٔ تجاری، ذکر منبع و حدود مجوز' ),
	array( 'OAIC — Key concepts: consent and de-identification', 'https://www.oaic.gov.au/privacy/australian-privacy-principles/australian-privacy-principles-guidelines/chapter-b-key-concepts', 'Voluntary, informed choices and withdrawal · انتخاب آگاهانه و اختیاری و انصراف' ),
);
?>
<main id="main-content" class="site-main dzn-container dzn-recording-info dzn-project" tabindex="-1">
	<header class="dzn-project__masthead">
		<p class="dzn-eyebrow" dir="ltr">DELNAVAZAN / THE PROJECT</p>
		<p class="dzn-project__label">پروژه · The Project</p>
		<p class="dzn-project__descriptor">موسیقی ایرانی، آموزش و پژوهش · Persian music, learning and research</p>
		<p class="dzn-project__language-hint"><a href="#project-fa-title" lang="fa" dir="rtl">نسخهٔ فارسی</a><span aria-hidden="true"> / </span><a href="#project-en-title" lang="en" dir="ltr">Read in English</a></p>
	</header>

	<?php foreach ( $language_order as $language ) : $copy = $project_copy[ $language ]; ?>
	<details id="project-<?php echo esc_attr( $language ); ?>" class="dzn-project__language" lang="<?php echo esc_attr( $language ); ?>" dir="<?php echo 'fa' === $language ? 'rtl' : 'ltr'; ?>" <?php echo $language === $primary_language ? 'open' : ''; ?>>
		<summary><?php echo esc_html( $copy['language'] ); ?><span><?php echo esc_html( $copy['intro'] ); ?></span></summary>
		<div class="dzn-project__document">
			<div class="dzn-project__hero">
				<p class="dzn-eyebrow"><?php echo esc_html( $copy['intro'] ); ?></p>
				<h1 id="project-<?php echo esc_attr( $language ); ?>-title"><?php echo esc_html( $copy['title'] ); ?></h1>
				<p class="dzn-project__lead"><?php echo esc_html( $copy['lead'] ); ?></p>
				<p class="dzn-project__status"><?php echo esc_html( $copy['status'] ); ?></p>
			</div>
			<nav class="dzn-project__nav" aria-label="<?php echo esc_attr( 'fa' === $language ? 'بخش‌های پروژه' : 'Project sections' ); ?>">
				<?php foreach ( $copy['nav'] as $anchor => $label ) : ?>
					<a href="#project-<?php echo esc_attr( $language . '-' . $anchor ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php foreach ( $copy['sections'] as $section ) : ?>
			<section id="project-<?php echo esc_attr( $language . '-' . $section['id'] ); ?>" class="dzn-project__section" aria-labelledby="project-title-<?php echo esc_attr( $language . '-' . $section['id'] ); ?>">
				<div class="dzn-project__section-heading">
					<p class="dzn-eyebrow"><?php echo esc_html( $section['eyebrow'] ); ?></p>
					<h2 id="project-title-<?php echo esc_attr( $language . '-' . $section['id'] ); ?>"><?php echo esc_html( $section['title'] ); ?></h2>
				</div>
				<div class="dzn-project__section-body">
					<?php foreach ( $section['paragraphs'] ?? array() as $paragraph ) : ?><p><?php echo wp_kses_post( $paragraph ); ?></p><?php endforeach; ?>
					<?php if ( ! empty( $section['steps'] ) ) : ?>
					<ol class="dzn-project__steps">
						<?php foreach ( $section['steps'] as $step ) : ?><li><h3><?php echo esc_html( $step[0] ); ?></h3><p><?php echo esc_html( $step[1] ); ?></p></li><?php endforeach; ?>
					</ol>
					<?php endif; ?>
					<?php foreach ( $section['faq'] ?? array() as $faq ) : ?>
					<details class="dzn-project__faq"><summary><?php echo esc_html( $faq[0] ); ?></summary><p><?php echo esc_html( $faq[1] ); ?></p></details>
					<?php endforeach; ?>
					<?php if ( 'join' === $section['id'] ) : ?><p><a class="dzn-button" href="<?php echo esc_url( dzn_theme_brand_identity()['support_mailto'] ); ?>"><?php echo esc_html( $copy['contact'] ); ?></a></p><?php endif; ?>
				</div>
			</section>
			<?php endforeach; ?>
			<aside class="dzn-project__policy" aria-label="<?php echo esc_attr( $copy['policy'] ); ?>">
				<h2><?php echo esc_html( $copy['policy'] ); ?></h2>
				<div class="dzn-recording-info__actions">
					<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>"><?php echo esc_html( $copy['privacy'] ); ?></a>
					<a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>"><?php echo esc_html( $copy['terms'] ); ?></a>
					<a href="<?php echo esc_url( home_url( '/student-portal/?portal-view=account' ) ); ?>"><?php echo esc_html( $copy['account'] ); ?></a>
					<a href="<?php echo esc_url( home_url( '/teacher-portal/?teacher-view=onboarding' ) ); ?>"><?php echo esc_html( $copy['onboarding'] ); ?></a>
				</div>
			</aside>
			<section class="dzn-recording-info__notices" aria-labelledby="project-notices-<?php echo esc_attr( $language ); ?>">
				<h2 id="project-notices-<?php echo esc_attr( $language ); ?>"><?php echo esc_html( $copy['notices'] ); ?></h2>
				<?php if ( $notices ) : ?><p><?php echo esc_html( $copy['notice_note'] ); ?></p><ul>
					<?php foreach ( $notices as $notice ) : ?><li><?php echo esc_html( (string) $notice['label'] . ' · ' . (string) ( $notice['language'] ?? '' ) . ' · v' . (string) ( $notice['version'] ?? '' ) ); ?></li><?php endforeach; ?>
				</ul><?php else : ?><p><?php echo esc_html( $copy['no_notices'] ); ?></p><?php endif; ?>
			</section>
		</div>
	</details>
	<?php endforeach; ?>

	<section class="dzn-project__sources" lang="en" dir="ltr" aria-labelledby="project-sources-title">
		<h2 id="project-sources-title">Sources and further reading <span lang="fa" dir="rtl">· منابع و مطالعهٔ بیشتر</span></h2>
		<p><?php echo esc_html( $project_copy['en']['reference_note'] ); ?></p>
		<p lang="fa" dir="rtl"><?php echo esc_html( $project_copy['fa']['reference_note'] ); ?></p>
		<ol>
			<?php foreach ( $sources as $index => $source ) : ?><li id="project-source-<?php echo (int) $index + 1; ?>"><a href="<?php echo esc_url( $source[1] ); ?>"><?php echo esc_html( $source[0] ); ?></a><p><?php echo esc_html( $source[2] ); ?></p></li><?php endforeach; ?>
		</ol>
	</section>
	<?php while ( have_posts() ) : the_post(); ?>
		<?php if ( '' !== trim( (string) get_the_content() ) ) : ?><section class="dzn-recording-info__editor" aria-label="Additional published information"><?php the_content(); ?></section><?php endif; ?>
	<?php endwhile; ?>
	<p class="dzn-project__footer-note" lang="en" dir="ltr">Recording and research information · The Project · Delnavazan</p>
</main>
