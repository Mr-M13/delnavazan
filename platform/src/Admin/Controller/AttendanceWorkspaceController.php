<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Temporary consolidated Attendance & Post-intro workspace.
 *
 * Attendance adjudication and post-intro automation remain separate canonical
 * workflows. Introductory outcomes continue to be recorded on the class record
 * until a contextual combined action is introduced.
 */
final class AttendanceWorkspaceController {
    public static function screen(): void {
        if (!current_user_can(Menu::ATTENDANCE_WORKSPACE_CAP)) wp_die('Forbidden', 403);
        echo '<div class="wrap"><h1>Attendance &amp; Post-intro</h1>';
        echo '<p>Review canonical attendance evidence, introductory delivery outcomes and post-intro preparation exceptions. These are separate backend workflows; this page does not merge their authority.</p>';
        echo '<div class="dzn-workspace-grid">';
        self::card('Attendance review', 'Review evidence and apply the authorised attendance adjudication for the exact case version.', 'dzn-attendance-review', 'dzn_manage_canonical_attendance_review');
        self::card('Post-intro automation', 'Review preparation exceptions, retryable activation jobs and the automation identity.', 'dzn-post-intro', 'dzn_manage_post_intro_automation');
        self::card('Classes and schedule', 'Open the class record to record an introductory delivery outcome or review its schedule.', 'dzn-lesson', 'dzn_manage_lessons');
        echo '</div>';
        echo '<p class="dzn-workspace-note">Temporary navigation: attendance and post-intro keep their existing authorities. A combined operational read view and contextual retry actions will be added in a later PR.</p>';
        echo '</div>';
    }

    private static function card(string $title, string $description, string $page, string $capability): void {
        if (!current_user_can($capability)) return;
        echo '<section class="dzn-workspace-card"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($page))) . '">Open ' . esc_html($title) . '</a></section>';
    }
}
