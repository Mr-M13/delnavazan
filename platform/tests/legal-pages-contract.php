<?php
/**
 * Source contract for the five public legal-policy pages.
 *
 * This is a non-WordPress source check. It proves that the reviewed document
 * files, the Theme URL/footer contract, the draft provisioner and the booking
 * links agree on the same five destinations and that no publication path was
 * added by accident.
 */
$root = dirname( __DIR__, 2 );
$fail = array();
$read = static function ( string $relative ) use ( $root ): string {
    $path = $root . '/' . $relative;
    return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
};
$assert = static function ( bool $condition, string $message ) use ( &$fail ): void {
    if ( ! $condition ) { $fail[] = $message; }
};

$documents = array(
    'privacy-policy' => array( 'file' => 'platform/content/legal/privacy-policy.html', 'title' => 'سیاست حفظ حریم خصوصی', 'markers' => array( 'اطلاعات کودکان', 'Google Calendar', 'Stripe', 'LiveKit', 'Australian Privacy Principles', 'GDPR' ) ),
    'terms-and-conditions' => array( 'file' => 'platform/content/legal/terms-and-conditions.html', 'title' => 'شرایط و ضوابط استفاده', 'markers' => array( 'حداقل سن ثبت‌نام ۷ سال', '۱۲ جلسه', 'یک هفته پیش از آغاز ترم بعد', 'مالکیت فکری', 'Australian Consumer Law' ) ),
    'cancellation-and-refunds' => array( 'file' => 'platform/content/legal/cancellation-and-refunds.html', 'title' => 'سیاست لغو و بازپرداخت', 'markers' => array( 'کلاس آشناییٔ رایگان', '۱۲ جلسهٔ پرداختی', 'یک هفته پیش از آغاز ترم بعد', 'Australian Consumer Law', 'دو جلسهٔ جبرانی', 'غیرقابل بازپرداخت بودن مطلق' ) ),
    'recording-and-ai-policy' => array( 'file' => 'platform/content/legal/recording-and-ai-policy.html', 'title' => 'سیاست ضبط کلاس‌ها و استفاده از هوش مصنوعی', 'markers' => array( 'خروجی پژوهش غیرفعال', 'رضایت صریح', 'ولی یا سرپرست', 'پس گرفتن رضایت', 'هیچ پژوهش' ) ),
    'cookie-policy' => array( 'file' => 'platform/content/legal/cookie-policy.html', 'title' => 'سیاست کوکی‌ها', 'markers' => array( 'dzn_booking_csrf', 'wordpress_logged_in_*', 'Stripe', 'Google', 'هیچ ارائه‌دهندهٔ تحلیل', 'مدیریت کوکی‌ها' ) ),
);

foreach ( $documents as $slug => $document ) {
    $content = $read( $document['file'] );
    $assert( '' !== trim( $content ), 'Missing legal source: ' . $document['file'] );
    $assert( false === stripos( $content, '<h1' ), 'Policy content must not author its own H1: ' . $slug );
    foreach ( $document['markers'] as $marker ) {
        $assert( false !== strpos( $content, $marker ), 'Missing required policy subject "' . $marker . '" in ' . $slug );
    }
    $assert( false !== strpos( $content, 'نیازمند تأیید' ) || 'cookie-policy' === $slug, 'Unconfirmed legal facts must stay explicitly marked in ' . $slug );
}

$theme = $read( 'theme/inc/legal.php' );
$assert( '' !== $theme, 'Missing Theme legal registry' );
foreach ( array_keys( $documents ) as $slug ) {
    $assert( false !== strpos( $theme, "'" . $slug . "'" ), 'Theme registry missing canonical slug: ' . $slug );
}
$assert( false !== strpos( $theme, 'قوانین و سیاست‌ها' ), 'Footer section title is missing' );
$assert( false !== strpos( $theme, 'dzn_theme_footer_policy_links' ), 'Footer policy renderer is missing' );
$assert( false !== strpos( $theme, 'noindex' ), 'Pending policy response must be non-indexable' );
$assert( false !== strpos( $theme, 'dzn_theme_legal_redirect_legacy' ), 'Legacy policy redirect is missing' );
$assert( false !== strpos( $theme, 'dzn_theme_render_legal_pending_page' ), 'Safe pending policy response is missing' );

$footer = $read( 'theme/footer.php' );
$assert( false !== strpos( $footer, 'dzn_theme_footer_policy_links' ), 'Footer does not render the policy section' );

$routes = $read( 'theme/inc/routes.php' );
$assert( false !== strpos( $routes, 'dzn_theme_legal_pending_slug_for_path' ), 'Virtual route does not resolve unpublished policies' );
$assert( false !== strpos( $routes, "legal:" ), 'Virtual route does not identify a pending legal document' );

$provisioner = $read( 'platform/src/Core/Application/LegalPageProvisioner.php' );
$assert( '' !== $provisioner, 'Missing legal page provisioner' );
foreach ( $documents as $slug => $document ) {
    $assert( false !== strpos( $provisioner, $document['title'] ), 'Provisioner title missing: ' . $slug );
}
$assert( false !== strpos( $provisioner, "'post_status' => 'draft'" ), 'New policy pages must be created as drafts' );
$assert( false === strpos( $provisioner, "'post_status' => 'publish'" ), 'Provisioner must never create a published policy' );
$assert( false === strpos( $provisioner, 'wp_publish_post' ), 'Provisioner must not publish a policy' );
$assert( false !== strpos( $provisioner, 'published_legal_page_requires_manual_update' ), 'Provisioner must not overwrite a published policy' );
$assert( false !== strpos( $provisioner, 'page-templates/content-policy.php' ), 'Policy presentation template is not attached' );

$booking = $read( 'theme/inc/booking.php' );
foreach ( array( '/privacy-policy/', '/terms-and-conditions/', '/cancellation-and-refunds/' ) as $path ) {
    $assert( false !== strpos( $booking, $path ), 'Booking flow is missing policy link: ' . $path );
}

$postIntro = $read( 'theme/template-parts/portal/post-intro.php' );
foreach ( array( '/privacy-policy/', '/terms-and-conditions/', '/cancellation-and-refunds/' ) as $path ) {
    $assert( false !== strpos( $postIntro, $path ), 'Paid continuation surface is missing policy link: ' . $path );
}

$plugin = $read( 'platform/delnavazan-platform.php' );
$assert( false !== strpos( $plugin, 'LegalPagesController::register' ), 'Operator provisioning action is not registered' );
$assert( false !== strpos( $plugin, 'LegalPagesCommand::register' ), 'WP-CLI provisioning command is not registered' );

if ( $fail ) {
    fwrite( STDERR, "Legal policy source contract failed:\n - " . implode( "\n - ", $fail ) . "\n" );
    exit( 1 );
}

echo "Legal policy source contract passed\n";
