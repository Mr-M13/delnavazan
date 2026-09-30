<?php
/**
 * Related articles from existing WordPress data.
 *
 * Presentation only: published posts that share at least one category with the current post, in the
 * theme's existing card language. No custom field, taxonomy or query is introduced.
 *
 * @package DelnavazanTheme
 */

$dzn_related_categories = wp_get_post_categories( get_the_ID() );

if ( ! $dzn_related_categories ) {
	return;
}

$dzn_related = new WP_Query(
	array(
		'category__in'        => $dzn_related_categories,
		'post__not_in'        => array( get_the_ID() ),
		'posts_per_page'      => 3,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	)
);

if ( ! $dzn_related->have_posts() ) {
	return;
}
?>
<section class="dzn-document__related" aria-labelledby="dzn-related-title">
	<h2 class="dzn-document__related-title" id="dzn-related-title">
		<?php esc_html_e( 'مطالب مرتبط', 'delnavazan-theme' ); ?>
	</h2>
	<ul class="dzn-document__related-list">
		<?php while ( $dzn_related->have_posts() ) : ?>
			<?php $dzn_related->the_post(); ?>
			<li class="dzn-document__related-item">
				<a class="dzn-document__related-link" href="<?php the_permalink(); ?>">
					<?php the_title(); ?>
				</a>
			</li>
		<?php endwhile; ?>
	</ul>
</section>
<?php
wp_reset_postdata();
