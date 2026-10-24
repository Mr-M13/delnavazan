<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Temporary consolidated Courses & Instruments workspace.
 *
 * Groups the existing catalogue, instrument, commercial-pricing and exception
 * screens without merging their authorities. Contextual catalogue actions will
 * be added on the individual records in a later PR.
 */
final class CatalogueWorkspaceController {
    public static function screen(): void {
        if (!current_user_can(Menu::CATALOGUE_WORKSPACE_CAP)) wp_die('Forbidden', 403);
        echo '<div class="wrap"><h1>Courses &amp; Instruments</h1>';
        echo '<p>Review the learning offer as instruments, their introductory and standard Courses, and the related commercial pricing and exceptions.</p>';
        echo '<div class="dzn-workspace-grid">';
        self::card('Courses', 'Review Courses, their instrument, duration, buffer and status.', 'dzn-course', 'dzn_manage_courses');
        self::card('Instruments', 'Review instruments and create or reconcile their associated introductory and standard Courses.', 'dzn-instrument', 'dzn_manage_courses');
        self::card('Student pricing', 'Review commercial products and regional prices that make the offer purchasable.', 'dzn-commercial-catalogue', 'dzn_manage_commercial_catalogue');
        self::card('Operational exceptions', 'Review catalogue and operational exceptions that need authorised follow-up.', 'dzn-exception', 'dzn_manage_exceptions');
        echo '</div>';
        echo '<p class="dzn-workspace-note">Temporary navigation: these are the current authorised catalogue workflows. Grouped instrument-and-course records and contextual editing will be added in a later PR.</p>';
        echo '</div>';
    }

    private static function card(string $title, string $description, string $page, string $capability): void {
        if (!current_user_can($capability)) return;
        echo '<section class="dzn-workspace-card"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($page))) . '">Open ' . esc_html($title) . '</a></section>';
    }
}
