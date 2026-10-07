<?php
/** Shared fail-closed environment policy for the synthetic dataset runner. */
function dzn_synthetic_dataset_policy_allows( $environment, $wpCli, $optIn, $stagingGate, $host ) {
	if ( ! $wpCli || $optIn !== '1' ) return false;
	if ( $environment === 'production' ) return false;
	if ( in_array( $environment, array( 'local', 'development' ), true ) ) return true;
	if ( $environment !== 'staging' || $stagingGate !== 'I_UNDERSTAND_STAGING_TEST_DATA' ) return false;
	$host = strtolower( rtrim( $host, '.' ) );
	return $host !== '' && ( $host === 'staging.delnavazan.com' || substr( $host, -strlen( '.staging.delnavazan.com' ) ) === '.staging.delnavazan.com' );
}
