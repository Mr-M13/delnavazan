<?php
/**
 * Fallback index.
 *
 * @package DelnavazanTheme
 */

get_header();
?>
<main id="main-content" class="site-main dzn-container" tabindex="-1">
	<?php if ( have_posts() ) : ?>
		<header class="archive-header">
			<h1><?php single_post_title(); ?></h1>
		</header>
		<div class="dzn-card-grid">
			<?php while ( have_posts() ) : the_post(); ?>
				<?php get_template_part( 'template-parts/content/content' ); ?>
			<?php endwhile; ?>
		</div>
		<?php the_posts_navigation(); ?>
	<?php else : ?>
		<?php get_template_part( 'template-parts/content/content', 'none' ); ?>
	<?php endif; ?>
</main>
<?php
get_footer();
