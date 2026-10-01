<?php
/**
 * Operations navigation.
 *
 * Only screens the operator is already allowed to open are rendered; the group
 * list itself is built from the Platform's own capability names.
 *
 * @package DelnavazanTheme
 */

$groups = isset( $args['groups'] ) && is_array( $args['groups'] ) ? $args['groups'] : array();

if ( ! $groups ) {
	return;
}
?>
<nav class="dzn-ops-nav" aria-labelledby="dzn-ops-nav-title">
	<h2 id="dzn-ops-nav-title"><?php esc_html_e( 'بخش‌های عملیاتی', 'delnavazan-theme' ); ?></h2>
	<?php foreach ( $groups as $group ) : ?>
		<section class="dzn-ops-nav__group" aria-label="<?php echo esc_attr( (string) ( $group['label'] ?? '' ) ); ?>">
			<h3 class="dzn-ops-nav__label"><?php echo esc_html( (string) ( $group['label'] ?? '' ) ); ?></h3>
			<ul class="dzn-ops-nav__list">
				<?php foreach ( (array) ( $group['items'] ?? array() ) as $item ) : ?>
					<li class="dzn-ops-nav__item">
						<a class="dzn-ops-nav__link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . (string) $item['slug'] ) ); ?>">
							<span class="dzn-ops-nav__title"><?php echo esc_html( (string) $item['title'] ); ?></span>
							<span class="dzn-ops-nav__description"><?php echo esc_html( (string) $item['description'] ); ?></span>
							<span class="dzn-ops-nav__open" aria-hidden="true">←</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
</nav>
