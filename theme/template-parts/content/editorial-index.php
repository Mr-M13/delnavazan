<?php
/**
 * Editorial index for the Articles page.
 *
 * Rendered by page.php for the `articles` page. The featured slider, the recent
 * grid and the older archive each run their own query so the page never depends
 * on the authored body of the page record.
 *
 * @package DelnavazanTheme
 */

$dzn_articles_title = get_the_title( get_queried_object_id() );

$dzn_featured = new WP_Query(
	array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 3,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	)
);
$dzn_recent   = new WP_Query(
	array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 6,
		'offset'              => 3,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	)
);
$dzn_page     = isset( $_GET['article-page'] ) ? max( 1, absint( wp_unslash( $_GET['article-page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only archive pagination.
$dzn_older    = new WP_Query(
	array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 9,
		'offset'              => 9 + ( $dzn_page - 1 ) * 9,
		'ignore_sticky_posts' => true,
	)
);
$dzn_pages    = max( 0, (int) ceil( max( 0, $dzn_older->found_posts - 9 ) / 9 ) );
?>
<main id="main-content" class="site-main dzn-container dzn-editorial-index" tabindex="-1">
	<header class="dzn-editorial-intro">
		<div>
			<span class="dzn-editorial-eyebrow"><?php esc_html_e( 'مجلهٔ دلنوازان', 'delnavazan-theme' ); ?></span>
			<h1><?php echo esc_html( $dzn_articles_title ); ?></h1>
			<p><?php esc_html_e( 'خواندنی‌هایی دربارهٔ موسیقی ایرانی، سازها، یادگیری و پیوند موسیقی با زندگی ما.', 'delnavazan-theme' ); ?></p>
		</div>
		<div class="dzn-editorial-intro__mark" aria-hidden="true">♪</div>
	</header>
	<?php if ( $dzn_featured->have_posts() ) : ?>
		<section class="dzn-editorial-featured" aria-labelledby="dzn-featured-title">
			<div class="dzn-editorial-section-heading">
				<div><span class="dzn-editorial-eyebrow"><?php esc_html_e( 'تازه منتشر شده', 'delnavazan-theme' ); ?></span><h2 id="dzn-featured-title"><?php esc_html_e( 'در صدر مجله', 'delnavazan-theme' ); ?></h2></div>
				<div class="dzn-editorial-slider__controls">
					<button type="button" class="dzn-editorial-slider__button" data-dzn-slide="prev" aria-label="<?php esc_attr_e( 'مقالهٔ قبلی', 'delnavazan-theme' ); ?>">→</button>
					<button type="button" class="dzn-editorial-slider__button" data-dzn-slide="next" aria-label="<?php esc_attr_e( 'مقالهٔ بعدی', 'delnavazan-theme' ); ?>">←</button>
				</div>
			</div>
			<div class="dzn-editorial-slider" id="dzn-editorial-slider" tabindex="0" aria-label="<?php esc_attr_e( 'مقاله‌های تازه', 'delnavazan-theme' ); ?>">
				<?php while ( $dzn_featured->have_posts() ) : ?>
					<?php $dzn_featured->the_post(); ?>
					<div class="dzn-editorial-slider__slide"><?php get_template_part( 'template-parts/content/editorial-card', null, array( 'variant' => 'featured' ) ); ?></div>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			</div>
			<p class="dzn-editorial-slider__hint"><?php esc_html_e( 'برای دیدن مقاله‌های تازه‌تر، ورق بزنید.', 'delnavazan-theme' ); ?></p>
		</section>
	<?php endif; ?>
	<?php if ( $dzn_recent->have_posts() ) : ?>
		<section class="dzn-editorial-recent" aria-labelledby="dzn-recent-title">
			<div class="dzn-editorial-section-heading"><div><span class="dzn-editorial-eyebrow"><?php esc_html_e( 'برای ادامهٔ خواندن', 'delnavazan-theme' ); ?></span><h2 id="dzn-recent-title"><?php esc_html_e( 'مقاله‌های اخیر', 'delnavazan-theme' ); ?></h2></div></div>
			<div class="dzn-editorial-grid">
				<?php while ( $dzn_recent->have_posts() ) : ?>
					<?php $dzn_recent->the_post(); ?>
					<?php get_template_part( 'template-parts/content/editorial-card' ); ?>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			</div>
		</section>
	<?php endif; ?>
	<aside class="dzn-editorial-callout" aria-label="<?php esc_attr_e( 'آشنایی با دلنوازان', 'delnavazan-theme' ); ?>">
		<div><span class="dzn-editorial-eyebrow"><?php esc_html_e( 'از خواندن تا نواختن', 'delnavazan-theme' ); ?></span><h2><?php esc_html_e( 'جای موسیقی ایرانی را در زندگی‌تان باز کنید', 'delnavazan-theme' ); ?></h2><p><?php esc_html_e( 'در دلنوازان، کلاس‌های آنلاین موسیقی ایرانی را با همراهی استادان باتجربه از هر کجای دنیا تجربه کنید.', 'delnavazan-theme' ); ?></p></div>
		<div class="dzn-editorial-callout__actions"><a href="<?php echo esc_url( home_url( '/enrol/' ) ); ?>"><?php esc_html_e( 'دیدن کلاس‌ها و ثبت‌نام', 'delnavazan-theme' ); ?> ←</a><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'آشنایی با دلنوازان', 'delnavazan-theme' ); ?></a></div>
	</aside>
	<?php if ( $dzn_older->have_posts() ) : ?>
		<section class="dzn-editorial-archive" aria-labelledby="dzn-archive-title">
			<div class="dzn-editorial-section-heading"><div><span class="dzn-editorial-eyebrow"><?php esc_html_e( 'گنجینهٔ مجله', 'delnavazan-theme' ); ?></span><h2 id="dzn-archive-title"><?php esc_html_e( 'مقاله‌های پیشین', 'delnavazan-theme' ); ?></h2></div></div>
			<div class="dzn-editorial-grid dzn-editorial-grid--archive">
				<?php while ( $dzn_older->have_posts() ) : ?>
					<?php $dzn_older->the_post(); ?>
					<?php get_template_part( 'template-parts/content/editorial-card' ); ?>
				<?php endwhile; ?>
			</div>
			<?php if ( $dzn_pages > 1 ) : ?>
				<nav class="dzn-editorial-pagination" aria-label="<?php esc_attr_e( 'صفحه‌های مقاله‌های پیشین', 'delnavazan-theme' ); ?>">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'article-page', '%#%', get_permalink() ),
								'format'    => '',
								'current'   => $dzn_page,
								'total'     => $dzn_pages,
								'prev_text' => '→ ' . __( 'جدیدتر', 'delnavazan-theme' ),
								'next_text' => __( 'قدیمی‌تر', 'delnavazan-theme' ) . ' ←',
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</section>
	<?php endif; ?>
	<?php wp_reset_postdata(); ?>
</main>
