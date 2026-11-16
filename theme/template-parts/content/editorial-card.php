<?php
/**
 * Shared editorial card.
 *
 * Uses the featured image when the post has one and falls back to the theme
 * instrument artwork when it does not, so the index never renders an empty tile.
 *
 * @package DelnavazanTheme
 *
 * @param array $args {
 *     @type string $variant Card variant: `standard` or `featured`.
 * }
 */

$dzn_card_variant = isset( $args['variant'] ) ? (string) $args['variant'] : 'standard';
$dzn_card_title   = get_the_title();
$dzn_card_art     = 'Motif-Navaz-FULL.webp';

foreach ( array(
	'دف' => 'C-Daf-800.webp',
	'تنبک' => 'C-Tombak-800.webp',
	'تمبک' => 'C-Tombak-800.webp',
	'گیتار' => 'C-Guitar-800.webp',
	'تنبور' => 'C-Tanboor-800.webp',
	'سنتور' => 'C-santour-800.webp',
	'تار' => 'C-Tar-800.webp',
	'سه‌تار' => 'C-Setar-800.webp',
	'کمانچه' => 'C-Kamancheh-800.webp',
	'نی' => 'C-Ney-800.webp',
	'آواز' => 'C-Avaz-800.webp',
) as $dzn_instrument => $dzn_instrument_art ) {
	if ( false !== strpos( $dzn_card_title, $dzn_instrument ) ) {
		$dzn_card_art = $dzn_instrument_art;
		break;
	}
}

$dzn_card_excerpt = wp_strip_all_tags( get_the_excerpt() );
if ( '' === trim( $dzn_card_excerpt ) ) {
	$dzn_card_excerpt = wp_trim_words( wp_strip_all_tags( get_the_content() ), 30 );
}
?>
<article class="dzn-editorial-card dzn-editorial-card--<?php echo esc_attr( $dzn_card_variant ); ?>">
	<a class="dzn-editorial-card__image" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr( $dzn_card_title ); ?>">
		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); ?>
		<?php else : ?>
			<img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/' . $dzn_card_art ) ); ?>" alt="" loading="lazy">
		<?php endif; ?>
	</a>
	<div class="dzn-editorial-card__body">
		<span class="dzn-editorial-card__label"><?php esc_html_e( 'مجلهٔ دلنوازان', 'delnavazan-theme' ); ?></span>
		<h3><a href="<?php the_permalink(); ?>"><?php echo esc_html( $dzn_card_title ); ?></a></h3>
		<p><?php echo esc_html( $dzn_card_excerpt ); ?></p>
		<a class="dzn-editorial-card__read" href="<?php the_permalink(); ?>"><?php esc_html_e( 'خواندن مقاله', 'delnavazan-theme' ); ?> <span aria-hidden="true">←</span></a>
	</div>
</article>
