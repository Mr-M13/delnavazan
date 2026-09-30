<?php
/**
 * Page template.
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
			array( 'mode' => dzn_theme_content_page_mode() )
		);
		?>
		<?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>
	<?php endwhile; ?>
</main>
<?php
get_footer();
