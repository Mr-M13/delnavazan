<?php
/**
 * Template Name: سیاست — Policy (Persian RTL)
 * Template Post Type: page
 *
 * Restrained policy document presentation: publication and revision metadata, the reusable document
 * column, a deterministic table of contents and print support. No promotional treatment, no related
 * content and no featured-image treatment.
 *
 * This is a standard WordPress page template. It introduces no bespoke authoring workflow, no custom
 * field and no content lock-in: selecting it only changes presentation.
 *
 * @package DelnavazanTheme
 */

get_header();
?>
<main id="main-content" class="site-main dzn-container dzn-document-layout" tabindex="-1">
	<?php while ( have_posts() ) : the_post(); ?>
		<?php
		get_template_part(
			'template-parts/content/content',
			'document',
			array( 'mode' => 'policy' )
		);
		?>
		<?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>
	<?php endwhile; ?>
</main>
<?php
get_footer();
