<?php
/**
 * Presentation-only teacher card.
 *
 * Expected arguments: name, url, image, image_alt, instruments, location, badge.
 * No Platform queries belong here.
 *
 * @package DelnavazanTheme
 */

$name        = isset( $args['name'] ) ? (string) $args['name'] : '';
$url         = isset( $args['url'] ) ? (string) $args['url'] : '';
$image       = isset( $args['image'] ) ? (string) $args['image'] : '';
$image_alt   = isset( $args['image_alt'] ) ? (string) $args['image_alt'] : $name;
$instruments = isset( $args['instruments'] ) ? (array) $args['instruments'] : array();
$location    = isset( $args['location'] ) ? (string) $args['location'] : '';
$badge       = isset( $args['badge'] ) ? (string) $args['badge'] : '';

if ( '' === $name ) {
	return;
}
?>
<article class="dzn-card dzn-card--teacher">
	<?php if ( $image ) : ?>
		<div class="dzn-card__media"><img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $image_alt ); ?>" loading="lazy"></div>
	<?php endif; ?>
	<div class="dzn-card__body">
		<?php if ( $badge ) : ?><p class="dzn-status dzn-status--neutral"><?php echo esc_html( $badge ); ?></p><?php endif; ?>
		<h3 class="dzn-card__title">
			<?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a><?php else : ?><?php echo esc_html( $name ); ?><?php endif; ?>
		</h3>
		<?php if ( $instruments ) : ?><p class="dzn-card__meta"><?php echo esc_html( implode( ' · ', array_map( 'sanitize_text_field', $instruments ) ) ); ?></p><?php endif; ?>
		<?php if ( $location ) : ?><p class="dzn-card__meta"><?php echo esc_html( $location ); ?></p><?php endif; ?>
	</div>
</article>
