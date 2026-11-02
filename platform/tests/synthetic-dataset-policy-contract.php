<?php
require_once __DIR__ . '/synthetic-dataset-policy.php';
$cases = array(
	array( 'local', true, '1', false, 'localhost', true, 'local opt-in' ),
	array( 'development', true, '1', false, 'dev.example.test', true, 'development opt-in' ),
	array( 'local', true, '1', false, 'delnavazan.com', false, 'production host rejected from local' ),
	array( 'local', true, '1', false, 'www.delnavazan.com', false, 'production alias rejected from local' ),
	array( 'development', true, '1', false, 'delnavazan.com', false, 'production host rejected from development' ),
	array( 'development', true, '1', false, 'blog.delnavazan.com', false, 'production subdomain rejected from development' ),
	array( 'local', true, '1', false, 'delnavazan.com.', false, 'production host with trailing dot rejected' ),
	array( 'local', true, '1', false, '', false, 'empty host rejected' ),
	array( 'staging', true, '1', false, 'staging.delnavazan.com', false, 'staging requires second gate' ),
	array( 'staging', true, '1', 'I_UNDERSTAND_STAGING_TEST_DATA', 'staging.delnavazan.com', true, 'authorized staging' ),
	array( 'staging', true, '1', 'I_UNDERSTAND_STAGING_TEST_DATA', 'delnavazan.com', false, 'production host rejected' ),
	array( 'staging', true, '1', 'I_UNDERSTAND_STAGING_TEST_DATA', 'www.delnavazan.com', false, 'production alias rejected' ),
	array( 'production', true, '1', 'I_UNDERSTAND_STAGING_TEST_DATA', 'staging.delnavazan.com', false, 'production environment rejected' ),
	array( 'production', true, '1', 'I_UNDERSTAND_STAGING_TEST_DATA', 'delnavazan.com', false, 'production rejected' ),
	array( 'staging', false, '1', 'I_UNDERSTAND_STAGING_TEST_DATA', 'staging.delnavazan.com', false, 'WP-CLI required' ),
	array( 'staging', true, '0', 'I_UNDERSTAND_STAGING_TEST_DATA', 'staging.delnavazan.com', false, 'primary opt-in required' ),
);
foreach ( $cases as $case ) {
	[ $environment, $cli, $optIn, $stagingGate, $host, $expected, $label ] = $case;
	$actual = dzn_synthetic_dataset_policy_allows( $environment, $cli, $optIn, $stagingGate, $host );
	if ( $actual !== $expected ) throw new RuntimeException( 'Environment policy case failed: ' . $label );
}
echo "Synthetic dataset policy contract passed (" . count( $cases ) . " cases)\n";
