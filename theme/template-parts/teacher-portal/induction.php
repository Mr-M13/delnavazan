<?php
/**
 * Teacher induction hub and module renderer.
 *
 * Content is supplied by TeacherInductionCurriculum and progress by TeacherInductionService.
 * This template performs no lifecycle, readiness, scheduling, finance or absence decision.
 *
 * @package DelnavazanTheme
 */
$model = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$hub = is_array( $model['induction'] ?? null ) ? $model['induction'] : array();
$module = is_array( $model['induction_module'] ?? null ) ? $model['induction_module'] : null;
$actions = is_array( $model['induction_actions'] ?? null ) ? $model['induction_actions'] : array();
$hub_url = (string) ( $model['induction_hub_url'] ?? '' );
$continue_url = (string) ( $model['induction_continue_url'] ?? $hub_url );
$support_url = (string) ( $model['induction_support_url'] ?? '' );
$screen_urls = array();
foreach ( (array) ( $model['navigation'] ?? array() ) as $nav_item ) {
	if ( is_array( $nav_item ) && isset( $nav_item['screen'], $nav_item['url'] ) ) { $screen_urls[ (string) $nav_item['screen'] ] = (string) $nav_item['url']; }
}
$screen_url = static function ( string $screen ) use ( $screen_urls, $hub_url ): string {
	return $screen_urls[ $screen ] ?? $hub_url;
};
$notices = array(
	'این بخش از راهنمای تدریس تکمیل شد.' => 'success',
	'این بخش پیش‌تر تکمیل شده بود؛ پیشرفت شما بدون تغییر ماند.' => 'success',
	'بخش درخواستی در راهنمای تدریس وجود ندارد.' => 'error',
	'ثبت پیشرفت انجام نشد. اگر حساب شما هنوز آماده نیست، ابتدا راه‌اندازی حساب را کامل کنید.' => 'error',
);
$notice = isset( $_GET['dzn_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) : '';
$completed = (int) ( $hub['completed_count'] ?? 0 );
$total = (int) ( $hub['total_count'] ?? 0 );
$percent = (int) ( $hub['percent'] ?? 0 );
$module_state = is_array( $module ) ? (string) ( $module['state'] ?? 'not_started' ) : '';
$module_done = 'completed' === $module_state;
$needs_reack = is_array( $module ) && ! empty( $module['needs_reacknowledgement'] );
?>
<section class="dzn-tp-section dzn-tp-induction" aria-labelledby="tp-induction-title">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">راهنمای تدریس</p><h2 id="tp-induction-title">راهنمای شروع تدریس در دلنوازان</h2></div>
		<span class="dzn-tp-induction__count"><?php echo esc_html( sprintf( '%s از %s بخش تکمیل شده', number_format_i18n( $completed ), number_format_i18n( $total ) ) ); ?></span>
	</div>
	<?php if ( isset( $notices[ $notice ] ) ) : ?><p class="dzn-tp-notice is-<?php echo esc_attr( $notices[ $notice ] ); ?>" role="<?php echo 'error' === $notices[ $notice ] ? 'alert' : 'status'; ?>"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
	<div class="dzn-tp-induction__progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $total ); ?>" aria-valuenow="<?php echo esc_attr( (string) $completed ); ?>" aria-label="پیشرفت راهنمای تدریس"><span style="inline-size:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
	<p class="dzn-tp-help">این راهنما برای آموزش کارهای روزانهٔ مدرس است و به‌تنهایی دسترسی شما را فعال یا محدود نمی‌کند. هر زمان می‌توانید آن را رها کنید و بعداً از همین صفحه ادامه دهید. تکمیل راهنما برای دسترسی به پرتال لازم نیست.</p>
	<?php if ( $module ) : ?>
		<nav class="dzn-tp-induction__breadcrumb" aria-label="مسیر راهنما"><a href="<?php echo esc_url( $hub_url ); ?>">همهٔ بخش‌ها</a><span aria-hidden="true">›</span><span><?php echo esc_html( (string) ( $module['short_title'] ?? $module['title'] ) ); ?></span></nav>
		<article class="dzn-tp-induction-module" aria-labelledby="tp-induction-module-title">
			<header class="dzn-tp-induction-module__header">
				<p class="dzn-tp-kicker">بخش <?php echo esc_html( number_format_i18n( (int) ( $module['order'] ?? 0 ) ) ); ?> از <?php echo esc_html( number_format_i18n( $total ) ); ?></p>
				<h3 id="tp-induction-module-title"><?php echo esc_html( (string) $module['title'] ); ?></h3>
				<p><?php echo esc_html( (string) $module['description'] ); ?></p>
				<p class="dzn-tp-induction-module__meta"><span class="dzn-tp-state dzn-tp-state--<?php echo esc_attr( $module_done ? 'ended' : ( 'in_progress' === $module_state ? 'in_progress' : 'upcoming' ) ); ?>"><?php echo esc_html( $module_done ? ( $needs_reack ? 'تکمیل‌شده · نیازمند تأیید تازه' : 'تکمیل‌شده' ) : ( 'in_progress' === $module_state ? 'در حال یادگیری' : 'شروع‌نشده' ) ); ?></span></p>
			</header>
			<?php if ( ! empty( $module['objectives'] ) ) : ?><section class="dzn-tp-induction-objectives" aria-labelledby="tp-induction-objectives"><h4 id="tp-induction-objectives">در این بخش یاد می‌گیرید</h4><ul><?php foreach ( (array) $module['objectives'] as $objective ) : ?><li><?php echo esc_html( (string) $objective ); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
			<?php if ( ! empty( $module['policy'] ) && is_array( $module['policy'] ) ) : ?><section class="dzn-tp-induction-policy" aria-labelledby="tp-induction-policy"><h4 id="tp-induction-policy"><?php echo esc_html( (string) ( $module['policy']['title'] ?? 'قاعدهٔ اصلی' ) ); ?></h4><p><?php echo esc_html( (string) ( $module['policy']['body'] ?? '' ) ); ?></p><?php if ( ! empty( $module['policy']['points'] ) ) : ?><ul><?php foreach ( (array) $module['policy']['points'] as $point ) : ?><li><?php echo esc_html( (string) $point ); ?></li><?php endforeach; ?></ul><?php endif; ?></section><?php endif; ?>
			<?php foreach ( (array) $module['sections'] as $section_index => $section ) : ?>
				<section class="dzn-tp-induction-section" aria-labelledby="tp-induction-section-<?php echo esc_attr( (string) $section_index ); ?>">
					<h4 id="tp-induction-section-<?php echo esc_attr( (string) $section_index ); ?>"><?php echo esc_html( (string) $section['title'] ); ?></h4>
					<?php if ( isset( $section['mandatory'] ) ) : ?><p class="dzn-tp-induction-tag <?php echo ! empty( $section['mandatory'] ) ? 'is-required' : 'is-recommended'; ?>"><?php echo ! empty( $section['mandatory'] ) ? 'اقدام الزامی پلتفرم' : 'توصیهٔ حرفه‌ای'; ?></p><?php endif; ?>
					<p><?php echo esc_html( (string) $section['body'] ); ?></p>
					<?php if ( ! empty( $section['steps'] ) ) : ?><ol class="dzn-tp-induction-steps"><?php foreach ( (array) $section['steps'] as $step ) : ?><li><?php echo esc_html( (string) $step ); ?></li><?php endforeach; ?></ol><?php endif; ?>
					<?php if ( ! empty( $section['points'] ) ) : ?><ul class="dzn-tp-induction-points"><?php foreach ( (array) $section['points'] as $point ) : ?><li><?php echo esc_html( (string) $point ); ?></li><?php endforeach; ?></ul><?php endif; ?>
					<?php if ( ! empty( $section['link']['label'] ) && ! empty( $section['link']['screen'] ) ) : ?><p><a class="dzn-portal-text-action" href="<?php echo esc_url( $screen_url( (string) $section['link']['screen'] ) ); ?>"><?php echo esc_html( (string) $section['link']['label'] ); ?></a></p><?php endif; ?>
					<?php if ( ! empty( $section['tour']['selector'] ) ) : ?><p><button class="dzn-button dzn-button--secondary dzn-button--small" type="button" data-dzn-induction-highlight="<?php echo esc_attr( (string) $section['tour']['selector'] ); ?>"><?php echo esc_html( (string) ( $section['tour']['label'] ?? 'نمایش در پرتال' ) ); ?></button><span class="dzn-tp-induction-highlight-status" role="status" aria-live="polite"></span></p><?php endif; ?>
				</section>
			<?php endforeach; ?>
			<?php if ( ! empty( $module['scenario_cards'] ) ) : ?><section class="dzn-tp-induction-scenarios" aria-labelledby="tp-induction-scenarios"><h4 id="tp-induction-scenarios">سناریوهای عملیاتی</h4><div class="dzn-tp-induction-scenario-grid"><?php foreach ( (array) $module['scenario_cards'] as $card ) : ?><article class="dzn-tp-induction-scenario"><h5><?php echo esc_html( (string) ( $card['title'] ?? '' ) ); ?></h5><dl><?php if ( ! empty( $card['happened'] ) ) : ?><dt>چه اتفاقی افتاده؟</dt><dd><?php echo esc_html( (string) $card['happened'] ); ?></dd><?php endif; ?><?php if ( ! empty( $card['check'] ) ) : ?><dt>چه چیزی را بررسی کنید؟</dt><dd><ul><?php foreach ( (array) $card['check'] as $check ) : ?><li><?php echo esc_html( (string) $check ); ?></li><?php endforeach; ?></ul></dd><?php endif; ?><?php if ( ! empty( $card['action'] ) ) : ?><dt>چه اقدامی مجاز است؟</dt><dd><?php echo esc_html( (string) $card['action'] ); ?></dd><?php endif; ?><?php if ( ! empty( $card['student'] ) ) : ?><dt>نقش هنرجو</dt><dd><?php echo esc_html( (string) $card['student'] ); ?></dd><?php endif; ?><?php if ( ! empty( $card['admin'] ) ) : ?><dt>زمان ورود مدیر</dt><dd><?php echo esc_html( (string) $card['admin'] ); ?></dd><?php endif; ?><?php if ( ! empty( $card['success'] ) ) : ?><dt>نتیجهٔ موفق</dt><dd><?php echo esc_html( (string) $card['success'] ); ?></dd><?php endif; ?></dl></article><?php endforeach; ?></div></section><?php endif; ?>
			<?php if ( ! empty( $module['scenarios'] ) ) : ?><section class="dzn-tp-induction-practice" aria-labelledby="tp-induction-practice"><h4 id="tp-induction-practice">تمرین کوتاه (اختیاری)</h4><p class="dzn-tp-help">پاسخ‌ها فقط در همین صفحه بررسی می‌شوند و جایی ذخیره نمی‌شوند. هیچ اقدام واقعی زمان‌بندی یا مالی انجام نمی‌شود.</p><?php foreach ( (array) $module['scenarios'] as $scenario_index => $scenario ) : ?><fieldset class="dzn-tp-induction-question" data-dzn-induction-question data-answer="<?php echo esc_attr( (string) (int) ( $scenario['answer'] ?? 0 ) ); ?>" data-explanation="<?php echo esc_attr( (string) ( $scenario['explanation'] ?? '' ) ); ?>"><legend><?php echo esc_html( (string) ( $scenario['prompt'] ?? '' ) ); ?></legend><?php foreach ( (array) ( $scenario['options'] ?? array() ) as $option_index => $option ) : ?><label><input type="radio" name="induction-question-<?php echo esc_attr( (string) $scenario_index ); ?>" value="<?php echo esc_attr( (string) $option_index ); ?>"> <?php echo esc_html( (string) $option ); ?></label><?php endforeach; ?><button class="dzn-button dzn-button--secondary dzn-button--small" type="button" data-dzn-induction-check>بررسی پاسخ</button><p class="dzn-tp-induction-answer" role="status" aria-live="polite"></p></fieldset><?php endforeach; ?></section><?php endif; ?>
			<section class="dzn-tp-induction-complete" aria-labelledby="tp-induction-complete"><h4 id="tp-induction-complete">تکمیل این بخش</h4><p><?php echo esc_html( (string) ( $module['completion_criteria'] ?? 'این بخش را بخوانید و تکمیل کنید.' ) ); ?></p><?php if ( $module_done && ! $needs_reack ) : ?><p class="dzn-tp-notice is-success">این بخش تکمیل شده است. می‌توانید هر زمان به آن برگردید.</p><div class="dzn-tp-actions"><a class="dzn-button" href="<?php echo esc_url( $hub_url ); ?>">بازگشت به همهٔ بخش‌ها</a></div><?php else : ?><form method="post" action="<?php echo esc_url( (string) ( $actions['complete_url'] ?? '' ) ); ?>" data-dzn-tp-submit><input type="hidden" name="module_id" value="<?php echo esc_attr( (string) $module['id'] ); ?>"><input type="hidden" name="content_version" value="<?php echo esc_attr( (string) (int) ( $module['version'] ?? 1 ) ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_induction_complete' ); ?><button class="dzn-button" type="submit"><?php echo $needs_reack ? 'تأیید نسخهٔ به‌روزشده و تکمیل' : 'تکمیل این بخش'; ?></button></form><?php endif; ?></section>
			<nav class="dzn-tp-induction-pager" aria-label="بخش‌های راهنما"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $hub_url ); ?>">همهٔ بخش‌ها</a><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $screen_url( (string) ( $module['portal_screen'] ?? 'home' ) ) ); ?>">رفتن به بخش مرتبط پرتال</a><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $continue_url ); ?>">ادامهٔ یادگیری</a></nav>
		</article>
	<?php else : ?>
		<?php if ( 0 === $completed ) : ?><div class="dzn-tp-induction-welcome"><h3>از کجا شروع کنم؟</h3><p>هفت بخش کوتاه، از آشنایی با پرتال تا غیبت، جبران، مالی و حل مشکلات. لازم نیست همه را یک‌جا بخوانید.</p></div><?php elseif ( $completed < $total ) : ?><div class="dzn-tp-induction-welcome"><h3>ادامه بدهید</h3><p>شما <?php echo esc_html( number_format_i18n( $completed ) ); ?> بخش را تکمیل کرده‌اید. بخش بعدی را از همین‌جا ادامه دهید یا بخش‌های تکمیل‌شده را مرور کنید.</p></div><?php else : ?><div class="dzn-tp-induction-welcome"><h3>همهٔ بخش‌ها تکمیل شده‌اند</h3><p>این راهنما همیشه برای مرور در دسترس است. اگر سیاستی تغییر کند، همان بخش نشان‌دار می‌شود و می‌توانید نسخهٔ تازه را تأیید کنید.</p></div><?php endif; ?>
		<div class="dzn-tp-actions"><a class="dzn-button" href="<?php echo esc_url( $continue_url ); ?>"><?php echo 0 === $completed ? 'شروع یادگیری' : ( $completed < $total ? 'ادامهٔ یادگیری' : 'مرور یک بخش' ); ?></a><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $screen_url( 'home' ) ); ?>">فعلاً رد کن؛ بعداً ادامه می‌دهم</a><?php if ( '' !== $support_url ) : ?><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer">درخواست کمک</a><?php endif; ?></div>
		<ol class="dzn-tp-induction-list">
			<?php foreach ( (array) $hub['modules'] as $item ) : ?>
				<?php $item_state = (string) ( $item['state'] ?? 'not_started' ); $reack = ! empty( $item['needs_reacknowledgement'] ); ?>
				<li class="dzn-tp-induction-card is-<?php echo esc_attr( $item_state ); ?>">
					<div class="dzn-tp-induction-card__number" aria-hidden="true"><?php echo esc_html( number_format_i18n( (int) ( $item['order'] ?? 0 ) ) ); ?></div>
					<div class="dzn-tp-induction-card__body">
						<h3><a href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['title'] ); ?></a></h3>
						<p><?php echo esc_html( (string) $item['description'] ); ?></p>
						<p class="dzn-tp-induction-card__meta"><span class="dzn-tp-state dzn-tp-state--<?php echo esc_attr( 'completed' === $item_state ? 'ended' : ( 'in_progress' === $item_state ? 'in_progress' : 'upcoming' ) ); ?>"><?php echo esc_html( 'completed' === $item_state ? ( $reack ? 'نیازمند تأیید تازه' : 'تکمیل‌شده' ) : ( 'in_progress' === $item_state ? 'در حال یادگیری' : 'شروع‌نشده' ) ); ?></span><?php if ( ! empty( $item['last_visited_at'] ) ) : ?><span class="dzn-tp-help">آخرین بازدید ثبت شده است.</span><?php endif; ?></p>
					</div>
					<div class="dzn-tp-induction-card__action"><a class="dzn-button dzn-button--secondary dzn-button--small" href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo 'completed' === $item_state ? 'مرور' : ( 'in_progress' === $item_state ? 'ادامه' : 'شروع' ); ?></a></div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</section>
