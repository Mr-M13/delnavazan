<?php
/**
 * Inline, dismissible contextual guidance.
 *
 * Uses native <details> so it works without JavaScript and is available again on every visit.
 * It never replaces the portal tour, and it links to the full induction module rather than
 * duplicating a long tutorial in a pop-up.
 *
 * @package DelnavazanTheme
 */
$title = trim( (string) ( $args['title'] ?? 'راهنما' ) );
$text = trim( (string) ( $args['text'] ?? '' ) );
if ( '' === $title || '' === $text ) { return; }
$module = sanitize_key( (string) ( $args['module'] ?? '' ) );
$induction_url = add_query_arg( 'teacher-view', 'induction', home_url( '/teacher-portal/' ) ) . ( '' !== $module ? '#tp-step-' . $module : '' );
?>
<details class="dzn-tp-tip">
	<summary><span class="dzn-tp-tip__icon" aria-hidden="true">؟</span><span><?php echo esc_html( $title ); ?></span></summary>
	<div class="dzn-tp-tip__body"><p><?php echo esc_html( $text ); ?></p><?php if ( '' !== $module ) : ?><p><a class="dzn-portal-text-action" href="<?php echo esc_url( $induction_url ); ?>">توضیح کامل در راهنمای تدریس</a></p><?php endif; ?></div>
</details>
