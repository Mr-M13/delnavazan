<?php
/**
 * Unassigned introductory opportunities at the bottom of the Teacher's Classes screen.
 *
 * Eligibility is read-only here: the backend returns only requests for an instrument this
 * Teacher may teach, with no active offer, confirmation or accepted delivery. Claiming re-reads
 * and locks the same request, so a stale card fails safely if another Teacher claimed it first.
 */
$items   = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context = dzn_theme_teacher_portal_context();
$demo    = ! empty( $context['demo'] );
if ( ! $items ) { return; }
?>
<section class="dzn-tp-section dzn-tp-open-requests" aria-labelledby="tp-open-requests">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">فرصت‌های آزاد</p><h2 id="tp-open-requests">فرصت‌های کلاس آشنایی</h2><p class="dzn-tp-help">درخواست‌های بدون مدرس برای سازهایی که تدریس می‌کنید</p></div>
		<span class="dzn-tp-count-badge"><?php echo esc_html( sprintf( '%d مورد', count( $items ) ) ); ?></span>
	</div>
	<div class="dzn-tp-attention-grid">
	<?php foreach ( $items as $index => $item ) : ?>
		<?php dzn_theme_teacher_portal_component( 'intro-request-card', array( 'item' => $item, 'opportunity' => true, 'index' => 'open-' . $index, 'demo' => $demo ) ); ?>
	<?php endforeach; ?>
	</div>
</section>
