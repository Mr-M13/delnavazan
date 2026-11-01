<?php
/**
 * Compact banner shown after a quick-start step jumps to its real portal section.
 * The banner explains the purpose; JavaScript highlights the target element.
 *
 * @package DelnavazanTheme
 */
$step = is_array( $args['step'] ?? null ) ? $args['step'] : array();
$selector = (string) ( $step['highlight_selector'] ?? '' );
$title = (string) ( $step['title'] ?? '' );
$description = (string) ( $step['description'] ?? '' );
$back = (string) ( $step['back_url'] ?? '' );
if ( '' === $title || '' === $description ) { return; }
?>
<section class="dzn-tp-section dzn-tp-highlight-banner" aria-labelledby="tp-induction-highlight-title" data-dzn-auto-highlight="<?php echo esc_attr( $selector ); ?>">
	<button class="dzn-tp-highlight-close" type="button" data-dzn-tp-highlight-close aria-label="بستن راهنمای این بخش">×</button>
	<div>
		<p class="dzn-tp-kicker">راهنمای سریع تدریس</p>
		<h2 id="tp-induction-highlight-title"><?php echo esc_html( $title ); ?></h2>
		<p><?php echo esc_html( $description ); ?></p>
	</div>
	<?php if ( '' !== $back ) : ?><p><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $back ); ?>">بازگشت به راهنمای سریع</a></p><?php endif; ?>
</section>
