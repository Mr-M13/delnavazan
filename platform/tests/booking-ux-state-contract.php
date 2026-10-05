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
$submit = substr( $js, strpos( $js, "root.querySelector('[data-submit]').addEventListener" ) );
$earlyReturn = strpos( $submit, 'if (!availabilityOk)' );
$finally = strpos( $submit, '} finally {' );
$unlock = strpos( $submit, 'submissionPending = false;', $finally );
$expect( $earlyReturn !== false && $finally !== false && $unlock !== false && $earlyReturn < $finally && strpos( $submit, "goTo('availability');", $earlyReturn ) < $finally && strpos( $submit, 'button.disabled = false;', $finally ) !== false, 'A failed pre-submit availability check must return through the submit finally unlock.' );
$expect( strpos( $js, 'timezoneRefreshPending || availabilityPreviewPending' ) !== false && strpos( $js, 'timezoneRefreshPending = true;' ) !== false, 'Contact review transition must wait for timezone refresh and active preview ownership.' );
$expect( strpos( $js, "goTo('availability');\n        showError(availabilityPreviewFailed" ) !== false, 'Availability failure explanation must be shown after navigation clears prior errors.' );
$expect( strpos( $js, 'return await consumeResponse(response);' ) !== false && substr_count( $js, 'async (response) => ({ response, result: await response.json() })' ) === 2, 'Preview and submit timeouts must cover response body parsing.' );
$expect( strpos( $validation, '! $city' ) !== false, 'City remains required by the canonical booking intake contract.' );
echo "Booking UX transition and timezone contract passed\n";
