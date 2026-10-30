<?php
/**
 * Disposable-runtime proof for legal-policy draft provisioning.
 *
 * Run only in the isolated local/development WordPress runtime:
 *   wp eval-file platform/tests/legal-pages-provisioner-runtime.php
 */
use Delnavazan\Platform\Core\Application\LegalPageProvisioner;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
    fwrite( STDERR, "Legal-page provisioner runtime refused: local/development WP-CLI only.\n" );
    exit( 1 );
}

function dzn_legal_runtime_assert( bool $condition, string $message ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

function dzn_legal_runtime_pages(): array {
    return get_posts(
        array(
            'post_type' => 'page',
            'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
            'posts_per_page' => -1,
            'meta_key' => LegalPageProvisioner::META_MARKER,
        )
    );
}

function dzn_legal_runtime_delete_test_pages(): void {
    foreach ( dzn_legal_runtime_pages() as $page ) {
        wp_delete_post( (int) $page->ID, true );
    }
    foreach ( LegalPageProvisioner::documents() as $document ) {
        foreach ( array_merge( array( (string) $document['slug'] ), (array) $document['legacy'] ) as $slug ) {
            $posts = get_posts(
                array(
                    'post_type' => 'page',
                    'name' => (string) $slug,
                    'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
                    'posts_per_page' => -1,
                )
            );
            foreach ( $posts as $post ) {
                if ( in_array( (string) $post->post_name, array( (string) $document['slug'], 'terms', 'refund_returns' ), true ) ) {
                    wp_delete_post( (int) $post->ID, true );
                }
            }
        }
    }
}

dzn_legal_runtime_delete_test_pages();

// A pending legacy draft must be consolidated in place, not duplicated.
$legacyId = wp_insert_post(
    array(
        'post_type' => 'page',
        'post_status' => 'pending',
        'post_title' => 'Legacy terms',
        'post_name' => 'terms',
        'post_content' => '<p>Legacy content</p>',
    ),
    true
);
dzn_legal_runtime_assert( ! is_wp_error( $legacyId ) && (int) $legacyId > 0, 'Could not create the legacy pending page' );

$first = LegalPageProvisioner::provisionDrafts();
dzn_legal_runtime_assert( 4 === (int) $first['created'], 'Expected four new drafts, got ' . (int) $first['created'] );
dzn_legal_runtime_assert( 1 === (int) $first['updated'], 'Expected the legacy pending page to be consolidated' );

foreach ( LegalPageProvisioner::documents() as $key => $document ) {
    $page = get_page_by_path( (string) $document['slug'], OBJECT, 'page' );
    dzn_legal_runtime_assert( $page instanceof WP_Post, 'Missing canonical page: ' . $key );
    dzn_legal_runtime_assert( 'publish' !== get_post_status( $page ), 'A legal page must not be published by provisioning: ' . $key );
    dzn_legal_runtime_assert( LegalPageProvisioner::PAGE_TEMPLATE === get_post_meta( (int) $page->ID, '_wp_page_template', true ), 'Policy template not attached: ' . $key );
    dzn_legal_runtime_assert( $key === get_post_meta( (int) $page->ID, LegalPageProvisioner::META_MARKER, true ), 'Legal marker missing: ' . $key );
    dzn_legal_runtime_assert( LegalPageProvisioner::REVIEW_STATUS === get_post_meta( (int) $page->ID, LegalPageProvisioner::META_REVIEW, true ), 'Review status missing: ' . $key );
    dzn_legal_runtime_assert( false !== strpos( (string) $page->post_content, '<h2>' ), 'Provisioned content is empty: ' . $key );
}
dzn_legal_runtime_assert( ! get_page_by_path( 'terms', OBJECT, 'page' ), 'The legacy terms slug still duplicates the canonical page' );

$second = LegalPageProvisioner::provisionDrafts();
dzn_legal_runtime_assert( 0 === (int) $second['created'] && 0 === (int) $second['updated'], 'A repeated provision run must not write again' );
dzn_legal_runtime_assert( 5 === (int) $second['skipped'], 'A repeated provision run must report all five as current' );

// A published, manually managed policy must never be overwritten by provisioning.
$cookie = get_page_by_path( 'cookie-policy', OBJECT, 'page' );
dzn_legal_runtime_assert( $cookie instanceof WP_Post, 'Cookie draft missing before published-page guard test' );
wp_delete_post( (int) $cookie->ID, true );
$publishedId = wp_insert_post(
    array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Manually published cookie policy',
        'post_name' => 'cookie-policy',
        'post_content' => '<p>LIVE POLICY — DO NOT OVERWRITE</p>',
    ),
    true
);
dzn_legal_runtime_assert( ! is_wp_error( $publishedId ) && (int) $publishedId > 0, 'Could not create the published guard page' );
$guard = LegalPageProvisioner::provisionDrafts();
$cookieResult = null;
foreach ( $guard['documents'] as $document ) {
    if ( 'cookie-policy' === $document['key'] ) { $cookieResult = $document; }
}
dzn_legal_runtime_assert( is_array( $cookieResult ) && 'existing_published_page_not_owned' === $cookieResult['reason'], 'A published policy was not protected from provisioning' );
dzn_legal_runtime_assert( false !== strpos( (string) get_post_field( 'post_content', (int) $publishedId ), 'LIVE POLICY — DO NOT OVERWRITE' ), 'A published policy was overwritten' );
wp_delete_post( (int) $publishedId, true );

// A dry run must never create a page.
$dry = LegalPageProvisioner::provisionDrafts( array( 'dry_run' => true ) );
dzn_legal_runtime_assert( 1 === (int) $dry['created'] && ! get_page_by_path( 'cookie-policy', OBJECT, 'page' ), 'A dry run created a page' );

dzn_legal_runtime_delete_test_pages();
echo "Legal-page provisioner runtime passed\n";
