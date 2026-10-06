<?php
$fixture = file_get_contents( __DIR__ . '/synthetic-dataset.php' );
if ( ! is_string( $fixture ) ) throw new RuntimeException( 'Synthetic dataset fixture is missing.' );
foreach ( array( 'DZN_SYNTHETIC_DATASET', "array( 'local', 'development' )", 'WP_CLI', 'dzn_manage_courses', 'dzn_manage_teachers', 'dzn_manage_students', 'synthetic.example.invalid', 'DZN-SYNTHETIC-DATASET-V1', 'CatalogueService', 'TeacherService', 'StudentService', 'function dzn_synthetic_dataset_preview', 'function dzn_synthetic_dataset_seed', 'function dzn_synthetic_dataset_verify', 'function dzn_synthetic_dataset_teardown', 'dzn_synthetic_dataset_v1' ) as $required ) {
	if ( strpos( $fixture, $required ) === false ) throw new RuntimeException( 'Synthetic dataset contract missing: ' . $required );
}
if ( strpos( $fixture, "array( 'staging', 'production' )" ) !== false || strpos( $fixture, 'wp_remote_' ) !== false || strpos( $fixture, 'wp_mail(' ) !== false || strpos( $fixture, 'CommercialPaymentService' ) !== false || strpos( $fixture, 'GoogleCalendarProjection' ) !== false ) {
	throw new RuntimeException( 'Synthetic dataset fixture must not enable live delivery, payment, or provider operations.' );
}
if ( strpos( $fixture, 'DELETE FROM {$wpdb->prefix}{$table} WHERE id IN ({$placeholders})' ) === false || strpos( $fixture, "'DZN-SYNTHETIC-DATASET-V1'" ) === false ) {
	throw new RuntimeException( 'Teardown must be restricted to the manifest-owned synthetic IDs.' );
}
echo "Synthetic dataset contract passed\n";
