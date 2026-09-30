<?php
/**
 * Template Name: Student Portal — Home
 * Template Post Type: page
 *
 * @package DelnavazanTheme
 */

get_header();

while ( have_posts() ) {
	the_post();
	dzn_theme_render_student_portal( 'home', dzn_theme_student_portal_view_model( 'home' ) );
}

get_footer();
