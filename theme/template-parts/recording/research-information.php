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
		foreach ( array( 'teacher_terms' => 'Teacher recorded-work terms · شرایط کار ضبط‌شدهٔ مدرس', 'teacher_lesson_recording' => 'Teacher recording · ضبط مدرس', 'teacher_research_model_training' => 'Teacher research · پژوهش مدرس', 'student_lesson_recording' => 'Student recording · ضبط هنرجو', 'student_research_model_training' => 'Student research · پژوهش هنرجو', 'privacy_terms' => 'Privacy · حریم خصوصی' ) as $audience => $label ) {
			foreach ( array( 'en', 'fa' ) as $language ) {
				$notice = $service->publishedNotice( $audience, $language );
				if ( is_array( $notice ) ) { $notices[] = array_merge( $notice, array( 'label' => $label ) ); }
			}
		}
	} catch ( Throwable ) { $notices = array(); }
}
$language_order = array( 'fa', 'en' );
?>
<main id="main-content" class="site-main dzn-container dzn-recording-info dzn-project" tabindex="-1">
	<header class="dzn-project__masthead" lang="fa" dir="rtl">
		<p class="dzn-eyebrow">دلنوازان · موسیقی ایرانی، آموزش و پژوهش</p>
		<h1 class="dzn-project__label">پروژه پژوهشی</h1>
		<p class="dzn-project__descriptor">از تجربهٔ آموزش امروز، برای یادگیری فردا</p>
	</header>
	<?php get_template_part( 'template-parts/recording/name-suggestion' ); ?>

	<?php foreach ( $language_order as $language ) : $copy = $project_copy[ $language ]; ?>
	<?php if ( 'en' === $language ) : ?>
	<details id="project-en" class="dzn-project__language" lang="en" dir="ltr">
		<summary>English translation <span>Research Project</span></summary>
	<?php else : ?>
	<article id="project-fa" class="dzn-project__primary" lang="fa" dir="rtl" aria-labelledby="project-fa-title">
	<?php endif; ?>
		<div class="dzn-project__document">
			<div class="dzn-project__hero">
				<p class="dzn-eyebrow"><?php echo esc_html( $copy['intro'] ); ?></p>
				<h2 id="project-<?php echo esc_attr( $language ); ?>-title"><?php echo esc_html( $copy['title'] ); ?></h2>
				<p class="dzn-project__lead"><?php echo esc_html( $copy['lead'] ); ?></p>
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
					<a href="<?php echo esc_url( home_url( '/recording-and-ai-policy/' ) ); ?>"><?php echo esc_html( $copy['recording_policy'] ); ?></a>
					<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>"><?php echo esc_html( $copy['privacy'] ); ?></a>
					<a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>"><?php echo esc_html( $copy['terms'] ); ?></a>
					<a href="<?php echo esc_url( home_url( '/student-portal/?portal-view=account' ) ); ?>"><?php echo esc_html( $copy['account'] ); ?></a>
					<a href="<?php echo esc_url( home_url( '/teacher-portal/?teacher-view=onboarding' ) ); ?>"><?php echo esc_html( $copy['onboarding'] ); ?></a>
				</div>
			</aside>
			<details class="dzn-recording-info__notices">
				<summary><?php echo esc_html( $copy['notices'] ); ?></summary>
				<?php if ( $notices ) : ?><p><?php echo esc_html( $copy['notice_note'] ); ?></p><ul>
					<?php foreach ( $notices as $notice ) : if ( $language !== ( $notice['language'] ?? '' ) ) { continue; } ?><li><?php echo esc_html( (string) $notice['label'] . ' · ' . (string) ( $notice['language'] ?? '' ) . ' · v' . (string) ( $notice['version'] ?? '' ) ); ?></li><?php endforeach; ?>
				</ul><?php else : ?><p><?php echo esc_html( $copy['no_notices'] ); ?></p><?php endif; ?>
			</details>
		</div>
	<?php if ( 'en' === $language ) : ?></details><?php else : ?></article><?php endif; ?>
	<?php endforeach; ?>

	<?php while ( have_posts() ) : the_post(); ?>
		<?php if ( '' !== trim( (string) get_the_content() ) ) : ?><section class="dzn-recording-info__editor" aria-label="Additional published information"><?php the_content(); ?></section><?php endif; ?>
	<?php endwhile; ?>
	<p class="dzn-project__footer-note" lang="en" dir="ltr">Research Project · Delnavazan</p>
</main>
