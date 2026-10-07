<?php
$fixture = file_get_contents( __DIR__ . '/synthetic-dataset.php' );
$policy = file_get_contents( __DIR__ . '/synthetic-dataset-policy.php' );
if ( ! is_string( $fixture ) ) throw new RuntimeException( 'Synthetic dataset fixture is missing.' );
if ( ! is_string( $policy ) ) throw new RuntimeException( 'Synthetic dataset environment policy is missing.' );
foreach ( array( 'DZN_SYNTHETIC_DATASET_STAGING', 'I_UNDERSTAND_STAGING_TEST_DATA', "'staging'", "'production'", 'staging.delnavazan.com', 'dzn_synthetic_dataset_policy_allows' ) as $policyNeedle ) {
	if ( strpos( $fixture . $policy, $policyNeedle ) === false ) throw new RuntimeException( 'Synthetic dataset staging safety policy missing: ' . $policyNeedle );
}
foreach ( array( 'DZN_SYNTHETIC_DATASET', "array( 'local', 'development' )", 'WP_CLI', 'dzn_manage_courses', 'dzn_manage_teachers', 'dzn_manage_students', 'dzn_manage_onboarding', 'dzn_issue_teacher_invitations', 'dzn_manage_teacher_availability', 'dzn_manage_teaching_eligibility', 'dzn_prepare_booking_request_matches', 'dzn_issue_student_account_claims', 'synthetic.example.invalid', 'DZN-SYNTHETIC-DATASET-V1', 'CatalogueService', 'TeacherService', 'StudentService', 'TeacherOnboardingService', 'TeacherAvailabilityService', 'TeachingEligibilityService', 'TeacherAcceptingStateService', 'BookingRequestSubmissionService', 'BookingRequestMatchAssessmentService', 'PrincipalInvitationService', 'StudentAccountClaimNotificationService', 'prepareDelivery', 'beginExistingClaim', 'finalizeClaim', 'coverage_found', 'dzn_synthetic_dataset_preview', 'dzn_synthetic_dataset_seed', 'dzn_synthetic_dataset_verify', 'dzn_synthetic_dataset_reconcile', 'dzn_synthetic_dataset_teardown', '2030-01-02', 'dzn_synthetic_dataset_v1' ) as $required ) {
	if ( strpos( $fixture, $required ) === false ) throw new RuntimeException( 'Synthetic dataset contract missing: ' . $required );
}
if ( strpos( $fixture, "array( 'staging', 'production' )" ) !== false || strpos( $fixture, 'wp_remote_' ) !== false || strpos( $fixture, 'wp_mail(' ) !== false || strpos( $fixture, 'CommercialPaymentService' ) !== false || strpos( $fixture, 'GoogleCalendarProjection' ) !== false ) {
	throw new RuntimeException( 'Synthetic dataset fixture must not enable live delivery, payment, or provider operations.' );
}
if ( strpos( $fixture, 'DELETE FROM {$wpdb->prefix}{$table} WHERE id IN ({$placeholders})' ) === false || strpos( $fixture, "'DZN-SYNTHETIC-DATASET-V1'" ) === false ) {
	throw new RuntimeException( 'Teardown must be restricted to the manifest-owned synthetic IDs.' );
}
foreach ( array( 'wp_mail(', 'wp_remote_request(', 'wp_remote_post(', 'wp_remote_get(', 'wp_remote_retrieve_body(', 'CommercialPaymentService', 'GoogleCalendarProjection', 'TeacherInvitationDeliveryWorker' ) as $forbidden ) {
	if ( strpos( $fixture, $forbidden ) !== false ) throw new RuntimeException( 'Synthetic dataset must not call external delivery or provider services: ' . $forbidden );
}
if ( strpos( $fixture, "'weekday' => 3" ) === false || strpos( $fixture, "'state' => 'requestable'" ) === false || strpos( $fixture, "'state' => 'accepting'" ) === false || strpos( $fixture, "'state' => 'active'" ) === false ) {
	throw new RuntimeException( 'Synthetic ready Teacher must have requestable availability, accepting state, and active eligibility.' );
}
foreach ( array( 'requested_instrument_id', 'selected_intro_course_id', 'booking_request_ids', 'student_account_claim', 'remove those rows through their owning lifecycle first' ) as $safetyNeedle ) {
	if ( strpos( $fixture, $safetyNeedle ) === false ) throw new RuntimeException( 'Synthetic verification or teardown safety missing: ' . $safetyNeedle );
}
echo "Synthetic dataset contract passed\n";
