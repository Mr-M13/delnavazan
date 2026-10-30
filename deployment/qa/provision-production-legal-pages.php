<?php
/**
 * Production payload: create or refresh the five legal pages as drafts.
 *
 * This payload has no publish operation. It is executed only through the
 * guarded `provision-production-legal-pages.sh` workflow step.
 */
use Delnavazan\Platform\Core\Application\LegalPageProvisioner;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "Refused: WP-CLI execution only.\n" );
    exit( 1 );
}
if ( 'production' !== wp_get_environment_type() ) {
    fwrite( STDERR, "Refused: production environment only.\n" );
    exit( 1 );
}
if ( ! class_exists( LegalPageProvisioner::class ) ) {
    fwrite( STDERR, "Refused: legal provisioner is unavailable in the deployed plugin.\n" );
    exit( 1 );
}

$result = LegalPageProvisioner::provisionDrafts(
    array(
        'dry_run' => false,
        'adopt_legacy' => true,
    )
);
echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
echo wp_json_encode( LegalPageProvisioner::status(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
