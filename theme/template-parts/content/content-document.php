<?php
/**
 * Reusable Article / Policy / General document presentation.
 *
 * One template renders every content-page mode; the mode selects metadata, media treatment, related
 * content and the print posture. The body is rendered through `the_content()`, and the table of
 * contents is derived from the exact same anchored outline.
 *
 * @package DelnavazanTheme
 *
 * @param array $args {
 *     @type string $mode Presentation mode: `article`, `policy` or `general`.
 * }
 */

$dzn_document_mode         = isset( $args['mode'] ) ? (string) $args['mode'] : dzn_theme_content_page_mode();
$dzn_document_presentation = dzn_theme_content_page_presentation( $dzn_document_mode );
$dzn_document_data         = dzn_theme_content_page_data();
$dzn_document_sections     = $dzn_document_data['sections'];
$dzn_document_is_article   = 'article' === $dzn_document_presentation['mode'];
$dzn_document_modified     = get_the_modified_date( DATE_W3C );
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry entry--document ' . $dzn_document_presentation['document_class'] ); ?>>
	<div class="dzn-document" data-dzn-document-mode="<?php echo esc_attr( $dzn_document_presentation['mode'] ); ?>">
		<header class="entry-header dzn-document__header">
			<?php if ( $dzn_document_presentation['show_publication'] ) : ?>
				<p class="entry-meta dzn-document__meta">
					<?php if ( $dzn_document_presentation['show_categories'] && has_category() ) : ?>
						<span class="dzn-document__categories"><?php the_category( '، ' ); ?></span>
					<?php endif; ?>
					<?php dzn_theme_posted_on(); ?>
					<?php if ( $dzn_document_presentation['show_reading_time'] && (int) $dzn_document_data['minutes'] > 0 ) : ?>
						<span class="dzn-document__reading-time">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: reading time in whole minutes. */
									__( '٪s دقیقه مطالعه', 'delnavazan-theme' ),
									number_format_i18n( (int) $dzn_document_data['minutes'] )
								)
							);
							?>
						</span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( $dzn_document_is_article || ! dzn_theme_content_has_h1() ) : ?>
				<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
			<?php endif; ?>
			<?php if ( 'policy' === $dzn_document_presentation['mode'] && $dzn_document_modified ) : ?>
				<p class="dzn-document__revision">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: last revision date. */
							__( 'آخرین بازنگری: %s', 'delnavazan-theme' ),
							get_the_modified_date()
						)
					);
					?>
				</p>
			<?php endif; ?>
		</header>
		<?php if ( $dzn_document_presentation['show_featured_image'] && has_post_thumbnail() ) : ?>
			<figure class="entry-featured-image dzn-document__featured-image"><?php the_post_thumbnail( 'large' ); ?></figure>
		<?php endif; ?>
		<div class="dzn-document__body">
			<?php if ( $dzn_document_data['requires_toc'] ) : ?>
				<div class="dzn-document__outline">
					<?php
					get_template_part(
						'template-parts/content/table-of-contents',
						null,
						array( 'sections' => $dzn_document_sections, 'variant' => 'desktop' )
					);
					?>
				</div>
			<?php endif; ?>
			<div class="dzn-document__content">
				<?php if ( $dzn_document_data['requires_toc'] ) : ?>
					<?php
					get_template_part(
						'template-parts/content/table-of-contents',
						null,
						array( 'sections' => $dzn_document_sections, 'variant' => 'mobile' )
					);
					?>
				<?php endif; ?>
				<div class="entry-content dzn-prose">
					<?php if ( $dzn_document_data['paginated'] ) : ?>
						<?php
						// Paginated documents keep core page splitting exactly as-is: no injected
						// anchors, no generated outline, and native reader navigation below.
						the_content();
						?>
					<?php else : ?>
						<?php
						// The anchored rendering of the canonical content pipeline. Anchoring is scoped
						// to this template, so no other surface on the site is mutated.
						echo $dzn_document_data['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered post content from the canonical pipeline.
						?>
					<?php endif; ?>
				</div>
				<?php
				wp_link_pages(
					array(
						'before'           => '<nav class="dzn-document__pagination" aria-label="' . esc_attr__( 'صفحه‌های این سند', 'delnavazan-theme' ) . '">',
						'after'            => '</nav>',
						'link_before'      => '<span class="dzn-document__page">',
						'link_after'       => '</span>',
						'next_or_number'   => 'number',
						'separator'        => ' ',
						'nextpagelink'     => esc_html__( 'صفحهٔ بعد', 'delnavazan-theme' ),
						'previouspagelink' => esc_html__( 'صفحهٔ قبل', 'delnavazan-theme' ),
					)
				);
				?>
				<?php if ( $dzn_document_presentation['show_print_hint'] ) : ?>
					<p class="dzn-document__print-hint">
						<?php esc_html_e( 'این سند برای چاپ آماده است؛ می‌توانید از حالت چاپ مرورگر استفاده کنید.', 'delnavazan-theme' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $dzn_document_presentation['show_related'] ) : ?>
			<?php get_template_part( 'template-parts/content/related-content' ); ?>
		<?php endif; ?>
		<?php if ( $dzn_document_presentation['show_previous_next'] ) : ?>
			<footer class="dzn-document__footer"><?php the_post_navigation(); ?></footer>
		<?php endif; ?>
	</div>
</article>
