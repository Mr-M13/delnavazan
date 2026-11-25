<?php
/**
 * Unassigned introductory opportunities on the Teacher's Account and Classes screens.
 *
 * Eligibility is read-only here: the backend returns only requests for an instrument this
 * Teacher may teach, with no active offer, confirmation or accepted delivery. Claiming re-reads
 * and locks the same request, so a stale card fails safely if another Teacher claimed it first.
 */
$items   = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context = dzn_theme_teacher_portal_context();
$demo    = ! empty( $context['demo'] );
?>
<section class="dzn-tp-section dzn-tp-open-requests" aria-labelledby="tp-open-requests">
	<div class="dzn-tp-heading">
		<div><h2 id="tp-open-requests">درخواست‌های بدون پاسخ</h2><p class="dzn-tp-help">این درخواست‌ها هنوز به مدرسی واگذار نشده‌اند و در زمان‌های پیشنهادی با برنامهٔ اعلام‌شدهٔ شما هماهنگ نشده‌اند. با این حال، می‌توانید آن‌ها را بررسی کرده و در صورت امکان بپذیرید.</p></div>
		<span class="dzn-tp-count-badge"><?php echo esc_html( dzn_theme_teacher_portal_digits( count( $items ) ) . ' مورد' ); ?></span>
	</div>
	<?php if ( ! $items ) : ?>
		<p class="dzn-tp-empty">در حال حاضر درخواست بدون پاسخی برای سازهایی که تدریس می‌کنید وجود ندارد. اگر سازهای قابل تدریس خود را تغییر داده‌اید، پس از ذخیرهٔ آن‌ها این بخش با درخواست‌های همان سازها به‌روز می‌شود.</p>
	<?php else : ?>
		<div class="dzn-tp-attention-grid">
		<?php foreach ( $items as $index => $item ) : ?>
			<?php dzn_theme_teacher_portal_component( 'intro-request-card', array( 'item' => $item, 'opportunity' => true, 'index' => 'open-' . $index, 'demo' => $demo ) ); ?>
		<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
