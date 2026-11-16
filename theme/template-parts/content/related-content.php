<?php
/**
 * Related articles using existing post categories.
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
	<div class="dzn-editorial-section-heading">
		<div><span class="dzn-editorial-eyebrow"><?php esc_html_e( 'برای ادامهٔ مطالعه', 'delnavazan-theme' ); ?></span><h2 class="dzn-document__related-title" id="dzn-related-title"><?php esc_html_e( 'مقاله‌های مرتبط', 'delnavazan-theme' ); ?></h2></div>
		<a class="dzn-document__related-all" href="<?php echo esc_url( home_url( '/articles/' ) ); ?>"><?php esc_html_e( 'همهٔ مقاله‌ها', 'delnavazan-theme' ); ?> ←</a>
	</div>
	<div class="dzn-document__related-list">
		<?php while ( $dzn_related->have_posts() ) : $dzn_related->the_post(); ?>
			<?php get_template_part( 'template-parts/content/editorial-card' ); ?>
		<?php endwhile; ?>
	</div>
</section>
<?php wp_reset_postdata(); ?>
