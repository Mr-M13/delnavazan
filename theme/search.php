<?php
/**
 * Search results template.
 *
 * @package DelnavazanTheme
 */

get_header();
?>
<main id="main-content" class="site-main dzn-container" tabindex="-1">
	<header class="archive-header">
		<h1><?php printf( esc_html__( 'نتایج جست‌وجو برای: %s', 'delnavazan-theme' ), '<span>' . esc_html( get_search_query() ) . '</span>' ); ?></h1>
	</header>
	<?php if ( have_posts() ) : ?>
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
