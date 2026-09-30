<?php
/**
 * Canonical Delnavazan front page.
 *
 * The homepage is a designed product surface, not an editable staging blog
 * page. WordPress remains the CMS for content elsewhere, while the canonical
 * homepage composition lives with the Theme so staging and production cannot
 * silently drift to starter or legacy page content.
 *
 * @package DelnavazanTheme
 */

get_header();
?>
<main id="main-content" class="site-main site-main--front" tabindex="-1">
	<article class="entry entry--front dzn-home">
		<div class="entry-content">
			<?php dzn_theme_render_canonical_homepage(); ?>
		</div>
	</article>
</main>
<?php
get_footer();
