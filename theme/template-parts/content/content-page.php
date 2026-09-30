<?php
/**
 * Page content.
 *
 * @package DelnavazanTheme
 */
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry entry--page' ); ?>>
	<?php if ( ! dzn_theme_content_has_h1() ) : ?>
		<header class="entry-header">
			<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
		</header>
	<?php endif; ?>
	<div class="entry-content">
		<?php the_content(); ?>
	</div>
</article>
