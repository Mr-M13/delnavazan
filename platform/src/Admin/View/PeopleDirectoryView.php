<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\PeopleDirectoryController;
use Delnavazan\Platform\Core\Application\PeopleDirectoryImportService;
use Delnavazan\Platform\Core\Application\PeopleDirectoryReadService;

/** Accessible presentation for the read-only People directory. */
final class PeopleDirectoryView {
	/** @param array<string,mixed> $query */
	public static function directory( PeopleDirectoryReadService $read, array $query ): void {
		$filters = array(
			'q' => isset( $query['q'] ) && is_scalar( $query['q'] ) ? sanitize_text_field( wp_unslash( (string) $query['q'] ) ) : '',
			'type' => isset( $query['type'] ) && is_scalar( $query['type'] ) ? sanitize_key( wp_unslash( (string) $query['type'] ) ) : '',
			'status' => isset( $query['status'] ) && is_scalar( $query['status'] ) ? sanitize_key( wp_unslash( (string) $query['status'] ) ) : '',
			'group' => isset( $query['group'] ) && is_scalar( $query['group'] ) ? sanitize_key( wp_unslash( (string) $query['group'] ) ) : '',
			'page' => isset( $query['page'] ) && is_scalar( $query['page'] ) ? absint( $query['page'] ) : 1,
			'per_page' => isset( $query['per_page'] ) && is_scalar( $query['per_page'] ) ? absint( $query['per_page'] ) : 25,
		);
		$result = $read->directory( $filters );
		$counts = $read->groupCounts();

		echo '<div class="wrap dzn-people"><h1>People</h1>';
		self::notices();
		echo '<p>Students, Teachers and Leads in one read-only directory. A row links through to the existing Student or Teacher workspace.</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . PeopleDirectoryController::PAGE . '&view=import' ) ) . '">Import contacts (CSV)</a></p>';

		self::filterForm( $filters, $counts );

		$rows = $result['rows'];
		if ( ! $rows ) {
			echo '<p>No people match the current filters.</p>';
		} else {
			self::table( $rows );
			self::pagination( $filters, $result );
		}
		echo '</div>';
	}

	public static function import( PeopleDirectoryReadService $read ): void {
		$preview = PeopleDirectoryController::storedPreview();
		echo '<div class="wrap dzn-people"><h1>People · import contacts</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . PeopleDirectoryController::PAGE ) ) . '">← Back to People</a></p>';
		self::notices();

		if ( $preview ) {
			self::previewTable( $preview );
			self::previewConfirmForm( $preview );
			return;
		}

		self::uploadForm();
		echo '</div>';
	}

