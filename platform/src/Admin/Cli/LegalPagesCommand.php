<?php
namespace Delnavazan\Platform\Admin\Cli;

use Delnavazan\Platform\Core\Application\LegalPageProvisioner;

/**
 * Create or refresh the five legal-policy pages as unpublished drafts.
 *
 * This command has no publish option by design. Publication remains a separate
 * human action after the owner and legal reviewer confirm the missing facts.
 *
 *   wp dzn-legal provision --dry-run
 *   wp dzn-legal provision
 *   wp dzn-legal status
 */
final class LegalPagesCommand {
    public static function register(): void {
        if ( class_exists( '\WP_CLI' ) ) {
            \WP_CLI::add_command( 'dzn-legal', __CLASS__ );
        }
    }

    /**
     * Create or update the five policy pages as drafts.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report the intended actions without writing.
     *
     * [--no-adopt-legacy]
     * : Do not consolidate the legacy `terms` and `refund_returns` drafts.
     */
    public function provision( array $args, array $assocArgs ): void {
        $options = array(
            'dry_run' => isset( $assocArgs['dry-run'] ),
            'adopt_legacy' => ! isset( $assocArgs['no-adopt-legacy'] ),
        );
        try {
            $result = LegalPageProvisioner::provisionDrafts( $options );
            \WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            if ( ! empty( $result['documents'] ) ) {
                foreach ( $result['documents'] as $document ) {
                    if ( 0 === strpos( (string) $document['reason'], 'wp_' ) || 'source_missing_or_empty' === $document['reason'] ) {
                        \WP_CLI::warning( $document['slug'] . ': ' . $document['reason'] );
                    }
                }
            }
        } catch ( \Throwable $error ) {
            \WP_CLI::error( $error->getMessage() );
        }
    }

    /**
     * Show the current page status without writing.
     */
    public function status( array $args, array $assocArgs ): void {
        \WP_CLI::line( (string) wp_json_encode( LegalPageProvisioner::status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
}
