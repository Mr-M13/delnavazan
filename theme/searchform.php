<?php
/**
 * Accessible search form.
 *
 * @package DelnavazanTheme
 */
?>
<form role="search" method="get" class="dzn-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label for="dzn-search-field"><?php esc_html_e( 'جست‌وجو', 'delnavazan-theme' ); ?></label>
	<div class="dzn-search-form__controls">
		<input id="dzn-search-field" type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>">
		<button class="dzn-button" type="submit"><?php esc_html_e( 'جست‌وجو', 'delnavazan-theme' ); ?></button>
	</div>
</form>
