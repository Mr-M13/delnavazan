<?php
/**
 * Post card.
 *
 * @package DelnavazanTheme
 */
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'dzn-card dzn-card--article' ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a class="dzn-card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); ?>
		</a>
	<?php endif; ?>
	<div class="dzn-card__body">
		<p class="dzn-card__meta"><?php dzn_theme_posted_on(); ?></p>
		<h2 class="dzn-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<div class="dzn-card__excerpt"><?php the_excerpt(); ?></div>
	</div>
</article>
