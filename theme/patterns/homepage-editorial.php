<?php
/**
 * Title: صفحهٔ اصلی ویرایشی دلنوازان
 * Slug: delnavazan-theme/homepage-editorial
 * Categories: delnavazan, featured
 * Keywords: homepage, persian, music, academy
 * Description: ساختار کامل صفحهٔ اصلی دلنوازان با بلوک‌های هستهٔ وردپرس.
 * Viewport Width: 1440
 */
?>
<!-- wp:group {"tagName":"section","align":"full","className":"dzn-home-section dzn-home-hero","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull dzn-home-section dzn-home-hero">
	<!-- wp:group {"align":"wide","className":"dzn-home-hero__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide dzn-home-hero__grid">
		<!-- wp:group {"className":"dzn-home-hero__copy","layout":{"type":"default"}} -->
		<div class="wp-block-group dzn-home-hero__copy">
			<!-- wp:paragraph {"className":"dzn-eyebrow"} --><p class="dzn-eyebrow">آکادمی آنلاین موسیقی ایرانی</p><!-- /wp:paragraph -->
			<!-- wp:heading {"level":1,"className":"dzn-home-hero__title"} --><h1 class="wp-block-heading dzn-home-hero__title">موسیقی ایرانی؛<br>نزدیک‌تر از همیشه</h1><!-- /wp:heading -->
			<!-- wp:paragraph {"className":"dzn-home-hero__lead"} --><p class="dzn-home-hero__lead">دلنوازان، مسیر یادگیری آنلاین ساز و آواز ایرانی برای فارسی‌زبانانِ خارج از ایران است؛ با آموزش زنده، برنامه‌ای روشن و همراهی انسانی در آغاز راه.</p><!-- /wp:paragraph -->
			<!-- wp:buttons {"className":"dzn-home-hero__actions"} -->
			<div class="wp-block-buttons dzn-home-hero__actions">
				<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/enrol/' ) ); ?>">شروع ثبت‌نام</a></div><!-- /wp:button -->
				<!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#courses">دیدن سازها</a></div><!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
			<!-- wp:paragraph {"className":"dzn-home-hero__note"} --><p class="dzn-home-hero__note">آغاز مسیر با جلسهٔ معارفهٔ رایگان</p><!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"dzn-home-hero__media dzn-owned-media-slot dzn-owned-media-slot--hero","layout":{"type":"default"}} -->
		<div class="wp-block-group dzn-home-hero__media dzn-owned-media-slot dzn-owned-media-slot--hero"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-home-hero__figure"} --><figure class="wp-block-image size-full dzn-home-hero__figure"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/home-hero.png' ) ); ?>" alt="فضای ایرانی با تار و تنبک در کنار پنجره" width="1672" height="941" loading="eager" fetchpriority="high" decoding="async"></figure><!-- /wp:image --></div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"dzn-home-section dzn-facts","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull dzn-home-section dzn-facts" aria-label="اطلاعات اصلی دوره‌ها">
	<!-- wp:group {"align":"wide","className":"dzn-facts__summary","layout":{"type":"default"}} --><div class="wp-block-group alignwide dzn-facts__summary"><!-- wp:paragraph --><p>هر ترم پرداخت‌شده: ۱۲ جلسهٔ خصوصی، هفته‌ای یک جلسهٔ ۳۰ دقیقه‌ای. جلسهٔ معارفه رایگان و جدا از ترم است.</p><!-- /wp:paragraph --></div><!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","anchor":"courses","align":"full","className":"dzn-home-section dzn-courses","layout":{"type":"constrained"}} -->
<section id="courses" class="wp-block-group alignfull dzn-home-section dzn-courses">
	<!-- wp:group {"align":"wide","className":"dzn-section-heading dzn-section-heading--folio","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide dzn-section-heading dzn-section-heading--folio"><!-- wp:group {"className":"dzn-section-heading__copy","layout":{"type":"default"}} --><div class="wp-block-group dzn-section-heading__copy"><!-- wp:paragraph {"className":"dzn-eyebrow"} --><p class="dzn-eyebrow">سازها و دوره‌ها</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">سازِ خودتان را پیدا کنید</h2><!-- /wp:heading --><!-- wp:paragraph --><p>این انتخاب، آغاز گفت‌وگو دربارهٔ مسیر آموزشی مناسب شماست.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph {"className":"dzn-inline-link dzn-instrument-folio__all"} --><p class="dzn-inline-link dzn-instrument-folio__all"><a href="<?php echo esc_url( home_url( '/enrol/' ) ); ?>">دیدن همهٔ سازها <span aria-hidden="true">←</span></a></p><!-- /wp:paragraph --></div>
	<!-- /wp:group -->
	<!-- wp:group {"align":"wide","className":"dzn-instrument-folio","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide dzn-instrument-folio">
		<!-- Individual tiles remain non-links until the enrolment system publishes a verified instrument-preselection contract. -->
		<!-- wp:group {"tagName":"article","className":"dzn-instrument-tile dzn-instrument-tile--tar","layout":{"type":"default"}} --><article class="wp-block-group dzn-instrument-tile dzn-instrument-tile--tar"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-instrument-tile__media"} --><figure class="wp-block-image size-full dzn-instrument-tile__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/instrument-tar.webp' ) ); ?>" alt="ساز تار ایرانی" width="281" height="855" loading="lazy" decoding="async"></figure><!-- /wp:image --><!-- wp:heading {"level":3,"className":"dzn-instrument-tile__copy"} --><h3 class="wp-block-heading dzn-instrument-tile__copy">تار</h3><!-- /wp:heading --></article><!-- /wp:group -->
		<!-- wp:group {"tagName":"article","className":"dzn-instrument-tile dzn-instrument-tile--setar","layout":{"type":"default"}} --><article class="wp-block-group dzn-instrument-tile dzn-instrument-tile--setar"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-instrument-tile__media"} --><figure class="wp-block-image size-full dzn-instrument-tile__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/instrument-setar.webp' ) ); ?>" alt="ساز سه‌تار ایرانی" width="234" height="984" loading="lazy" decoding="async"></figure><!-- /wp:image --><!-- wp:heading {"level":3,"className":"dzn-instrument-tile__copy"} --><h3 class="wp-block-heading dzn-instrument-tile__copy">سه‌تار</h3><!-- /wp:heading --></article><!-- /wp:group -->
		<!-- wp:group {"tagName":"article","className":"dzn-instrument-tile dzn-instrument-tile--santur","layout":{"type":"default"}} --><article class="wp-block-group dzn-instrument-tile dzn-instrument-tile--santur"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-instrument-tile__media"} --><figure class="wp-block-image size-full dzn-instrument-tile__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/instrument-santur.webp' ) ); ?>" alt="ساز سنتور ایرانی" width="721" height="547" loading="lazy" decoding="async"></figure><!-- /wp:image --><!-- wp:heading {"level":3,"className":"dzn-instrument-tile__copy"} --><h3 class="wp-block-heading dzn-instrument-tile__copy">سنتور</h3><!-- /wp:heading --></article><!-- /wp:group -->
		<!-- wp:group {"tagName":"article","className":"dzn-instrument-tile dzn-instrument-tile--kamancheh","layout":{"type":"default"}} --><article class="wp-block-group dzn-instrument-tile dzn-instrument-tile--kamancheh"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-instrument-tile__media"} --><figure class="wp-block-image size-full dzn-instrument-tile__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/instrument-kamancheh.webp' ) ); ?>" alt="ساز کمانچهٔ ایرانی با آرشه" width="187" height="540" loading="lazy" decoding="async"></figure><!-- /wp:image --><!-- wp:heading {"level":3,"className":"dzn-instrument-tile__copy"} --><h3 class="wp-block-heading dzn-instrument-tile__copy">کمانچه</h3><!-- /wp:heading --></article><!-- /wp:group -->
		<!-- wp:group {"tagName":"article","className":"dzn-instrument-tile dzn-instrument-tile--tombak","layout":{"type":"default"}} --><article class="wp-block-group dzn-instrument-tile dzn-instrument-tile--tombak"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-instrument-tile__media"} --><figure class="wp-block-image size-full dzn-instrument-tile__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/instrument-tombak.webp' ) ); ?>" alt="ساز تنبک ایرانی" width="501" height="781" loading="lazy" decoding="async"></figure><!-- /wp:image --><!-- wp:heading {"level":3,"className":"dzn-instrument-tile__copy"} --><h3 class="wp-block-heading dzn-instrument-tile__copy">تنبک</h3><!-- /wp:heading --></article><!-- /wp:group -->
		<!-- wp:group {"tagName":"article","className":"dzn-instrument-tile dzn-instrument-tile--piano","layout":{"type":"default"}} --><article class="wp-block-group dzn-instrument-tile dzn-instrument-tile--piano"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-instrument-tile__media"} --><figure class="wp-block-image size-full dzn-instrument-tile__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/instrument-piano.webp' ) ); ?>" alt="پیانوی گرند مشکی" width="236" height="237" loading="lazy" decoding="async"></figure><!-- /wp:image --><!-- wp:heading {"level":3,"className":"dzn-instrument-tile__copy"} --><h3 class="wp-block-heading dzn-instrument-tile__copy">پیانو</h3><!-- /wp:heading --></article><!-- /wp:group -->
		<!-- wp:group {"tagName":"article","className":"dzn-instrument-tile dzn-instrument-tile--daf","layout":{"type":"default"}} --><article class="wp-block-group dzn-instrument-tile dzn-instrument-tile--daf"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"dzn-instrument-tile__media"} --><figure class="wp-block-image size-full dzn-instrument-tile__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/instrument-daf.webp' ) ); ?>" alt="ساز دف ایرانی" width="500" height="500" loading="lazy" decoding="async"></figure><!-- /wp:image --><!-- wp:heading {"level":3,"className":"dzn-instrument-tile__copy"} --><h3 class="wp-block-heading dzn-instrument-tile__copy">دف</h3><!-- /wp:heading --></article><!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"dzn-home-section dzn-process","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull dzn-home-section dzn-process">
	<!-- wp:group {"align":"wide","className":"dzn-section-heading","layout":{"type":"default"}} --><div class="wp-block-group alignwide dzn-section-heading"><!-- wp:paragraph {"className":"dzn-eyebrow"} --><p class="dzn-eyebrow">دلنوازان چگونه کار می‌کند؟</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">یک مسیر روشن، با همراهی انسان‌ها</h2><!-- /wp:heading --><!-- wp:paragraph --><p>پس از ثبت‌نام اولیه، دلنوازان برای روشن‌شدن مسیر مناسب آموزش و هماهنگی‌های آغاز کار همراه شماست؛ نه یک بازار ناشناسِ دوره‌ها.</p><!-- /wp:paragraph --></div><!-- /wp:group -->
	<!-- wp:list {"ordered":true,"align":"wide","className":"dzn-process__list"} -->
	<ol class="dzn-process__list alignwide"><li><strong>ثبت‌نام / درخواست</strong><span>ساز، تجربه و شرایط زمانی خود را معرفی می‌کنید.</span></li><li><strong>جلسهٔ معارفهٔ رایگان</strong><span>پیش از تعهد به ترم، با فضای آموزش و مسیر کلاس آشنا می‌شوید.</span></li><li><strong>تصمیم برای ادامه و پرداخت هزینهٔ ترم</strong><span>اگر ادامه می‌دهید، هزینهٔ ترم پیش از آغاز ۱۲ جلسهٔ آموزشی پرداخت می‌شود.</span></li><li><strong>آغاز ترم پرداخت‌شدهٔ ۱۲ جلسه‌ای</strong><span>برنامهٔ یک ترم شامل ۱۲ جلسهٔ خصوصی هفتگی تنظیم می‌شود.</span></li></ol>
	<!-- /wp:list -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"dzn-home-section dzn-pricing","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull dzn-home-section dzn-pricing">
	<!-- wp:group {"align":"wide","className":"dzn-pricing__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide dzn-pricing__grid">
		<!-- wp:group {"className":"dzn-pricing__intro","layout":{"type":"default"}} --><div class="wp-block-group dzn-pricing__intro"><!-- wp:paragraph {"className":"dzn-eyebrow dzn-eyebrow--light"} --><p class="dzn-eyebrow dzn-eyebrow--light">هزینه و ساختار دوره</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">شهریه‌ای روشن برای یک ترم</h2><!-- /wp:heading --><!-- wp:paragraph --><p>شهریه بر اساس منطقهٔ قیمت‌گذاری انتخاب‌شده نمایش داده می‌شود. این نمایش برای راهنمایی است و نرخ تبدیل زنده نیست.</p><!-- /wp:paragraph --></div><!-- /wp:group -->
		<!-- wp:group {"className":"dzn-price-ledger","layout":{"type":"default"}} -->
		<div class="wp-block-group dzn-price-ledger" data-dzn-pricing>
			<!-- wp:group {"className":"dzn-price-ledger__feature","layout":{"type":"default"}} --><div class="wp-block-group dzn-price-ledger__feature"><!-- wp:paragraph {"className":"dzn-price-ledger__label"} --><p class="dzn-price-ledger__label">شهریهٔ یک ترم</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"dzn-price-ledger__price"} --><p class="dzn-price-ledger__price" data-dzn-pricing-amount aria-live="polite">منطقهٔ خود را انتخاب کنید</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"dzn-price-ledger__local"} --><p class="dzn-price-ledger__local" data-dzn-pricing-region aria-live="polite">برای نمایش شهریه، یکی از مناطق فعال را انتخاب کنید.</p><!-- /wp:paragraph --></div><!-- /wp:group -->
			<!-- wp:group {"className":"dzn-pricing-region","layout":{"type":"default"}} --><div class="wp-block-group dzn-pricing-region"><!-- wp:paragraph {"className":"dzn-pricing-region__label"} --><p class="dzn-pricing-region__label">منطقهٔ قیمت‌گذاری</p><!-- /wp:paragraph --><!-- wp:html --><label class="screen-reader-text" for="dzn-pricing-region-select">منطقهٔ قیمت‌گذاری را انتخاب کنید</label><select id="dzn-pricing-region-select" class="dzn-pricing-region__select" data-dzn-pricing-select><option value="">منطقهٔ خود را انتخاب کنید</option><option value="AU">استرالیا</option><option value="NZ">نیوزیلند</option><option value="US">ایالات متحده</option><option value="CA">کانادا</option><option value="EU">منطقهٔ یورو</option><option value="GB">بریتانیا</option></select><p class="dzn-pricing-region__status" data-dzn-pricing-status aria-live="polite">انتخاب شما در این دستگاه ذخیره می‌شود.</p><!-- /wp:html --></div><!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"dzn-home-section dzn-hamnavaz","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull dzn-home-section dzn-hamnavaz">
	<!-- wp:group {"align":"wide","className":"dzn-hamnavaz__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide dzn-hamnavaz__grid">
		<!-- Final approved Hamnavaz copy belongs in this stable group. No product functionality is implied. -->
		<!-- wp:group {"className":"dzn-hamnavaz__copy","layout":{"type":"default"}} --><div class="wp-block-group dzn-hamnavaz__copy"><!-- wp:heading --><h2 class="wp-block-heading">همنواز</h2><!-- /wp:heading --></div><!-- /wp:group -->
		<!-- Replace only this stable group with approved Hamnavaz media. -->
		<!-- wp:group {"className":"dzn-hamnavaz__media dzn-owned-media-slot dzn-owned-media-slot--hamnavaz dzn-owned-media-slot--pending","layout":{"type":"default"}} --><div class="wp-block-group dzn-hamnavaz__media dzn-owned-media-slot dzn-owned-media-slot--hamnavaz dzn-owned-media-slot--pending"></div><!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","anchor":"faq","align":"full","className":"dzn-home-section dzn-faq","layout":{"type":"constrained"}} -->
<section id="faq" class="wp-block-group alignfull dzn-home-section dzn-faq">
	<!-- wp:group {"align":"wide","className":"dzn-faq__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide dzn-faq__grid">
		<!-- wp:group {"className":"dzn-section-heading","layout":{"type":"default"}} --><div class="wp-block-group dzn-section-heading"><!-- wp:paragraph {"className":"dzn-eyebrow"} --><p class="dzn-eyebrow">پرسش‌های متداول</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">پیش از شروع</h2><!-- /wp:heading --><!-- wp:paragraph --><p>پاسخ کوتاه به سؤال‌هایی که بیشتر هنرجویان پیش از ثبت‌نام دارند.</p><!-- /wp:paragraph --></div><!-- /wp:group -->
		<!-- wp:group {"className":"dzn-faq__list","layout":{"type":"default"}} -->
		<div class="wp-block-group dzn-faq__list">
			<!-- wp:details --><details class="wp-block-details"><summary>کلاس‌ها برای چه کشورهایی برگزار می‌شود؟</summary><!-- wp:paragraph --><p>کلاس‌های آنلاین برای فارسی‌زبانان خارج از ایران طراحی شده است. زمان نهایی با توجه به منطقهٔ زمانی هنرجو هماهنگ می‌شود.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
			<!-- wp:details --><details class="wp-block-details"><summary>اگر هنوز ساز ندارم چه کنم؟</summary><!-- wp:paragraph --><p>در ثبت‌نام اولیه این موضوع را مطرح کنید تا پیش از شروع کلاس، راهنمایی متناسب با ساز انتخابی دریافت کنید.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
			<!-- wp:details --><details class="wp-block-details"><summary>آیا می‌توانم از سطح کاملاً مبتدی شروع کنم؟</summary><!-- wp:paragraph --><p>بله. تجربهٔ قبلی خود را در ثبت‌نام ذکر کنید تا مسیر آغاز کلاس متناسب با سطح شما روشن شود.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
			<!-- wp:details --><details class="wp-block-details"><summary>اگر زمان یک جلسه مناسب نباشد چه می‌شود؟</summary><!-- wp:paragraph --><p>شرایط زمانی خود را از ابتدا اعلام کنید. جزئیات جابه‌جایی یا هماهنگی جلسه بر اساس سیاست جاری دلنوازان مشخص می‌شود.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
			<!-- wp:details --><details class="wp-block-details"><summary>هزینهٔ ترم چه زمانی پرداخت می‌شود؟</summary><!-- wp:paragraph --><p>جلسهٔ معارفه رایگان و جدا از ترم است. اگر پس از آن تصمیم به ادامه بگیرید، هزینهٔ ترم پیش از آغاز ۱۲ جلسهٔ آموزشی پرداخت می‌شود.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
			<!-- wp:details --><details class="wp-block-details"><summary>آیا داخل ایران هم می‌توان ثبت‌نام کرد؟</summary><!-- wp:paragraph --><p>در حال حاضر خدمات دلنوازان برای هنرجویان خارج از ایران ارائه می‌شود.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"dzn-home-section dzn-editorial","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull dzn-home-section dzn-editorial">
	<!-- wp:group {"align":"wide","className":"dzn-section-heading dzn-section-heading--row","layout":{"type":"default"}} --><div class="wp-block-group alignwide dzn-section-heading dzn-section-heading--row"><!-- wp:group {"className":"dzn-section-heading__copy","layout":{"type":"default"}} --><div class="wp-block-group dzn-section-heading__copy"><!-- wp:paragraph {"className":"dzn-eyebrow"} --><p class="dzn-eyebrow">مجلهٔ دلنوازان</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">برای شنیدن و شناختن</h2><!-- /wp:heading --></div><!-- /wp:group --><!-- wp:paragraph {"className":"dzn-inline-link"} --><p class="dzn-inline-link"><a href="<?php echo esc_url( home_url( '/articles/' ) ); ?>">همهٔ مقاله‌ها <span aria-hidden="true">←</span></a></p><!-- /wp:paragraph --></div><!-- /wp:group -->
	<!-- wp:query {"queryId":4,"query":{"perPage":3,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"exclude","inherit":false},"align":"wide","className":"dzn-article-query"} -->
	<div class="wp-block-query alignwide dzn-article-query"><!-- wp:post-template {"className":"dzn-article-grid"} -->
		<!-- wp:group {"tagName":"article","className":"dzn-article-card","layout":{"type":"default"}} --><article class="wp-block-group dzn-article-card"><!-- wp:post-featured-image {"isLink":true,"aspectRatio":"4/3"} /--><!-- wp:post-title {"isLink":true,"level":3} /--><!-- wp:post-excerpt {"moreText":"خواندن مقاله"} /--></article><!-- /wp:group -->
	<!-- /wp:post-template --></div>
	<!-- /wp:query -->
</section>
<!-- /wp:group -->
