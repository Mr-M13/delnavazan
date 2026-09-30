<?php
/**
 * Not-found template.
 *
 * @package DelnavazanTheme
 */

get_header();
?>
<main id="main-content" class="site-main dzn-container" tabindex="-1">
	<section class="dzn-state dzn-state--empty" aria-labelledby="not-found-title">
		<p class="dzn-state__eyebrow">۴۰۴</p>
		<h1 id="not-found-title"><?php esc_html_e( 'این صفحه پیدا نشد.', 'delnavazan-theme' ); ?></h1>
		<p><?php esc_html_e( 'جست‌وجو کنید یا به صفحهٔ اصلی بازگردید.', 'delnavazan-theme' ); ?></p>
		<?php get_search_form(); ?>
		<a class="dzn-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'بازگشت به خانه', 'delnavazan-theme' ); ?></a>
	</section>
</main>
<?php
get_footer();
