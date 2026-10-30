<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\LegalPageProvisioner;

/**
 * Explicit operator action that creates/refreshes policy drafts.
 *
 * It has no publish action. The owner publishes a reviewed page in WordPress.
 */
final class LegalPagesController {
    public const ACTION = 'dzn_provision_legal_pages';

    public static function register(): void {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    public static function handle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( self::ACTION );

        $result = LegalPageProvisioner::provisionDrafts(
            array(
                'dry_run' => false,
                'adopt_legacy' => true,
            )
        );
        $state = ( $result['created'] + $result['updated'] ) > 0 ? 'provisioned' : 'unchanged';
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => GeneralSettingsController::MENU_SLUG,
                    'dzn_legal_result' => $state,
                    'dzn_legal_created' => (int) $result['created'],
                    'dzn_legal_updated' => (int) $result['updated'],
                    'dzn_legal_skipped' => (int) $result['skipped'],
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
