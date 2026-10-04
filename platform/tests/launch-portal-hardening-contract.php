<?php
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/delnavazan-platform.php' );
$teacher = file_get_contents( dirname( $root ) . '/theme/template-parts/teacher-portal/shell.php' );
if ( false === $plugin || false === $teacher ) throw new RuntimeException( 'Unable to read launch-hardening sources' );
if ( strpos( $plugin, "DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS', false" ) === false ) throw new RuntimeException( 'Temporary nonce diagnostics must default off' );
if ( strpos( $teacher, 'wp_logout_url' ) === false ) throw new RuntimeException( 'Teacher portal must expose an authenticated logout path' );
echo "Launch portal hardening contract passed\n";
