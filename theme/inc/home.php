<?php
/**
 * Presentation-only homepage fallback for an empty staging clone.
 *
 * Authored WordPress content remains authoritative. This fallback is used only
 * when the isolated clone still contains the starter post, so staging can
 * exercise the real public shell before content import.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dzn_theme_render_staging_homepage() {
	?>
	<section class="dzn-home-section dzn-home-hero" aria-labelledby="dzn-home-title">
		<div class="dzn-home-hero__grid alignwide">
			<div class="dzn-home-hero__copy">
				<p class="dzn-eyebrow">آکادمی آنلاین موسیقی ایرانی</p>
				<h1 id="dzn-home-title" class="dzn-home-hero__title">موسیقی ایرانی؛<br>نزدیک‌تر از همیشه</h1>
				<p class="dzn-home-hero__lead">دلنوازان مسیر یادگیری آنلاین ساز و آواز ایرانی برای فارسی‌زبانان خارج از ایران است؛ با آموزش زنده، برنامه‌ای روشن و همراهی انسانی.</p>
				<div class="wp-block-buttons dzn-home-hero__actions">
					<p class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/login/' ) ); ?>">ورود به پرتال</a></p>
					<p class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#dzn-audience-title">آشنایی با مسیر</a></p>
				</div>
			</div>
			<div class="dzn-home-hero__media dzn-owned-media-slot dzn-owned-media-slot--hero" aria-hidden="true"></div>
		</div>
	</section>
	<section class="dzn-home-section dzn-facts" aria-labelledby="dzn-facts-title">
		<div class="alignwide">
			<p class="dzn-eyebrow">مسیر آموزش</p>
			<h2 id="dzn-facts-title">یک مسیر روشن، با همراهی انسان‌ها</h2>
			<p>جلسهٔ معارفهٔ رایگان، سپس یک ترم ۱۲ جلسه‌ای خصوصی با برنامه‌ریزی متناسب با منطقهٔ زمانی شما.</p>
		</div>
	</section>
	<section class="dzn-home-section dzn-process" aria-labelledby="dzn-audience-title">
		<div class="alignwide">
			<p class="dzn-eyebrow">برای چه کسی؟</p>
			<h2 id="dzn-audience-title">فضایی برای هنرجو، مدرس و آموزشگاه</h2>
			<div class="dzn-process__list">
				<p><strong>هنرجو</strong><br>برنامهٔ کلاس، مسیر ترم و اطلاعات حساب را در پرتال خود می‌بیند.</p>
				<p><strong>مدرس</strong><br>کلاس‌ها، زمان‌های کاری و موارد نیازمند توجه در نمای مدرس جمع می‌شود.</p>
				<p><strong>آموزشگاه</strong><br>عملیات و تصمیم‌های معتبر از منبع پلتفرم می‌آیند؛ پوسته فقط آن‌ها را روشن نمایش می‌دهد.</p>
			</div>
		</div>
	</section>
	<?php
}
