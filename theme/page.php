<?php
/**
 * Page template.
 *
 * The Articles page renders the editorial index from the theme instead of the
 * authored body, so it branches before the generic document layout. Its template
 * lives in template-parts because the release package ships that directory whole.
 *
 * @package DelnavazanTheme
 */

get_header();

if ( is_page( 'articles' ) ) {
	get_template_part( 'template-parts/content/editorial-index' );
} else {
	?>
	<main id="main-content" class="site-main dzn-container dzn-document-layout" tabindex="-1">
		<?php while ( have_posts() ) : the_post(); ?>
			<?php
			get_template_part(
				'template-parts/content/content',
				'document',
				array( 'mode' => dzn_theme_content_page_mode() )
			);
			?>
			<?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>
		<?php endwhile; ?>
	</main>
	<?php
}

get_footer();
