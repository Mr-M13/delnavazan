<?php
/** Shared fail-closed environment policy for the synthetic dataset runner. */

/**
 * True for the live site and every non-staging delnavazan.com subdomain.
 * Staging is carved out because it has its own separate acknowledgement gate.
 */
function dzn_synthetic_dataset_is_production_host( $host ) {
	$host = strtolower( rtrim( (string) $host, '.' ) );
	if ( $host === '' ) return false;
	if ( $host === 'staging.delnavazan.com' || substr( $host, -strlen( '.staging.delnavazan.com' ) ) === '.staging.delnavazan.com' ) return false;
	return $host === 'delnavazan.com' || substr( $host, -strlen( '.delnavazan.com' ) ) === '.delnavazan.com';
}

function dzn_synthetic_dataset_policy_allows( $environment, $wpCli, $optIn, $stagingGate, $host ) {
	if ( ! $wpCli || $optIn !== '1' ) return false;
	$host = strtolower( rtrim( (string) $host, '.' ) );
	// A production hostname is never eligible, regardless of environment type.
	// This closes the local/development bypass if WP_ENVIRONMENT_TYPE is wrong.
	if ( dzn_synthetic_dataset_is_production_host( $host ) ) return false;
	if ( $environment === 'production' ) return false;
	if ( in_array( $environment, array( 'local', 'development' ), true ) ) return $host !== '';
	if ( $environment !== 'staging' || $stagingGate !== 'I_UNDERSTAND_STAGING_TEST_DATA' ) return false;
	return $host !== '' && ( $host === 'staging.delnavazan.com' || substr( $host, -strlen( '.staging.delnavazan.com' ) ) === '.staging.delnavazan.com' );
}
