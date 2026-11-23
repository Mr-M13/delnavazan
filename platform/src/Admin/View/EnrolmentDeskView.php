<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\EnrolmentDeskController;

/**
 * List and detail for the Enrolment Desk. Read-only by design: the one action on each step is
 * a link to the existing authority, never a second implementation of it.
 *
 * See docs/ENROLMENT-DESK-SPEC-2026-11-23.md.
 */
final class EnrolmentDeskView {

    /** @param array<int,array<string,mixed>> $rows */
    public static function index( array $rows, int $total ): void {
        echo '<div class="wrap dzn-enrolment-desk"><h1>Enrolment desk</h1>';
        echo '<p class="description">One person, one status, one next action. Statuses are derived from the booking and term records; nothing here is a separate source of truth.</p>';
        if ( $rows === array() ) {
            echo '<div class="notice notice-info"><p>No people need attention right now.</p></div></div>';
            return;
        }
        echo '<p><strong>' . esc_html( (string) $total ) . '</strong> in the current queue.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Learner</th><th>Instrument</th><th>Status</th><th>Next action</th><th>Updated</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $url = admin_url( 'admin.php?page=' . EnrolmentDeskController::PAGE . '&request_id=' . (int) ( $row['id'] ?? 0 ) );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( (string) ( $row['learner_name'] ?? 'Learner' ) ) . '</strong></a><br><small>' . esc_html( (string) ( $row['reference'] ?? '' ) ) . '</small></td>';
            echo '<td>' . esc_html( (string) ( $row['instrument'] ?? '' ) ) . '</td>';
            echo '<td><span class="dzn-desk-status dzn-desk-status--' . esc_attr( (string) ( $row['desk_tone'] ?? 'muted' ) ) . '">' . esc_html( (string) ( $row['desk_status'] ?? '' ) ) . '</span></td>';
            echo '<td><a class="button button-small" href="' . esc_url( (string) ( $row['desk_href'] ?? $url ) ) . '">' . esc_html( (string) ( $row['desk_next'] ?? 'Open' ) ) . '</a></td>';
            echo '<td>' . esc_html( (string) ( $row['age_label'] ?? '' ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** @param array<string,mixed> $person */
    public static function detail( array $person ): void {
        $case  = (array) $person['case'];
        $desk  = (array) $person['desk'];
        $steps = (array) $person['steps'];
        $alerts = (array) $person['alerts'];

        echo '<div class="wrap dzn-enrolment-desk dzn-enrolment-desk--person">';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . EnrolmentDeskController::PAGE ) ) . '">← Back to the desk</a></p>';
        echo '<h1>' . esc_html( (string) ( $case['learner_name'] ?? 'Learner' ) ) . '</h1>';
        echo '<p>' . esc_html( (string) ( $case['instrument'] ?? '' ) ) . ' · ' . esc_html( (string) ( $case['course'] ?? '' ) ) . ' · ' . esc_html( (string) ( $case['timezone'] ?? '' ) ) . ' · <code>' . esc_html( (string) ( $case['reference'] ?? '' ) ) . '</code></p>';
        echo '<p><span class="dzn-desk-status dzn-desk-status--' . esc_attr( (string) ( $desk['tone'] ?? 'muted' ) ) . '">' . esc_html( (string) ( $desk['label'] ?? '' ) ) . '</span></p>';

        echo '<ol class="dzn-desk-steps">';
        foreach ( $steps as $step ) {
            $state = (string) ( $step['state'] ?? 'pending' );
            echo '<li class="dzn-desk-step dzn-desk-step--' . esc_attr( $state ) . '">';
            echo '<h2><span class="dzn-desk-step__number">' . esc_html( (string) ( $step['number'] ?? '' ) ) . '</span> ' . esc_html( (string) ( $step['title'] ?? '' ) ) . '</h2>';
            echo '<p><strong>' . esc_html( (string) ( $step['detail'] ?? '' ) ) . '</strong></p>';
            echo '<p class="description">' . esc_html( (string) ( $step['note'] ?? '' ) ) . '</p>';
            echo '</li>';
        }
        echo '</ol>';

        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=dzn-enrolment-workspace&request_id=' . (int) ( $case['id'] ?? 0 ) ) . (string) ( $desk['anchor'] ?? '' ) ) . '">' . esc_html( (string) ( $desk['next'] ?? 'Open the case' ) ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=dzn-enrolment-workspace&request_id=' . (int) ( $case['id'] ?? 0 ) ) ) . '">Open the full case</a></p>';

        if ( $alerts !== array() ) {
            echo '<h2>Needs attention</h2>';
            foreach ( $alerts as $alert ) {
                echo '<div class="notice notice-warning inline dzn-desk-alert"><p>' . esc_html( (string) ( $alert['text'] ?? '' ) ) . ' <a href="' . esc_url( (string) ( $alert['href'] ?? '#' ) ) . '">' . esc_html( (string) ( $alert['action'] ?? 'Open' ) ) . '</a></p></div>';
            }
        }

        echo '</div>';
    }
}
