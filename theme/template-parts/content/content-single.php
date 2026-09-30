<?php
/**
 * Single article content.
 *
 * @package DelnavazanTheme
 */
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry entry--single' ); ?>>
	<header class="entry-header dzn-article-header">
		<p class="entry-meta"><?php dzn_theme_posted_on(); ?></p>
		<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
	</header>
	<?php if ( has_post_thumbnail() ) : ?>
		<figure class="entry-featured-image"><?php the_post_thumbnail( 'large' ); ?></figure>
	<?php endif; ?>
	<div class="entry-content dzn-prose">
		<?php the_content(); ?>
	</div>
</article>
