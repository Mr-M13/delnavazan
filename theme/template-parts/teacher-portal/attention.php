<?php
/**
 * Items needing the teacher's attention. Only a teacher-scoped, backend-authorised booking request is actionable in production;
 * preview-only controls render solely in the non-production preview (see dzn_theme_teacher_portal_context()).
 */
$items   = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context = dzn_theme_teacher_portal_context();
$demo    = ! empty( $context['demo'] );
if ( ! $items ) { return; }
?>
<section class="dzn-tp-section" aria-labelledby="tp-attention"><div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">نیازمند توجه شما</p><h2 id="tp-attention">کارهای مهم</h2></div><span class="dzn-tp-count-badge"><?php echo esc_html( sprintf( '%d مورد', count( $items ) ) ); ?></span></div>
<?php if ( ! $items ) : ?>
	<div class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status"><h3>اکنون کار فوری‌ای ندارید</h3><p>درخواست‌های تازهٔ کلاس و موارد نیازمند پاسخ شما همین‌جا نمایش داده می‌شوند.</p></div>
<?php else : ?>
<div class="dzn-tp-attention-grid">
<?php foreach ( $items as $index => $item ) :
	$valid      = dzn_theme_teacher_portal_attention_valid( $item );
	$authored   = $valid && 'intro_request' === $item['state'] && ! empty( $item['authoritative_request'] );
	$reschedule = $valid && ! empty( $item['reschedule_available'] ) && ! empty( $item['lesson_uid'] ) && ! empty( $item['schedule_version_uid'] );
	$actionable = $valid && ( $authored || $reschedule || $demo );
	?>
	<article class="dzn-tp-attention-card is-<?php echo esc_attr( $valid ? $item['state'] : 'unknown' ); ?>"><p class="dzn-tp-state"><span aria-hidden="true">◆</span> <?php echo esc_html( $valid ? ( $item['due'] ?? 'نیازمند بررسی' ) : 'وضعیت ناشناخته' ); ?></p><h3><?php echo esc_html( $valid ? ( $item['title'] ?? '' ) : 'این مورد در دسترس نیست' ); ?></h3><p class="dzn-tp-attention-context"><strong><?php echo esc_html( $valid ? ( $item['context'] ?? '' ) : '' ); ?></strong></p><p class="dzn-tp-attention-msg"><?php echo esc_html( $valid ? ( $item['message'] ?? '' ) : 'برای امنیت، همهٔ اقدام‌ها پنهان شدند.' ); ?></p>
	<?php if ( $valid && ! $actionable ) : ?><p class="dzn-tp-help">این مورد فقط برای اطلاع نمایش داده می‌شود؛ اقدام روی آن هنوز از همین پرتال فعال نیست.</p><?php endif; ?>
	<?php if ( $actionable ) : ?><div class="dzn-tp-actions"><button class="dzn-button" type="button" aria-haspopup="dialog" data-dzn-tp-open="tp-action-<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $item['primary'] ); ?></button><?php if ( dzn_theme_teacher_portal_text( $item['secondary'] ?? null ) ) : ?><button class="dzn-button dzn-button--secondary" type="button" aria-haspopup="dialog" data-dzn-tp-open="tp-action-<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $item['secondary'] ); ?></button><?php endif; ?></div>
	<dialog id="tp-action-<?php echo esc_attr( $index ); ?>" class="dzn-tp-dialog" aria-labelledby="tp-action-title-<?php echo esc_attr( $index ); ?>" aria-modal="true"><div><button type="button" class="dzn-tp-close" data-dzn-tp-close aria-label="بستن">×</button><h2 id="tp-action-title-<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $item['title'] ?? '' ); ?></h2>
	<?php if ( $reschedule ) : ?><p><?php echo esc_html( $item['message'] ?? '' ); ?></p><?php dzn_theme_teacher_portal_component( 'reschedule-form', array( 'item' => $item, 'timezone' => (string) ( $item['reschedule_timezone'] ?? '' ), 'index' => $index, 'demo' => $demo ) ); ?>
	<?php elseif ( $authored ) : ?><?php $response_key = wp_generate_uuid4(); ?><p><?php echo esc_html( $item['message'] ?? '' ); ?></p><p><strong>وضعیت: درخواست در انتظار بررسی.</strong> پذیرش ثبت می‌شود. برای جلسهٔ معارفهٔ رایگان، رویداد Google Calendar و پیوند Meet ساخته می‌شود و کلاس فقط پس از موفقیت هر دو تأیید می‌شود. برای جلسهٔ معارفهٔ پرداختی، ساخت تقویم و پیوند Meet پس از پرداخت موفق هنرجو انجام می‌شود.</p><div class="dzn-tp-actions"><form method="post" action="<?php echo esc_url( $item['response_action_url'] ?? '' ); ?>" data-dzn-tp-submit><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $item['response_nonce'] ?? '' ); ?>"><input type="hidden" name="response_key" value="<?php echo esc_attr( $response_key ); ?>"><input type="hidden" name="delivery_uid" value="<?php echo esc_attr( $item['delivery_uid'] ?? '' ); ?>"><input type="hidden" name="decision" value="accept"><button class="dzn-button" type="submit" data-dzn-tp-busy-label="در حال ساخت رویداد و پیوند Meet… لطفاً صفحه را نبندید">پذیرش درخواست</button></form><form method="post" action="<?php echo esc_url( $item['response_action_url'] ?? '' ); ?>" data-dzn-tp-submit><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $item['response_nonce'] ?? '' ); ?>"><input type="hidden" name="response_key" value="<?php echo esc_attr( $response_key ); ?>"><input type="hidden" name="delivery_uid" value="<?php echo esc_attr( $item['delivery_uid'] ?? '' ); ?>"><input type="hidden" name="decision" value="decline"><button class="dzn-button dzn-button--secondary" type="submit" data-dzn-tp-busy-label="در حال ثبت رد درخواست…">رد درخواست</button></form></div><p class="dzn-tp-action-status" role="status" aria-live="polite"></p>
	<?php else : ?><p>این کنترل فقط پیش‌نمایش رابط آینده است؛ هیچ اطلاعات یا حقیقتی ثبت نشد.</p><?php if ( 'intro_request' === $item['state'] ) : ?><fieldset><legend>چند زمان جایگزین نمایشی</legend><label><input type="checkbox"> یکشنبه ۱۸:۰۰</label><label><input type="checkbox"> سه‌شنبه ۱۹:۳۰</label><label><input type="checkbox"> پنجشنبه ۱۷:۰۰</label></fieldset><?php elseif ( in_array( $item['state'], array( 'replacement', 'flexible_term_dates' ), true ) ) : ?><label>تاریخ نمایشی <input type="date" disabled></label><label>ساعت نمایشی <input type="time" disabled></label><?php elseif ( 'paid_term_review' === $item['state'] ) : ?><ol class="dzn-tp-schedule-summary"><?php for ( $i = 1; $i <= 12; $i++ ) : ?><li>جلسهٔ <?php echo esc_html( $i ); ?> · شنبه</li><?php endfor; ?></ol><?php endif; ?><button class="dzn-button" type="button" data-dzn-tp-presentation>ادامه در آینده</button><p class="dzn-tp-action-status" role="status" aria-live="polite"></p><?php endif; ?></div></dialog><?php endif; ?>
	</article>
<?php endforeach; ?>
</div>
<?php endif; ?></section>
