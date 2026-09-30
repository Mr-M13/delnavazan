<?php
/**
 * Reusable document outline.
 *
 * Renders the same outline twice on purpose: a sticky desktop navigation and a native, keyboard
 * friendly <details> disclosure for small screens. No JavaScript is required, and the hidden variant
 * is removed from the accessibility tree by `display: none` at that breakpoint.
 *
 * @package DelnavazanTheme
 *
 * @param array $args {
 *     @type array  $sections Outline sections with `level`, `anchor` and `text`.
 *     @type string $variant  Either `desktop` or `mobile`.
 * }
 */

$dzn_toc_sections = isset( $args['sections'] ) && is_array( $args['sections'] ) ? $args['sections'] : array();
$dzn_toc_variant  = isset( $args['variant'] ) && 'mobile' === $args['variant'] ? 'mobile' : 'desktop';

if ( ! $dzn_toc_sections ) {
	return;
}

$dzn_toc_title_id = 'dzn-toc-title-' . $dzn_toc_variant;
$dzn_toc_items    = static function () use ( $dzn_toc_sections ) {
	?>
	<ol class="dzn-toc__list">
		<?php foreach ( $dzn_toc_sections as $dzn_toc_section ) : ?>
			<li class="dzn-toc__item dzn-toc__item--level-<?php echo (int) $dzn_toc_section['level']; ?>">
				<a class="dzn-toc__link" href="<?php echo esc_attr( '#' . $dzn_toc_section['anchor'] ); ?>">
					<?php echo esc_html( $dzn_toc_section['text'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
};
?>
<?php if ( 'mobile' === $dzn_toc_variant ) : ?>
	<details class="dzn-toc dzn-toc--mobile">
		<summary class="dzn-toc__summary">
			<span class="dzn-toc__summary-label"><?php esc_html_e( 'فهرست مطالب', 'delnavazan-theme' ); ?></span>
			<span class="dzn-toc__summary-icon" aria-hidden="true"></span>
		</summary>
		<nav class="dzn-toc__panel" id="dzn-toc-title-mobile" aria-label="<?php esc_attr_e( 'فهرست مطالب', 'delnavazan-theme' ); ?>">
			<?php $dzn_toc_items(); ?>
		</nav>
	</details>
<?php else : ?>
	<nav class="dzn-toc dzn-toc--desktop" aria-labelledby="<?php echo esc_attr( $dzn_toc_title_id ); ?>">
		<h2 class="dzn-toc__title" id="<?php echo esc_attr( $dzn_toc_title_id ); ?>">
			<?php esc_html_e( 'فهرست مطالب', 'delnavazan-theme' ); ?>
		</h2>
		<?php $dzn_toc_items(); ?>
	</nav>
<?php endif; ?>
