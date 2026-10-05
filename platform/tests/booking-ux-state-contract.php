<?php
$root = dirname( __DIR__ );
$js = file_get_contents( $root . '/../theme/assets/js/booking.js' );
$view = file_get_contents( $root . '/../theme/inc/booking.php' );
$validation = file_get_contents( $root . '/src/Core/Application/BookingRequestValidationService.php' );
$expect = static function ( bool $condition, string $message ): void {
    if ( ! $condition ) throw new RuntimeException( $message );
};
$expect( strpos( $view, 'data-step="contact"' ) !== false && strpos( $view, 'data-step="review"' ) !== false, 'Details and final review must be separate steps.' );
$expect( strpos( $view, 'data-next="review"' ) !== false && strpos( $view, 'data-edit-details' ) !== false, 'Review must have explicit forward and edit transitions.' );
$expect( strpos( $js, "currentStep() === 'contact'" ) !== false && strpos( $js, 'buildReview();' ) !== false, 'Details must validate and commit into read-only review content.' );
$expect( strpos( $js, "root.querySelector('[data-edit-details]').addEventListener('click', () => goTo('contact'))") !== false, 'Edit must intentionally reopen details.' );
$expect( strpos( $view, 'data-timezone required' ) !== false && strpos( $js, "timezone.value = detectedZone" ) !== false, 'Student timezone selector must default from browser timezone when supported.' );
$expect( strpos( $js, "requested_times: slots.map((slot) => ({ local_date: slot.local_date, local_start_time: slot.local_start_time, timezone: timezone.value }))" ) !== false, 'Selected timezone must control submitted booking times.' );
$countryChange = substr( $js, strpos( $js, "country.addEventListener('change'" ), 180 );
$expect( strpos( $countryChange, 'timezone' ) === false, 'Country changes must not assign timezone.' );
$expect( strpos( $js, 'requestId !== availabilityRequestId) return false' ) !== false && strpos( $js, 'fetchWithTimeout' ) !== false && strpos( $js, 'controller.abort()' ) !== false, 'Stale previews must not clear current state and current previews must time out.' );
$expect( strpos( $js, "submissionPending = false;\n      button.disabled = false;" ) !== false, 'A failed availability recheck must unlock the submit action.' );
$expect( strpos( $validation, "! $city" ) !== false, 'City remains required by the canonical booking intake contract.' );
echo "Booking UX transition and timezone contract passed\n";