	private static function uploadForm(): void {
		echo '<form method="post" enctype="multipart/form-data" class="dzn-people__import">';
		wp_nonce_field( PeopleDirectoryController::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( PeopleDirectoryController::ACTION ) . '">';
		echo '<input type="hidden" name="operation" value="import_preview">';
		echo '<p>Upload a CSV with a header row of <code>name,phone,email,city,country,instrument,teacher_name,source</code>. '
			. 'Rows are matched to existing Students and Teachers by normalised phone or email so one person is not duplicated. '
			. 'Nothing is written until you confirm the preview.</p>';
		echo '<label>CSV file <input type="file" name="csv" accept=".csv,text/csv,text/plain"></label>';
		echo '<label class="dzn-people__paste">or paste CSV <textarea name="csv_text" rows="6" placeholder="name,phone,email,city,country,instrument,teacher_name"></textarea></label>';
		echo '<p><button class="button button-primary">Preview import</button></p>';
		echo '</form>';
	}

	/** @param array<string,mixed> $preview */
	private static function previewTable( array $preview ): void {
		$rows = $preview['rows'];
		echo '<h2>Preview</h2>';
		echo '<p>' . esc_html( (string) ( $preview['total'] ?? count( $rows ) ) ) . ' contact(s) parsed';
		if ( (int) ( $preview['skipped'] ?? 0 ) > 0 ) echo ', ' . esc_html( (string) $preview['skipped'] ) . ' duplicate(s) collapsed';
		echo '.</p>';
		if ( ! $rows ) { echo '<p>No contacts to import.</p>'; return; }
		echo '<table class="widefat striped dzn-people__table"><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Location</th><th>Instrument</th><th>Teacher (given)</th><th>Match</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$match = (string) ( $row['matched_label'] ?? '' );
			$matched = ( (int) ( $row['matched_student_id'] ?? 0 ) > 0 ) || ( (int) ( $row['matched_teacher_id'] ?? 0 ) > 0 );
			echo '<tr><td>' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['phone'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['email'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( trim( ( (string) ( $row['city'] ?? '' ) . ' ' . (string) ( $row['country'] ?? '' ) ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['instrument'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['teacher_name'] ?? '' ) ) . '</td>';
			echo '<td>' . ( $matched ? '<span class="dzn-badge dzn-badge--match">' . esc_html( $match ) . '</span>' : '<span class="dzn-badge dzn-badge--new">new</span>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $preview */
	private static function previewConfirmForm( array $preview ): void {
		$csv = (string) ( $preview['csv'] ?? '' );
		echo '<form method="post">';
		wp_nonce_field( PeopleDirectoryController::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( PeopleDirectoryController::ACTION ) . '">';
		echo '<input type="hidden" name="operation" value="import_commit">';
		echo '<textarea name="csv_text" hidden>' . esc_textarea( $csv ) . '</textarea>';
		echo '<p><button class="button button-primary">Confirm import</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . PeopleDirectoryController::PAGE . '&view=import' ) ) . '">Cancel</a></p>';
		echo '</form>';
	}

	/** @param array<string,mixed> $filters @param array<string,int> $counts */
	private static function filterForm( array $filters, array $counts ): void {
		$page = PeopleDirectoryController::PAGE;
		$base = admin_url( 'admin.php' );
		echo '<form method="get" class="dzn-people__filter">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		echo '<label>Search <input type="search" name="q" value="' . esc_attr( $filters['q'] ) . '" placeholder="Name, email or phone"></label>';
		echo '<label>Type <select name="type"><option value="">All</option>';
		foreach ( array( 'student' => 'Students', 'teacher' => 'Teachers', 'lead' => 'Leads' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['type'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Status <select name="status"><option value="">All</option>';
		foreach ( array( 'active' => 'Active', 'former' => 'Former', 'lead' => 'Lead' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['status'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Group <select name="group"><option value="">Everyone</option>';
		foreach ( array(
			'active_students' => 'Active students',
			'former_students' => 'Former students',
			'active_teachers' => 'Active teachers',
			'former_teachers' => 'Former teachers',
			'leads' => 'Leads (intro without enrolment)',
			'imported_contacts' => 'Imported contacts',
		) as $value => $label ) {
			$count = $counts[ $value ] ?? 0;
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['group'], $value, false ) . '>' . esc_html( $label . ' (' . $count . ')' ) . '</option>';
		}
		echo '</select></label>';
		echo '<button class="button">Filter</button> <a class="button" href="' . esc_url( add_query_arg( 'page', $page, $base ) ) . '">Reset</a>';
		echo '</form>';
	}

	/** @param array<int,array<string,mixed>> $rows */
	private static function table( array $rows ): void {
		echo '<table class="widefat striped dzn-people__table"><thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Contact</th><th>Location</th><th>Instrument</th><th>Enrolment / terms</th><th>Workspace</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$type = (string) ( $row['type'] ?? '' );
			$status = (string) ( $row['status'] ?? '' );
			$id = (int) ( $row['id'] ?? 0 );
			$workspace = '';
			if ( $type === 'student' && $id > 0 ) {
				$workspace = '<a href="' . esc_url( add_query_arg( array( 'page' => 'dzn-student', 'id' => $id ), admin_url( 'admin.php' ) ) ) . '">Student workspace</a>';
			} elseif ( $type === 'teacher' && $id > 0 ) {
				$workspace = '<a href="' . esc_url( add_query_arg( array( 'page' => 'dzn-teachers-menu', 'id' => $id ), admin_url( 'admin.php' ) ) ) . '">Teacher workspace</a>';
			}
			$term = $type === 'student' || $status === 'lead' || $type === 'lead'
				? PeopleDirectoryReadService::termCountLabel( (int) ( $row['term_count'] ?? 0 ) )
				: '—';
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</strong></td>';
			echo '<td>' . esc_html( ucfirst( $type ) ) . '</td>';
			echo '<td>' . self::badge( $status ) . '</td>';
			echo '<td>' . self::contact( $row ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['location'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['instrument'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $term ) . '</td>';
			echo '<td>' . $workspace . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $row */
	private static function contact( array $row ): string {
		$parts = array_filter( array( (string) ( $row['phone'] ?? '' ), (string) ( $row['email'] ?? '' ) ) );
		return esc_html( implode( ' · ', $parts ) );
	}

	private static function badge( string $status ): string {
		$label = array( 'active' => 'Active', 'former' => 'Former', 'lead' => 'Lead' )[ $status ] ?? ucfirst( $status );
		return '<span class="dzn-badge dzn-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/** @param array<string,mixed> $filters @param array<string,mixed> $result */
	private static function pagination( array $filters, array $result ): void {
		$pages = (int) ( $result['pages'] ?? 1 );
		if ( $pages <= 1 ) return;
		$page = (int) ( $result['page'] ?? 1 );
		$links = array();
		for ( $i = 1; $i <= $pages; $i++ ) {
			$args = array_merge( $filters, array( 'page' => $i ) );
			$args = array_filter( $args, static fn( $value ): bool => $value !== '' && $value !== null );
			$url = add_query_arg( $args, admin_url( 'admin.php' ) );
			$links[] = '<a class="button' . ( $i === $page ? ' button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . $i . '</a>';
		}
		echo '<div class="tablenav"><div class="tablenav-pages">' . implode( ' ', $links ) . '</div></div>';
	}

	private static function notices(): void {
		$message = isset( $_GET['dzn_people_notice'] ) && is_scalar( $_GET['dzn_people_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dzn_people_notice'] ) ) : '';
		if ( $message === '' ) return;
		$error = (string) ( $_GET['dzn_people_error'] ?? '0' ) === '1';
		echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		PeopleDirectoryController::clearPreview();
	}
}
