<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\PeopleDirectoryView;
use Delnavazan\Platform\Core\Application\PeopleDirectoryImportService;
use Delnavazan\Platform\Core\Application\PeopleDirectoryReadService;

/**
 * Read-only People directory: Students, Teachers and Leads in one capability-gated
 * administration surface, plus the owner-driven generic contact import.
 *
 * The screen itself never edits or deletes a person. The only write is the CSV
 * import, which is preview-then-confirm, nonce-protected and administrator-only.
 */
final class PeopleDirectoryController {
	public const PAGE = 'dzn-people';
	public const ACTION = 'dzn_people_directory';
	public const CAPABILITY = PeopleDirectoryReadService::CAPABILITY;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function screen(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) wp_die( 'Forbidden', 'Delnavazan', array( 'response' => 403 ) );
		$read = new PeopleDirectoryReadService();
		$view = isset( $_GET['view'] ) && is_scalar( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : '';
		if ( $view === 'import' ) {
			PeopleDirectoryView::import( $read );
			return;
		}
		PeopleDirectoryView::directory( $read, $_GET );
	}

	public static function handle(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
		if ( ! current_user_can( self::CAPABILITY ) ) wp_die( 'Forbidden', 'Delnavazan', array( 'response' => 403 ) );
		check_admin_referer( self::ACTION );
		$operation = sanitize_key( (string) ( wp_unslash( $_POST['operation'] ?? '' ) ) );
		$return = add_query_arg( array( 'page' => self::PAGE, 'view' => 'import' ), admin_url( 'admin.php' ) );
		try {
			if ( $operation === 'import_commit' ) {
				$csv = self::csvText( wp_unslash( $_POST ) );
				$rows = PeopleDirectoryImportService::parseCsv( $csv );
				if ( $rows === array() ) throw new \InvalidArgumentException( 'No contact rows were found in the uploaded CSV.' );
				$key = hash( 'sha256', $csv );
				$result = ( new PeopleDirectoryImportService() )->import( $rows, $key );
				$message = $result['idempotent']
					? 'This CSV was already imported; no duplicate contacts were created.'
					: 'Imported ' . (int) $result['imported'] . ' contact(s), matched ' . (int) $result['matched'] . ' existing person(s), skipped ' . (int) $result['skipped'] . ' duplicate(s).';
				self::redirect( $return, $message, false );
			}
			if ( $operation === 'import_preview' ) {
				$csv = self::csvText( wp_unslash( $_POST ) );
				$rows = PeopleDirectoryImportService::parseCsv( $csv );
				if ( $rows === array() ) throw new \InvalidArgumentException( 'No contact rows were found in the uploaded CSV.' );
				$preview = ( new PeopleDirectoryImportService() )->preview( $rows );
				$key = 'dzn_people_preview_' . get_current_user_id();
				set_transient( $key, array( 'csv' => $csv, 'rows' => $preview['rows'], 'total' => $preview['total'], 'skipped' => $preview['skipped_duplicates'] ), 3600 );
				wp_safe_redirect( $return );
				exit;
			}
			throw new \InvalidArgumentException( 'Unsupported operation' );
		} catch ( \Throwable $exception ) {
			$message = $exception->getMessage();
			if ( $message === 'Unauthorized' ) $message = 'You no longer have permission for this action.';
			self::redirect( $return, $message, true );
		}
	}

	/** @return array<string,mixed>|null */
	public static function storedPreview(): ?array {
		$key = 'dzn_people_preview_' . get_current_user_id();
		$preview = get_transient( $key );
		return is_array( $preview ) ? $preview : null;
	}

	public static function clearPreview(): void {
		delete_transient( 'dzn_people_preview_' . get_current_user_id() );
	}

	private static function csvText( array $post ): string {
		if ( isset( $_FILES['csv']['tmp_name'] ) && is_string( $_FILES['csv']['tmp_name'] ) && $_FILES['csv']['tmp_name'] !== '' && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$content = (string) file_get_contents( $_FILES['csv']['tmp_name'] );
			if ( $content !== '' ) return $content;
		}
		return (string) ( $post['csv_text'] ?? '' );
	}

	private static function redirect( string $url, string $message, bool $error ): never {
		$url = add_query_arg( array( 'dzn_people_notice' => $message, 'dzn_people_error' => $error ? '1' : '0' ), $url );
		nocache_headers();
		if ( wp_safe_redirect( $url ) ) exit;
		wp_die( esc_html( $message ), 'Delnavazan', array( 'response' => $error ? 400 : 200, 'back_link' => true ) );
	}
}
