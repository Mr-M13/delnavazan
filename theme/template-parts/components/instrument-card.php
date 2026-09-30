<?php
/**
 * Presentation-only instrument card.
 *
 * @package DelnavazanTheme
 */

$title = isset( $args['title'] ) ? (string) $args['title'] : '';
$url   = isset( $args['url'] ) ? (string) $args['url'] : '';
$image = isset( $args['image'] ) ? (string) $args['image'] : '';
$text  = isset( $args['text'] ) ? (string) $args['text'] : '';

if ( '' === $title ) {
	return;
}
?>
<article class="dzn-card dzn-card--instrument">
	<?php if ( $image ) : ?><div class="dzn-card__media"><img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy"></div><?php endif; ?>
	<div class="dzn-card__body">
		<h3 class="dzn-card__title"><?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a><?php else : ?><?php echo esc_html( $title ); ?><?php endif; ?></h3>
		<?php if ( $text ) : ?><p><?php echo esc_html( $text ); ?></p><?php endif; ?>
	</div>
</article>
