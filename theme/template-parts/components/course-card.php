<?php
/**
 * Presentation-only course card.
 *
 * @package DelnavazanTheme
 */

$title = isset( $args['title'] ) ? (string) $args['title'] : '';
$url   = isset( $args['url'] ) ? (string) $args['url'] : '';
$meta  = isset( $args['meta'] ) ? (array) $args['meta'] : array();
$text  = isset( $args['text'] ) ? (string) $args['text'] : '';

if ( '' === $title ) {
	return;
}
?>
<article class="dzn-card dzn-card--course">
	<div class="dzn-card__body">
		<h3 class="dzn-card__title"><?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a><?php else : ?><?php echo esc_html( $title ); ?><?php endif; ?></h3>
		<?php if ( $meta ) : ?><p class="dzn-card__meta"><?php echo esc_html( implode( ' · ', array_map( 'sanitize_text_field', $meta ) ) ); ?></p><?php endif; ?>
		<?php if ( $text ) : ?><p><?php echo esc_html( $text ); ?></p><?php endif; ?>
	</div>
</article>
