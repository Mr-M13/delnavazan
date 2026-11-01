<?php
/**
 * Minimal Home + Account Portal navigation.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? array_slice( $args['items'], 0, 4 ) : array();
$nav_id = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : '';

if ( ! $items ) {
	return;
}
?>
<nav id="<?php echo esc_attr( $nav_id ); ?>" class="dzn-portal-nav" aria-label="<?php esc_attr_e( 'فهرست هنرجو', 'delnavazan-theme' ); ?>">
	<ul class="dzn-portal-nav__list">
		<?php foreach ( $items as $item ) : ?>
			<?php
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			$current = ! empty( $item['current'] );
			if ( ! $label ) {
				continue;
			}
			?>
			<li>
				<?php if ( $url ) : ?>
					<a class="dzn-portal-nav__link<?php echo $current ? ' is-current' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php else : ?>
					<span class="dzn-portal-nav__link is-disabled<?php echo $current ? ' is-current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> aria-disabled="true"><?php echo esc_html( $label ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
